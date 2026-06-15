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
}
