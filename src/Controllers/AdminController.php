<?php
namespace App\Controllers;

use App\Config\Database;
use App\Services\AuditService;
use App\Services\BalanceRecalculator;

class AdminController extends BaseController {

    private BalanceRecalculator $balanceRecalculator;
    private AuditService $audit;

    public function __construct() {
        $db = Database::getConnection();
        $this->balanceRecalculator = new BalanceRecalculator($db);
        $this->audit = new AuditService($db);
    }

    // POST /admin/recalculate-balances
    public function recalculateBalances(): void {
        try {
            $ctx = $this->auth();
            $userPerms = $ctx->permissions ?? [];

            if (!$ctx->is_tenant_owner && !in_array('settings.manage', $userPerms, true)) {
                $this->json(false, null, 'Missing permission: settings.manage or tenant owner', 403);
                return;
            }

            $report = $this->balanceRecalculator->recalculate(
                null,
                $ctx->is_tenant_owner ? null : ($ctx->business_id ?? null),
                $ctx->is_tenant_owner ? ($ctx->tenant_id ?? null) : null
            );

            // Log action via AuditService
            $this->audit->log(
                'BALANCE_RECALCULATION',
                'business_accounts',
                0,
                'recalculate_all',
                null,
                ['accounts_processed' => count($report)],
                $ctx->user_id ?? null,
                $ctx->tenant_id ?? null,
                $ctx->business_id ?? null
            );

            $this->json(true, ['report' => $report]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to recalculate balances: ' . $e->getMessage());
        }
    }

    // POST /admin/recalculate-balances/{accountId}
    public function recalculateAccountBalance(int $accountId): void {
        try {
            $ctx = $this->auth();
            $userPerms = $ctx->permissions ?? [];

            if (!$ctx->is_tenant_owner && !in_array('settings.manage', $userPerms, true)) {
                $this->json(false, null, 'Missing permission: settings.manage or tenant owner', 403);
                return;
            }

            $report = $this->balanceRecalculator->recalculate(
                $accountId,
                $ctx->is_tenant_owner ? null : ($ctx->business_id ?? null),
                $ctx->is_tenant_owner ? ($ctx->tenant_id ?? null) : null
            );

            // Log action via AuditService
            $this->audit->log(
                'BALANCE_RECALCULATION',
                'business_accounts',
                $accountId,
                'recalculate_single',
                null,
                ['accounts_processed' => count($report)],
                $ctx->user_id ?? null,
                $ctx->tenant_id ?? null,
                $ctx->business_id ?? null
            );

            $this->json(true, ['report' => $report]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to recalculate balance: ' . $e->getMessage());
        }
    }
}
