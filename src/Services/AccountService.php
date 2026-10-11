<?php
namespace App\Services;

use App\Repository\AccountRepository;
use App\Support\AuthContext;
use PDO;

class AccountService {
    private AccountRepository $repo;
    private AuditService      $audit;
    private PDO               $db;

    public function __construct(PDO $db) {
        $this->db    = $db;
        $this->repo  = new AccountRepository($db);
        $this->audit = new AuditService($db);
    }

    /**
     * Return all accounts for the current business.
     * Optionally filter by currency (case-insensitive).
     */
    public function list(?string $currency = null): array {
        $ctx = AuthContext::get();
        $accounts = $this->repo->findAllForBusiness($ctx->business_id, $currency);
        return $this->applyDynamicExchangeRates($accounts);
    }

    /**
     * Return a single account by id, scoped to the current business.
     *
     * @throws \RuntimeException if not found
     */
    public function getById(int $id): array {
        $ctx     = AuthContext::get();
        $account = $this->repo->findById($id, $ctx->business_id);
        if (!$account) {
            throw new \RuntimeException('Account not found');
        }
        return $this->applyDynamicExchangeRates([$account])[0];
    }

    /**
     * Dynamically calculates the Base Currency (BC) balance for accounts using the latest exchange rates.
     * A1: Rates are cached per-currency to avoid N+1 queries.
     */
    private function applyDynamicExchangeRates(array $accounts): array {
        $exchangeRateService = new ExchangeRateService($this->db);
        $rateCache = []; // Cache: currency => lookup result

        foreach ($accounts as &$account) {
            $currency = $account['currency'];

            if (!isset($rateCache[$currency])) {
                $rateCache[$currency] = $exchangeRateService->getLatestForLookup($currency);
            }

            $lookup = $rateCache[$currency];
            $rate = $lookup['rate'] !== null ? (float) $lookup['rate'] : 1.0;

            if (!$lookup['is_base'] && $rate > 0) {
                // For foreign currency accounts, dynamically calculate BC equivalent from FC balance
                $account['balance'] = round((float) $account['balance_fc'] * $rate, 2);
            }
        }
        return $accounts;
    }

    /**
     * Create a new account.
     *
     * Required keys: name, currency
     * Optional keys: account_type, is_local_currency_account
     *
     * @throws \InvalidArgumentException if required fields are missing
     */
    public function create(array $data): int {
        $ctx = AuthContext::get();

        if (empty(trim($data['name'] ?? ''))) {
            throw new \InvalidArgumentException('Name is required');
        }
        if (empty(trim($data['currency'] ?? ''))) {
            throw new \InvalidArgumentException('Currency is required');
        }

        $isLocal = !empty($data['is_local_currency_account']);
        $accountType = in_array($data['account_type'] ?? '', ['BANK','CASH','CREDIT','OTHER']) ? $data['account_type'] : 'CASH';

        $this->db->beginTransaction();
        try {
            if ($isLocal) {
                $this->repo->clearLocalCurrencyFlag($ctx->business_id);
            }

            $cashDenom = isset($data['cash_denomination']) && $data['cash_denomination'] !== '' ? (float)$data['cash_denomination'] : null;

            $id = $this->repo->create([
                'business_id'               => $ctx->business_id,
                'name'                      => trim($data['name']),
                'currency'                  => trim($data['currency']),
                'account_type'              => $accountType,
                'is_local_currency_account' => $isLocal,
                'cash_denomination'         => $cashDenom,
            ]);

            $this->audit->log(
                'ACCOUNTS',
                'business_accounts',
                $id,
                'CREATE',
                null,
                [
                    'name'                      => trim($data['name']),
                    'currency'                  => trim($data['currency']),
                    'account_type'              => $accountType,
                    'is_local_currency_account' => $isLocal,
                ],
                $ctx->user_id,
                $ctx->tenant_id,
                $ctx->business_id
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $id;
    }

    /**
     * Update an existing account.
     *
     * @throws \RuntimeException if not found
     * @throws \InvalidArgumentException if attempting to change currency
     */
    public function update(int $id, array $data): void {
        $ctx    = AuthContext::get();
        $before = $this->repo->findById($id, $ctx->business_id);
        if (!$before) {
            throw new \RuntimeException('Account not found');
        }

        // Updating currency is typically disallowed if balance exists. For now we just block it.
        if (!empty($data['currency']) && trim($data['currency']) !== $before['currency']) {
            throw new \InvalidArgumentException('Cannot change currency of an existing account.');
        }

        $newName = !empty(trim($data['name'] ?? '')) ? trim($data['name']) : $before['name'];
        $isLocal = isset($data['is_local_currency_account']) ? !empty($data['is_local_currency_account']) : (bool)$before['is_local_currency_account'];
        $accountType = in_array($data['account_type'] ?? '', ['BANK','CASH','CREDIT','OTHER']) ? $data['account_type'] : $before['account_type'];
        $cashDenom = array_key_exists('cash_denomination', $data)
            ? ($data['cash_denomination'] !== '' && $data['cash_denomination'] !== null ? (float)$data['cash_denomination'] : null)
            : $before['cash_denomination'];

        $this->db->beginTransaction();
        try {
            if ($isLocal && !$before['is_local_currency_account']) {
                $this->repo->clearLocalCurrencyFlag($ctx->business_id);
            }

            $this->repo->update($id, $ctx->business_id, [
                'name'                      => $newName,
                'account_type'              => $accountType,
                'is_local_currency_account' => $isLocal,
                'cash_denomination'         => $cashDenom,
            ]);

            $after = $this->repo->findById($id, $ctx->business_id);

            $this->audit->log(
                'ACCOUNTS',
                'business_accounts',
                $id,
                'UPDATE',
                $before,
                $after,
                $ctx->user_id,
                $ctx->tenant_id,
                $ctx->business_id
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Delete an account.
     *
     * Guards: balance must be 0 AND no linked slips.
     *
     * @throws \RuntimeException if not found, balance != 0, or has linked transactions
     */
    public function delete(int $id): void {
        $ctx = AuthContext::get();

        $this->db->beginTransaction();
        try {
            $account = $this->repo->findById($id, $ctx->business_id);
            if (!$account) {
                throw new \RuntimeException('Account not found');
            }

            if (abs((float) $account['balance']) > 0.001) {
                throw new \RuntimeException('Cannot delete: account has a non-zero balance');
            }

            if ($this->repo->countSlips($id) > 0) {
                throw new \RuntimeException('Cannot delete: account has linked transactions');
            }

            $this->repo->delete($id, $ctx->business_id);

            $this->audit->log(
                'ACCOUNTS',
                'business_accounts',
                $id,
                'DELETE',
                $account,
                null,
                $ctx->user_id,
                $ctx->tenant_id,
                $ctx->business_id
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
