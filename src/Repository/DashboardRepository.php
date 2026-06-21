<?php
namespace App\Repository;

use PDO;

class DashboardRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Get all businesses for a tenant with their type name.
     */
    public function getBusinessesForTenant(int $tenantId): array {
        $stmt = $this->db->prepare("
            SELECT b.id, b.name, bt.name AS type
            FROM businesses b
            LEFT JOIN business_types bt ON bt.id = b.business_type_id
            WHERE b.tenant_id = :tenant_id
            ORDER BY b.name ASC
        ");
        $stmt->execute(['tenant_id' => $tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get the count of users assigned to a business.
     */
    public function getUserCountForBusiness(int $businessId): int {
        $stmt = $this->db->prepare("
            SELECT COUNT(DISTINCT user_id)
            FROM user_roles
            WHERE business_id = :business_id
        ");
        $stmt->execute(['business_id' => $businessId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Get outstanding receivables for a business in a given month/year.
     * Outstanding = invoices that are not fully paid and not cancelled.
     */
    public function getMonthlyReceivables(int $businessId, int $year, int $month): float {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(total_amount_bc), 0)
            FROM sales_invoices
            WHERE business_id = :business
              AND YEAR(invoice_date)  = :year
              AND MONTH(invoice_date) = :month
              AND status NOT IN ('PAID', 'CANCELLED')
        ");
        $stmt->execute([
            'business' => $businessId,
            'year'     => $year,
            'month'    => $month,
        ]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Get outstanding payables for a business in a given month/year.
     * Outstanding = purchase orders that are not fully paid and not cancelled.
     */
    public function getMonthlyPayables(int $businessId, int $year, int $month): float {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(total_amount_bc), 0)
            FROM purchase_orders
            WHERE business_id = :business
              AND YEAR(po_date)  = :year
              AND MONTH(po_date) = :month
              AND status NOT IN ('CLOSED', 'CANCELLED')
        ");
        $stmt->execute([
            'business' => $businessId,
            'year'     => $year,
            'month'    => $month,
        ]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Return the total revenue for a given month/year.
     * Sums total_amount_bc from sales_invoices where status = 'PAID'.
     * Returns 0 (not null) when no paid invoices exist.
     */
    public function getMonthlyRevenue(int $businessId, int $year, int $month): float {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(total_amount_bc), 0)
            FROM sales_invoices
            WHERE business_id = :business
              AND status      = 'PAID'
              AND YEAR(invoice_date)  = :year
              AND MONTH(invoice_date) = :month
        ");
        $stmt->execute([
            'business' => $businessId,
            'year'     => $year,
            'month'    => $month,
        ]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Return the total expenses for a given month/year.
     * Sums amount_bc from expenses.
     * Returns 0 (not null) when no expenses exist.
     */
    public function getMonthlyExpenses(int $businessId, int $year, int $month): float {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount_bc), 0)
            FROM expenses
            WHERE business_id = :business
              AND YEAR(expense_date)  = :year
              AND MONTH(expense_date) = :month
        ");
        $stmt->execute([
            'business' => $businessId,
            'year'     => $year,
            'month'    => $month,
        ]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Return all business accounts with name, currency, and balance.
     */
    public function getAccountBalances(int $businessId): array {
        $stmt = $this->db->prepare("
            SELECT name, currency, balance
            FROM business_accounts
            WHERE business_id = :business
            ORDER BY name
        ");
        $stmt->execute(['business' => $businessId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return the most recent transactions across expenses, payment slips,
     * and collection slips, ordered by created_at DESC.
     *
     * Each row has: type, name (payee/person), amount (amount_bc), date, created_at
     *
     * - expenses       → type='Expense',    date=expense_date
     * - payment_slips  → type='Payment',    date=payment_date
     * - collection_slips → type='Collection', date=slip_date
     *
     * Payee name resolved via payees → people join where available.
     */
    public function getRecentTransactions(int $businessId, int $limit = 10): array {
        $stmt = $this->db->prepare("
            SELECT *
            FROM (
                SELECT
                    'Expense'       AS type,
                    p.name          AS name,
                    e.amount_bc     AS amount,
                    e.expense_date  AS date,
                    e.created_at
                FROM expenses e
                LEFT JOIN payees py
                    ON py.id = e.payee_id
                LEFT JOIN people p
                    ON p.id = py.people_id
                WHERE e.business_id = :business1
                  AND e.expense_date <= CURRENT_DATE()

                UNION ALL

                SELECT
                    'Payment'        AS type,
                    p.name           AS name,
                    ps.amount_bc     AS amount,
                    ps.payment_date  AS date,
                    ps.created_at
                FROM payment_slips ps
                LEFT JOIN payees py
                    ON py.id = ps.payee_id
                LEFT JOIN people p
                    ON p.id = py.people_id
                WHERE ps.business_id = :business2
                  AND ps.payment_date <= CURRENT_DATE()

                UNION ALL

                SELECT
                    'Collection'    AS type,
                    p.name          AS name,
                    cs.amount_bc    AS amount,
                    cs.slip_date    AS date,
                    cs.created_at
                FROM collection_slips cs
                LEFT JOIN payees py
                    ON py.id = cs.payee_id
                LEFT JOIN people p
                    ON p.id = py.people_id
                WHERE cs.business_id = :business3
                  AND cs.slip_date <= CURRENT_DATE()
            ) AS transactions
            ORDER BY created_at DESC
            LIMIT :lim
        ");
        $stmt->bindValue(':business1', $businessId, PDO::PARAM_INT);
        $stmt->bindValue(':business2', $businessId, PDO::PARAM_INT);
        $stmt->bindValue(':business3', $businessId, PDO::PARAM_INT);
        $stmt->bindValue(':lim',       $limit,      PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return the total service revenue for a given month/year.
     * Sums total_price_bc from si_details where service_id IS NOT NULL,
     * joined to sales_invoices with status DELIVERED and invoice_date in the given month.
     * Returns 0 when no matching lines exist.
     */
    public function getMonthlyServiceRevenue(int $businessId, int $year, int $month): float {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(sd.total_price_bc), 0)
            FROM si_details sd
            INNER JOIN sales_invoices si
                ON si.id = sd.sales_invoice_id
            WHERE si.business_id = :business
              AND si.status = 'DELIVERED'
              AND YEAR(si.invoice_date)  = :year
              AND MONTH(si.invoice_date) = :month
              AND sd.service_id IS NOT NULL
        ");
        $stmt->execute([
            'business' => $businessId,
            'year'     => $year,
            'month'    => $month,
        ]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Return the count of distinct services invoiced in a given month/year.
     * Counts distinct service_id from si_details joined to sales_invoices
     * with status DELIVERED and invoice_date in the given month.
     * Returns 0 when no matching lines exist.
     */
    public function getMonthlyServiceCount(int $businessId, int $year, int $month): int {
        $stmt = $this->db->prepare("
            SELECT COUNT(DISTINCT sd.service_id)
            FROM si_details sd
            INNER JOIN sales_invoices si
                ON si.id = sd.sales_invoice_id
            WHERE si.business_id = :business
              AND si.status = 'DELIVERED'
              AND YEAR(si.invoice_date)  = :year
              AND MONTH(si.invoice_date) = :month
              AND sd.service_id IS NOT NULL
        ");
        $stmt->execute([
            'business' => $businessId,
            'year'     => $year,
            'month'    => $month,
        ]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Return items whose stock quantity is at or below the item's min_stock threshold.
     * Joins items with the stock table scoped to the business.
     * Items with no stock entry are treated as quantity = 0.
     * Items with NULL min_stock are treated as threshold 0.
     */
    public function getLowStockItems(int $businessId): array {
        $stmt = $this->db->prepare("
            SELECT i.id, i.name, i.sku, i.unit,
                   COALESCE(s.quantity, 0) AS quantity
            FROM items i
            LEFT JOIN stock s
                ON s.item_id = i.id AND s.business_id = :business
            WHERE (i.business_id = :business2 OR i.business_id IS NULL)
              AND i.is_active = 1
              AND COALESCE(s.quantity, 0) <= COALESCE(i.min_stock, 0)
            ORDER BY COALESCE(s.quantity, 0) ASC, i.name ASC
        ");
        $stmt->bindValue(':business', $businessId, PDO::PARAM_INT);
        $stmt->bindValue(':business2', $businessId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get monthly revenue and expenses for the given year.
     * Returns an array of 12 elements (Jan-Dec).
     */
    public function getAnnualEarningsOverview(int $businessId, int $year): array {
        $stmtRev = $this->db->prepare("
            SELECT MONTH(invoice_date) as m, SUM(total_amount_bc) as total
            FROM sales_invoices
            WHERE business_id = :business
              AND status = 'PAID'
              AND YEAR(invoice_date) = :year
              AND invoice_date <= CURRENT_DATE()
            GROUP BY MONTH(invoice_date)
        ");
        $stmtRev->execute(['business' => $businessId, 'year' => $year]);
        $revRows = $stmtRev->fetchAll(PDO::FETCH_KEY_PAIR);

        $stmtExp = $this->db->prepare("
            SELECT MONTH(expense_date) as m, SUM(amount_bc) as total
            FROM expenses
            WHERE business_id = :business
              AND YEAR(expense_date) = :year
              AND expense_date <= CURRENT_DATE()
            GROUP BY MONTH(expense_date)
        ");
        $stmtExp->execute(['business' => $businessId, 'year' => $year]);
        $expRows = $stmtExp->fetchAll(PDO::FETCH_KEY_PAIR);

        $result = [];
        for ($i = 1; $i <= 12; $i++) {
            $result[] = [
                'month' => $i,
                'revenue' => (float)($revRows[$i] ?? 0),
                'expenses' => (float)($expRows[$i] ?? 0),
            ];
        }
        return $result;
    }

    /**
     * Get expense breakdown by category for the given month and year.
     */
    public function getExpenseBreakdown(int $businessId, int $year, int $month): array {
        $stmt = $this->db->prepare("
            SELECT COALESCE(ec.name, 'Uncategorized') as category, SUM(e.amount_bc) as total
            FROM expenses e
            LEFT JOIN expense_categories ec ON ec.id = e.expense_category_id
            WHERE e.business_id = :business
              AND YEAR(e.expense_date) = :year
              AND MONTH(e.expense_date) = :month
              AND e.expense_date <= CURRENT_DATE()
            GROUP BY ec.id, ec.name
            ORDER BY total DESC
            LIMIT 5
        ");
        $stmt->execute(['business' => $businessId, 'year' => $year, 'month' => $month]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
