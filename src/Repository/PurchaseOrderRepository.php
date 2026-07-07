<?php
namespace App\Repository;

use PDO;

class PurchaseOrderRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Return all purchase orders for a business with supplier name.
     * JOINs: payees → people for supplier name.
     */
    public function findAllForBusiness(int $businessId): array {
        $stmt = $this->db->prepare("
            SELECT
                po.id,
                po.business_id,
                po.supplier_id,
                p.name          AS supplier_name,
                po.po_number,
                po.po_date,
                po.status,
                po.currency,
                po.exchange_rate,
                po.total_amount_fc,
                po.total_amount_bc,
                po.notes,
                po.created_at,
                po.updated_at,
                po.created_by
            FROM purchase_orders po
            LEFT JOIN payees py
                ON py.id = po.supplier_id
            LEFT JOIN people p
                ON p.id = py.people_id
            WHERE po.business_id = :business
            ORDER BY po.po_date DESC, po.id DESC
        ");
        $stmt->execute(['business' => $businessId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return a single purchase order by id, scoped to business.
     * Includes line items with item names.
     */
    public function findById(int $id, int $businessId): ?array {
        $stmt = $this->db->prepare("
            SELECT
                po.id,
                po.business_id,
                po.supplier_id,
                p.name          AS supplier_name,
                po.po_number,
                po.po_date,
                po.status,
                po.currency,
                po.exchange_rate,
                po.total_amount_fc,
                po.total_amount_bc,
                po.notes,
                po.created_at,
                po.updated_at,
                po.created_by
            FROM purchase_orders po
            LEFT JOIN payees py
                ON py.id = po.supplier_id
            LEFT JOIN people p
                ON p.id = py.people_id
            WHERE po.id          = :id
              AND po.business_id = :business
            LIMIT 1
        ");
        $stmt->execute(['id' => $id, 'business' => $businessId]);
        $po = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$po) {
            return null;
        }

        $po['lines'] = $this->getLines($id);
        return $po;
    }

    /**
     * Insert a new purchase order header and return the new id.
     *
     * Expected keys: business_id, supplier_id, po_number, po_date, currency,
     *                exchange_rate, notes, created_by
     */
    public function create(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO purchase_orders
              (business_id, supplier_id, po_number, po_date, status,
               currency, exchange_rate, total_amount_fc, total_amount_bc,
               discount_percent, notes, created_at, updated_at, created_by)
            VALUES
              (:business_id, :supplier_id, :po_number, :po_date, 'DRAFT',
               :currency, :exchange_rate, 0, 0,
               :discount_percent, :notes, NOW(), NOW(), :created_by)
        ");
        $stmt->execute([
            'business_id'      => $data['business_id'],
            'supplier_id'      => $data['supplier_id'] ?? null,
            'po_number'        => $data['po_number'],
            'po_date'          => $data['po_date'],
            'currency'         => $data['currency'],
            'exchange_rate'    => $data['exchange_rate'],
            'discount_percent' => $data['discount_percent'] ?? 0,
            'notes'            => $data['notes'] ?? null,
            'created_by'       => $data['created_by'],
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Insert a po_details row and return the new line id.
     *
     * Expected keys: item_id, quantity, unit_price_fc, unit_price_bc
     * Optional keys: tax_rate (defaults to 0), discount_percent, expiry_date
     */
    public function addLine(int $poId, array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO po_details
              (purchase_order_id, item_id, quantity,
               unit_price_fc, unit_price_bc,
               total_price_fc, total_price_bc,
               tax_rate, tax_amount_fc, tax_amount_bc,
               discount_percent, expiry_date,
               created_at)
            VALUES
              (:po_id, :item_id, :quantity,
               :unit_price_fc, :unit_price_bc,
               :total_price_fc, :total_price_bc,
               :tax_rate, :tax_amount_fc, :tax_amount_bc,
               :discount_percent, :expiry_date,
               NOW())
        ");
        $qty             = (float) $data['quantity'];
        $unitFc          = (float) $data['unit_price_fc'];
        $unitBc          = (float) $data['unit_price_bc'];
        $discountPercent = (float) ($data['discount_percent'] ?? 0);

        // Apply discount before calculating total: discounted = qty × unit × (1 - discount/100)
        $discountMultiplier = 1 - ($discountPercent / 100);
        $totalFc     = round($qty * $unitFc * $discountMultiplier, 2);
        $totalBc     = round($qty * $unitBc * $discountMultiplier, 2);

        // Tax calculation: tax is applied to the discounted total
        $taxRate     = (float) ($data['tax_rate'] ?? 0);
        $taxAmountFc = round($totalFc * ($taxRate / 100), 2);
        $taxAmountBc = round($totalBc * ($taxRate / 100), 2);

        // Expiry date (optional, for batch-tracked items)
        $expiryDate = (!empty($data['expiry_date']) && $data['expiry_date'] !== '') ? $data['expiry_date'] : null;

        $stmt->execute([
            'po_id'            => $poId,
            'item_id'          => $data['item_id'],
            'quantity'         => $qty,
            'unit_price_fc'    => $unitFc,
            'unit_price_bc'    => $unitBc,
            'total_price_fc'   => $totalFc,
            'total_price_bc'   => $totalBc,
            'tax_rate'         => $taxRate,
            'tax_amount_fc'    => $taxAmountFc,
            'tax_amount_bc'    => $taxAmountBc,
            'discount_percent' => $discountPercent,
            'expiry_date'      => $expiryDate,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Delete a po_details row scoped to the given PO.
     */
    public function removeLine(int $lineId, int $poId): void {
        $stmt = $this->db->prepare("
            DELETE FROM po_details
            WHERE id                = :line_id
              AND purchase_order_id = :po_id
        ");
        $stmt->execute(['line_id' => $lineId, 'po_id' => $poId]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Line item not found or not allowed');
        }
    }

    /**
     * Update the status of a purchase order.
     */
    public function updateStatus(int $id, int $businessId, string $status): void {
        $stmt = $this->db->prepare("
            UPDATE purchase_orders
            SET status     = :status,
                updated_at = NOW()
            WHERE id          = :id
              AND business_id = :business
        ");
        $stmt->execute([
            'status'   => $status,
            'id'       => $id,
            'business' => $businessId,
        ]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Purchase order not found or not allowed');
        }
    }

    /**
     * Delete all line items (po_details) for a given purchase order.
     */
    public function deleteLines(int $poId): void {
        $stmt = $this->db->prepare("
            DELETE FROM po_details
            WHERE purchase_order_id = :po_id
        ");
        $stmt->execute(['po_id' => $poId]);
    }

    /**
     * Delete a purchase order scoped to business.
     * Only allowed if status is DRAFT.
     */
    public function delete(int $id, int $businessId): void {
        $stmt = $this->db->prepare("
            DELETE FROM purchase_orders
            WHERE id          = :id
              AND business_id = :business
              AND status      = 'DRAFT'
        ");
        $stmt->execute(['id' => $id, 'business' => $businessId]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Purchase order not found, not in DRAFT status, or not allowed');
        }
    }

    /**
     * Update the PO number.
     */
    public function updatePoNumber(int $id, string $newNumber, PDO $db): void {
        $stmt = $db->prepare("
            UPDATE purchase_orders
            SET po_number  = :po_number,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            'po_number' => $newNumber,
            'id'        => $id,
        ]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Purchase order not found or number unchanged');
        }
    }

    /**
     * Update the header details of a purchase order (allowed for DRAFT POs).
     */
    public function updateHeader(int $id, int $businessId, array $data): void {
        $stmt = $this->db->prepare("
            UPDATE purchase_orders
            SET supplier_id      = :supplier_id,
                po_date          = :po_date,
                currency         = :currency,
                exchange_rate    = :exchange_rate,
                discount_percent = :discount_percent,
                notes            = :notes,
                updated_at       = NOW()
            WHERE id          = :id
              AND business_id = :business
              AND status      = 'DRAFT'
        ");
        $stmt->execute([
            'supplier_id'      => $data['supplier_id'] ?? null,
            'po_date'          => $data['po_date'],
            'currency'         => $data['currency'],
            'exchange_rate'    => $data['exchange_rate'],
            'discount_percent' => $data['discount_percent'] ?? 0,
            'notes'            => $data['notes'] ?? null,
            'id'               => $id,
            'business'         => $businessId,
        ]);
    }

    /**
     * Recalculate and update total_amount_fc, total_amount_bc, tax_total_fc, tax_total_bc
     * by summing all po_details lines for the given PO.
     *
     * Applies header-level discount_percent to the line subtotal before adding tax.
     * Formula: total_amount = subtotal × (1 - discount_percent/100)
     * Grand total (what's owed) = total_amount + tax_total
     *
     * Accepts an optional $db parameter for use inside transactions.
     */
    public function recalculateTotals(int $id, ?PDO $db = null): void {
        $conn = $db ?? $this->db;

        // Get line subtotals and tax totals
        $stmt = $conn->prepare("
            SELECT
                COALESCE(SUM(total_price_fc), 0) AS subtotal_fc,
                COALESCE(SUM(total_price_bc), 0) AS subtotal_bc,
                COALESCE(SUM(tax_amount_fc), 0)  AS tax_fc,
                COALESCE(SUM(tax_amount_bc), 0)  AS tax_bc
            FROM po_details
            WHERE purchase_order_id = :po_id
        ");
        $stmt->execute(['po_id' => $id]);
        $totals = $stmt->fetch(PDO::FETCH_ASSOC);

        // Get the header-level discount percent
        $discStmt = $conn->prepare("SELECT discount_percent FROM purchase_orders WHERE id = :id");
        $discStmt->execute(['id' => $id]);
        $discountPercent = (float) ($discStmt->fetchColumn() ?: 0);

        $subtotalFc = (float) $totals['subtotal_fc'];
        $subtotalBc = (float) $totals['subtotal_bc'];
        $taxFc = (float) $totals['tax_fc'];
        $taxBc = (float) $totals['tax_bc'];

        // Apply global discount to subtotal (tax is not discounted — it's already per-line)
        $discountMultiplier = 1 - ($discountPercent / 100);
        $totalFc = round($subtotalFc * $discountMultiplier, 2);
        $totalBc = round($subtotalBc * $discountMultiplier, 2);

        $upd = $conn->prepare("
            UPDATE purchase_orders
            SET total_amount_fc = :total_fc,
                total_amount_bc = :total_bc,
                tax_total_fc    = :tax_fc,
                tax_total_bc    = :tax_bc,
                updated_at      = NOW()
            WHERE id = :id
        ");
        $upd->execute([
            'total_fc' => $totalFc + $taxFc,
            'total_bc' => $totalBc + $taxBc,
            'tax_fc'   => $taxFc,
            'tax_bc'   => $taxBc,
            'id'       => $id,
        ]);
    }

    /**
     * Generate the next PO number for a business in the current year.
     * Format: PO-{YYYY}-{NNN} (zero-padded to 3 digits, sequential per business per year).
     */
    public function generatePoNumber(int $businessId): string {
        $year = date('Y');
        $prefix = 'PO-' . $year . '-';
        $prefixLen = strlen($prefix);
        $stmt = $this->db->prepare("
            SELECT MAX(CAST(SUBSTRING(po_number, :prefixLen + 1) AS UNSIGNED)) AS max_seq
            FROM purchase_orders
            WHERE business_id = :business
              AND po_number LIKE :prefix
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
     * Return all po_details lines for a given PO, with item names and expiry flag.
     */
    public function getLines(int $poId): array {
        $stmt = $this->db->prepare("
            SELECT
                pd.id,
                pd.purchase_order_id,
                pd.item_id,
                i.name          AS item_name,
                i.has_expiry,
                pd.quantity,
                pd.unit_price_fc,
                pd.unit_price_bc,
                pd.total_price_fc,
                pd.total_price_bc,
                pd.tax_rate,
                pd.tax_amount_fc,
                pd.tax_amount_bc,
                pd.discount_percent,
                pd.expiry_date,
                pd.created_at
            FROM po_details pd
            LEFT JOIN items i ON i.id = pd.item_id
            WHERE pd.purchase_order_id = :po_id
            ORDER BY pd.id
        ");
        $stmt->execute(['po_id' => $poId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
