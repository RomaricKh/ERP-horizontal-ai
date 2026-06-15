<?php
namespace App\Controllers;

use App\Config\Database;

class InsightsController extends BaseController {

    // GET /people/{payeeId}/insights/supplier
    public function supplier(int $payeeId): void {
        try {
            $ctx = $this->auth();
            $db = Database::getConnection();
            $businessId = $ctx->business_id;

            // Verify payee belongs to current business
            $stmt = $db->prepare("SELECT id FROM payees WHERE id = :id AND business_id = :bid LIMIT 1");
            $stmt->execute(['id' => $payeeId, 'bid' => $businessId]);
            if (!$stmt->fetch()) {
                $this->json(false, null, 'Payee not found', 404);
                return;
            }

            // Aggregate purchase data from po_details JOIN purchase_orders
            $stmt = $db->prepare("
                SELECT i.name AS item_name,
                       SUM(pod.quantity) AS total_quantity,
                       AVG(pod.unit_price_fc) AS avg_unit_price,
                       MAX(po.po_date) AS last_date,
                       COUNT(DISTINCT po.id) AS document_count
                FROM po_details pod
                JOIN purchase_orders po ON po.id = pod.purchase_order_id
                JOIN items i ON i.id = pod.item_id
                WHERE po.supplier_id = :payee_id
                  AND po.business_id = :business_id
                  AND po.status != 'CANCELLED'
                GROUP BY pod.item_id, i.name
                ORDER BY total_quantity DESC
            ");
            $stmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId]);
            $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Get last price for each item
            foreach ($items as &$item) {
                $lpStmt = $db->prepare("
                    SELECT pod.unit_price_fc
                    FROM po_details pod
                    JOIN purchase_orders po ON po.id = pod.purchase_order_id
                    JOIN items i ON i.id = pod.item_id
                    WHERE po.supplier_id = :payee_id
                      AND po.business_id = :business_id
                      AND i.name = :item_name
                      AND po.status != 'CANCELLED'
                    ORDER BY po.po_date DESC
                    LIMIT 1
                ");
                $lpStmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId, 'item_name' => $item['item_name']]);
                $item['last_price'] = $lpStmt->fetchColumn() ?: $item['avg_unit_price'];
            }

            $this->json(true, ['items' => $items]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch supplier insights');
        }
    }

    // GET /people/{payeeId}/insights/customer
    public function customer(int $payeeId): void {
        try {
            $ctx = $this->auth();
            $db = Database::getConnection();
            $businessId = $ctx->business_id;

            // Verify payee belongs to current business
            $stmt = $db->prepare("SELECT id FROM payees WHERE id = :id AND business_id = :bid LIMIT 1");
            $stmt->execute(['id' => $payeeId, 'bid' => $businessId]);
            if (!$stmt->fetch()) {
                $this->json(false, null, 'Payee not found', 404);
                return;
            }

            // Aggregate sales data from si_details JOIN sales_invoices
            $stmt = $db->prepare("
                SELECT i.name AS item_name,
                       SUM(sid.quantity) AS total_quantity,
                       AVG(sid.unit_price_fc) AS avg_unit_price,
                       MAX(si.invoice_date) AS last_date,
                       COUNT(DISTINCT si.id) AS document_count
                FROM si_details sid
                JOIN sales_invoices si ON si.id = sid.sales_invoice_id
                JOIN items i ON i.id = sid.item_id
                WHERE si.customer_id = :payee_id
                  AND si.business_id = :business_id
                  AND si.status != 'CANCELLED'
                  AND sid.item_id IS NOT NULL
                GROUP BY sid.item_id, i.name
                ORDER BY total_quantity DESC
            ");
            $stmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId]);
            $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Get last price for each item
            foreach ($items as &$item) {
                $lpStmt = $db->prepare("
                    SELECT sid.unit_price_fc
                    FROM si_details sid
                    JOIN sales_invoices si ON si.id = sid.sales_invoice_id
                    JOIN items i ON i.id = sid.item_id
                    WHERE si.customer_id = :payee_id
                      AND si.business_id = :business_id
                      AND i.name = :item_name
                      AND si.status != 'CANCELLED'
                      AND sid.item_id IS NOT NULL
                    ORDER BY si.invoice_date DESC
                    LIMIT 1
                ");
                $lpStmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId, 'item_name' => $item['item_name']]);
                $item['last_price'] = $lpStmt->fetchColumn() ?: $item['avg_unit_price'];
            }

            $this->json(true, ['items' => $items]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch customer insights');
        }
    }

    // GET /people/{payeeId}/insights/general
    public function general(int $payeeId): void {
        try {
            $ctx = $this->auth();
            $db = Database::getConnection();
            $businessId = $ctx->business_id;

            // Verify payee belongs to current business
            $stmt = $db->prepare("SELECT id FROM payees WHERE id = :id AND business_id = :bid LIMIT 1");
            $stmt->execute(['id' => $payeeId, 'bid' => $businessId]);
            if (!$stmt->fetch()) {
                $this->json(false, null, 'Payee not found', 404);
                return;
            }

            // Expenses
            $expStmt = $db->prepare("
                SELECT COUNT(*) as count, COALESCE(SUM(amount_fc), 0) as total
                FROM expenses
                WHERE payee_id = :payee_id AND business_id = :business_id
            ");
            $expStmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId]);
            $expRow = $expStmt->fetch(\PDO::FETCH_ASSOC);

            // Expense categories breakdown
            $catStmt = $db->prepare("
                SELECT ec.name, COUNT(*) as count, COALESCE(SUM(e.amount_fc), 0) as total
                FROM expenses e
                LEFT JOIN expense_categories ec ON ec.id = e.expense_category_id
                WHERE e.payee_id = :payee_id AND e.business_id = :business_id
                GROUP BY e.expense_category_id, ec.name
                ORDER BY total DESC
            ");
            $catStmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId]);
            $categories = $catStmt->fetchAll(\PDO::FETCH_ASSOC);

            // Salaries
            $salStmt = $db->prepare("
                SELECT COUNT(*) as count,
                       COALESCE(SUM(gross_amount_fc), 0) as gross_total,
                       COALESCE(SUM(net_amount_fc), 0) as net_total
                FROM salaries
                WHERE payee_id = :payee_id AND business_id = :business_id
            ");
            $salStmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId]);
            $salRow = $salStmt->fetch(\PDO::FETCH_ASSOC);

            // Payments
            $payStmt = $db->prepare("
                SELECT COUNT(*) as count, COALESCE(SUM(amount_fc), 0) as total
                FROM payment_slips
                WHERE payee_id = :payee_id AND business_id = :business_id
            ");
            $payStmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId]);
            $payRow = $payStmt->fetch(\PDO::FETCH_ASSOC);

            // Collections
            $colStmt = $db->prepare("
                SELECT COUNT(*) as count, COALESCE(SUM(amount_fc), 0) as total
                FROM collection_slips
                WHERE payee_id = :payee_id AND business_id = :business_id
            ");
            $colStmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId]);
            $colRow = $colStmt->fetch(\PDO::FETCH_ASSOC);

            $this->json(true, [
                'expenses' => [
                    'count' => (int) $expRow['count'],
                    'total' => (float) $expRow['total'],
                    'categories' => $categories
                ],
                'salaries' => [
                    'count' => (int) ($salRow['count'] ?? 0),
                    'gross_total' => (float) ($salRow['gross_total'] ?? 0),
                    'net_total' => (float) ($salRow['net_total'] ?? 0)
                ],
                'payments' => [
                    'count' => (int) $payRow['count'],
                    'total' => (float) $payRow['total']
                ],
                'collections' => [
                    'count' => (int) $colRow['count'],
                    'total' => (float) $colRow['total']
                ]
            ]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch general insights');
        }
    }

    // GET /people/{payeeId}/customer-items
    public function customerItems(int $payeeId): void {
        try {
            $ctx = $this->auth();
            $db = Database::getConnection();
            $businessId = $ctx->business_id;

            // Verify payee belongs to current business
            $stmt = $db->prepare("SELECT id FROM payees WHERE id = :id AND business_id = :bid LIMIT 1");
            $stmt->execute(['id' => $payeeId, 'bid' => $businessId]);
            if (!$stmt->fetch()) {
                $this->json(false, null, 'Payee not found', 404);
                return;
            }

            // Get items assigned to this customer with stock levels and batch info
            $stmt = $db->prepare("
                SELECT i.id, i.name,
                       COALESCE(s.quantity, 0) AS stock_quantity,
                       (SELECT COUNT(*) FROM item_batches ib WHERE ib.item_id = i.id AND ib.business_id = :bid2 AND ib.status = 'ACTIVE') AS batch_count,
                       (SELECT MIN(ib2.expiry_date) FROM item_batches ib2 WHERE ib2.item_id = i.id AND ib2.business_id = :bid3 AND ib2.status = 'ACTIVE' AND ib2.expiry_date IS NOT NULL) AS nearest_expiry
                FROM items i
                LEFT JOIN stock_levels s ON s.item_id = i.id AND s.business_id = :bid4
                WHERE i.customer_id = :payee_id AND i.business_id = :bid1
                ORDER BY i.name
            ");
            $stmt->execute([
                'payee_id' => $payeeId,
                'bid1' => $businessId,
                'bid2' => $businessId,
                'bid3' => $businessId,
                'bid4' => $businessId,
            ]);
            $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $this->json(true, ['items' => $items]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch customer items');
        }
    }

    // GET /people/{payeeId}/insights/partner
    public function partner(int $payeeId): void {
        try {
            $ctx = $this->auth();
            $db = Database::getConnection();
            $businessId = $ctx->business_id;

            // Verify payee belongs to current business
            $stmt = $db->prepare("SELECT id FROM payees WHERE id = :id AND business_id = :bid LIMIT 1");
            $stmt->execute(['id' => $payeeId, 'bid' => $businessId]);
            if (!$stmt->fetch()) {
                $this->json(false, null, 'Payee not found', 404);
                return;
            }

            // Partner shares
            $shareStmt = $db->prepare("
                SELECT COALESCE(ownership_percentage, 0) AS ownership_pct,
                       COALESCE(invested_capital, 0) AS invested_capital
                FROM partner_shares
                WHERE partner_id = :payee_id AND business_id = :business_id
                LIMIT 1
            ");
            $shareStmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId]);
            $shares = $shareStmt->fetch(\PDO::FETCH_ASSOC) ?: ['ownership_pct' => 0, 'invested_capital' => 0];

            // Equity movements
            $equityStmt = $db->prepare("
                SELECT
                    COALESCE(SUM(CASE WHEN type = 'CONTRIBUTION' THEN amount ELSE 0 END), 0) AS contributions,
                    COALESCE(SUM(CASE WHEN type = 'WITHDRAWAL' THEN amount ELSE 0 END), 0) AS withdrawals
                FROM partner_equity
                WHERE partner_id = :payee_id AND business_id = :business_id
            ");
            $equityStmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId]);
            $equityRow = $equityStmt->fetch(\PDO::FETCH_ASSOC);
            $contributions = (float)($equityRow['contributions'] ?? 0);
            $withdrawals = (float)($equityRow['withdrawals'] ?? 0);

            // Profit distributions
            $distStmt = $db->prepare("
                SELECT COUNT(*) AS count, COALESCE(SUM(amount), 0) AS total
                FROM profit_distributions
                WHERE partner_id = :payee_id AND business_id = :business_id
            ");
            $distStmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId]);
            $distRow = $distStmt->fetch(\PDO::FETCH_ASSOC);

            // Payments
            $payStmt = $db->prepare("
                SELECT COUNT(*) AS count, COALESCE(SUM(amount_fc), 0) AS total
                FROM payment_slips WHERE payee_id = :payee_id AND business_id = :business_id
            ");
            $payStmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId]);
            $payRow = $payStmt->fetch(\PDO::FETCH_ASSOC);

            // Collections
            $colStmt = $db->prepare("
                SELECT COUNT(*) AS count, COALESCE(SUM(amount_fc), 0) AS total
                FROM collection_slips WHERE payee_id = :payee_id AND business_id = :business_id
            ");
            $colStmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId]);
            $colRow = $colStmt->fetch(\PDO::FETCH_ASSOC);

            $this->json(true, [
                'shares' => [
                    'ownership_pct'   => (float) $shares['ownership_pct'],
                    'invested_capital' => (float) $shares['invested_capital'],
                ],
                'equity' => [
                    'contributions' => $contributions,
                    'withdrawals'   => $withdrawals,
                    'net'           => $contributions - $withdrawals,
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
            ]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch partner insights');
        }
    }
}
