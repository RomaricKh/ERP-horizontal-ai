<?php
namespace App\Repository;

use PDO;

class AccountRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Return all accounts for a business, ordered by name.
     * Optionally filter by currency (case-insensitive).
     */
    public function findAllForBusiness(int $businessId, ?string $currency = null): array {
        $sql = "
            SELECT id, business_id, name, currency, account_type, balance, balance_fc, is_local_currency_account, cash_denomination, created_at, updated_at
            FROM business_accounts
            WHERE business_id = :business
        ";
        $params = ['business' => $businessId];

        if ($currency !== null) {
            $sql .= " AND LOWER(currency) = LOWER(:currency)";
            $params['currency'] = $currency;
        }

        $sql .= " ORDER BY name";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return a single account by id, scoped to business.
     */
    public function findById(int $id, int $businessId): ?array {
        $stmt = $this->db->prepare("
            SELECT id, business_id, name, currency, account_type, balance, balance_fc, is_local_currency_account, cash_denomination, created_at, updated_at
            FROM business_accounts
            WHERE id = :id
              AND business_id = :business
            LIMIT 1
        ");
        $stmt->execute(['id' => $id, 'business' => $businessId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Insert a new account and return the new id.
     * Balance starts at 0; it is system-managed via updateBalance.
     *
     * Expected keys in $data: name, currency, business_id, account_type, is_local_currency_account
     */
    public function create(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO business_accounts
              (business_id, name, currency, account_type, balance, balance_fc, is_local_currency_account, cash_denomination, created_at, updated_at)
            VALUES
              (:business_id, :name, :currency, :account_type, 0, 0, :is_local, :cash_denomination, NOW(), NOW())
        ");
        $stmt->execute([
            'business_id'  => $data['business_id'],
            'name'         => $data['name'],
            'currency'     => $data['currency'],
            'account_type' => $data['account_type'],
            'is_local'          => $data['is_local_currency_account'] ? 1 : 0,
            'cash_denomination' => $data['cash_denomination'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Update the account.
     */
    public function update(int $id, int $businessId, array $data): void {
        $stmt = $this->db->prepare("
            UPDATE business_accounts
            SET name                      = :name,
                account_type              = :account_type,
                is_local_currency_account = :is_local,
                cash_denomination         = :cash_denomination,
                updated_at                = NOW()
            WHERE id          = :id
              AND business_id  = :business
        ");
        $stmt->execute([
            'name'         => $data['name'],
            'account_type' => $data['account_type'],
            'is_local'          => $data['is_local_currency_account'] ? 1 : 0,
            'cash_denomination' => $data['cash_denomination'] ?? null,
            'id'           => $id,
            'business'     => $businessId,
        ]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Account not found or not allowed');
        }
    }

    /**
     * Clear the local currency flag from all accounts in this business.
     */
    public function clearLocalCurrencyFlag(int $businessId): void {
        $stmt = $this->db->prepare("
            UPDATE business_accounts
            SET is_local_currency_account = 0
            WHERE business_id = :business
        ");
        $stmt->execute(['business' => $businessId]);
    }

    /**
     * Delete an account scoped to business.
     */
    public function delete(int $id, int $businessId): void {
        $stmt = $this->db->prepare("
            DELETE FROM business_accounts
            WHERE id         = :id
              AND business_id = :business
        ");
        $stmt->execute(['id' => $id, 'business' => $businessId]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Account not found or not allowed');
        }
    }

    /**
     * Count total slips (payment + collection) linked to an account.
     * Used to guard against deleting accounts that have transactions.
     */
    public function countSlips(int $accountId): int {
        $stmt = $this->db->prepare("
            SELECT
              (SELECT COUNT(*) FROM payment_slips    WHERE account_id = :id1) +
              (SELECT COUNT(*) FROM collection_slips WHERE account_id = :id2)
            AS total
        ");
        $stmt->execute(['id1' => $accountId, 'id2' => $accountId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Atomically adjust the account balance by delta (positive or negative).
     * Accepts an explicit PDO connection to support external transaction control.
     * The $deltaFc parameter adjusts the native-currency balance (defaults to 0.0 for backward compatibility).
     */
    public function updateBalance(int $accountId, float $delta, PDO $db, float $deltaFc = 0.0): void {
        $stmt = $db->prepare("
            UPDATE business_accounts
            SET balance    = balance + :delta,
                balance_fc = balance_fc + :delta_fc,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute(['delta' => $delta, 'delta_fc' => $deltaFc, 'id' => $accountId]);
    }
}
