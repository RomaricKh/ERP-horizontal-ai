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

            // Build running balance (signed: debit + credit since signs are baked in)
            $runningBalance = $openingBalance;
            foreach ($transactions as &$txn) {
                $debit = (float)($txn['debit_amount'] ?? 0);
                $credit = (float)($txn['credit_amount'] ?? 0);
                $runningBalance += $debit + $credit;
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

    /**
     * GET /people/{payeeId}/statement/export?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD
     * Export statement as CSV download.
     */
    public function export(int $payeeId): void {
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

            // Verify payee
            $stmt = $db->prepare("SELECT id, people_id FROM payees WHERE id = :id AND business_id = :bid LIMIT 1");
            $stmt->execute(['id' => $payeeId, 'bid' => $businessId]);
            $payee = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$payee) {
                $this->json(false, null, 'Payee not found', 404);
                return;
            }

            $nameStmt = $db->prepare("SELECT name FROM people WHERE id = :id LIMIT 1");
            $nameStmt->execute(['id' => $payee['people_id']]);
            $payeeName = $nameStmt->fetchColumn() ?: 'Unknown';

            $transactions = $this->getTransactions($db, $payeeId, $businessId, $startDate, $endDate);
            $openingBalance = $this->calculateOpeningBalance($db, $payeeId, $businessId, $startDate);

            // Build running balance (signed: debit + credit since signs are baked in)
            $runningBalance = $openingBalance;
            foreach ($transactions as &$txn) {
                $debit = (float)($txn['debit_amount'] ?? 0);
                $credit = (float)($txn['credit_amount'] ?? 0);
                $runningBalance += $debit + $credit;
                $txn['running_balance'] = $runningBalance;
            }

            // Generate CSV
            $filename = "statement_{$payeeName}_{$startDate}_{$endDate}.csv";
            $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-cache, no-store, must-revalidate');

            $output = fopen('php://output', 'w');

            // Header info
            fputcsv($output, ['Statement of Account']);
            fputcsv($output, ['Payee', $payeeName]);
            fputcsv($output, ['Period', $startDate . ' to ' . $endDate]);
            fputcsv($output, ['Opening Balance', number_format($openingBalance, 2, '.', '')]);
            fputcsv($output, []);

            // Column headers
            fputcsv($output, ['Date', 'Type', 'Reference', 'Description', 'Debit', 'Credit', 'Running Balance', 'Status']);

            // Data rows
            foreach ($transactions as $txn) {
                fputcsv($output, [
                    $txn['date'],
                    $txn['type'],
                    $txn['reference_number'] ?? '',
                    $txn['description'] ?? '',
                    number_format((float)($txn['debit_amount'] ?? 0), 2, '.', ''),
                    number_format((float)($txn['credit_amount'] ?? 0), 2, '.', ''),
                    number_format((float)($txn['running_balance'] ?? 0), 2, '.', ''),
                    $txn['status'] ?? '',
                ]);
            }

            // Footer
            fputcsv($output, []);
            fputcsv($output, ['Closing Balance', '', '', '', '', '', number_format($runningBalance, 2, '.', '')]);

            fclose($output);
            exit;

        } catch (\Throwable $e) {
            $this->serverError('Failed to export statement: ' . $e->getMessage());
        }
    }

    private function getTransactions(PDO $db, int $payeeId, int $businessId, string $startDate, string $endDate): array {
        $transactions = [];

        // Convention (signed balance):
        //   Debit column  = documents/obligations (negative = we owe them, positive = they owe us)
        //   Credit column = cash settlements (positive = we paid them, negative = they paid us)
        //   Running balance = SUM(debit) + SUM(credit) → positive = they owe us, negative = we owe them

        // Purchase Orders (we owe them → negative debit)
        $stmt = $db->prepare("
            SELECT po.po_date AS date, 'purchase_order' AS type,
                   po.po_number AS reference_number,
                   CONCAT('PO #', po.po_number) AS description,
                   (po.total_amount_fc * -1) AS debit_amount, 0 AS credit_amount,
                   po.status
            FROM purchase_orders po
            WHERE po.supplier_id = :payee_id AND po.business_id = :business_id
              AND po.po_date >= :start_date AND po.po_date <= :end_date
              AND po.status != 'CANCELLED'
        ");
        $stmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId, 'start_date' => $startDate, 'end_date' => $endDate]);
        $transactions = array_merge($transactions, $stmt->fetchAll(PDO::FETCH_ASSOC));

        // Sales Invoices (they owe us → positive debit)
        $stmt = $db->prepare("
            SELECT si.invoice_date AS date, 'sales_invoice' AS type,
                   si.invoice_number AS reference_number,
                   CONCAT('Invoice #', si.invoice_number) AS description,
                   si.total_amount_fc AS debit_amount, 0 AS credit_amount,
                   si.status
            FROM sales_invoices si
            WHERE si.customer_id = :payee_id AND si.business_id = :business_id
              AND si.invoice_date >= :start_date AND si.invoice_date <= :end_date
              AND si.status != 'CANCELLED'
        ");
        $stmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId, 'start_date' => $startDate, 'end_date' => $endDate]);
        $transactions = array_merge($transactions, $stmt->fetchAll(PDO::FETCH_ASSOC));

        // Expenses (we owe them → negative debit)
        $stmt = $db->prepare("
            SELECT e.expense_date AS date, 'expense' AS type,
                   e.reference_number, e.notes AS description,
                   (e.amount_fc * -1) AS debit_amount, 0 AS credit_amount,
                   'RECORDED' AS status
            FROM expenses e
            WHERE e.payee_id = :payee_id AND e.business_id = :business_id
              AND e.expense_date >= :start_date AND e.expense_date <= :end_date
        ");
        $stmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId, 'start_date' => $startDate, 'end_date' => $endDate]);
        $transactions = array_merge($transactions, $stmt->fetchAll(PDO::FETCH_ASSOC));

        // Salaries (we owe them → negative debit)
        $stmt = $db->prepare("
            SELECT s.period_start AS date, 'salary' AS type,
                   CONCAT('Salary ', s.period_start, ' - ', s.period_end) AS reference_number,
                   'Salary payment' AS description,
                   (s.net_amount_fc * -1) AS debit_amount, 0 AS credit_amount,
                   'RECORDED' AS status
            FROM salaries s
            WHERE s.payee_id = :payee_id AND s.business_id = :business_id
              AND s.period_start >= :start_date AND s.period_end <= :end_date
        ");
        $stmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId, 'start_date' => $startDate, 'end_date' => $endDate]);
        $transactions = array_merge($transactions, $stmt->fetchAll(PDO::FETCH_ASSOC));

        // Payment Slips (we paid them → positive credit)
        $stmt = $db->prepare("
            SELECT ps.payment_date AS date, 'payment_slip' AS type,
                   ps.payment_number AS reference_number,
                   ps.notes AS description,
                   0 AS debit_amount, ps.amount_fc AS credit_amount,
                   'PAID' AS status
            FROM payment_slips ps
            WHERE ps.payee_id = :payee_id AND ps.business_id = :business_id
              AND ps.payment_date >= :start_date AND ps.payment_date <= :end_date
        ");
        $stmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId, 'start_date' => $startDate, 'end_date' => $endDate]);
        $transactions = array_merge($transactions, $stmt->fetchAll(PDO::FETCH_ASSOC));

        // Collection Slips (they paid us → negative credit)
        $stmt = $db->prepare("
            SELECT cs.slip_date AS date, 'collection_slip' AS type,
                   cs.slip_number AS reference_number,
                   cs.notes AS description,
                   0 AS debit_amount, (cs.amount_fc * -1) AS credit_amount,
                   'COLLECTED' AS status
            FROM collection_slips cs
            WHERE cs.payee_id = :payee_id AND cs.business_id = :business_id
              AND cs.slip_date >= :start_date AND cs.slip_date <= :end_date
        ");
        $stmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId, 'start_date' => $startDate, 'end_date' => $endDate]);
        $transactions = array_merge($transactions, $stmt->fetchAll(PDO::FETCH_ASSOC));

        // Debit Notes — ISSUED (we returned items to supplier → reduces what we owe → positive debit)
        $stmt = $db->prepare("
            SELECT dn.debit_note_date AS date, 'debit_note' AS type,
                   dn.debit_note_number AS reference_number,
                   CONCAT('Debit Note #', dn.debit_note_number, ' (return on PO #', po.po_number, ')') AS description,
                   dn.total_amount_fc AS debit_amount, 0 AS credit_amount,
                   dn.status
            FROM debit_notes dn
            JOIN purchase_orders po ON po.id = dn.purchase_order_id
            WHERE po.supplier_id = :payee_id AND dn.business_id = :business_id
              AND dn.debit_note_date >= :start_date AND dn.debit_note_date <= :end_date
              AND dn.status = 'ISSUED'
        ");
        $stmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId, 'start_date' => $startDate, 'end_date' => $endDate]);
        $transactions = array_merge($transactions, $stmt->fetchAll(PDO::FETCH_ASSOC));

        // Credit Notes — ISSUED (customer returned items → reduces what they owe us → negative debit)
        $stmt = $db->prepare("
            SELECT cn.credit_note_date AS date, 'credit_note' AS type,
                   cn.credit_note_number AS reference_number,
                   CONCAT('Credit Note #', cn.credit_note_number, ' (return on Inv #', si.invoice_number, ')') AS description,
                   (cn.total_amount_fc * -1) AS debit_amount, 0 AS credit_amount,
                   cn.status
            FROM credit_notes cn
            JOIN sales_invoices si ON si.id = cn.sales_invoice_id
            WHERE si.customer_id = :payee_id AND cn.business_id = :business_id
              AND cn.credit_note_date >= :start_date AND cn.credit_note_date <= :end_date
              AND cn.status = 'ISSUED'
        ");
        $stmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId, 'start_date' => $startDate, 'end_date' => $endDate]);
        $transactions = array_merge($transactions, $stmt->fetchAll(PDO::FETCH_ASSOC));

        // Sort by date
        usort($transactions, fn($a, $b) => strcmp($a['date'], $b['date']));

        return $transactions;
    }

    private function calculateOpeningBalance(PDO $db, int $payeeId, int $businessId, string $startDate): float {
        // Same signed convention: positive = they owe us, negative = we owe them
        $balance = 0.0;

        // Sales Invoices before start (they owe us → positive)
        $stmt = $db->prepare("SELECT COALESCE(SUM(total_amount_fc), 0) FROM sales_invoices WHERE customer_id = :pid AND business_id = :bid AND invoice_date < :d AND status != 'CANCELLED'");
        $stmt->execute(['pid' => $payeeId, 'bid' => $businessId, 'd' => $startDate]);
        $balance += (float)$stmt->fetchColumn();

        // POs before start (we owe them → negative)
        $stmt = $db->prepare("SELECT COALESCE(SUM(total_amount_fc), 0) FROM purchase_orders WHERE supplier_id = :pid AND business_id = :bid AND po_date < :d AND status != 'CANCELLED'");
        $stmt->execute(['pid' => $payeeId, 'bid' => $businessId, 'd' => $startDate]);
        $balance -= (float)$stmt->fetchColumn();

        // Expenses before start (we owe them → negative)
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount_fc), 0) FROM expenses WHERE payee_id = :pid AND business_id = :bid AND expense_date < :d");
        $stmt->execute(['pid' => $payeeId, 'bid' => $businessId, 'd' => $startDate]);
        $balance -= (float)$stmt->fetchColumn();

        // Salaries before start (we owe them → negative)
        $stmt = $db->prepare("SELECT COALESCE(SUM(net_amount_fc), 0) FROM salaries WHERE payee_id = :pid AND business_id = :bid AND period_start < :d");
        $stmt->execute(['pid' => $payeeId, 'bid' => $businessId, 'd' => $startDate]);
        $balance -= (float)$stmt->fetchColumn();

        // Payments before start (we paid them → positive, reduces what we owe)
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount_fc), 0) FROM payment_slips WHERE payee_id = :pid AND business_id = :bid AND payment_date < :d");
        $stmt->execute(['pid' => $payeeId, 'bid' => $businessId, 'd' => $startDate]);
        $balance += (float)$stmt->fetchColumn();

        // Collections before start (they paid us → negative, reduces what they owe)
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount_fc), 0) FROM collection_slips WHERE payee_id = :pid AND business_id = :bid AND slip_date < :d");
        $stmt->execute(['pid' => $payeeId, 'bid' => $businessId, 'd' => $startDate]);
        $balance -= (float)$stmt->fetchColumn();

        // Debit Notes before start — ISSUED (we returned to supplier → positive, reduces what we owe)
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(dn.total_amount_fc), 0)
            FROM debit_notes dn
            JOIN purchase_orders po ON po.id = dn.purchase_order_id
            WHERE po.supplier_id = :pid AND dn.business_id = :bid
              AND dn.debit_note_date < :d AND dn.status = 'ISSUED'
        ");
        $stmt->execute(['pid' => $payeeId, 'bid' => $businessId, 'd' => $startDate]);
        $balance += (float)$stmt->fetchColumn();

        // Credit Notes before start — ISSUED (customer returned to us → negative, reduces what they owe)
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(cn.total_amount_fc), 0)
            FROM credit_notes cn
            JOIN sales_invoices si ON si.id = cn.sales_invoice_id
            WHERE si.customer_id = :pid AND cn.business_id = :bid
              AND cn.credit_note_date < :d AND cn.status = 'ISSUED'
        ");
        $stmt->execute(['pid' => $payeeId, 'bid' => $businessId, 'd' => $startDate]);
        $balance -= (float)$stmt->fetchColumn();

        return $balance;
    }
}
