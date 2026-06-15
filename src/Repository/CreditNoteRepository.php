<?php

namespace App\Repository;

use PDO;

class CreditNoteRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Create a credit note header and return the new ID.
     *
     * Expected keys: business_id, sales_invoice_id, credit_note_number,
     *                credit_note_date, currency, exchange_rate, notes, created_by
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO credit_notes
              (business_id, sales_invoice_id, credit_note_number, credit_note_date,
               status, currency, exchange_rate, total_amount_fc, total_amount_bc,
               notes, created_at, created_by)
            VALUES
              (:business_id, :sales_invoice_id, :credit_note_number, :credit_note_date,
               'DRAFT', :currency, :exchange_rate, 0.00, 0.00,
               :notes, NOW(), :created_by)
        ");
        $stmt->execute([
            'business_id'        => $data['business_id'],
            'sales_invoice_id'   => $data['sales_invoice_id'],
            'credit_note_number' => $data['credit_note_number'],
            'credit_note_date'   => $data['credit_note_date'],
            'currency'           => $data['currency'],
            'exchange_rate'      => $data['exchange_rate'],
            'notes'              => $data['notes'] ?? null,
            'created_by'         => $data['created_by'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Add a line item to a credit note and return the new line ID.
     *
     * Expected keys: item_id (or service_id), quantity, unit_price_fc, unit_price_bc
     */
    public function addLine(int $creditNoteId, array $data): int
    {
        $qty     = (float) $data['quantity'];
        $unitFc  = (float) $data['unit_price_fc'];
        $unitBc  = (float) $data['unit_price_bc'];
        $totalFc = round($qty * $unitFc, 2);
        $totalBc = round($qty * $unitBc, 2);

        $stmt = $this->db->prepare("
            INSERT INTO credit_note_items
              (credit_note_id, item_id, service_id, quantity,
               unit_price_fc, unit_price_bc, total_price_fc, total_price_bc)
            VALUES
              (:credit_note_id, :item_id, :service_id, :quantity,
               :unit_price_fc, :unit_price_bc, :total_price_fc, :total_price_bc)
        ");
        $stmt->execute([
            'credit_note_id' => $creditNoteId,
            'item_id'        => $data['item_id'] ?? null,
            'service_id'     => $data['service_id'] ?? null,
            'quantity'        => $qty,
            'unit_price_fc'  => $unitFc,
            'unit_price_bc'  => $unitBc,
            'total_price_fc' => $totalFc,
            'total_price_bc' => $totalBc,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Recalculate and update total amounts on the credit note header.
     */
    public function recalculateTotals(int $creditNoteId): void
    {
        $stmt = $this->db->prepare("
            SELECT
                COALESCE(SUM(total_price_fc), 0) AS total_fc,
                COALESCE(SUM(total_price_bc), 0) AS total_bc
            FROM credit_note_items
            WHERE credit_note_id = :id
        ");
        $stmt->execute(['id' => $creditNoteId]);
        $totals = $stmt->fetch(PDO::FETCH_ASSOC);

        $upd = $this->db->prepare("
            UPDATE credit_notes
            SET total_amount_fc = :total_fc,
                total_amount_bc = :total_bc
            WHERE id = :id
        ");
        $upd->execute([
            'total_fc' => $totals['total_fc'],
            'total_bc' => $totals['total_bc'],
            'id'       => $creditNoteId,
        ]);
    }

    /**
     * Update the status of a credit note.
     */
    public function updateStatus(int $id, string $status): void
    {
        $stmt = $this->db->prepare("
            UPDATE credit_notes SET status = :status WHERE id = :id
        ");
        $stmt->execute(['status' => $status, 'id' => $id]);
    }

    /**
     * Find a credit note by ID, scoped to a business.
     * Includes line items.
     */
    public function findById(int $id, int $businessId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT
                cn.id, cn.business_id, cn.sales_invoice_id,
                cn.credit_note_number, cn.credit_note_date, cn.status,
                cn.currency, cn.exchange_rate,
                cn.total_amount_fc, cn.total_amount_bc,
                cn.notes, cn.created_at, cn.created_by,
                si.invoice_number
            FROM credit_notes cn
            LEFT JOIN sales_invoices si ON si.id = cn.sales_invoice_id
            WHERE cn.id = :id AND cn.business_id = :business_id
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
     * Find all credit notes for a business.
     */
    public function findAllForBusiness(int $businessId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                cn.id, cn.business_id, cn.sales_invoice_id,
                cn.credit_note_number, cn.credit_note_date, cn.status,
                cn.currency, cn.exchange_rate,
                cn.total_amount_fc, cn.total_amount_bc,
                cn.notes, cn.created_at, cn.created_by,
                si.invoice_number
            FROM credit_notes cn
            LEFT JOIN sales_invoices si ON si.id = cn.sales_invoice_id
            WHERE cn.business_id = :business_id
            ORDER BY cn.credit_note_date DESC, cn.id DESC
        ");
        $stmt->execute(['business_id' => $businessId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get all line items for a credit note.
     */
    public function getLines(int $creditNoteId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                cni.id, cni.credit_note_id, cni.item_id, cni.service_id,
                cni.quantity, cni.unit_price_fc, cni.unit_price_bc,
                cni.total_price_fc, cni.total_price_bc,
                i.name AS item_name,
                s.name AS service_name
            FROM credit_note_items cni
            LEFT JOIN items i ON i.id = cni.item_id
            LEFT JOIN services s ON s.id = cni.service_id
            WHERE cni.credit_note_id = :credit_note_id
            ORDER BY cni.id
        ");
        $stmt->execute(['credit_note_id' => $creditNoteId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Generate the next credit note number for a business.
     * Format: CN-{YYYY}-{NNN}
     */
    public function generateNumber(int $businessId): string
    {
        $year = date('Y');
        $prefix = 'CN-' . $year . '-';
        $prefixLen = strlen($prefix);
        $stmt = $this->db->prepare("
            SELECT MAX(CAST(SUBSTRING(credit_note_number, :prefixLen + 1) AS UNSIGNED)) AS max_seq
            FROM credit_notes
            WHERE business_id = :business
              AND credit_note_number LIKE :prefix
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
     * Get the sum of all credit notes issued against a specific invoice.
     */
    public function sumForInvoice(int $salesInvoiceId, int $businessId): float
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(total_amount_bc), 0) AS total
            FROM credit_notes
            WHERE sales_invoice_id = :invoice_id
              AND business_id = :business_id
              AND status = 'ISSUED'
        ");
        $stmt->execute([
            'invoice_id'  => $salesInvoiceId,
            'business_id' => $businessId,
        ]);
        return (float) $stmt->fetchColumn();
    }
}
