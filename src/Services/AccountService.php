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
        return $this->repo->findAllForBusiness($ctx->business_id, $currency);
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
        return $account;
    }

    /**
     * Create a new account.
     *
     * Required keys: name, currency
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

        $this->db->beginTransaction();
        try {
            $id = $this->repo->create([
                'business_id' => $ctx->business_id,
                'name'        => trim($data['name']),
                'currency'    => trim($data['currency']),
            ]);

            $this->audit->log(
                'ACCOUNTS',
                'business_accounts',
                $id,
                'CREATE',
                null,
                [
                    'name'     => trim($data['name']),
                    'currency' => trim($data['currency']),
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

        // Currency immutability check
        if (array_key_exists('currency', $data)) {
            if (strtolower(trim($data['currency'])) !== strtolower(trim($before['currency']))) {
                throw new \InvalidArgumentException('Account currency cannot be changed');
            }
        }

        $this->db->beginTransaction();
        try {
            $this->repo->update($id, $ctx->business_id, $data);

            $this->audit->log(
                'ACCOUNTS',
                'business_accounts',
                $id,
                'UPDATE',
                $before,
                $data,
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

            if ((float) $account['balance'] != 0) {
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
