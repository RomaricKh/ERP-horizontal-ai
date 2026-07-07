<?php
namespace App\Controllers;

use App\Config\Database;
use App\Services\PurchaseOrderService;
use App\Services\PdfService;
use App\Templates\PurchaseOrderPdf;
use App\Repository\BusinessRepository;
use App\Support\AuthContext;

class PurchaseOrdersController extends BaseController {

    private PurchaseOrderService $service;

    public function __construct() {
        $this->service = new PurchaseOrderService(Database::getConnection());
    }

    // GET /purchase-orders
    public function index(): void {
        try {
            $this->auth();
            $ctx = AuthContext::get();
            $db = Database::getConnection();
            $pos = $this->service->list();
            // Include computed payment_status and amounts for each PO
            foreach ($pos as &$po) {
                try {
                    $po['payment_status'] = $this->service->computePaymentStatus((int) $po['id']);
                } catch (\Throwable $e) {
                    $po['payment_status'] = null;
                }

                // Get actual payments (from payment_allocations)
                $paidStmt = $db->prepare("
                    SELECT COALESCE(SUM(amount_fc), 0)
                    FROM payment_allocations
                    WHERE reference_type = 'PURCHASE_ORDER' AND reference_id = :po_id
                ");
                $paidStmt->execute(['po_id' => (int) $po['id']]);
                $po['amount_paid_fc'] = (float) $paidStmt->fetchColumn();

                // Get debit note returns (ISSUED only)
                $dnStmt = $db->prepare("
                    SELECT COALESCE(SUM(total_amount_fc), 0)
                    FROM debit_notes
                    WHERE purchase_order_id = :po_id AND business_id = :bid AND status = 'ISSUED'
                ");
                $dnStmt->execute(['po_id' => (int) $po['id'], 'bid' => $ctx->business_id]);
                $po['returns_fc'] = (float) $dnStmt->fetchColumn();

                // Balance = total - returns - paid
                $totalFc = (float) ($po['total_amount_fc'] ?? 0);
                $po['balance_fc'] = round($totalFc - $po['returns_fc'] - $po['amount_paid_fc'], 2);
                if ($po['balance_fc'] < 0) $po['balance_fc'] = 0;
            }
            unset($po);
            $this->json(true, ['purchase_orders' => $pos]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch purchase orders');
        }
    }

    // POST /purchase-orders
    public function store(): void {
        try {
            $this->auth();
            $data  = $this->input(['po_date', 'currency', 'exchange_rate']);
            $lines = $data['lines'] ?? [];
            unset($data['lines']);
            $id = $this->service->create($data, $lines);
            $this->json(true, ['id' => $id], null, 201);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to create purchase order: ' . $e->getMessage());
        }
    }

    // GET /purchase-orders/{id}
    public function viewById(int $id): void {
        try {
            $this->auth();
            $po = $this->service->getById($id);
            // Include computed payment_status in detail response
            try {
                $po['payment_status'] = $this->service->computePaymentStatus($id);
            } catch (\Throwable $e) {
                $po['payment_status'] = null;
            }
            // Include already-returned quantities per item for debit note UI
            $ctx = AuthContext::get();
            $db = Database::getConnection();
            $dnRepo = new \App\Repository\DebitNoteRepository($db);
            $po['returned_quantities'] = $dnRepo->getReturnedQuantitiesByItem($id, $ctx->business_id);

            $this->json(true, ['purchase_order' => $po]);
        } catch (\RuntimeException $e) {
            $this->notFound($e->getMessage());
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch purchase order');
        }
    }

    // PUT /purchase-orders/{id}
    public function update(int $id): void {
        try {
            $this->auth();
            $data  = $this->input([]);
            $lines = $data['lines'] ?? null;
            unset($data['lines']);
            $this->service->update($id, $data, $lines);
            $this->json(true, ['id' => $id]);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to update purchase order: ' . $e->getMessage());
        }
    }

    // PUT /purchase-orders/{id}/number
    public function updateNumber(int $id): void {
        try {
            $this->auth();
            $data = $this->input(['po_number']);
            if (empty(trim($data['po_number'] ?? ''))) {
                $this->json(false, null, 'Purchase order number is required', 422);
                return;
            }
            $this->service->updateNumber($id, trim($data['po_number']));
            $this->json(true, ['id' => $id, 'po_number' => trim($data['po_number'])], 'Purchase order number updated');
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to update purchase order number');
        }
    }

    // DELETE /purchase-orders/{id}
    public function delete(int $id): void {
        try {
            $this->auth();
            $this->service->delete($id);
            $this->json(true, ['id' => $id]);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to delete purchase order');
        }
    }

    // POST /purchase-orders/{id}/status
    public function updateStatus(int $id): void {
        try {
            $this->auth();
            $data      = $this->input(['status']);
            $newStatus = strtoupper(trim($data['status'] ?? ''));

            // Check permission based on the requested transition
            $ctx = $this->auth();
            $userPerms = $ctx->permissions ?? [];
            if ($newStatus === 'APPROVED' && !in_array('po.approve', $userPerms, true)) {
                $this->forbidden('Missing permission: po.approve');
            }
            if ($newStatus === 'RECEIVED' && !in_array('po.receive', $userPerms, true)) {
                $this->forbidden('Missing permission: po.receive');
            }

            // Extract optional batch_info for expiry-tracked items (used during RECEIVED transition)
            $batchInfo = $data['batch_info'] ?? null;

            $this->service->updateStatus($id, $newStatus, $batchInfo);
            $this->json(true, ['id' => $id, 'status' => $newStatus]);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to update purchase order status: ' . $e->getMessage());
        }
    }

    // POST /purchase-orders/{id}/lines
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

    // DELETE /purchase-orders/{id}/lines/{lineId}
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

    // POST /purchase-orders/{id}/duplicate
    public function duplicate(int $id): void {
        try {
            $this->auth();
            $newId = $this->service->duplicate($id);
            $this->json(true, ['id' => $newId], null, 201);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to duplicate purchase order: ' . $e->getMessage());
        }
    }

    // GET /purchase-orders/{id}/payment-status
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

    // GET /purchase-orders/{id}/payment-shortcut
    public function paymentShortcut(int $id): void {
        try {
            $this->auth();
            $data = $this->service->getPaymentShortcutData($id);
            $this->json(true, ['payment_shortcut' => $data]);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to get payment shortcut data');
        }
    }

    // GET /purchase-orders/{id}/pdf
    public function downloadPdf(int $id): void {
        try {
            $this->auth();
            $ctx = AuthContext::get();
            $db  = Database::getConnection();

            $po    = $this->service->getById($id);
            $lines = $po['lines'] ?? [];

            // Get business info
            $businessRepo = new BusinessRepository($db);
            $business     = $businessRepo->getBusinessByID($ctx->business_id) ?? [];

            // Get supplier info via payee → people
            $supplier = [];
            if (!empty($po['supplier_id'])) {
                $stmt = $db->prepare("
                    SELECT p.name, p.address, p.phone, p.email
                    FROM payees py
                    JOIN people p ON p.id = py.people_id
                    WHERE py.id = :payee_id
                    LIMIT 1
                ");
                $stmt->execute(['payee_id' => $po['supplier_id']]);
                $supplier = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            }

            $html     = PurchaseOrderPdf::render($po, $lines, $business, $supplier);
            $filename = 'PO_' . ($po['po_number'] ?? $id) . '.pdf';

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
