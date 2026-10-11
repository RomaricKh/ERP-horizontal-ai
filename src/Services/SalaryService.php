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
     * Generate a payslip PDF for the given salary record.
     *
     * Orchestrates: salary lookup → business info → HTML render → Dompdf generation.
     *
     * @param int $salaryId
     * @return array{content: string, filename: string} PDF bytes and sanitized filename
     * @throws \RuntimeException if salary not found
     */
    public function generatePayslipPdf(int $salaryId): array {
        $salary = $this->getById($salaryId);

        $business = $this->repo->findBusinessInfo(AuthContext::businessId());

        $html = \App\Templates\PayslipPdf::render($salary, $business);

        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = 'payslip-' . ($salary['employee_name'] ?? 'unknown') . '-' . $salary['period_start'] . '.pdf';
        $filename = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $filename);

        return [
            'content'  => $dompdf->output(),
            'filename' => $filename,
        ];
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

        // Payee ownership verification
        $payeeId = isset($data['payee_id']) && $data['payee_id'] !== '' ? (int) $data['payee_id'] : null;
        if ($payeeId) {
            $this->peopleRepo->ensurePayeeBelongsToBusiness($payeeId, $ctx->business_id);
        }

        // Period overlap validation
        if ($payeeId) {
            $this->checkPeriodOverlap($payeeId, $ctx->business_id, $data['period_start'], $data['period_end']);
        }

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

        // Payee ownership verification
        $newPayeeIdCheck = isset($data['payee_id']) && $data['payee_id'] !== '' ? (int) $data['payee_id'] : null;
        if ($newPayeeIdCheck) {
            $this->peopleRepo->ensurePayeeBelongsToBusiness($newPayeeIdCheck, $ctx->business_id);
        }

        // Period overlap validation (exclude current record)
        if ($newPayeeIdCheck) {
            $this->checkPeriodOverlap($newPayeeIdCheck, $ctx->business_id, $data['period_start'], $data['period_end'], $id);
        }

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
     * Guards: reject if payment allocations exist for this salary.
     *
     * @throws \RuntimeException if not found or has allocations
     */
    public function delete(int $id): void {
        $ctx    = AuthContext::get();
        $before = $this->repo->findById($id, $ctx->business_id);

        if (!$before) {
            throw new \RuntimeException('Salary record not found');
        }

        // Guard: reject deletion if payment allocations exist
        $paidAmount = $this->repo->sumPaymentAllocationsForSalary($id);
        if ($paidAmount > 0) {
            throw new \RuntimeException('Cannot delete salary with existing payment allocations. Remove related payments first.');
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

    /**
     * Generate batch payroll for all employees with active recurring schedules.
     * Creates salary records for a given period for all employees that don't already
     * have a salary record overlapping that period.
     *
     * @param array $data Required: period_start, period_end. Optional: payee_ids (array of specific employee payee IDs)
     * @return array Summary: ['created' => int, 'skipped' => int, 'details' => [...]]
     * @throws \InvalidArgumentException
     */
    public function generatePayroll(array $data): array {
        $ctx = AuthContext::get();

        if (empty($data['period_start'] ?? '')) {
            throw new \InvalidArgumentException('Period start is required');
        }
        if (empty($data['period_end'] ?? '')) {
            throw new \InvalidArgumentException('Period end is required');
        }
        if ($data['period_end'] < $data['period_start']) {
            throw new \InvalidArgumentException('Period end must not be before period start');
        }

        $periodStart = $data['period_start'];
        $periodEnd   = $data['period_end'];

        // Get all employees in this business (people with type 'Employee')
        $employees = $this->repo->findEmployeesWithRecurrences($ctx->business_id);

        // Filter by specific payee IDs if provided
        $targetPayeeIds = $data['payee_ids'] ?? null;
        if (is_array($targetPayeeIds) && !empty($targetPayeeIds)) {
            $employees = array_filter($employees, fn($e) => in_array((int)$e['payee_id'], $targetPayeeIds));
        }

        $summary = ['created' => 0, 'skipped' => 0, 'details' => []];

        $this->db->beginTransaction();
        try {
            foreach ($employees as $emp) {
                $payeeId = (int) $emp['payee_id'];

                // Check if salary already exists for this employee in this period
                if ($this->repo->existsOverlappingPeriod($payeeId, $ctx->business_id, $periodStart, $periodEnd)) {
                    $summary['skipped']++;
                    $summary['details'][] = [
                        'payee_id' => $payeeId,
                        'employee_name' => $emp['employee_name'],
                        'status' => 'skipped',
                        'reason' => 'Salary already exists for this period',
                    ];
                    continue;
                }

                // Determine amounts: use latest salary as template, fallback to recurrence schedule
                $templateSalary = $this->repo->findLatestForPayee($payeeId, $ctx->business_id);

                if ($templateSalary) {
                    $grossAmountFc = (float) $templateSalary['gross_amount_fc'];
                    $taxFc = (float) $templateSalary['tax_fc'];
                    $currency = $templateSalary['currency'];
                } elseif (!empty($emp['default_amount'])) {
                    // Fallback to recurrence schedule (default_amount = net)
                    $netFromSchedule = (float) $emp['default_amount'];
                    $grossAmountFc = $netFromSchedule; // Approximate: use net as gross if no template
                    $taxFc = 0.0;
                    $currency = $emp['default_currency'] ?? 'USD';
                } else {
                    $summary['skipped']++;
                    $summary['details'][] = [
                        'payee_id' => $payeeId,
                        'employee_name' => $emp['employee_name'],
                        'status' => 'skipped',
                        'reason' => 'No salary history or recurring schedule configured',
                    ];
                    continue;
                }

                // Resolve exchange rate
                $exchangeRate = $this->exchangeRateService->resolveRate($currency, null);

                $grossAmountBc = round($grossAmountFc * $exchangeRate, 2);
                $taxBc = round($taxFc * $exchangeRate, 2);
                $netAmountFc = round($grossAmountFc - $taxFc, 2);
                $netAmountBc = round($grossAmountBc - $taxBc, 2);

                $salaryId = $this->repo->create([
                    'business_id'    => $ctx->business_id,
                    'payee_id'       => $payeeId,
                    'period_start'   => $periodStart,
                    'period_end'     => $periodEnd,
                    'currency'       => $currency,
                    'exchange_rate'  => $exchangeRate,
                    'gross_amount_fc' => $grossAmountFc,
                    'gross_amount_bc' => $grossAmountBc,
                    'tax_fc'         => $taxFc,
                    'tax_bc'         => $taxBc,
                    'net_amount_fc'  => $netAmountFc,
                    'net_amount_bc'  => $netAmountBc,
                    'notes'          => 'Batch payroll - ' . $periodStart . ' to ' . $periodEnd,
                ]);

                // Decrease payee balance
                $this->peopleRepo->updatePayeeBalance($payeeId, -$netAmountBc);

                $summary['created']++;
                $summary['details'][] = [
                    'payee_id' => $payeeId,
                    'employee_name' => $emp['employee_name'],
                    'salary_id' => $salaryId,
                    'status' => 'created',
                    'gross_amount_fc' => $grossAmountFc,
                    'currency' => $currency,
                ];
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $summary;
    }

    /* =====================================================================
     * Private helpers
     * ===================================================================== */

    /**
     * Validate that there's no period overlap for the same employee.
     *
     * @param int    $payeeId     Employee payee ID
     * @param int    $businessId  Business ID
     * @param string $periodStart Start of the new period (Y-m-d)
     * @param string $periodEnd   End of the new period (Y-m-d)
     * @param int|null $excludeId Salary ID to exclude (for updates)
     * @throws \InvalidArgumentException If an overlapping record exists
     */
    private function checkPeriodOverlap(int $payeeId, int $businessId, string $periodStart, string $periodEnd, ?int $excludeId = null): void {
        $sql = "
            SELECT COUNT(*) FROM salaries
            WHERE payee_id = :payee_id
              AND business_id = :business_id
              AND period_start <= :period_end
              AND period_end >= :period_start
        ";
        $params = [
            'payee_id'     => $payeeId,
            'business_id'  => $businessId,
            'period_start' => $periodStart,
            'period_end'   => $periodEnd,
        ];

        if ($excludeId !== null) {
            $sql .= " AND id != :exclude_id";
            $params['exclude_id'] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $count = (int) $stmt->fetchColumn();

        if ($count > 0) {
            throw new \InvalidArgumentException('A salary record already exists for this employee that overlaps the specified period');
        }
    }

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

        // Gross amount must be numeric and positive
        if (!is_numeric($data['gross_amount_fc']) || (float) $data['gross_amount_fc'] <= 0) {
            throw new \InvalidArgumentException('Gross amount must be greater than zero');
        }

        // Tax must be numeric and non-negative (if provided)
        if (isset($data['tax_fc']) && $data['tax_fc'] !== '') {
            if (!is_numeric($data['tax_fc']) || (float) $data['tax_fc'] < 0) {
                throw new \InvalidArgumentException('Tax amount must not be negative');
            }
        }

        // Exchange rate must be positive (if provided)
        if (isset($data['exchange_rate']) && $data['exchange_rate'] !== '') {
            if (!is_numeric($data['exchange_rate']) || (float) $data['exchange_rate'] <= 0) {
                throw new \InvalidArgumentException('Exchange rate must be positive');
            }
        }

        // Validate period_start date format
        $this->validateDate($data['period_start'], 'period_start');

        // Validate period_end date format
        $this->validateDate($data['period_end'], 'period_end');

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
     * Validate that a date string matches YYYY-MM-DD format and represents a valid calendar date.
     *
     * @throws \InvalidArgumentException if the date is malformed or invalid
     */
    private function validateDate(string $date, string $fieldName): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \InvalidArgumentException("Invalid date format for {$fieldName}. Expected YYYY-MM-DD");
        }

        $parts = explode('-', $date);
        $year  = (int) $parts[0];
        $month = (int) $parts[1];
        $day   = (int) $parts[2];

        if (!checkdate($month, $day, $year)) {
            throw new \InvalidArgumentException("Invalid date format for {$fieldName}. Expected YYYY-MM-DD");
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
