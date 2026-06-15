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
     * Logic:
     * 1. Get current balances (before values)
     * 2. Reset balance and balance_fc to 0
     * 3. Replay all payment slips: subtract amount_bc from balance, subtract amount_fc from balance_fc
     * 4. Replay all collection slips: add amount_bc to balance, add amount_fc to balance_fc
     * 5. Get new balances (after values)
     * 6. Return report array
     *
     * @param int|null $accountId Specific account, or null for all
     * @return array [{account_id, prev_balance, new_balance, prev_balance_fc, new_balance_fc}, ...]
     */
    public function recalculate(?int $accountId = null): array {
        $this->db->beginTransaction();
        try {
            // Step 1: Get current balances for the report (before values)
            $accounts = $this->getAccounts($accountId);

            $report = [];
            $beforeBalances = [];
            foreach ($accounts as $account) {
                $id = (int) $account['id'];
                $beforeBalances[$id] = [
                    'balance'    => (float) $account['balance'],
                    'balance_fc' => (float) $account['balance_fc'],
                ];
            }

            // Step 2: Reset balance and balance_fc to 0 for target account(s)
            $this->resetBalances($accountId);

            // Step 3: Replay all payment slips (subtract)
            $this->replayPayments($accountId);

            // Step 4: Replay all collection slips (add)
            $this->replayCollections($accountId);

            // Step 5: Get new balances (after values)
            $accountsAfter = $this->getAccounts($accountId);

            // Step 6: Build report
            foreach ($accountsAfter as $account) {
                $id = (int) $account['id'];
                $before = $beforeBalances[$id] ?? ['balance' => 0.0, 'balance_fc' => 0.0];
                $report[] = [
                    'account_id'      => $id,
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

    /**
     * Get accounts to process (all or single).
     */
    private function getAccounts(?int $accountId): array {
        if ($accountId !== null) {
            $stmt = $this->db->prepare(
                "SELECT id, balance, balance_fc FROM business_accounts WHERE id = :id"
            );
            $stmt->execute(['id' => $accountId]);
        } else {
            $stmt = $this->db->prepare(
                "SELECT id, balance, balance_fc FROM business_accounts"
            );
            $stmt->execute();
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Reset balance and balance_fc to 0 for target account(s).
     */
    private function resetBalances(?int $accountId): void {
        if ($accountId !== null) {
            $stmt = $this->db->prepare(
                "UPDATE business_accounts SET balance = 0, balance_fc = 0 WHERE id = :id"
            );
            $stmt->execute(['id' => $accountId]);
        } else {
            $stmt = $this->db->prepare(
                "UPDATE business_accounts SET balance = 0, balance_fc = 0"
            );
            $stmt->execute();
        }
    }

    /**
     * Replay all payment slips: subtract amount_bc from balance, subtract amount_fc from balance_fc.
     */
    private function replayPayments(?int $accountId): void {
        if ($accountId !== null) {
            $stmt = $this->db->prepare("
                SELECT account_id, SUM(amount_bc) AS total_bc, SUM(amount_fc) AS total_fc
                FROM payment_slips
                WHERE account_id = :account_id
                GROUP BY account_id
            ");
            $stmt->execute(['account_id' => $accountId]);
        } else {
            $stmt = $this->db->prepare("
                SELECT account_id, SUM(amount_bc) AS total_bc, SUM(amount_fc) AS total_fc
                FROM payment_slips
                GROUP BY account_id
            ");
            $stmt->execute();
        }

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

    /**
     * Replay all collection slips: add amount_bc to balance, add amount_fc to balance_fc.
     */
    private function replayCollections(?int $accountId): void {
        if ($accountId !== null) {
            $stmt = $this->db->prepare("
                SELECT account_id, SUM(amount_bc) AS total_bc, SUM(amount_fc) AS total_fc
                FROM collection_slips
                WHERE account_id = :account_id
                GROUP BY account_id
            ");
            $stmt->execute(['account_id' => $accountId]);
        } else {
            $stmt = $this->db->prepare("
                SELECT account_id, SUM(amount_bc) AS total_bc, SUM(amount_fc) AS total_fc
                FROM collection_slips
                GROUP BY account_id
            ");
            $stmt->execute();
        }

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
}
