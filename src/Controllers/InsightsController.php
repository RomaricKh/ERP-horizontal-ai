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
            $this->serverError('Failed to fetch supplier insights: ' . $e->getMessage());
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
            // Include both item-based and service-based lines
            $stmt = $db->prepare("
                SELECT COALESCE(i.name, s.name, 'Unspecified') AS item_name,
                       SUM(sid.quantity) AS total_quantity,
                       AVG(sid.unit_price_fc) AS avg_unit_price,
                       MAX(si.invoice_date) AS last_date,
                       COUNT(DISTINCT si.id) AS document_count
                FROM si_details sid
                JOIN sales_invoices si ON si.id = sid.sales_invoice_id
                LEFT JOIN items i ON i.id = sid.item_id
                LEFT JOIN services s ON s.id = sid.service_id
                WHERE si.customer_id = :payee_id
                  AND si.business_id = :business_id
                  AND si.status != 'CANCELLED'
                GROUP BY COALESCE(sid.item_id, sid.service_id + 1000000), COALESCE(i.name, s.name, 'Unspecified')
                ORDER BY total_quantity DESC
            ");
            $stmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId]);
            $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // If no detail rows exist, show invoice-level summary
            if (empty($items)) {
                $fallbackStmt = $db->prepare("
                    SELECT CONCAT('Invoice #', si.invoice_number) AS item_name,
                           1 AS total_quantity,
                           si.total_amount_fc AS avg_unit_price,
                           si.invoice_date AS last_date,
                           1 AS document_count
                    FROM sales_invoices si
                    WHERE si.customer_id = :payee_id
                      AND si.business_id = :business_id
                      AND si.status != 'CANCELLED'
                    ORDER BY si.invoice_date DESC
                ");
                $fallbackStmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId]);
                $items = $fallbackStmt->fetchAll(\PDO::FETCH_ASSOC);
            }

            // Get last price for each item (skip for fallback invoice-level rows)
            foreach ($items as &$item) {
                if (isset($item['last_price'])) continue; // fallback rows don't need this
                $lpStmt = $db->prepare("
                    SELECT sid.unit_price_fc
                    FROM si_details sid
                    JOIN sales_invoices si ON si.id = sid.sales_invoice_id
                    WHERE si.customer_id = :payee_id
                      AND si.business_id = :business_id
                      AND si.status != 'CANCELLED'
                      AND (
                        (sid.item_id IS NOT NULL AND sid.item_id IN (SELECT id FROM items WHERE name = :item_name))
                        OR (sid.service_id IS NOT NULL AND sid.service_id IN (SELECT id FROM services WHERE name = :item_name2))
                      )
                    ORDER BY si.invoice_date DESC
                    LIMIT 1
                ");
                $lpStmt->execute([
                    'payee_id' => $payeeId,
                    'business_id' => $businessId,
                    'item_name' => $item['item_name'],
                    'item_name2' => $item['item_name']
                ]);
                $lastPrice = $lpStmt->fetchColumn();
                $item['last_price'] = $lastPrice !== false ? $lastPrice : $item['avg_unit_price'];
            }

            $this->json(true, ['items' => $items]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch customer insights: ' . $e->getMessage());
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

            // Verify payee belongs to current business and get its people_id
            $stmt = $db->prepare("SELECT id, people_id FROM payees WHERE id = :id AND business_id = :bid LIMIT 1");
            $stmt->execute(['id' => $payeeId, 'bid' => $businessId]);
            $payee = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$payee) {
                $this->json(false, null, 'Payee not found', 404);
                return;
            }

            $peopleId = $payee['people_id'];

            // Get items assigned to this customer (items.customer_id = people.id) with stock and batch info
            $stmt = $db->prepare("
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
                SELECT COALESCE(share_percent, 0) AS ownership_pct,
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
                    COALESCE(SUM(CASE WHEN equity_type = 'CONTRIBUTION' THEN amount ELSE 0 END), 0) AS contributions,
                    COALESCE(SUM(CASE WHEN equity_type = 'WITHDRAWAL' THEN amount ELSE 0 END), 0) AS withdrawals
                FROM partner_equity
                WHERE partner_id = :payee_id AND business_id = :business_id
            ");
            $equityStmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId]);
            $equityRow = $equityStmt->fetch(\PDO::FETCH_ASSOC);
            $contributions = (float)($equityRow['contributions'] ?? 0);
            $withdrawals = (float)($equityRow['withdrawals'] ?? 0);

            // Profit distributions
            $distStmt = $db->prepare("
                SELECT COUNT(*) AS count, COALESCE(SUM(amount_bc), 0) AS total
                FROM profit_distributions
                WHERE partner_id = :payee_id
            ");
            $distStmt->execute(['payee_id' => $payeeId]);
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
