<?php
namespace App\Controllers;

use App\Config\Database;
use App\Support\AuthContext;
use PDO;

class PayeeStatementController extends BaseController {

    /**
     * GET /people/{payeeId}/statement?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD
     */
    public function show(int $payeeId): void {
        try {
            $ctx = $this->auth();
            $db = Database::getConnection();
            $businessId = $ctx->business_id;

            $startDate = $_GET['start_date'] ?? null;
            $endDate   = $_GET['end_date']   ?? null;

            if (!$startDate || !$endDate) {
                $this->json(false, null, 'start_date and end_date are required', 422);
                return;
            }

            // Verify payee belongs to current business
            $stmt = $db->prepare("SELECT id, people_id, balance FROM payees WHERE id = :id AND business_id = :bid LIMIT 1");
            $stmt->execute(['id' => $payeeId, 'bid' => $businessId]);
            $payee = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$payee) {
                $this->json(false, null, 'Payee not found', 404);
                return;
            }

            // Get payee name
            $nameStmt = $db->prepare("SELECT name FROM people WHERE id = :id LIMIT 1");
            $nameStmt->execute(['id' => $payee['people_id']]);
            $payeeName = $nameStmt->fetchColumn() ?: 'Unknown';

            // Gather all transactions in date range
            $transactions = $this->getTransactions($db, $payeeId, $businessId, $startDate, $endDate);

            // Calculate opening balance (sum of all debits - credits before start_date)
            $openingBalance = $this->calculateOpeningBalance($db, $payeeId, $businessId, $startDate);

            // Build running balance
            $runningBalance = $openingBalance;
            foreach ($transactions as &$txn) {
                $debit = (float)($txn['debit_amount'] ?? 0);
                $credit = (float)($txn['credit_amount'] ?? 0);
                $runningBalance += $debit - $credit;
                $txn['running_balance'] = $runningBalance;
            }

            $closingBalance = $runningBalance;

            $this->json(true, [
                'payee_name'      => $payeeName,
                'opening_balance' => $openingBalance,
                'closing_balance' => $closingBalance,
                'transactions'    => $transactions,
            ]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to load statement: ' . $e->getMessage());
        }
    }

    private function getTransactions(PDO $db, int $payeeId, int $businessId, string $startDate, string $endDate): array {
        $transactions = [];

        // Purchase Orders (debit = increases what we owe supplier)
        $stmt = $db->prepare("
            SELECT po.po_date AS date, 'purchase_order' AS type,
                   po.po_number AS reference_number,
                   CONCAT('PO to ', p.name) AS description,
                   po.total_amount_fc AS debit_amount, 0 AS credit_amount,
                   po.status
            FROM purchase_orders po
            JOIN payees py ON py.id = po.supplier_id
            JOIN people p ON p.id = py.people_id
            WHERE po.supplier_id = :payee_id AND po.business_id = :business_id
              AND po.po_date >= :start_date AND po.po_date <= :end_date
              AND po.status != 'CANCELLED'
        ");
        $stmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId, 'start_date' => $startDate, 'end_date' => $endDate]);
        $transactions = array_merge($transactions, $stmt->fetchAll(PDO::FETCH_ASSOC));

        // Sales Invoices (debit = customer owes us)
        $stmt = $db->prepare("
            SELECT si.invoice_date AS date, 'sales_invoice' AS type,
                   si.invoice_number AS reference_number,
                   CONCAT('Invoice to ', p.name) AS description,
                   si.total_amount_fc AS debit_amount, 0 AS credit_amount,
                   si.status
            FROM sales_invoices si
            JOIN payees py ON py.id = si.customer_id
            JOIN people p ON p.id = py.people_id
            WHERE si.customer_id = :payee_id AND si.business_id = :business_id
              AND si.invoice_date >= :start_date AND si.invoice_date <= :end_date
              AND si.status != 'CANCELLED'
        ");
        $stmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId, 'start_date' => $startDate, 'end_date' => $endDate]);
        $transactions = array_merge($transactions, $stmt->fetchAll(PDO::FETCH_ASSOC));

        // Expenses (debit = we owe the payee)
        $stmt = $db->prepare("
            SELECT e.expense_date AS date, 'expense' AS type,
                   e.reference_number, e.notes AS description,
                   e.amount_fc AS debit_amount, 0 AS credit_amount,
                   'RECORDED' AS status
            FROM expenses e
            WHERE e.payee_id = :payee_id AND e.business_id = :business_id
              AND e.expense_date >= :start_date AND e.expense_date <= :end_date
        ");
        $stmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId, 'start_date' => $startDate, 'end_date' => $endDate]);
        $transactions = array_merge($transactions, $stmt->fetchAll(PDO::FETCH_ASSOC));

        // Salaries (debit = we owe the employee)
        $stmt = $db->prepare("
            SELECT s.period_start AS date, 'salary' AS type,
                   CONCAT('Salary ', s.period_start, ' - ', s.period_end) AS reference_number,
                   'Salary payment' AS description,
                   s.net_amount_fc AS debit_amount, 0 AS credit_amount,
                   'RECORDED' AS status
            FROM salaries s
            WHERE s.payee_id = :payee_id AND s.business_id = :business_id
              AND s.period_start >= :start_date AND s.period_end <= :end_date
        ");
        $stmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId, 'start_date' => $startDate, 'end_date' => $endDate]);
        $transactions = array_merge($transactions, $stmt->fetchAll(PDO::FETCH_ASSOC));

        // Payment Slips (credit = reduces what we owe)
        $stmt = $db->prepare("
            SELECT ps.payment_date AS date, 'payment_slip' AS type,
                   ps.slip_number AS reference_number,
                   ps.notes AS description,
                   0 AS debit_amount, ps.amount_fc AS credit_amount,
                   'PAID' AS status
            FROM payment_slips ps
            WHERE ps.payee_id = :payee_id AND ps.business_id = :business_id
              AND ps.payment_date >= :start_date AND ps.payment_date <= :end_date
        ");
        $stmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId, 'start_date' => $startDate, 'end_date' => $endDate]);
        $transactions = array_merge($transactions, $stmt->fetchAll(PDO::FETCH_ASSOC));

        // Collection Slips (credit = customer paid us)
        $stmt = $db->prepare("
            SELECT cs.slip_date AS date, 'collection_slip' AS type,
                   cs.slip_number AS reference_number,
                   cs.notes AS description,
                   0 AS debit_amount, cs.amount_fc AS credit_amount,
                   'COLLECTED' AS status
            FROM collection_slips cs
            WHERE cs.payee_id = :payee_id AND cs.business_id = :business_id
              AND cs.slip_date >= :start_date AND cs.slip_date <= :end_date
        ");
        $stmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId, 'start_date' => $startDate, 'end_date' => $endDate]);
        $transactions = array_merge($transactions, $stmt->fetchAll(PDO::FETCH_ASSOC));

        // Sort by date
        usort($transactions, fn($a, $b) => strcmp($a['date'], $b['date']));

        return $transactions;
    }

    private function calculateOpeningBalance(PDO $db, int $payeeId, int $businessId, string $startDate): float {
        $balance = 0.0;

        // POs before start
        $stmt = $db->prepare("SELECT COALESCE(SUM(total_amount_fc), 0) FROM purchase_orders WHERE supplier_id = :pid AND business_id = :bid AND po_date < :d AND status != 'CANCELLED'");
        $stmt->execute(['pid' => $payeeId, 'bid' => $businessId, 'd' => $startDate]);
        $balance += (float)$stmt->fetchColumn();

        // Invoices before start
        $stmt = $db->prepare("SELECT COALESCE(SUM(total_amount_fc), 0) FROM sales_invoices WHERE customer_id = :pid AND business_id = :bid AND invoice_date < :d AND status != 'CANCELLED'");
        $stmt->execute(['pid' => $payeeId, 'bid' => $businessId, 'd' => $startDate]);
        $balance += (float)$stmt->fetchColumn();

        // Expenses before start
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount_fc), 0) FROM expenses WHERE payee_id = :pid AND business_id = :bid AND expense_date < :d");
        $stmt->execute(['pid' => $payeeId, 'bid' => $businessId, 'd' => $startDate]);
        $balance += (float)$stmt->fetchColumn();

        // Salaries before start
        $stmt = $db->prepare("SELECT COALESCE(SUM(net_amount_fc), 0) FROM salaries WHERE payee_id = :pid AND business_id = :bid AND period_start < :d");
        $stmt->execute(['pid' => $payeeId, 'bid' => $businessId, 'd' => $startDate]);
        $balance += (float)$stmt->fetchColumn();

        // Payments before start (reduce balance)
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount_fc), 0) FROM payment_slips WHERE payee_id = :pid AND business_id = :bid AND payment_date < :d");
        $stmt->execute(['pid' => $payeeId, 'bid' => $businessId, 'd' => $startDate]);
        $balance -= (float)$stmt->fetchColumn();

        // Collections before start (reduce balance)
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount_fc), 0) FROM collection_slips WHERE payee_id = :pid AND business_id = :bid AND slip_date < :d");
        $stmt->execute(['pid' => $payeeId, 'bid' => $businessId, 'd' => $startDate]);
        $balance -= (float)$stmt->fetchColumn();

        return $balance;
    }
}
