<?php

namespace App\Services;

use App\Repository\CreditNoteRepository;
use App\Repository\SalesInvoiceRepository;
use App\Repository\PeopleRepository;
use App\Support\AuthContext;
use PDO;

class CreditNoteService
{
    private CreditNoteRepository $repo;
    private SalesInvoiceRepository $invoiceRepo;
    private PeopleRepository $peopleRepo;
    private ItemService $itemService;
    private AuditService $audit;
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db          = $db;
        $this->repo        = new CreditNoteRepository($db);
        $this->invoiceRepo = new SalesInvoiceRepository($db);
        $this->peopleRepo  = new PeopleRepository($db);
        $this->itemService = new ItemService($db);
        $this->audit       = new AuditService($db);
    }

    /**
     * List all credit notes for the current business.
     */
    public function list(): array
    {
        $ctx = AuthContext::get();
        return $this->repo->findAllForBusiness($ctx->business_id);
    }

    /**
     * Get a single credit note by ID.
     */
    public function getById(int $id): array
    {
        $ctx  = AuthContext::get();
        $note = $this->repo->findById($id, $ctx->business_id);
        if (!$note) {
            throw new \RuntimeException('Credit note not found');
        }
        return $note;
    }

    /**
     * Create a credit note (DRAFT) against a sales invoice.
     *
     * Required: sales_invoice_id, credit_note_date, lines[]
     * Each line: item_id or service_id, quantity, unit_price_fc, unit_price_bc
     *
     * Validates:
     * - Invoice exists and is DELIVERED or PAID
     * - Total does not exceed invoice total (Property 4)
     */
    public function create(array $data, array $lines = []): int
    {
        $ctx = AuthContext::get();

        if (empty($data['sales_invoice_id'])) {
            throw new \InvalidArgumentException('Sales invoice ID is required');
        }
        if (empty($data['credit_note_date'])) {
            throw new \InvalidArgumentException('Credit note date is required');
        }

        $invoice = $this->invoiceRepo->findById((int) $data['sales_invoice_id'], $ctx->business_id);
        if (!$invoice) {
            throw new \RuntimeException('Sales invoice not found');
        }
        if (!in_array($invoice['status'], ['DELIVERED', 'PAID'], true)) {
            throw new \RuntimeException('Credit notes can only be issued for DELIVERED or PAID invoices');
        }

        $this->db->beginTransaction();
        try {
            $creditNoteNumber = $this->repo->generateNumber($ctx->business_id);

            $noteId = $this->repo->create([
                'business_id'        => $ctx->business_id,
                'sales_invoice_id'   => (int) $data['sales_invoice_id'],
                'credit_note_number' => $creditNoteNumber,
                'credit_note_date'   => $data['credit_note_date'],
                'currency'           => $invoice['currency'],
                'exchange_rate'      => $invoice['exchange_rate'],
                'notes'              => $data['notes'] ?? null,
                'created_by'         => $ctx->user_id,
            ]);

            foreach ($lines as $line) {
                $this->repo->addLine($noteId, $line);
            }

            $this->repo->recalculateTotals($noteId);

            // Validate Property 4: credit note total SHALL NOT exceed invoice total
            $note = $this->repo->findById($noteId, $ctx->business_id);
            $existingCreditTotal = $this->repo->sumForInvoice((int) $data['sales_invoice_id'], $ctx->business_id);
            $invoiceTotalBc = (float) $invoice['total_amount_bc'];
            $newTotal = $existingCreditTotal + (float) $note['total_amount_bc'];

            if ($newTotal > $invoiceTotalBc) {
                throw new \InvalidArgumentException(
                    'Credit note total would exceed the original invoice total. ' .
                    'Invoice total: ' . $invoiceTotalBc . ', already credited: ' . $existingCreditTotal .
                    ', this note: ' . $note['total_amount_bc']
                );
            }

            $this->audit->log(
                'CREATE', 'credit_notes', $noteId, 'CREATE', null,
                ['credit_note_number' => $creditNoteNumber, 'sales_invoice_id' => $data['sales_invoice_id']],
                $ctx->user_id, $ctx->tenant_id, $ctx->business_id
            );

            $this->db->commit();
            return $noteId;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Issue a credit note (transition from DRAFT to ISSUED).
     *
     * When issued:
     * - Reverse stock IN for each item line (returned items go back to stock)
     * - Reduce the customer's payee balance by the credit note total
     */
    public function issue(int $id): void
    {
        $ctx  = AuthContext::get();
        $note = $this->repo->findById($id, $ctx->business_id);
        if (!$note) {
            throw new \RuntimeException('Credit note not found');
        }
        if ($note['status'] !== 'DRAFT') {
            throw new \RuntimeException('Only DRAFT credit notes can be issued');
        }

        // Validate Property 4 again at issue time
        $invoice = $this->invoiceRepo->findById((int) $note['sales_invoice_id'], $ctx->business_id);
        if (!$invoice) {
            throw new \RuntimeException('Linked sales invoice not found');
        }
        $existingCreditTotal = $this->repo->sumForInvoice((int) $note['sales_invoice_id'], $ctx->business_id);
        $invoiceTotalBc = (float) $invoice['total_amount_bc'];
        $newTotal = $existingCreditTotal + (float) $note['total_amount_bc'];
        if ($newTotal > $invoiceTotalBc) {
            throw new \InvalidArgumentException(
                'Credit note total would exceed the original invoice total.'
            );
        }

        $this->db->beginTransaction();
        try {
            // Update status to ISSUED
            $this->repo->updateStatus($id, 'ISSUED');

            // Reverse stock: IN for each item line (returned items go back to stock)
            $lines = $note['lines'] ?? [];
            foreach ($lines as $line) {
                if (empty($line['item_id'])) {
                    continue; // Skip service lines
                }
                $this->itemService->adjustStock(
                    (int) $line['item_id'],
                    'IN',
                    (float) $line['quantity'],
                    'CN-' . $note['credit_note_number'],
                    'Stock IN from Credit Note #' . $note['credit_note_number']
                );
            }

            // Reduce customer payee balance (credit note reduces what customer owes)
            $customerId = $invoice['customer_id'] ?? null;
            if ($customerId) {
                $totalBc = (float) $note['total_amount_bc'];
                if ($totalBc > 0) {
                    $this->peopleRepo->updatePayeeBalance((int) $customerId, -$totalBc);
                }
            }

            $this->audit->log(
                'UPDATE', 'credit_notes', $id, 'STATUS_CHANGE',
                ['status' => 'DRAFT'], ['status' => 'ISSUED'],
                $ctx->user_id, $ctx->tenant_id, $ctx->business_id
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
