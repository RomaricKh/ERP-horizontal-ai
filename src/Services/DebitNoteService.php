<?php

namespace App\Services;

use App\Repository\DebitNoteRepository;
use App\Repository\PurchaseOrderRepository;
use App\Repository\PeopleRepository;
use App\Support\AuthContext;
use PDO;

class DebitNoteService
{
    private DebitNoteRepository $repo;
    private PurchaseOrderRepository $poRepo;
    private PeopleRepository $peopleRepo;
    private ItemService $itemService;
    private AuditService $audit;
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db          = $db;
        $this->repo        = new DebitNoteRepository($db);
        $this->poRepo      = new PurchaseOrderRepository($db);
        $this->peopleRepo  = new PeopleRepository($db);
        $this->itemService = new ItemService($db);
        $this->audit       = new AuditService($db);
    }

    /**
     * List all debit notes for the current business.
     */
    public function list(): array
    {
        $ctx = AuthContext::get();
        return $this->repo->findAllForBusiness($ctx->business_id);
    }

    /**
     * Get a single debit note by ID.
     */
    public function getById(int $id): array
    {
        $ctx  = AuthContext::get();
        $note = $this->repo->findById($id, $ctx->business_id);
        if (!$note) {
            throw new \RuntimeException('Debit note not found');
        }
        return $note;
    }

    /**
     * Create a debit note (DRAFT) against a purchase order.
     *
     * Required: purchase_order_id, debit_note_date, lines[]
     * Each line: item_id, quantity, unit_price_fc, unit_price_bc
     *
     * Validates:
     * - PO exists and is RECEIVED or CLOSED
     * - Total does not exceed PO total (Property 5)
     */
    public function create(array $data, array $lines = []): int
    {
        $ctx = AuthContext::get();

        if (empty($data['purchase_order_id'])) {
            throw new \InvalidArgumentException('Purchase order ID is required');
        }
        if (empty($data['debit_note_date'])) {
            throw new \InvalidArgumentException('Debit note date is required');
        }

        $po = $this->poRepo->findById((int) $data['purchase_order_id'], $ctx->business_id);
        if (!$po) {
            throw new \RuntimeException('Purchase order not found');
        }
        if (!in_array($po['status'], ['RECEIVED', 'CLOSED'], true)) {
            throw new \RuntimeException('Debit notes can only be issued for RECEIVED or CLOSED purchase orders');
        }

        $this->db->beginTransaction();
        try {
            $debitNoteNumber = $this->repo->generateNumber($ctx->business_id);

            $noteId = $this->repo->create([
                'business_id'       => $ctx->business_id,
                'purchase_order_id' => (int) $data['purchase_order_id'],
                'debit_note_number' => $debitNoteNumber,
                'debit_note_date'   => $data['debit_note_date'],
                'currency'          => $po['currency'],
                'exchange_rate'     => $po['exchange_rate'],
                'notes'             => $data['notes'] ?? null,
                'created_by'        => $ctx->user_id,
            ]);

            foreach ($lines as $line) {
                // Validate return quantity doesn't exceed available stock
                if (!empty($line['item_id'])) {
                    $stockStmt = $this->db->prepare("
                        SELECT COALESCE(s.quantity, 0) AS stock_quantity
                        FROM stock s
                        WHERE s.item_id = :item_id AND s.business_id = :business_id
                        LIMIT 1
                    ");
                    $stockStmt->execute([
                        'item_id'     => (int) $line['item_id'],
                        'business_id' => $ctx->business_id,
                    ]);
                    $currentStock = (float) ($stockStmt->fetchColumn() ?: 0);
                    $returnQty = (float) $line['quantity'];

                    if ($returnQty > $currentStock) {
                        $itemNameStmt = $this->db->prepare("SELECT name FROM items WHERE id = :id LIMIT 1");
                        $itemNameStmt->execute(['id' => (int) $line['item_id']]);
                        $itemName = $itemNameStmt->fetchColumn() ?: "Item #{$line['item_id']}";
                        throw new \InvalidArgumentException(
                            "Cannot return {$returnQty} of '{$itemName}': only {$currentStock} in stock"
                        );
                    }
                }

                $this->repo->addLine($noteId, $line);
            }

            $this->repo->recalculateTotals($noteId);

            // Validate Property 5: debit note total SHALL NOT exceed PO total
            $note = $this->repo->findById($noteId, $ctx->business_id);
            $existingDebitTotal = $this->repo->sumForPurchaseOrder((int) $data['purchase_order_id'], $ctx->business_id);
            $poTotalBc = (float) $po['total_amount_bc'];
            $newTotal = $existingDebitTotal + (float) $note['total_amount_bc'];

            if ($newTotal > $poTotalBc) {
                throw new \InvalidArgumentException(
                    'Debit note total would exceed the original PO total. ' .
                    'PO total: ' . $poTotalBc . ', already debited: ' . $existingDebitTotal .
                    ', this note: ' . $note['total_amount_bc']
                );
            }

            $this->audit->log(
                'CREATE', 'debit_notes', $noteId, 'CREATE', null,
                ['debit_note_number' => $debitNoteNumber, 'purchase_order_id' => $data['purchase_order_id']],
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
     * Issue a debit note (transition from DRAFT to ISSUED).
     *
     * When issued:
     * - Reverse stock OUT for each item line (returned items leave stock)
     * - Reduce the supplier's payee balance by the debit note total
     */
    public function issue(int $id): void
    {
        $ctx  = AuthContext::get();
        $note = $this->repo->findById($id, $ctx->business_id);
        if (!$note) {
            throw new \RuntimeException('Debit note not found');
        }
        if ($note['status'] !== 'DRAFT') {
            throw new \RuntimeException('Only DRAFT debit notes can be issued');
        }

        // Validate Property 5 again at issue time
        $po = $this->poRepo->findById((int) $note['purchase_order_id'], $ctx->business_id);
        if (!$po) {
            throw new \RuntimeException('Linked purchase order not found');
        }
        $existingDebitTotal = $this->repo->sumForPurchaseOrder((int) $note['purchase_order_id'], $ctx->business_id);
        $poTotalBc = (float) $po['total_amount_bc'];
        $newTotal = $existingDebitTotal + (float) $note['total_amount_bc'];
        if ($newTotal > $poTotalBc) {
            throw new \InvalidArgumentException(
                'Debit note total would exceed the original PO total.'
            );
        }

        $this->db->beginTransaction();
        try {
            // Update status to ISSUED
            $this->repo->updateStatus($id, 'ISSUED');

            // Reverse stock: OUT for each item line (returned items leave stock)
            $lines = $note['lines'] ?? [];
            foreach ($lines as $line) {
                if (empty($line['item_id'])) {
                    continue;
                }
                $this->itemService->adjustStock(
                    (int) $line['item_id'],
                    'OUT',
                    (float) $line['quantity'],
                    'DN-' . $note['debit_note_number'],
                    'Stock OUT from Debit Note #' . $note['debit_note_number']
                );
            }

            // Reduce supplier payee balance (debit note reduces what we owe supplier)
            // Convention: positive delta = reduces debt (same as payment)
            $supplierId = $po['supplier_id'] ?? null;
            if ($supplierId) {
                $totalBc = (float) $note['total_amount_bc'];
                if ($totalBc > 0) {
                    $this->peopleRepo->updatePayeeBalance((int) $supplierId, $totalBc);
                }
            }

            $this->audit->log(
                'UPDATE', 'debit_notes', $id, 'STATUS_CHANGE',
                ['status' => 'DRAFT'], ['status' => 'ISSUED'],
                $ctx->user_id, $ctx->tenant_id, $ctx->business_id
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Delete a DRAFT debit note.
     * Only DRAFT status can be deleted. ISSUED debit notes cannot be reversed.
     *
     * @throws \RuntimeException if not found or not DRAFT
     */
    public function delete(int $id): void
    {
        $ctx  = AuthContext::get();
        $note = $this->repo->findById($id, $ctx->business_id);
        if (!$note) {
            throw new \RuntimeException('Debit note not found');
        }
        if ($note['status'] !== 'DRAFT') {
            throw new \RuntimeException('Only DRAFT debit notes can be deleted. Issued notes cannot be reversed.');
        }

        $this->db->beginTransaction();
        try {
            // Delete lines first (CASCADE should handle it, but explicit for safety)
            $stmt = $this->db->prepare("DELETE FROM debit_note_items WHERE debit_note_id = :id");
            $stmt->execute(['id' => $id]);

            // Delete the debit note header
            $stmt = $this->db->prepare("DELETE FROM debit_notes WHERE id = :id AND business_id = :business_id");
            $stmt->execute(['id' => $id, 'business_id' => $ctx->business_id]);

            $this->audit->log(
                'DELETE', 'debit_notes', $id, 'DELETE',
                $note, null,
                $ctx->user_id, $ctx->tenant_id, $ctx->business_id
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
