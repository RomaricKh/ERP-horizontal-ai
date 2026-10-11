<?php
namespace App\Repository;

use PDO;

class InsightsRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Aggregate supplier item insights from purchase orders with last-price
     * via ROW_NUMBER window function (eliminates N+1 per-item loop).
     *
     * @param int $payeeId  The supplier's payee ID
     * @param int $businessId  The current business ID
     * @return array  Rows with item_name, total_quantity, avg_unit_price, last_date, document_count, document_numbers, last_price
     */
    public function getSupplierItemInsights(int $payeeId, int $businessId): array {
        $stmt = $this->db->prepare("
            SELECT i.name AS item_name,
                   SUM(pod.quantity) AS total_quantity,
                   AVG(pod.unit_price_fc) AS avg_unit_price,
                   MAX(po.po_date) AS last_date,
                   COUNT(DISTINCT po.id) AS document_count,
                   GROUP_CONCAT(DISTINCT po.po_number ORDER BY po.po_date DESC SEPARATOR ', ') AS document_numbers,
                   lp.last_price
            FROM po_details pod
            JOIN purchase_orders po ON po.id = pod.purchase_order_id
            JOIN items i ON i.id = pod.item_id
            LEFT JOIN (
                SELECT pod2.item_id,
                       pod2.unit_price_fc AS last_price,
                       ROW_NUMBER() OVER (PARTITION BY pod2.item_id ORDER BY po2.po_date DESC, po2.id DESC) AS rn
                FROM po_details pod2
                JOIN purchase_orders po2 ON po2.id = pod2.purchase_order_id
                WHERE po2.supplier_id = :payee_id_lp
                  AND po2.business_id = :business_id_lp
                  AND po2.status != 'CANCELLED'
            ) lp ON lp.item_id = pod.item_id AND lp.rn = 1
            WHERE po.supplier_id = :payee_id
              AND po.business_id = :business_id
              AND po.status != 'CANCELLED'
            GROUP BY pod.item_id, i.name, lp.last_price
            ORDER BY total_quantity DESC
        ");
        $stmt->execute([
            'payee_id_lp' => $payeeId,
            'business_id_lp' => $businessId,
            'payee_id' => $payeeId,
            'business_id' => $businessId,
        ]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fallback: if last_price is null (no matching window row), use avg_unit_price
        foreach ($items as &$item) {
            if ($item['last_price'] === null) {
                $item['last_price'] = $item['avg_unit_price'];
            }
        }

        return $items;
    }

    /**
     * Aggregate customer item/service insights from sales invoices with last-price
     * via ROW_NUMBER window function (eliminates N+1 per-item loop).
     *
     * @param int $payeeId  The customer's payee ID
     * @param int $businessId  The current business ID
     * @return array  Rows with item_name, total_quantity, avg_unit_price, last_date, document_count, document_numbers, last_price
     */
    public function getCustomerItemInsights(int $payeeId, int $businessId): array {
        $stmt = $this->db->prepare("
            SELECT COALESCE(i.name, s.name, 'Unspecified') AS item_name,
                   SUM(sid.quantity) AS total_quantity,
                   AVG(sid.unit_price_fc) AS avg_unit_price,
                   MAX(si.invoice_date) AS last_date,
                   COUNT(DISTINCT si.id) AS document_count,
                   GROUP_CONCAT(DISTINCT si.invoice_number ORDER BY si.invoice_date DESC SEPARATOR ', ') AS document_numbers,
                   lp.last_price
            FROM si_details sid
            JOIN sales_invoices si ON si.id = sid.sales_invoice_id
            LEFT JOIN items i ON i.id = sid.item_id
            LEFT JOIN services s ON s.id = sid.service_id
            LEFT JOIN (
                SELECT sid2.item_id,
                       sid2.service_id,
                       sid2.unit_price_fc AS last_price,
                       ROW_NUMBER() OVER (
                           PARTITION BY COALESCE(sid2.item_id, sid2.service_id + 1000000)
                           ORDER BY si2.invoice_date DESC, si2.id DESC
                       ) AS rn
                FROM si_details sid2
                JOIN sales_invoices si2 ON si2.id = sid2.sales_invoice_id
                WHERE si2.customer_id = :payee_id_lp
                  AND si2.business_id = :business_id_lp
                  AND si2.status != 'CANCELLED'
            ) lp ON (
                (lp.item_id IS NOT NULL AND lp.item_id = sid.item_id)
                OR (lp.service_id IS NOT NULL AND lp.service_id = sid.service_id)
            ) AND lp.rn = 1
            WHERE si.customer_id = :payee_id
              AND si.business_id = :business_id
              AND si.status != 'CANCELLED'
            GROUP BY COALESCE(sid.item_id, sid.service_id + 1000000), COALESCE(i.name, s.name, 'Unspecified'), lp.last_price
            ORDER BY total_quantity DESC
        ");
        $stmt->execute([
            'payee_id_lp' => $payeeId,
            'business_id_lp' => $businessId,
            'payee_id' => $payeeId,
            'business_id' => $businessId,
        ]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fallback: if last_price is null, use avg_unit_price
        foreach ($items as &$item) {
            if ($item['last_price'] === null) {
                $item['last_price'] = $item['avg_unit_price'];
            }
        }

        return $items;
    }

    /**
     * Invoice-level fallback summary when no SI line items exist for the customer.
     *
     * @param int $payeeId  The customer's payee ID
     * @param int $businessId  The current business ID
     * @return array  Rows with item_name (invoice reference), total_quantity=1, avg_unit_price, last_date, document_count=1, document_numbers
     */
    public function getCustomerInvoiceFallback(int $payeeId, int $businessId): array {
        $stmt = $this->db->prepare("
            SELECT CONCAT('Invoice #', si.invoice_number) AS item_name,
                   1 AS total_quantity,
                   si.total_amount_fc AS avg_unit_price,
                   si.invoice_date AS last_date,
                   1 AS document_count,
                   si.invoice_number AS document_numbers
            FROM sales_invoices si
            WHERE si.customer_id = :payee_id
              AND si.business_id = :business_id
              AND si.status != 'CANCELLED'
            ORDER BY si.invoice_date DESC
        ");
        $stmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * General insights: expenses, salaries, payments, collections aggregates.
     *
     * @param int $payeeId  The payee ID
     * @param int $businessId  The current business ID
     * @return array  Associative array with 'expenses', 'salaries', 'payments', 'collections' keys
     */
    public function getGeneralInsights(int $payeeId, int $businessId): array {
        // Expenses
        $expStmt = $this->db->prepare("
            SELECT COUNT(*) as count, COALESCE(SUM(amount_fc), 0) as total
            FROM expenses
            WHERE payee_id = :payee_id AND business_id = :business_id
        ");
        $expStmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId]);
        $expRow = $expStmt->fetch(PDO::FETCH_ASSOC);

        // Expense categories breakdown
        $catStmt = $this->db->prepare("
            SELECT ec.name, COUNT(*) as count, COALESCE(SUM(e.amount_fc), 0) as total
            FROM expenses e
            LEFT JOIN expense_categories ec ON ec.id = e.expense_category_id
            WHERE e.payee_id = :payee_id AND e.business_id = :business_id
            GROUP BY e.expense_category_id, ec.name
            ORDER BY total DESC
        ");
        $catStmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId]);
        $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

        // Salaries
        $salStmt = $this->db->prepare("
            SELECT COUNT(*) as count,
                   COALESCE(SUM(gross_amount_fc), 0) as gross_total,
                   COALESCE(SUM(net_amount_fc), 0) as net_total
            FROM salaries
            WHERE payee_id = :payee_id AND business_id = :business_id
        ");
        $salStmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId]);
        $salRow = $salStmt->fetch(PDO::FETCH_ASSOC);

        // Payments
        $payStmt = $this->db->prepare("
            SELECT COUNT(*) as count, COALESCE(SUM(amount_fc), 0) as total
            FROM payment_slips
            WHERE payee_id = :payee_id AND business_id = :business_id
        ");
        $payStmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId]);
        $payRow = $payStmt->fetch(PDO::FETCH_ASSOC);

        // Collections
        $colStmt = $this->db->prepare("
            SELECT COUNT(*) as count, COALESCE(SUM(amount_fc), 0) as total
            FROM collection_slips
            WHERE payee_id = :payee_id AND business_id = :business_id
        ");
        $colStmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId]);
        $colRow = $colStmt->fetch(PDO::FETCH_ASSOC);

        return [
            'expenses' => [
                'count' => (int) $expRow['count'],
                'total' => (float) $expRow['total'],
                'categories' => $categories,
            ],
            'salaries' => [
                'count' => (int) ($salRow['count'] ?? 0),
                'gross_total' => (float) ($salRow['gross_total'] ?? 0),
                'net_total' => (float) ($salRow['net_total'] ?? 0),
            ],
            'payments' => [
                'count' => (int) $payRow['count'],
                'total' => (float) $payRow['total'],
            ],
            'collections' => [
                'count' => (int) $colRow['count'],
                'total' => (float) $colRow['total'],
            ],
        ];
    }

    /**
     * Partner insights: shares, equity movements, distributions, payments, collections.
     *
     * @param int $payeeId  The partner's payee ID
     * @param int $businessId  The current business ID
     * @return array  Associative array with 'shares', 'equity', 'distributions', 'payments', 'collections' keys
     */
    public function getPartnerInsights(int $payeeId, int $businessId): array {
        // Partner shares
        $shareStmt = $this->db->prepare("
            SELECT COALESCE(share_percent, 0) AS ownership_pct,
                   COALESCE(invested_capital, 0) AS invested_capital
            FROM partner_shares
            WHERE partner_id = :payee_id AND business_id = :business_id
            LIMIT 1
        ");
        $shareStmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId]);
        $shares = $shareStmt->fetch(PDO::FETCH_ASSOC) ?: ['ownership_pct' => 0, 'invested_capital' => 0];

        // Equity movements
        $equityStmt = $this->db->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN equity_type = 'CONTRIBUTION' THEN amount ELSE 0 END), 0) AS contributions,
                COALESCE(SUM(CASE WHEN equity_type = 'WITHDRAWAL' THEN amount ELSE 0 END), 0) AS withdrawals
            FROM partner_equity
            WHERE partner_id = :payee_id AND business_id = :business_id
        ");
        $equityStmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId]);
        $equityRow = $equityStmt->fetch(PDO::FETCH_ASSOC);
        $contributions = (float)($equityRow['contributions'] ?? 0);
        $withdrawals = (float)($equityRow['withdrawals'] ?? 0);

        // Profit distributions
        $distStmt = $this->db->prepare("
            SELECT COUNT(*) AS count, COALESCE(SUM(amount_bc), 0) AS total
            FROM profit_distributions
            WHERE partner_id = :payee_id
        ");
        $distStmt->execute(['payee_id' => $payeeId]);
        $distRow = $distStmt->fetch(PDO::FETCH_ASSOC);

        // Payments
        $payStmt = $this->db->prepare("
            SELECT COUNT(*) AS count, COALESCE(SUM(total_bc), 0) AS total
            FROM payment_slips WHERE payee_id = :payee_id AND business_id = :business_id
        ");
        $payStmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId]);
        $payRow = $payStmt->fetch(PDO::FETCH_ASSOC);

        // Collections
        $colStmt = $this->db->prepare("
            SELECT COUNT(*) AS count, COALESCE(SUM(total_bc), 0) AS total
            FROM collection_slips WHERE payee_id = :payee_id AND business_id = :business_id
        ");
        $colStmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId]);
        $colRow = $colStmt->fetch(PDO::FETCH_ASSOC);

        return [
            'shares' => [
                'ownership_pct' => (float) $shares['ownership_pct'],
                'invested_capital' => (float) $shares['invested_capital'],
            ],
            'equity' => [
                'contributions' => $contributions,
                'withdrawals' => $withdrawals,
                'net' => $contributions - $withdrawals,
            ],
            'distributions' => [
                'count' => (int) ($distRow['count'] ?? 0),
                'total' => (float) ($distRow['total'] ?? 0),
            ],
            'payments' => [
                'count' => (int) $payRow['count'],
                'total' => (float) $payRow['total'],
            ],
            'collections' => [
                'count' => (int) $colRow['count'],
                'total' => (float) $colRow['total'],
            ],
        ];
    }

    /**
     * Items assigned to a customer (via items.customer_id = people.id) with stock and batch info.
     *
     * @param int $peopleId  The person's people.id (not payee_id)
     * @param int $businessId  The current business ID
     * @return array  Rows with id, name, cost_price, sale_price, item_type, stock_quantity, batch_count, nearest_expiry
     */
    public function getCustomerItems(int $peopleId, int $businessId): array {
        $stmt = $this->db->prepare("
            SELECT i.id, i.name, i.cost_price, i.sale_price, it.name AS item_type,
                   COALESCE(s.quantity, 0) AS stock_quantity,
                   (SELECT COUNT(*) FROM item_batches ib WHERE ib.item_id = i.id AND ib.business_id = :bid2 AND ib.status = 'active') AS batch_count,
                   (SELECT MIN(ib2.expiry_date) FROM item_batches ib2 WHERE ib2.item_id = i.id AND ib2.business_id = :bid3 AND ib2.status = 'active' AND ib2.expiry_date IS NOT NULL) AS nearest_expiry
            FROM items i
            LEFT JOIN stock s ON s.item_id = i.id AND s.business_id = :bid4
            LEFT JOIN item_types it ON it.id = i.item_type_id
            WHERE i.customer_id = :people_id AND i.business_id = :bid1
            ORDER BY i.name
        ");
        $stmt->execute([
            'people_id' => $peopleId,
            'bid1' => $businessId,
            'bid2' => $businessId,
            'bid3' => $businessId,
            'bid4' => $businessId,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
