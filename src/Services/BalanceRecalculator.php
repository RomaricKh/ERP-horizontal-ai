<?php
namespace App\Services;

use PDO;

class BalanceRecalculator {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Recalculate balance and balance_fc for one or all accounts.
     * Returns report of changes.
     *
     * @param int|null $accountId Specific account, or null for all
     * @param int|null $businessId Specific business to scope to
     * @param int|null $tenantId Specific tenant to scope to
     * @return array [{account_id, prev_balance, new_balance, prev_balance_fc, new_balance_fc}, ...]
     */
    public function recalculate(?int $accountId = null, ?int $businessId = null, ?int $tenantId = null): array {
        $this->db->beginTransaction();
        try {
            $accounts = $this->getAccounts($accountId, $businessId, $tenantId);

            $report = [];
            $beforeBalances = [];
            $accountIds = [];
            foreach ($accounts as $account) {
                $id = (int) $account['id'];
                $accountIds[] = $id;
                $beforeBalances[$id] = [
                    'name'       => $account['name'],
                    'balance'    => (float) $account['balance'],
                    'balance_fc' => (float) $account['balance_fc'],
                ];
            }

            if (empty($accountIds)) {
                $this->db->rollBack();
                return [];
            }

            $this->resetBalances($accountIds);
            $this->replayPayments($accountIds);
            $this->replayCollections($accountIds);
            $this->replayTransfersOut($accountIds);
            $this->replayTransfersIn($accountIds);

            $accountsAfter = $this->getAccounts($accountId, $businessId, $tenantId);

            foreach ($accountsAfter as $account) {
                $id = (int) $account['id'];
                $before = $beforeBalances[$id] ?? ['name' => $account['name'], 'balance' => 0.0, 'balance_fc' => 0.0];
                $report[] = [
                    'account_id'      => $id,
                    'account_name'    => $before['name'],
                    'prev_balance'    => $before['balance'],
                    'new_balance'     => (float) $account['balance'],
                    'prev_balance_fc' => $before['balance_fc'],
                    'new_balance_fc'  => (float) $account['balance_fc'],
                ];
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $report;
    }

    private function getAccounts(?int $accountId, ?int $businessId, ?int $tenantId): array {
        $sql = "SELECT a.id, a.name, a.balance, a.balance_fc FROM business_accounts a WHERE 1=1";
        $params = [];

        if ($accountId !== null) {
            $sql .= " AND a.id = :id";
            $params['id'] = $accountId;
        }
        if ($businessId !== null) {
            $sql .= " AND a.business_id = :business_id";
            $params['business_id'] = $businessId;
        } elseif ($tenantId !== null) {
            $sql .= " AND a.business_id IN (SELECT id FROM businesses WHERE tenant_id = :tenant_id)";
            $params['tenant_id'] = $tenantId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function resetBalances(array $accountIds): void {
        if (empty($accountIds)) return;
        $inQuery = implode(',', array_fill(0, count($accountIds), '?'));
        $stmt = $this->db->prepare("UPDATE business_accounts SET balance = 0, balance_fc = 0 WHERE id IN ($inQuery)");
        $stmt->execute($accountIds);
    }

    private function replayPayments(array $accountIds): void {
        if (empty($accountIds)) return;
        $inQuery = implode(',', array_fill(0, count($accountIds), '?'));
        $stmt = $this->db->prepare("
            SELECT psl.account_id, SUM(psl.amount_bc) AS total_bc, SUM(CASE WHEN psl.currency = a.currency THEN psl.amount_fc ELSE psl.amount_bc END) AS total_fc
            FROM payment_slip_lines psl
            JOIN business_accounts a ON a.id = psl.account_id
            WHERE psl.account_id IN ($inQuery)
            GROUP BY psl.account_id
        ");
        $stmt->execute($accountIds);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $updateStmt = $this->db->prepare("
                UPDATE business_accounts
                SET balance = balance - :total_bc,
                    balance_fc = balance_fc - :total_fc
                WHERE id = :account_id
            ");
            $updateStmt->execute([
                'total_bc'   => (float) $row['total_bc'],
                'total_fc'   => (float) $row['total_fc'],
                'account_id' => (int) $row['account_id'],
            ]);
        }
    }

    private function replayCollections(array $accountIds): void {
        if (empty($accountIds)) return;
        $inQuery = implode(',', array_fill(0, count($accountIds), '?'));
        $stmt = $this->db->prepare("
            SELECT csl.account_id, SUM(csl.amount_bc) AS total_bc, SUM(CASE WHEN csl.currency = a.currency THEN csl.amount_fc ELSE csl.amount_bc END) AS total_fc
            FROM collection_slip_lines csl
            JOIN business_accounts a ON a.id = csl.account_id
            WHERE csl.account_id IN ($inQuery)
            GROUP BY csl.account_id
        ");
        $stmt->execute($accountIds);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $updateStmt = $this->db->prepare("
                UPDATE business_accounts
                SET balance = balance + :total_bc,
                    balance_fc = balance_fc + :total_fc
                WHERE id = :account_id
            ");
            $updateStmt->execute([
                'total_bc'   => (float) $row['total_bc'],
                'total_fc'   => (float) $row['total_fc'],
                'account_id' => (int) $row['account_id'],
            ]);
        }
    }

    private function replayTransfersOut(array $accountIds): void {
        if (empty($accountIds)) return;
        $inQuery = implode(',', array_fill(0, count($accountIds), '?'));
        $stmt = $this->db->prepare("
            SELECT t.from_account_id, SUM(t.amount_bc) AS total_bc, SUM(t.amount_from) AS total_fc
            FROM account_transfers t
            WHERE t.from_account_id IN ($inQuery)
            GROUP BY t.from_account_id
        ");
        $stmt->execute($accountIds);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $updateStmt = $this->db->prepare("
                UPDATE business_accounts
                SET balance = balance - :total_bc,
                    balance_fc = balance_fc - :total_fc
                WHERE id = :account_id
            ");
            $updateStmt->execute([
                'total_bc'   => (float) $row['total_bc'],
                'total_fc'   => (float) $row['total_fc'],
                'account_id' => (int) $row['from_account_id'],
            ]);
        }
    }

    private function replayTransfersIn(array $accountIds): void {
        if (empty($accountIds)) return;
        $inQuery = implode(',', array_fill(0, count($accountIds), '?'));
        $stmt = $this->db->prepare("
            SELECT t.to_account_id, SUM(t.amount_bc) AS total_bc, SUM(t.amount_to) AS total_fc
            FROM account_transfers t
            WHERE t.to_account_id IN ($inQuery)
            GROUP BY t.to_account_id
        ");
        $stmt->execute($accountIds);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $updateStmt = $this->db->prepare("
                UPDATE business_accounts
                SET balance = balance + :total_bc,
                    balance_fc = balance_fc + :total_fc
                WHERE id = :account_id
            ");
            $updateStmt->execute([
                'total_bc'   => (float) $row['total_bc'],
                'total_fc'   => (float) $row['total_fc'],
                'account_id' => (int) $row['to_account_id'],
            ]);
        }
    }
}
