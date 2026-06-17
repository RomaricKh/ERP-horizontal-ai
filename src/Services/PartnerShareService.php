<?php
namespace App\Services;

use App\Repository\PartnerShareRepository;
use App\Support\AuthContext;
use PDO;

class PartnerShareService {
    private PartnerShareRepository $repo;
    private AuditService           $audit;
    private PDO                    $db;

    public function __construct(PDO $db) {
        $this->db    = $db;
        $this->repo  = new PartnerShareRepository($db);
        $this->audit = new AuditService($db);
    }

    /**
     * Return all partner shares for the current business with partner names.
     */
    public function list(): array {
        $businessId = AuthContext::businessId();
        return $this->repo->findAll($businessId);
    }

    /**
     * Create a new partner share record.
     *
     * Validates:
     *   - partner_id references a valid payee in the current business
     *   - total share_percent for the business does not exceed 100.00
     *
     * If invested_capital > 0 and account_id is provided, also creates
     * a CONTRIBUTION equity entry (collection slip + equity record).
     *
     * Logs via AuditService.
     *
     * @return int The new partner share ID
     * @throws \InvalidArgumentException if validation fails
     */
    public function create(array $data): int {
        $ctx = AuthContext::get();

        // Validate partner_id is a valid payee in this business
        $this->validatePayee((int) $data['partner_id'], $ctx->business_id);

        // Validate total share_percent does not exceed 100
        $newPercent   = (float) $data['share_percent'];
        $currentTotal = $this->repo->getTotalSharePercent($ctx->business_id);

        if (($currentTotal + $newPercent) > 100.00) {
            throw new \InvalidArgumentException(
                'Total share percentage would exceed 100%. Current total: ' . $currentTotal . '%, requested: ' . $newPercent . '%'
            );
        }

        $investedCapital = isset($data['invested_capital']) ? (float) $data['invested_capital'] : 0.00;

        $record = [
            'business_id'      => $ctx->business_id,
            'partner_id'       => (int) $data['partner_id'],
            'share_percent'    => $newPercent,
            'invested_capital' => $investedCapital,
        ];

        $this->db->beginTransaction();
        try {
            $id = $this->repo->insert($record);

            // If invested_capital > 0 and account_id provided, create equity contribution
            // (done outside the share transaction to avoid nested transaction issues)
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        // Create equity entry after share is committed (separate transaction)
        $accountId = isset($data['account_id']) ? (int) $data['account_id'] : null;
        if ($investedCapital > 0 && $accountId) {
            try {
                $equityService = new PartnerEquityService($this->db);
                $equityService->create([
                    'partner_id'    => (int) $data['partner_id'],
                    'equity_type'   => 'CONTRIBUTION',
                    'amount'        => $investedCapital,
                    'account_id'    => $accountId,
                    'currency'      => $data['currency'] ?? 'USD',
                    'exchange_rate' => $data['exchange_rate'] ?? 1.0000,
                    'notes'         => 'Initial investment on partner share creation',
                ]);
            } catch (\Throwable $e) {
                // Share was created but equity failed — log but don't fail the whole operation
                error_log('Partner equity creation failed after share creation: ' . $e->getMessage());
            }
        }

        $this->audit->log(
            'CREATE',
            'partner_shares',
            $id,
            'CREATE',
            null,
            $record,
            $ctx->user_id,
            $ctx->tenant_id,
            $ctx->business_id
        );

        return $id;
    }

    /**
     * Update an existing partner share record.
     *
     * Validates total share_percent ≤ 100 (excluding the current record).
     * Logs via AuditService.
     *
     * @throws \InvalidArgumentException if total would exceed 100%
     * @throws \RuntimeException if record not found
     */
    public function update(int $id, array $data): void {
        $ctx = AuthContext::get();

        $before = $this->repo->findById($id, $ctx->business_id);
        if (!$before) {
            throw new \RuntimeException('Partner share not found');
        }

        // Validate total share_percent does not exceed 100 (excluding this record)
        $newPercent   = (float) $data['share_percent'];
        $currentTotal = $this->repo->getTotalSharePercent($ctx->business_id, $id);

        if (($currentTotal + $newPercent) > 100.00) {
            throw new \InvalidArgumentException(
                'Total share percentage would exceed 100%. Other partners total: ' . $currentTotal . '%, requested: ' . $newPercent . '%'
            );
        }

        $updateData = [
            'share_percent'    => $newPercent,
            'invested_capital' => isset($data['invested_capital']) ? (float) $data['invested_capital'] : (float) $before['invested_capital'],
        ];

        $this->repo->update($id, $ctx->business_id, $updateData);

        $this->audit->log(
            'UPDATE',
            'partner_shares',
            $id,
            'UPDATE',
            $before,
            $updateData,
            $ctx->user_id,
            $ctx->tenant_id,
            $ctx->business_id
        );
    }

    /**
     * Delete a partner share record.
     * Guards: reject if equity entries or profit distributions exist.
     * Logs via AuditService.
     *
     * @throws \RuntimeException if record not found or has related records
     */
    public function delete(int $id): void {
        $ctx = AuthContext::get();

        $before = $this->repo->findById($id, $ctx->business_id);
        if (!$before) {
            throw new \RuntimeException('Partner share not found');
        }

        // Guard: check for equity entries
        $equityStmt = $this->db->prepare("
            SELECT COUNT(*) FROM partner_equity
            WHERE partner_id = :partner_id AND business_id = :business_id
        ");
        $equityStmt->execute(['partner_id' => $before['partner_id'], 'business_id' => $ctx->business_id]);
        if ((int) $equityStmt->fetchColumn() > 0) {
            throw new \RuntimeException('Cannot delete: partner has equity entries (contributions/withdrawals). Remove them first.');
        }

        // Guard: check for profit distributions
        $distStmt = $this->db->prepare("
            SELECT COUNT(*) FROM profit_distributions
            WHERE partner_id = :partner_id
              AND business_profit_id IN (SELECT id FROM business_profit WHERE business_id = :business_id)
        ");
        $distStmt->execute(['partner_id' => $before['partner_id'], 'business_id' => $ctx->business_id]);
        if ((int) $distStmt->fetchColumn() > 0) {
            throw new \RuntimeException('Cannot delete: partner has profit distributions. This is a permanent record.');
        }

        $this->repo->delete($id, $ctx->business_id);

        $this->audit->log(
            'DELETE',
            'partner_shares',
            $id,
            'DELETE',
            $before,
            null,
            $ctx->user_id,
            $ctx->tenant_id,
            $ctx->business_id
        );
    }

    /**
     * Get the total share percentage for a business.
     */
    public function getTotalSharePercent(int $businessId): float {
        return $this->repo->getTotalSharePercent($businessId);
    }

    /**
     * Get all shares for a business (used by profit distribution).
     */
    public function getSharesForBusiness(int $businessId): array {
        return $this->repo->getSharesForBusiness($businessId);
    }

    /* =====================================================================
     * Private helpers
     * ===================================================================== */

    /**
     * Validate that a partner_id references a valid payee in the given business.
     *
     * @throws \InvalidArgumentException if payee not found
     */
    private function validatePayee(int $partnerId, int $businessId): void {
        $stmt = $this->db->prepare("
            SELECT id FROM payees
            WHERE id = :partner_id
              AND business_id = :business_id
            LIMIT 1
        ");
        $stmt->execute([
            'partner_id'  => $partnerId,
            'business_id' => $businessId,
        ]);

        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            throw new \InvalidArgumentException('Invalid partner: payee not found in this business');
        }
    }
}
