<?php
namespace App\Services;

use App\Repository\PeopleRepository;
use App\Repository\SalaryRepository;
use App\Services\ExchangeRateService;
use App\Services\NotificationService;
use App\Support\AuthContext;
use PDO;

class SalaryService {
    private SalaryRepository    $repo;
    private PeopleRepository    $peopleRepo;
    private ExchangeRateService $exchangeRateService;
    private AuditService        $audit;
    private NotificationService $notificationService;
    private PDO                 $db;

    public function __construct(PDO $db) {
        $this->db                  = $db;
        $this->repo                = new SalaryRepository($db);
        $this->peopleRepo          = new PeopleRepository($db);
        $this->exchangeRateService = new ExchangeRateService($db);
        $this->audit               = new AuditService($db);
        $this->notificationService = new NotificationService($db);
    }

    /**
     * Return all salary records for the current business.
     */
    public function list(): array {
        $ctx = AuthContext::get();
        return $this->repo->findAllForBusiness($ctx->business_id);
    }

    /**
     * Return a single salary record by id.
     *
     * @throws \RuntimeException if not found
     */
    public function getById(int $id): array {
        $ctx    = AuthContext::get();
        $salary = $this->repo->findById($id, $ctx->business_id);
        if (!$salary) {
            throw new \RuntimeException('Salary record not found');
        }
        return $salary;
    }

    /**
     * Create a new salary record.
     *
     * Business logic:
     *   gross_amount_bc = gross_amount_fc * exchange_rate
     *   tax_bc          = tax_fc * exchange_rate
     *   net_amount_fc   = gross_amount_fc - tax_fc
     *   net_amount_bc   = gross_amount_bc - tax_bc
     *
     * Required keys: period_start, period_end, currency, exchange_rate, gross_amount_fc
     * Optional keys: payee_id, tax_fc (defaults to 0), notes
     *
     * @throws \InvalidArgumentException if required fields are missing
     */
    public function create(array $data): int {
        $ctx = AuthContext::get();

        $this->validateData($data);

        // Resolve exchange rate: use provided value or fall back to latest from exchange_rates table
        $providedRate = (!isset($data['exchange_rate']) || $data['exchange_rate'] === '' || $data['exchange_rate'] === null)
            ? null
            : (float) $data['exchange_rate'];
        $resolvedRate = $this->exchangeRateService->resolveRate(trim($data['currency']), $providedRate);
        $data['exchange_rate'] = $resolvedRate;

        $calculated = $this->calculateAmounts($data);

        $this->db->beginTransaction();
        try {
            $payeeId = isset($data['payee_id']) && $data['payee_id'] !== '' ? (int) $data['payee_id'] : null;

            $id = $this->repo->create(array_merge([
                'business_id' => $ctx->business_id,
                'payee_id'    => $payeeId,
                'period_start' => $data['period_start'],
                'period_end'   => $data['period_end'],
                'currency'     => trim($data['currency']),
                'notes'        => $data['notes'] ?? null,
            ], $calculated));

            // Decrease payee balance (we owe the employee for this salary)
            if ($payeeId) {
                $this->peopleRepo->updatePayeeBalance($payeeId, -$calculated['net_amount_bc']);
            }

            $this->audit->log(
                'SALARIES',
                'salaries',
                $id,
                'CREATE',
                null,
                [
                    'period_start'    => $data['period_start'],
                    'period_end'      => $data['period_end'],
                    'currency'        => trim($data['currency']),
                    'gross_amount_fc' => $calculated['gross_amount_fc'],
                    'tax_fc'          => $calculated['tax_fc'],
                    'net_amount_fc'   => $calculated['net_amount_fc'],
                    'net_amount_bc'   => $calculated['net_amount_bc'],
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

        // Notify the employee user (if linked) about the new salary entry
        if ($payeeId) {
            $this->notifyLinkedEmployee($payeeId, $id, $data['period_start'], $data['period_end'], $ctx->business_id);
        }

        return $id;
    }

    /**
     * Update an existing salary record.
     * Recalculates all derived amounts.
     *
     * @throws \RuntimeException if not found
     */
    public function update(int $id, array $data): void {
        $ctx    = AuthContext::get();
        $before = $this->repo->findById($id, $ctx->business_id);

        if (!$before) {
            throw new \RuntimeException('Salary record not found');
        }

        $this->validateData($data);

        // Resolve exchange rate: use provided value or fall back to latest from exchange_rates table
        $providedRate = (!isset($data['exchange_rate']) || $data['exchange_rate'] === '' || $data['exchange_rate'] === null)
            ? null
            : (float) $data['exchange_rate'];
        $resolvedRate = $this->exchangeRateService->resolveRate(trim($data['currency']), $providedRate);
        $data['exchange_rate'] = $resolvedRate;

        $calculated = $this->calculateAmounts($data);

        $this->db->beginTransaction();
        try {
            $newPayeeId = isset($data['payee_id']) && $data['payee_id'] !== '' ? (int) $data['payee_id'] : null;

            $this->repo->update($id, $ctx->business_id, array_merge([
                'payee_id'     => $newPayeeId,
                'period_start' => $data['period_start'],
                'period_end'   => $data['period_end'],
                'currency'     => trim($data['currency']),
                'notes'        => $data['notes'] ?? null,
            ], $calculated));

            // Adjust payee balance based on payee/amount changes
            $oldPayeeId    = isset($before['payee_id']) ? (int) $before['payee_id'] : null;
            $oldNetAmountBc = (float) ($before['net_amount_bc'] ?? 0);
            $newNetAmountBc = $calculated['net_amount_bc'];

            if ($oldPayeeId && $newPayeeId && $oldPayeeId === $newPayeeId) {
                // Same payee — adjust by delta if net amount changed (negative = we owe more)
                $delta = $newNetAmountBc - $oldNetAmountBc;
                if ($delta != 0) {
                    $this->peopleRepo->updatePayeeBalance($oldPayeeId, -$delta);
                }
            } elseif ($oldPayeeId && $newPayeeId && $oldPayeeId !== $newPayeeId) {
                // Payee changed — reverse old (increase back), apply new (decrease)
                $this->peopleRepo->updatePayeeBalance($oldPayeeId, $oldNetAmountBc);
                $this->peopleRepo->updatePayeeBalance($newPayeeId, -$newNetAmountBc);
            } elseif ($oldPayeeId && !$newPayeeId) {
                // Payee removed — reverse old (increase back)
                $this->peopleRepo->updatePayeeBalance($oldPayeeId, $oldNetAmountBc);
            } elseif (!$oldPayeeId && $newPayeeId) {
                // Payee added — apply new (decrease)
                $this->peopleRepo->updatePayeeBalance($newPayeeId, -$newNetAmountBc);
            }

            $this->audit->log(
                'SALARIES',
                'salaries',
                $id,
                'UPDATE',
                $before,
                [
                    'period_start'    => $data['period_start'],
                    'period_end'      => $data['period_end'],
                    'currency'        => trim($data['currency']),
                    'gross_amount_fc' => $calculated['gross_amount_fc'],
                    'tax_fc'          => $calculated['tax_fc'],
                    'net_amount_fc'   => $calculated['net_amount_fc'],
                    'net_amount_bc'   => $calculated['net_amount_bc'],
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
    }

    /**
     * Delete a salary record.
     *
     * @throws \RuntimeException if not found
     */
    public function delete(int $id): void {
        $ctx    = AuthContext::get();
        $before = $this->repo->findById($id, $ctx->business_id);

        if (!$before) {
            throw new \RuntimeException('Salary record not found');
        }

        $this->db->beginTransaction();
        try {
            $this->repo->delete($id, $ctx->business_id);

            // Reverse payee balance (increase back since we no longer owe)
            $payeeId = isset($before['payee_id']) ? (int) $before['payee_id'] : null;
            if ($payeeId) {
                $this->peopleRepo->updatePayeeBalance($payeeId, (float) $before['net_amount_bc']);
            }

            $this->audit->log(
                'SALARIES',
                'salaries',
                $id,
                'DELETE',
                $before,
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

    /* =====================================================================
     * Private helpers
     * ===================================================================== */

    /**
     * Validate required fields.
     *
     * @throws \InvalidArgumentException
     */
    private function validateData(array $data): void {
        if (empty($data['period_start'] ?? '')) {
            throw new \InvalidArgumentException('Period start is required');
        }
        if (empty($data['period_end'] ?? '')) {
            throw new \InvalidArgumentException('Period end is required');
        }
        if (empty(trim($data['currency'] ?? ''))) {
            throw new \InvalidArgumentException('Currency is required');
        }
        if (!isset($data['gross_amount_fc']) || $data['gross_amount_fc'] === '') {
            throw new \InvalidArgumentException('Gross amount is required');
        }

        // Date range validation
        if ($data['period_end'] < $data['period_start']) {
            throw new \InvalidArgumentException('Period end must not be before period start');
        }

        // Tax cannot exceed gross amount
        $grossAmountFc = (float) $data['gross_amount_fc'];
        $taxFc = isset($data['tax_fc']) && $data['tax_fc'] !== '' ? (float) $data['tax_fc'] : 0.0;
        if ($taxFc > $grossAmountFc) {
            throw new \InvalidArgumentException('Tax cannot exceed gross amount');
        }
    }

    /**
     * Calculate all derived monetary amounts.
     *
     * Returns array with keys:
     *   exchange_rate, gross_amount_fc, gross_amount_bc,
     *   tax_fc, tax_bc, net_amount_fc, net_amount_bc
     */
    private function calculateAmounts(array $data): array {
        $exchangeRate   = (float) $data['exchange_rate'];
        $grossAmountFc  = (float) $data['gross_amount_fc'];
        $taxFc          = isset($data['tax_fc']) && $data['tax_fc'] !== '' ? (float) $data['tax_fc'] : 0.0;

        $grossAmountBc  = round($grossAmountFc * $exchangeRate, 2);
        $taxBc          = round($taxFc * $exchangeRate, 2);
        $netAmountFc    = round($grossAmountFc - $taxFc, 2);
        $netAmountBc    = round($grossAmountBc - $taxBc, 2);

        return [
            'exchange_rate'   => $exchangeRate,
            'gross_amount_fc' => $grossAmountFc,
            'gross_amount_bc' => $grossAmountBc,
            'tax_fc'          => $taxFc,
            'tax_bc'          => $taxBc,
            'net_amount_fc'   => $netAmountFc,
            'net_amount_bc'   => $netAmountBc,
        ];
    }

    /**
     * Notify the employee user linked to a payee about a new salary entry.
     *
     * The link is resolved by: payee_id → payees.people_id → people.email → users.email.
     * If the person has no email or no matching user account exists, the notification is skipped.
     * Errors are handled internally and never propagate.
     */
    private function notifyLinkedEmployee(int $payeeId, int $salaryId, string $periodStart, string $periodEnd, int $businessId): void {
        try {
            $userId = $this->resolveUserIdFromPayee($payeeId);
            if ($userId === null) {
                return;
            }

            $this->notificationService->notify(
                'SYSTEM_ALERT',
                'New Salary Entry',
                'A salary record has been created for the period ' . $periodStart . ' to ' . $periodEnd,
                $userId,
                'salaries',
                $salaryId,
                null
            );
        } catch (\Throwable $e) {
            error_log("[SalaryService::notifyLinkedEmployee] Error: " . $e->getMessage());
        }
    }

    /**
     * Resolve the user ID linked to a payee by matching email addresses.
     * Chain: payee_id → payees.people_id → people.email → users.email → users.id
     *
     * Additionally checks that the user is active in the same business via user_roles.
     *
     * @return int|null The user ID if found, null otherwise
     */
    private function resolveUserIdFromPayee(int $payeeId): ?int {
        $stmt = $this->db->prepare("
            SELECT u.id
            FROM payees py
            JOIN people p ON p.id = py.people_id
            JOIN users u ON u.email = p.email AND u.is_active = 1
            JOIN user_roles ur ON ur.user_id = u.id AND ur.business_id = py.business_id
            WHERE py.id = :payee_id
              AND p.email IS NOT NULL
              AND p.email != ''
            LIMIT 1
        ");
        $stmt->execute(['payee_id' => $payeeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? (int) $row['id'] : null;
    }
}
