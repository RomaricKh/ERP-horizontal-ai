<?php

namespace App\Repository;

use PDO;

class DebitNoteRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Create a debit note header and return the new ID.
     *
     * Expected keys: business_id, purchase_order_id, debit_note_number,
     *                debit_note_date, currency, exchange_rate, notes, created_by
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO debit_notes
              (business_id, purchase_order_id, debit_note_number, debit_note_date,
               status, currency, exchange_rate, total_amount_fc, total_amount_bc,
               notes, created_at, created_by)
            VALUES
              (:business_id, :purchase_order_id, :debit_note_number, :debit_note_date,
               'DRAFT', :currency, :exchange_rate, 0.00, 0.00,
               :notes, NOW(), :created_by)
        ");
        $stmt->execute([
            'business_id'       => $data['business_id'],
            'purchase_order_id' => $data['purchase_order_id'],
            'debit_note_number' => $data['debit_note_number'],
            'debit_note_date'   => $data['debit_note_date'],
            'currency'          => $data['currency'],
            'exchange_rate'     => $data['exchange_rate'],
            'notes'             => $data['notes'] ?? null,
            'created_by'        => $data['created_by'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Add a line item to a debit note and return the new line ID.
     *
     * Expected keys: item_id, quantity, unit_price_fc, unit_price_bc
     */
    public function addLine(int $debitNoteId, array $data): int
    {
        $qty     = (float) $data['quantity'];
        $unitFc  = (float) $data['unit_price_fc'];
        $unitBc  = (float) $data['unit_price_bc'];
        $totalFc = round($qty * $unitFc, 2);
        $totalBc = round($qty * $unitBc, 2);

        $stmt = $this->db->prepare("
            INSERT INTO debit_note_items
              (debit_note_id, item_id, quantity,
               unit_price_fc, unit_price_bc, total_price_fc, total_price_bc)
            VALUES
              (:debit_note_id, :item_id, :quantity,
               :unit_price_fc, :unit_price_bc, :total_price_fc, :total_price_bc)
        ");
        $stmt->execute([
            'debit_note_id'  => $debitNoteId,
            'item_id'        => $data['item_id'] ?? null,
            'quantity'       => $qty,
            'unit_price_fc'  => $unitFc,
            'unit_price_bc'  => $unitBc,
            'total_price_fc' => $totalFc,
            'total_price_bc' => $totalBc,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Recalculate and update total amounts on the debit note header.
     */
    public function recalculateTotals(int $debitNoteId): void
    {
        $stmt = $this->db->prepare("
            SELECT
                COALESCE(SUM(total_price_fc), 0) AS total_fc,
                COALESCE(SUM(total_price_bc), 0) AS total_bc
            FROM debit_note_items
            WHERE debit_note_id = :id
        ");
        $stmt->execute(['id' => $debitNoteId]);
        $totals = $stmt->fetch(PDO::FETCH_ASSOC);

        $upd = $this->db->prepare("
            UPDATE debit_notes
            SET total_amount_fc = :total_fc,
                total_amount_bc = :total_bc
            WHERE id = :id
        ");
        $upd->execute([
            'total_fc' => $totals['total_fc'],
            'total_bc' => $totals['total_bc'],
            'id'       => $debitNoteId,
        ]);
    }

    /**
     * Update the status of a debit note.
     */
    public function updateStatus(int $id, string $status): void
    {
        $stmt = $this->db->prepare("
            UPDATE debit_notes SET status = :status WHERE id = :id
        ");
        $stmt->execute(['status' => $status, 'id' => $id]);
    }

    /**
     * Find a debit note by ID, scoped to a business.
     * Includes line items.
     */
    public function findById(int $id, int $businessId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT
                dn.id, dn.business_id, dn.purchase_order_id,
                dn.debit_note_number, dn.debit_note_date, dn.status,
                dn.currency, dn.exchange_rate,
                dn.total_amount_fc, dn.total_amount_bc,
                dn.notes, dn.created_at, dn.created_by,
                po.po_number
            FROM debit_notes dn
            LEFT JOIN purchase_orders po ON po.id = dn.purchase_order_id
            WHERE dn.id = :id AND dn.business_id = :business_id
            LIMIT 1
        ");
        $stmt->execute(['id' => $id, 'business_id' => $businessId]);
        $note = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$note) {
            return null;
        }

        $note['lines'] = $this->getLines($id);
        return $note;
    }

    /**
     * Find all debit notes for a business.
     */
    public function findAllForBusiness(int $businessId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                dn.id, dn.business_id, dn.purchase_order_id,
                dn.debit_note_number, dn.debit_note_date, dn.status,
                dn.currency, dn.exchange_rate,
                dn.total_amount_fc, dn.total_amount_bc,
                dn.notes, dn.created_at, dn.created_by,
                po.po_number
            FROM debit_notes dn
            LEFT JOIN purchase_orders po ON po.id = dn.purchase_order_id
            WHERE dn.business_id = :business_id
            ORDER BY dn.debit_note_date DESC, dn.id DESC
        ");
        $stmt->execute(['business_id' => $businessId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get all line items for a debit note.
     */
    public function getLines(int $debitNoteId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                dni.id, dni.debit_note_id, dni.item_id,
                dni.quantity, dni.unit_price_fc, dni.unit_price_bc,
                dni.total_price_fc, dni.total_price_bc,
                i.name AS item_name
            FROM debit_note_items dni
            LEFT JOIN items i ON i.id = dni.item_id
            WHERE dni.debit_note_id = :debit_note_id
            ORDER BY dni.id
        ");
        $stmt->execute(['debit_note_id' => $debitNoteId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Generate the next debit note number for a business.
     * Format: DN-{YYYY}-{NNN}
     */
    public function generateNumber(int $businessId): string
    {
        $year = date('Y');
        $prefix = 'DN-' . $year . '-';
        $prefixLen = strlen($prefix);
        $stmt = $this->db->prepare("
            SELECT MAX(CAST(SUBSTRING(debit_note_number, :prefixLen + 1) AS UNSIGNED)) AS max_seq
            FROM debit_notes
            WHERE business_id = :business
              AND debit_note_number LIKE :prefix
        ");
        $stmt->execute([
            'prefixLen' => $prefixLen,
            'business'  => $businessId,
            'prefix'    => $prefix . '%',
        ]);
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        $next = ($row['max_seq'] !== null) ? ((int) $row['max_seq'] + 1) : 1;
        return $prefix . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Get the sum of all debit notes issued against a specific PO.
     */
    public function sumForPurchaseOrder(int $purchaseOrderId, int $businessId): float
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(total_amount_bc), 0) AS total
            FROM debit_notes
            WHERE purchase_order_id = :po_id
              AND business_id = :business_id
              AND status = 'ISSUED'
        ");
        $stmt->execute([
            'po_id'       => $purchaseOrderId,
            'business_id' => $businessId,
        ]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Get already-returned quantities per item for a PO (from ISSUED debit notes).
     * Returns associative array: item_id => total_returned_qty
     */
    public function getReturnedQuantitiesByItem(int $purchaseOrderId, int $businessId): array
    {
        $stmt = $this->db->prepare("
            SELECT dni.item_id, SUM(dni.quantity) AS returned_qty
            FROM debit_note_items dni
            JOIN debit_notes dn ON dn.id = dni.debit_note_id
            WHERE dn.purchase_order_id = :po_id
              AND dn.business_id = :business_id
              AND dn.status = 'ISSUED'
            GROUP BY dni.item_id
        ");
        $stmt->execute([
            'po_id'       => $purchaseOrderId,
            'business_id' => $businessId,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['item_id']] = (float) $row['returned_qty'];
        }
        return $result;
    }
}
