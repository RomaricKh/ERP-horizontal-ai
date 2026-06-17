<?php
namespace App\Repository;

use PDO;

class SalesInvoiceRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Return all sales invoices for a business with customer name.
     * JOINs: payees → people for customer name.
     */
    public function findAllForBusiness(int $businessId): array {
        $stmt = $this->db->prepare("
            SELECT
                si.id,
                si.business_id,
                si.customer_id,
                p.name           AS customer_name,
                si.invoice_number,
                si.invoice_date,
                si.due_date,
                si.status,
                si.currency,
                si.exchange_rate,
                si.total_amount_fc,
                si.total_amount_bc,
                si.notes,
                si.created_at,
                si.updated_at,
                si.created_by
            FROM sales_invoices si
            LEFT JOIN payees py
                ON py.id = si.customer_id
            LEFT JOIN people p
                ON p.id = py.people_id
            WHERE si.business_id = :business
            ORDER BY si.invoice_date DESC, si.id DESC
        ");
        $stmt->execute(['business' => $businessId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return a single sales invoice by id, scoped to business.
     * Includes line items with item names.
     */
    public function findById(int $id, int $businessId): ?array {
        $stmt = $this->db->prepare("
            SELECT
                si.id,
                si.business_id,
                si.customer_id,
                si.customer_id   AS customer_payee_id,
                p.name           AS customer_name,
                si.invoice_number,
                si.invoice_date,
                si.due_date,
                si.status,
                si.currency,
                si.exchange_rate,
                si.discount_percent,
                si.total_amount_fc,
                si.total_amount_bc,
                si.notes,
                si.created_at,
                si.updated_at,
                si.created_by
            FROM sales_invoices si
            LEFT JOIN payees py
                ON py.id = si.customer_id
            LEFT JOIN people p
                ON p.id = py.people_id
            WHERE si.id          = :id
              AND si.business_id = :business
            LIMIT 1
        ");
        $stmt->execute(['id' => $id, 'business' => $businessId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            return null;
        }

        $invoice['lines'] = $this->getLines($id);
        return $invoice;
    }

    /**
     * Insert a new sales invoice header and return the new id.
     *
     * Expected keys: business_id, customer_id, invoice_number, invoice_date,
     *                currency, exchange_rate, notes, created_by
     */
    public function create(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO sales_invoices
              (business_id, customer_id, invoice_number, invoice_date, due_date, status,
               currency, exchange_rate, discount_percent, total_amount_fc, total_amount_bc,
               notes, created_at, updated_at, created_by)
            VALUES
              (:business_id, :customer_id, :invoice_number, :invoice_date, :due_date, 'DRAFT',
               :currency, :exchange_rate, :discount_percent, 0, 0,
               :notes, NOW(), NOW(), :created_by)
        ");
        $stmt->execute([
            'business_id'      => $data['business_id'],
            'customer_id'      => $data['customer_id'] ?? null,
            'invoice_number'   => $data['invoice_number'],
            'invoice_date'     => $data['invoice_date'],
            'due_date'         => $data['due_date'] ?? null,
            'currency'         => $data['currency'],
            'exchange_rate'    => $data['exchange_rate'],
            'discount_percent' => $data['discount_percent'] ?? 0,
            'notes'            => $data['notes'] ?? null,
            'created_by'       => $data['created_by'],
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Insert a si_details row and return the new line id.
     *
     * Expected keys: quantity, unit_price_fc, unit_price_bc
     * Optional keys: tax_rate (defaults to 0)
     * Plus one of: item_id (for item lines) or service_id (for service lines).
     * When service_id is provided, item_id is stored as NULL.
     */
    public function addLine(int $invoiceId, array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO si_details
              (sales_invoice_id, item_id, service_id, quantity,
               unit_price_fc, unit_price_bc,
               total_price_fc, total_price_bc,
               tax_rate, tax_amount_fc, tax_amount_bc,
               discount_percent,
               created_at)
            VALUES
              (:invoice_id, :item_id, :service_id, :quantity,
               :unit_price_fc, :unit_price_bc,
               :total_price_fc, :total_price_bc,
               :tax_rate, :tax_amount_fc, :tax_amount_bc,
               :discount_percent,
               NOW())
        ");
        $qty             = (float) $data['quantity'];
        $unitFc          = (float) $data['unit_price_fc'];
        $unitBc          = (float) $data['unit_price_bc'];
        $discountPercent = (float) ($data['discount_percent'] ?? 0);

        // Apply discount before calculating total: discounted = qty × unit × (1 - discount/100)
        $discountMultiplier = 1 - ($discountPercent / 100);
        $totalFc = round($qty * $unitFc * $discountMultiplier, 2);
        $totalBc = round($qty * $unitBc * $discountMultiplier, 2);

        // Tax calculation: tax is applied to the discounted total
        $taxRate     = (float) ($data['tax_rate'] ?? 0);
        $taxAmountFc = round($totalFc * ($taxRate / 100), 2);
        $taxAmountBc = round($totalBc * ($taxRate / 100), 2);

        // Determine item_id and service_id (mutually exclusive)
        $serviceId = $data['service_id'] ?? null;
        $itemId    = $serviceId !== null ? null : ($data['item_id'] ?? null);

        $stmt->execute([
            'invoice_id'       => $invoiceId,
            'item_id'          => $itemId,
            'service_id'       => $serviceId,
            'quantity'         => $qty,
            'unit_price_fc'    => $unitFc,
            'unit_price_bc'    => $unitBc,
            'total_price_fc'   => $totalFc,
            'total_price_bc'   => $totalBc,
            'tax_rate'         => $taxRate,
            'tax_amount_fc'    => $taxAmountFc,
            'tax_amount_bc'    => $taxAmountBc,
            'discount_percent' => $discountPercent,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Delete a si_details row scoped to the given invoice.
     */
    public function removeLine(int $lineId, int $invoiceId): void {
        $stmt = $this->db->prepare("
            DELETE FROM si_details
            WHERE id               = :line_id
              AND sales_invoice_id = :invoice_id
        ");
        $stmt->execute(['line_id' => $lineId, 'invoice_id' => $invoiceId]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Line item not found or not allowed');
        }
    }

    /**
     * Update the status of a sales invoice.
     */
    public function updateStatus(int $id, int $businessId, string $status): void {
        $stmt = $this->db->prepare("
            UPDATE sales_invoices
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
            throw new \RuntimeException('Sales invoice not found or not allowed');
        }
    }

    /**
     * Delete a sales invoice scoped to business.
     * Only allowed if status is DRAFT.
     */
    public function delete(int $id, int $businessId): void {
        $stmt = $this->db->prepare("
            DELETE FROM sales_invoices
            WHERE id          = :id
              AND business_id = :business
              AND status      = 'DRAFT'
        ");
        $stmt->execute(['id' => $id, 'business' => $businessId]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Sales invoice not found, not in DRAFT status, or not allowed');
        }
    }

    /**
     * Recalculate and update total_amount_fc, total_amount_bc, tax_total_fc, tax_total_bc
     * by summing all si_details lines for the given invoice.
     *
     * total_amount includes the line totals (subtotal) — tax totals are stored separately.
     * Accepts an optional $db parameter for use inside transactions.
     */
    public function recalculateTotals(int $id, ?PDO $db = null): void {
        $conn = $db ?? $this->db;

        // 1. Sum line subtotals and tax
        $stmt = $conn->prepare("
            SELECT
                COALESCE(SUM(total_price_fc), 0) AS subtotal_fc,
                COALESCE(SUM(total_price_bc), 0) AS subtotal_bc,
                COALESCE(SUM(tax_amount_fc), 0)  AS tax_fc,
                COALESCE(SUM(tax_amount_bc), 0)  AS tax_bc
            FROM si_details
            WHERE sales_invoice_id = :invoice_id
        ");
        $stmt->execute(['invoice_id' => $id]);
        $totals = $stmt->fetch(PDO::FETCH_ASSOC);

        $subtotalFc = (float) $totals['subtotal_fc'];
        $subtotalBc = (float) $totals['subtotal_bc'];
        $taxFc      = (float) $totals['tax_fc'];
        $taxBc      = (float) $totals['tax_bc'];

        // 2. Fetch global discount_percent from invoice header
        $discStmt = $conn->prepare("SELECT discount_percent FROM sales_invoices WHERE id = :id");
        $discStmt->execute(['id' => $id]);
        $discountPercent = (float) ($discStmt->fetchColumn() ?: 0);

        // 3. Apply global discount to subtotals
        $totalFc = round($subtotalFc * (1 - $discountPercent / 100), 2);
        $totalBc = round($subtotalBc * (1 - $discountPercent / 100), 2);

        // 4. Grand total = discounted subtotal + tax
        $grandTotalFc = $totalFc + $taxFc;
        $grandTotalBc = $totalBc + $taxBc;

        $upd = $conn->prepare("
            UPDATE sales_invoices
            SET total_amount_fc = :total_fc,
                total_amount_bc = :total_bc,
                tax_total_fc    = :tax_fc,
                tax_total_bc    = :tax_bc,
                updated_at      = NOW()
            WHERE id = :id
        ");
        $upd->execute([
            'total_fc' => $grandTotalFc,
            'total_bc' => $grandTotalBc,
            'tax_fc'   => $taxFc,
            'tax_bc'   => $taxBc,
            'id'       => $id,
        ]);
    }

    /**
     * Generate the next invoice number for a business in the current year.
     * Format: INV-{YYYY}-{NNN} (zero-padded to 3 digits, sequential per business per year).
     */
    public function generateInvoiceNumber(int $businessId): string {
        $year = date('Y');
        $prefix = 'INV-' . $year . '-';
        $prefixLen = strlen($prefix);
        $stmt = $this->db->prepare("
            SELECT MAX(CAST(SUBSTRING(invoice_number, :prefixLen + 1) AS UNSIGNED)) AS max_seq
            FROM sales_invoices
            WHERE business_id = :business
              AND invoice_number LIKE :prefix
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
     * Return all si_details lines for a given invoice, with item and service details.
     * LEFT JOINs both items and services tables.
     * Includes a `line_type` field: "item" or "service".
     */
    public function getLines(int $invoiceId): array {
        $stmt = $this->db->prepare("
            SELECT
                sd.id,
                sd.sales_invoice_id,
                sd.item_id,
                i.name           AS item_name,
                sd.service_id,
                s.name           AS service_name,
                s.unit           AS service_unit,
                sd.quantity,
                sd.unit_price_fc,
                sd.unit_price_bc,
                sd.total_price_fc,
                sd.total_price_bc,
                sd.tax_rate,
                sd.tax_amount_fc,
                sd.tax_amount_bc,
                sd.discount_percent,
                sd.created_at,
                CASE
                    WHEN sd.item_id IS NOT NULL THEN 'item'
                    WHEN sd.service_id IS NOT NULL THEN 'service'
                END AS line_type
            FROM si_details sd
            LEFT JOIN items i ON i.id = sd.item_id
            LEFT JOIN services s ON s.id = sd.service_id
            WHERE sd.sales_invoice_id = :invoice_id
            ORDER BY sd.id
        ");
        $stmt->execute(['invoice_id' => $invoiceId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Delete all line items (si_details) for a given sales invoice.
     */
    public function deleteLines(int $invoiceId): void {
        $stmt = $this->db->prepare("
            DELETE FROM si_details
            WHERE sales_invoice_id = :invoice_id
        ");
        $stmt->execute(['invoice_id' => $invoiceId]);
    }

    /**
     * Insert a collection slip record for a paid invoice.
     *
     * Expected keys: business_id, account_id, payee_id (customer_id),
     *                reference_id (invoice id), slip_number, slip_date,
     *                currency, exchange_rate, amount_fc, amount_bc,
     *                method, notes, created_by
     */
    public function createCollectionSlip(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO collection_slips
              (business_id, account_id, payee_id,
               slip_number, slip_date, currency, exchange_rate,
               amount_fc, amount_bc, method, notes,
               created_at, updated_at, created_by)
            VALUES
              (:business_id, :account_id, :payee_id,
               :slip_number, :slip_date, :currency, :exchange_rate,
               :amount_fc, :amount_bc, :method, :notes,
               NOW(), NOW(), :created_by)
        ");
        $stmt->execute([
            'business_id'   => $data['business_id'],
            'account_id'    => $data['account_id'],
            'payee_id'      => $data['payee_id'],
            'slip_number'   => $data['slip_number'],
            'slip_date'     => $data['slip_date'],
            'currency'      => $data['currency'],
            'exchange_rate' => $data['exchange_rate'],
            'amount_fc'     => $data['amount_fc'],
            'amount_bc'     => $data['amount_bc'],
            'method'        => $data['method'] ?? 'CASH',
            'notes'         => $data['notes'] ?? null,
            'created_by'    => $data['created_by'],
        ]);
        $slipId = (int) $this->db->lastInsertId();

        // Create collection allocation for this invoice
        if (!empty($data['reference_id'])) {
            $allocStmt = $this->db->prepare("
                INSERT INTO collection_allocations
                  (collection_slip_id, reference_type, reference_id, amount_fc, amount_bc, created_at)
                VALUES
                  (:slip_id, 'SALES_INVOICE', :reference_id, :amount_fc, :amount_bc, NOW())
            ");
            $allocStmt->execute([
                'slip_id'      => $slipId,
                'reference_id' => $data['reference_id'],
                'amount_fc'    => $data['amount_fc'],
                'amount_bc'    => $data['amount_bc'],
            ]);
        }

        return $slipId;
    }

    /**
     * Generate a slip number for collection slips.
     * Format: CS-{YYYY}-{NNN}
     */
    public function generateSlipNumber(int $businessId): string {
        $year = date('Y');
        $prefix = 'CS-' . $year . '-';
        $prefixLen = strlen($prefix);
        $stmt = $this->db->prepare("
            SELECT MAX(CAST(SUBSTRING(slip_number, :prefixLen + 1) AS UNSIGNED)) AS max_seq
            FROM collection_slips
            WHERE business_id = :business
              AND slip_number LIKE :prefix
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
     * Return AR aging report for a business.
     * Groups outstanding (DELIVERED) invoices with a due_date into aging buckets:
     *   current (not yet due), 1-30, 31-60, 61-90, 90+ days overdue.
     * Returns per-customer breakdown and totals.
     */
    public function getAgingReport(int $businessId): array {
        $stmt = $this->db->prepare("
            SELECT
                si.id,
                si.invoice_number,
                si.invoice_date,
                si.due_date,
                si.total_amount_fc,
                si.total_amount_bc,
                si.customer_id,
                p.name AS customer_name,
                DATEDIFF(CURDATE(), si.due_date) AS days_overdue
            FROM sales_invoices si
            LEFT JOIN payees py ON py.id = si.customer_id
            LEFT JOIN people p ON p.id = py.people_id
            WHERE si.business_id = :business
              AND si.status = 'DELIVERED'
              AND si.due_date IS NOT NULL
            ORDER BY si.due_date ASC
        ");
        $stmt->execute(['business' => $businessId]);
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $buckets = [
            'current' => ['total_fc' => 0, 'total_bc' => 0, 'invoices' => []],
            '1_30'    => ['total_fc' => 0, 'total_bc' => 0, 'invoices' => []],
            '31_60'   => ['total_fc' => 0, 'total_bc' => 0, 'invoices' => []],
            '61_90'   => ['total_fc' => 0, 'total_bc' => 0, 'invoices' => []],
            '90_plus' => ['total_fc' => 0, 'total_bc' => 0, 'invoices' => []],
        ];

        $byCustomer = [];

        foreach ($invoices as $inv) {
            $daysOverdue = (int) $inv['days_overdue'];

            if ($daysOverdue <= 0) {
                $bucket = 'current';
            } elseif ($daysOverdue <= 30) {
                $bucket = '1_30';
            } elseif ($daysOverdue <= 60) {
                $bucket = '31_60';
            } elseif ($daysOverdue <= 90) {
                $bucket = '61_90';
            } else {
                $bucket = '90_plus';
            }

            $amountFc = (float) $inv['total_amount_fc'];
            $amountBc = (float) $inv['total_amount_bc'];

            $buckets[$bucket]['total_fc'] += $amountFc;
            $buckets[$bucket]['total_bc'] += $amountBc;
            $buckets[$bucket]['invoices'][] = $inv;

            // Per-customer breakdown
            $custId   = $inv['customer_id'] ?? 0;
            $custName = $inv['customer_name'] ?? 'Unknown';
            if (!isset($byCustomer[$custId])) {
                $byCustomer[$custId] = [
                    'customer_id'   => $custId,
                    'customer_name' => $custName,
                    'current'       => 0,
                    '1_30'          => 0,
                    '31_60'         => 0,
                    '61_90'         => 0,
                    '90_plus'       => 0,
                    'total'         => 0,
                ];
            }
            $byCustomer[$custId][$bucket] += $amountBc;
            $byCustomer[$custId]['total'] += $amountBc;
        }

        return [
            'buckets'     => $buckets,
            'by_customer' => array_values($byCustomer),
        ];
    }
}
