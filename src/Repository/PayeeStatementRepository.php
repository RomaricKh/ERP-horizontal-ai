<?php
namespace App\Repository;

use PDO;

class PayeeStatementRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Fetch all transactions for a payee within a date range.
     *
     * Returns rows from: purchase_orders, sales_invoices, expenses, salaries,
     * payment_slips, collection_slips, debit_notes, credit_notes, profit_distributions.
     * Sorted by date ascending.
     *
     * Convention (signed balance):
     *   Debit column  = documents/obligations (negative = we owe them, positive = they owe us)
     *   Credit column = cash settlements (positive = we paid them, negative = they paid us)
     *   Running balance = SUM(debit) + SUM(credit)
     */
    public function getTransactions(int $payeeId, int $businessId, string $startDate, string $endDate): array {
        $transactions = [];

        // Purchase Orders (we owe them → negative debit)
        $stmt = $this->db->prepare("
            SELECT po.po_date AS date, 'purchase_order' AS type,
                   po.po_number AS reference_number,
                   CONCAT('PO #', po.po_number) AS description,
                   (po.total_amount_bc * -1) AS debit_amount, 0 AS credit_amount,
                   po.total_amount_fc AS original_amount, po.currency,
                   po.status
            FROM purchase_orders po
            WHERE po.supplier_id = :payee_id AND po.business_id = :business_id
              AND po.po_date >= :start_date AND po.po_date <= :end_date
              AND po.status != 'CANCELLED'
        ");
        $stmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId, 'start_date' => $startDate, 'end_date' => $endDate]);
        $transactions = array_merge($transactions, $stmt->fetchAll(PDO::FETCH_ASSOC));

        // Sales Invoices (they owe us → positive debit)
        $stmt = $this->db->prepare("
            SELECT si.invoice_date AS date, 'sales_invoice' AS type,
                   si.invoice_number AS reference_number,
                   CONCAT('Invoice #', si.invoice_number) AS description,
                   si.total_amount_bc AS debit_amount, 0 AS credit_amount,
                   si.total_amount_fc AS original_amount, si.currency,
                   si.status
            FROM sales_invoices si
            WHERE si.customer_id = :payee_id AND si.business_id = :business_id
              AND si.invoice_date >= :start_date AND si.invoice_date <= :end_date
              AND si.status != 'CANCELLED'
        ");
        $stmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId, 'start_date' => $startDate, 'end_date' => $endDate]);
        $transactions = array_merge($transactions, $stmt->fetchAll(PDO::FETCH_ASSOC));

        // Expenses (we owe them → negative debit)
        $stmt = $this->db->prepare("
            SELECT e.expense_date AS date, 'expense' AS type,
                   e.reference_number, e.notes AS description,
                   (e.amount_bc * -1) AS debit_amount, 0 AS credit_amount,
                   e.amount_fc AS original_amount, e.currency,
                   'RECORDED' AS status
            FROM expenses e
            WHERE e.payee_id = :payee_id AND e.business_id = :business_id
              AND e.expense_date >= :start_date AND e.expense_date <= :end_date
        ");
        $stmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId, 'start_date' => $startDate, 'end_date' => $endDate]);
        $transactions = array_merge($transactions, $stmt->fetchAll(PDO::FETCH_ASSOC));

        // Salaries (we owe them → negative debit)
        $stmt = $this->db->prepare("
            SELECT s.period_start AS date, 'salary' AS type,
                   CONCAT('Salary ', s.period_start, ' - ', s.period_end) AS reference_number,
                   'Salary payment' AS description,
                   (s.net_amount_bc * -1) AS debit_amount, 0 AS credit_amount,
                   s.net_amount_fc AS original_amount, '' AS currency,
                   'RECORDED' AS status
            FROM salaries s
            WHERE s.payee_id = :payee_id AND s.business_id = :business_id
              AND s.period_start >= :start_date AND s.period_end <= :end_date
        ");
        $stmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId, 'start_date' => $startDate, 'end_date' => $endDate]);
        $transactions = array_merge($transactions, $stmt->fetchAll(PDO::FETCH_ASSOC));

        // Payment Slips (we paid them → positive credit)
        $stmt = $this->db->prepare("
            SELECT ps.payment_date AS date, 'payment_slip' AS type,
                   ps.payment_number AS reference_number,
                   ps.notes AS description,
                   0 AS debit_amount, ps.total_bc AS credit_amount,
                   ps.total_bc AS original_amount, 'MIXED' AS currency,
                   'PAID' AS status
            FROM payment_slips ps
            WHERE ps.payee_id = :payee_id AND ps.business_id = :business_id
              AND ps.payment_date >= :start_date AND ps.payment_date <= :end_date
        ");
        $stmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId, 'start_date' => $startDate, 'end_date' => $endDate]);
        $transactions = array_merge($transactions, $stmt->fetchAll(PDO::FETCH_ASSOC));

        // Collection Slips (they paid us → negative credit)
        $stmt = $this->db->prepare("
            SELECT cs.slip_date AS date, 'collection_slip' AS type,
                   cs.slip_number AS reference_number,
                   cs.notes AS description,
                   0 AS debit_amount, (cs.total_bc * -1) AS credit_amount,
                   cs.total_bc AS original_amount, 'MIXED' AS currency,
                   'COLLECTED' AS status
            FROM collection_slips cs
            WHERE cs.payee_id = :payee_id AND cs.business_id = :business_id
              AND cs.slip_date >= :start_date AND cs.slip_date <= :end_date
        ");
        $stmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId, 'start_date' => $startDate, 'end_date' => $endDate]);
        $transactions = array_merge($transactions, $stmt->fetchAll(PDO::FETCH_ASSOC));

        // Debit Notes — ISSUED (we returned items to supplier → reduces what we owe → positive debit)
        $stmt = $this->db->prepare("
            SELECT dn.debit_note_date AS date, 'debit_note' AS type,
                   dn.debit_note_number AS reference_number,
                   CONCAT('Debit Note #', dn.debit_note_number, ' (return on PO #', po.po_number, ')') AS description,
                   dn.total_amount_bc AS debit_amount, 0 AS credit_amount,
                   dn.total_amount_fc AS original_amount, po.currency,
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
        $stmt = $this->db->prepare("
            SELECT cn.credit_note_date AS date, 'credit_note' AS type,
                   cn.credit_note_number AS reference_number,
                   CONCAT('Credit Note #', cn.credit_note_number, ' (return on Inv #', si.invoice_number, ')') AS description,
                   (cn.total_amount_bc * -1) AS debit_amount, 0 AS credit_amount,
                   cn.total_amount_fc AS original_amount, si.currency,
                   cn.status
            FROM credit_notes cn
            JOIN sales_invoices si ON si.id = cn.sales_invoice_id
            WHERE si.customer_id = :payee_id AND cn.business_id = :business_id
              AND cn.credit_note_date >= :start_date AND cn.credit_note_date <= :end_date
              AND cn.status = 'ISSUED'
        ");
        $stmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId, 'start_date' => $startDate, 'end_date' => $endDate]);
        $transactions = array_merge($transactions, $stmt->fetchAll(PDO::FETCH_ASSOC));

        // Profit Distributions (we owe them → negative debit)
        $stmt = $this->db->prepare("
            SELECT bp.period_end AS date, 'profit_distribution' AS type,
                   CONCAT('Profit ', bp.period_start, ' to ', bp.period_end) AS reference_number,
                   'Business Profit Distribution' AS description,
                   (pd.amount_bc * -1) AS debit_amount, 0 AS credit_amount,
                   pd.amount_bc AS original_amount, '' AS currency,
                   bp.status
            FROM profit_distributions pd
            JOIN business_profit bp ON bp.id = pd.business_profit_id
            WHERE pd.partner_id = :payee_id AND bp.business_id = :business_id
              AND bp.period_end >= :start_date AND bp.period_end <= :end_date
              AND bp.status = 'DISTRIBUTED'
        ");
        $stmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId, 'start_date' => $startDate, 'end_date' => $endDate]);
        $transactions = array_merge($transactions, $stmt->fetchAll(PDO::FETCH_ASSOC));

        // Sort by date ascending
        usort($transactions, fn($a, $b) => strcmp($a['date'], $b['date']));

        return $transactions;
    }

    /**
     * Calculate the opening balance for a payee before a given start date.
     *
     * Computes the signed sum of all pre-period transactions:
     *   positive = they owe us, negative = we owe them
     */
    public function calculateOpeningBalance(int $payeeId, int $businessId, string $startDate): float {
        $balance = 0.0;

        // Sales Invoices before start (they owe us → positive)
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(total_amount_bc), 0) FROM sales_invoices WHERE customer_id = :pid AND business_id = :bid AND invoice_date < :d AND status != 'CANCELLED'");
        $stmt->execute(['pid' => $payeeId, 'bid' => $businessId, 'd' => $startDate]);
        $balance += (float)$stmt->fetchColumn();

        // POs before start (we owe them → negative)
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(total_amount_bc), 0) FROM purchase_orders WHERE supplier_id = :pid AND business_id = :bid AND po_date < :d AND status != 'CANCELLED'");
        $stmt->execute(['pid' => $payeeId, 'bid' => $businessId, 'd' => $startDate]);
        $balance -= (float)$stmt->fetchColumn();

        // Expenses before start (we owe them → negative)
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(amount_bc), 0) FROM expenses WHERE payee_id = :pid AND business_id = :bid AND expense_date < :d");
        $stmt->execute(['pid' => $payeeId, 'bid' => $businessId, 'd' => $startDate]);
        $balance -= (float)$stmt->fetchColumn();

        // Salaries before start (we owe them → negative)
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(net_amount_bc), 0) FROM salaries WHERE payee_id = :pid AND business_id = :bid AND period_start < :d");
        $stmt->execute(['pid' => $payeeId, 'bid' => $businessId, 'd' => $startDate]);
        $balance -= (float)$stmt->fetchColumn();

        // Payments before start (we paid them → positive, reduces what we owe)
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(total_bc), 0) FROM payment_slips WHERE payee_id = :pid AND business_id = :bid AND payment_date < :d");
        $stmt->execute(['pid' => $payeeId, 'bid' => $businessId, 'd' => $startDate]);
        $balance += (float)$stmt->fetchColumn();

        // Collections before start (they paid us → negative, reduces what they owe)
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(total_bc), 0) FROM collection_slips WHERE payee_id = :pid AND business_id = :bid AND slip_date < :d");
        $stmt->execute(['pid' => $payeeId, 'bid' => $businessId, 'd' => $startDate]);
        $balance -= (float)$stmt->fetchColumn();

        // Debit Notes before start — ISSUED (we returned to supplier → positive, reduces what we owe)
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(dn.total_amount_bc), 0)
            FROM debit_notes dn
            JOIN purchase_orders po ON po.id = dn.purchase_order_id
            WHERE po.supplier_id = :pid AND dn.business_id = :bid
              AND dn.debit_note_date < :d AND dn.status = 'ISSUED'
        ");
        $stmt->execute(['pid' => $payeeId, 'bid' => $businessId, 'd' => $startDate]);
        $balance += (float)$stmt->fetchColumn();

        // Credit Notes before start — ISSUED (customer returned to us → negative, reduces what they owe)
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(cn.total_amount_bc), 0)
            FROM credit_notes cn
            JOIN sales_invoices si ON si.id = cn.sales_invoice_id
            WHERE si.customer_id = :pid AND cn.business_id = :bid
              AND cn.credit_note_date < :d AND cn.status = 'ISSUED'
        ");
        $stmt->execute(['pid' => $payeeId, 'bid' => $businessId, 'd' => $startDate]);
        $balance -= (float)$stmt->fetchColumn();

        // Profit Distributions before start (we owe them → negative)
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(pd.amount_bc), 0)
            FROM profit_distributions pd
            JOIN business_profit bp ON bp.id = pd.business_profit_id
            WHERE pd.partner_id = :pid AND bp.business_id = :bid
              AND bp.period_end < :d AND bp.status = 'DISTRIBUTED'
        ");
        $stmt->execute(['pid' => $payeeId, 'bid' => $businessId, 'd' => $startDate]);
        $balance -= (float)$stmt->fetchColumn();

        return $balance;
    }
}
