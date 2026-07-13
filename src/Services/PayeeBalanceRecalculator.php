<?php
namespace App\Services;

use PDO;

class PayeeBalanceRecalculator {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Recalculate balance for one or all payees.
     * Returns report of changes.
     */
    public function recalculate(?int $payeeId = null, ?int $businessId = null): array {
        $this->db->beginTransaction();
        try {
            $sql = "SELECT id, business_id, balance FROM payees WHERE 1=1";
            $params = [];
            if ($payeeId) {
                $sql .= " AND id = :pid";
                $params['pid'] = $payeeId;
            }
            if ($businessId) {
                $sql .= " AND business_id = :bid";
                $params['bid'] = $businessId;
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $payees = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $report = [];

            foreach ($payees as $payee) {
                $pid = (int)$payee['id'];
                $bid = (int)$payee['business_id'];
                $oldBalance = (float)$payee['balance'];

                $newBalance = $this->calculateExactBalance($pid, $bid);

                if (abs($oldBalance - $newBalance) > 0.001) {
                    $upd = $this->db->prepare("UPDATE payees SET balance = :bal WHERE id = :id");
                    $upd->execute(['bal' => $newBalance, 'id' => $pid]);
                }

                $report[] = [
                    'payee_id'    => $pid,
                    'business_id' => $bid,
                    'old_balance' => $oldBalance,
                    'new_balance' => $newBalance,
                ];
            }

            $this->db->commit();
            return $report;

        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function calculateExactBalance(int $payeeId, int $businessId): float {
        $balance = 0.0;
        // Same signed convention: positive = they owe us, negative = we owe them

        // Sales Invoices
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(total_amount_bc), 0) FROM sales_invoices WHERE customer_id = :pid AND business_id = :bid AND status != 'CANCELLED'");
        $stmt->execute(['pid' => $payeeId, 'bid' => $businessId]);
        $balance += (float)$stmt->fetchColumn();

        // POs
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(total_amount_bc), 0) FROM purchase_orders WHERE supplier_id = :pid AND business_id = :bid AND status != 'CANCELLED'");
        $stmt->execute(['pid' => $payeeId, 'bid' => $businessId]);
        $balance -= (float)$stmt->fetchColumn();

        // Expenses
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(amount_bc), 0) FROM expenses WHERE payee_id = :pid AND business_id = :bid");
        $stmt->execute(['pid' => $payeeId, 'bid' => $businessId]);
        $balance -= (float)$stmt->fetchColumn();

        // Salaries
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(net_amount_bc), 0) FROM salaries WHERE payee_id = :pid AND business_id = :bid");
        $stmt->execute(['pid' => $payeeId, 'bid' => $businessId]);
        $balance -= (float)$stmt->fetchColumn();

        // Payments (we paid them -> reduces what we owe them -> positive effect on balance)
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(total_bc), 0) FROM payment_slips WHERE payee_id = :pid AND business_id = :bid");
        $stmt->execute(['pid' => $payeeId, 'bid' => $businessId]);
        $balance += (float)$stmt->fetchColumn();

        // Collections (they paid us -> reduces what they owe us -> negative effect on balance)
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(total_bc), 0) FROM collection_slips WHERE payee_id = :pid AND business_id = :bid");
        $stmt->execute(['pid' => $payeeId, 'bid' => $businessId]);
        $balance -= (float)$stmt->fetchColumn();

        // Debit Notes (ISSUED)
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(dn.total_amount_bc), 0)
            FROM debit_notes dn
            JOIN purchase_orders po ON po.id = dn.purchase_order_id
            WHERE po.supplier_id = :pid AND dn.business_id = :bid
              AND dn.status = 'ISSUED'
        ");
        $stmt->execute(['pid' => $payeeId, 'bid' => $businessId]);
        $balance += (float)$stmt->fetchColumn();

        // Credit Notes (ISSUED)
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(cn.total_amount_bc), 0)
            FROM credit_notes cn
            JOIN sales_invoices si ON si.id = cn.sales_invoice_id
            WHERE si.customer_id = :pid AND cn.business_id = :bid
              AND cn.status = 'ISSUED'
        ");
        $stmt->execute(['pid' => $payeeId, 'bid' => $businessId]);
        $balance -= (float)$stmt->fetchColumn();

        // Profit Distributions
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(pd.amount_bc), 0)
            FROM profit_distributions pd
            JOIN business_profit bp ON bp.id = pd.business_profit_id
            WHERE pd.partner_id = :pid AND bp.business_id = :bid
              AND bp.status = 'DISTRIBUTED'
        ");
        $stmt->execute(['pid' => $payeeId, 'bid' => $businessId]);
        $balance -= (float)$stmt->fetchColumn();

        return round($balance, 2);
    }
}
