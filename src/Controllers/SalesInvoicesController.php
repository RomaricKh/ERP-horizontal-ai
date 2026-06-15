<?php
namespace App\Controllers;

use App\Config\Database;
use App\Services\SalesInvoiceService;
use App\Services\PdfService;
use App\Templates\InvoicePdf;
use App\Repository\BusinessRepository;
use App\Repository\PeopleRepository;
use App\Support\AuthContext;

class SalesInvoicesController extends BaseController {

    private SalesInvoiceService $service;

    public function __construct() {
        $this->service = new SalesInvoiceService(Database::getConnection());
    }

    // GET /sales-invoices
    public function index(): void {
        try {
            $this->auth();
            $invoices = $this->service->list();
            // Include computed payment_status and amounts for each invoice
            foreach ($invoices as &$invoice) {
                try {
                    $invoice['payment_status'] = $this->service->computePaymentStatus((int) $invoice['id']);
                } catch (\Throwable $e) {
                    $invoice['payment_status'] = null;
                }
                $remaining = $this->service->computeRemainingAmountFc((int) $invoice['id']);
                $totalFc = (float) ($invoice['total_amount_fc'] ?? 0);
                $invoice['amount_paid_fc'] = round($totalFc - $remaining, 2);
                $invoice['balance_fc'] = $remaining;
            }
            unset($invoice);
            $this->json(true, ['sales_invoices' => $invoices]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch sales invoices'.$e);
        }
    }

    // POST /sales-invoices
    public function store(): void {
        try {
            $this->auth();
            $data  = $this->input(['invoice_date', 'currency', 'exchange_rate']);
            $lines = $data['lines'] ?? [];
            unset($data['lines']);
            $id = $this->service->create($data, $lines);
            $this->json(true, ['id' => $id], null, 201);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to create sales invoice: ' . $e->getMessage());
        }
    }

    // GET /sales-invoices/{id}
    public function viewById(int $id): void {
        try {
            $this->auth();
            $invoice = $this->service->getById($id);
            // Include computed payment_status in detail response
            try {
                $invoice['payment_status'] = $this->service->computePaymentStatus($id);
            } catch (\Throwable $e) {
                $invoice['payment_status'] = null;
            }
            // Compute remaining amount for collection shortcut
            $invoice['remaining_amount_fc'] = $this->service->computeRemainingAmountFc($id);
            $this->json(true, ['sales_invoice' => $invoice]);
        } catch (\RuntimeException $e) {
            $this->notFound($e->getMessage());
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch sales invoice');
        }
    }

    // PUT /sales-invoices/{id}
    public function update(int $id): void {
        try {
            $this->auth();
            // Header edits are not supported after creation in this version
            // Use addLine/removeLine/updateStatus for modifications
            $this->json(false, null, 'Use specific endpoints to modify a sales invoice', 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to update sales invoice');
        }
    }

    // DELETE /sales-invoices/{id}
    public function delete(int $id): void {
        try {
            $this->auth();
            $this->service->delete($id);
            $this->json(true, ['id' => $id]);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to delete sales invoice');
        }
    }

    // POST /sales-invoices/{id}/status
    public function updateStatus(int $id): void {
        try {
            $this->auth();
            $data      = $this->input(['status']);
            $newStatus = strtoupper(trim($data['status'] ?? ''));

            // Check permission based on the requested transition
            $ctx       = $this->auth();
            $userPerms = $ctx->permissions ?? [];

            if ($newStatus === 'APPROVED' && !in_array('invoices.approve', $userPerms, true)) {
                $this->forbidden('Missing permission: invoices.approve');
            }
            if ($newStatus === 'PAID' && !in_array('invoices.pay', $userPerms, true)) {
                $this->forbidden('Missing permission: invoices.pay');
            }

            // Extra data for PAY transition (account_id, method, notes)
            $extra = [
                'account_id' => $data['account_id'] ?? null,
                'method'     => $data['method']     ?? 'CASH',
                'notes'      => $data['notes']      ?? null,
            ];

            $this->service->updateStatus($id, $newStatus, $extra);
            $this->json(true, ['id' => $id, 'status' => $newStatus]);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to update sales invoice status: ' . $e->getMessage());
        }
    }

    // POST /sales-invoices/{id}/lines
    public function addLine(int $id): void {
        try {
            $this->auth();
            $data   = $this->input(['item_id', 'quantity', 'unit_price_fc', 'unit_price_bc']);
            $lineId = $this->service->addLine($id, $data);
            $this->json(true, ['id' => $lineId], null, 201);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to add line item: ' . $e->getMessage());
        }
    }

    // DELETE /sales-invoices/{id}/lines/{lineId}
    public function removeLine(int $id, int $lineId): void {
        try {
            $this->auth();
            $this->service->removeLine($id, $lineId);
            $this->json(true, ['id' => $lineId]);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to remove line item: ' . $e->getMessage());
        }
    }

    // GET /sales-invoices/{id}/payment-status
    public function paymentStatus(int $id): void {
        try {
            $this->auth();
            $status = $this->service->computePaymentStatus($id);
            $this->json(true, ['id' => $id, 'payment_status' => $status]);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 404);
        } catch (\Throwable $e) {
            $this->serverError('Failed to compute payment status');
        }
    }

    // GET /sales-invoices/aging
    public function aging(): void {
        try {
            $this->auth();
            $ctx = AuthContext::get();
            $db  = Database::getConnection();
            $repo = new \App\Repository\SalesInvoiceRepository($db);
            $report = $repo->getAgingReport($ctx->business_id);
            $this->json(true, ['aging' => $report]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch aging report');
        }
    }

    // GET /sales-invoices/{id}/pdf
    public function downloadPdf(int $id): void {
        try {
            $this->auth();
            $ctx = AuthContext::get();
            $db  = Database::getConnection();

            $invoice = $this->service->getById($id);
            $lines   = $invoice['lines'] ?? [];

            // Get business info
            $businessRepo = new BusinessRepository($db);
            $business     = $businessRepo->getBusinessByID($ctx->business_id) ?? [];

            // Get customer info via payee → people
            $peopleRepo = new PeopleRepository($db);
            $customer   = [];
            if (!empty($invoice['customer_id'])) {
                $stmt = $db->prepare("
                    SELECT p.name, p.address, p.phone, p.email
                    FROM payees py
                    JOIN people p ON p.id = py.people_id
                    WHERE py.id = :payee_id
                    LIMIT 1
                ");
                $stmt->execute(['payee_id' => $invoice['customer_id']]);
                $customer = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            }

            $html     = InvoicePdf::render($invoice, $lines, $business, $customer);
            $filename = 'Invoice_' . ($invoice['invoice_number'] ?? $id) . '.pdf';

            $pdfService = new PdfService();
            $pdfService->generate($html, $filename);
            exit;
        } catch (\RuntimeException $e) {
            $this->notFound($e->getMessage());
        } catch (\Throwable $e) {
            $this->serverError('Failed to generate PDF');
        }
    }
}
