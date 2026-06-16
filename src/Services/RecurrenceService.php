<?php

namespace App\Services;

use App\Repository\RecurrenceScheduleRepository;
use App\Repository\RecurrenceInstanceRepository;
use App\Repository\RecurrenceAuditLogRepository;
use App\Repository\BulkAdvancePaymentRepository;
use App\Repository\PrepaidAssetRepository;
use App\Repository\ExpenseRepository;
use App\Repository\PeopleRepository;
use App\Repository\AccountRepository;
use App\Repository\PaymentRepository;
use App\Repository\PaymentAllocationRepository;
use App\Support\AuthContext;
use PDO;

class RecurrenceService
{
    private PDO $db;
    private RecurrenceScheduleRepository $scheduleRepo;
    private RecurrenceInstanceRepository $instanceRepo;
    private RecurrenceAuditLogRepository $auditRepo;
    private BulkAdvancePaymentRepository $bulkAdvanceRepo;
    private PrepaidAssetRepository $prepaidAssetRepo;
    private ExpenseRepository $expenseRepo;
    private PeopleRepository $peopleRepo;
    private AccountRepository $accountRepo;
    private PaymentRepository $paymentRepo;
    private PaymentAllocationRepository $paymentAllocationRepo;
    private ExchangeRateService $exchangeRateService;
    private ScheduleGeneratorService $generatorService;
    private NotificationService $notificationService;
    private AccountingEntryService $accountingEntryService;

    private const VALID_ENTITY_TYPES = ['EXPENSE', 'SALARY'];
    private const VALID_FREQUENCIES = ['weekly', 'bi-weekly', 'monthly', 'quarterly', 'yearly'];

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->scheduleRepo = new RecurrenceScheduleRepository($db);
        $this->instanceRepo = new RecurrenceInstanceRepository($db);
        $this->auditRepo = new RecurrenceAuditLogRepository($db);
        $this->bulkAdvanceRepo = new BulkAdvancePaymentRepository($db);
        $this->prepaidAssetRepo = new PrepaidAssetRepository($db);
        $this->expenseRepo = new ExpenseRepository($db);
        $this->peopleRepo = new PeopleRepository($db);
        $this->accountRepo = new AccountRepository($db);
        $this->paymentRepo = new PaymentRepository($db);
        $this->paymentAllocationRepo = new PaymentAllocationRepository($db);
        $this->exchangeRateService = new ExchangeRateService($db);
        $this->generatorService = new ScheduleGeneratorService();
        $this->notificationService = new NotificationService($db);
        $this->accountingEntryService = new AccountingEntryService($db);
    }

    /**
     * Create a new recurrence schedule for a given entity.
     *
     * Validates:
     * - Entity type is valid
     * - Entity exists and meets type constraints (is_fixed for categories, Employee for salaries)
     * - Date validation (end_date >= start_date when provided)
     * - No duplicate active schedule for the same entity
     *
     * @param string $entityType 'expense_category' or 'salary'
     * @param int    $entityId   The entity ID (category ID or payee/people ID)
     * @param array  $data       Schedule data: frequency, default_amount, default_currency, start_date, end_date (optional)
     * @return int The created schedule ID
     * @throws \InvalidArgumentException For validation errors (400-level)
     * @throws \RuntimeException For business logic errors
     */
    public function createSchedule(string $entityType, int $entityId, array $data): int
    {
        // Validate entity type
        $this->validateEntityType($entityType);

        // Validate required fields
        $this->validateScheduleData($data);

        // Validate entity exists and meets constraints
        $this->validateEntity($entityType, $entityId);

        // Validate dates
        $this->validateDates($data);

        // Check for existing active schedule
        $existing = $this->scheduleRepo->findByEntity($entityType, $entityId);
        if ($existing) {
            throw new \RuntimeException('An active recurrence schedule already exists for this entity');
        }

        // Create the schedule
        $ctx = AuthContext::get();
        $scheduleId = $this->scheduleRepo->create([
            'entity_type'      => $entityType,
            'entity_id'        => $entityId,
            'business_id'      => $ctx->business_id,
            'frequency'        => $data['frequency'],
            'default_amount'   => $data['default_amount'],
            'default_currency' => $data['default_currency'],
            'start_date'       => $data['start_date'],
            'end_date'         => $data['end_date'] ?? null,
        ]);

        // Log audit entry
        $this->auditRepo->create([
            'entity_type'    => 'schedule',
            'entity_id'      => $scheduleId,
            'action_type'    => 'schedule_created',
            'previous_state' => null,
            'new_state'      => [
                'entity_type'      => $entityType,
                'entity_id'        => $entityId,
                'frequency'        => $data['frequency'],
                'default_amount'   => $data['default_amount'],
                'default_currency' => $data['default_currency'],
                'start_date'       => $data['start_date'],
                'end_date'         => $data['end_date'] ?? null,
                'is_active'        => 1,
            ],
        ]);

        return $scheduleId;
    }

    /**
     * Get the active recurrence schedule for a given entity.
     *
     * @param string $entityType 'expense_category' or 'salary'
     * @param int    $entityId   The entity ID
     * @return array|null The schedule data or null if not found
     */
    public function getSchedule(string $entityType, int $entityId): ?array
    {
        $this->validateEntityType($entityType);

        return $this->scheduleRepo->findByEntity($entityType, $entityId);
    }

    /**
     * Deactivate a recurrence schedule for a given entity.
     *
     * - Cancels all pending instances (preserves paid/skipped)
     * - Sets the schedule as inactive
     * - Logs audit entry
     *
     * @param string $entityType 'expense_category' or 'salary'
     * @param int    $entityId   The entity ID
     * @throws \RuntimeException If no active schedule found
     */
    public function deactivateSchedule(string $entityType, int $entityId): void
    {
        $this->validateEntityType($entityType);

        $schedule = $this->scheduleRepo->findByEntity($entityType, $entityId);
        if (!$schedule) {
            throw new \RuntimeException('Recurrence schedule not found');
        }

        $this->db->beginTransaction();
        try {
            // Cancel all pending instances for this schedule
            $pendingInstances = $this->instanceRepo->findBySchedule((int) $schedule['id'], ['status' => 'pending']);
            foreach ($pendingInstances as $instance) {
                $this->instanceRepo->updateStatus((int) $instance['id'], [
                    'status' => 'skipped',
                ]);
            }

            // Deactivate the schedule
            $this->scheduleRepo->deactivate((int) $schedule['id']);

            // Log audit entry
            $this->auditRepo->create([
                'entity_type'    => 'schedule',
                'entity_id'      => (int) $schedule['id'],
                'action_type'    => 'schedule_deactivated',
                'previous_state' => [
                    'is_active'        => 1,
                    'entity_type'      => $schedule['entity_type'],
                    'entity_id'        => (int) $schedule['entity_id'],
                    'frequency'        => $schedule['frequency'],
                    'default_amount'   => $schedule['default_amount'],
                    'default_currency' => $schedule['default_currency'],
                    'start_date'       => $schedule['start_date'],
                    'end_date'         => $schedule['end_date'],
                ],
                'new_state' => [
                    'is_active' => 0,
                ],
                'metadata' => [
                    'pending_instances_cancelled' => count($pendingInstances),
                ],
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Update an active recurrence schedule for a given entity.
     *
     * Applies changes to future instances only — preserves past (paid/skipped/overdue) instances unchanged.
     * When amount changes: uses new amount for future pending instances, preserves advance-paid instances.
     * When end_date is set: stops future generation, cancels pending instances beyond end_date.
     * Logs audit entry with previous and new state.
     *
     * All operations are wrapped in a single transaction for atomicity.
     *
     * @param string $entityType 'expense_category' or 'salary'
     * @param int    $entityId   The entity ID (category ID or payee/people ID)
     * @param array  $data       Fields to update: frequency, default_amount, default_currency, start_date, end_date
     * @throws \InvalidArgumentException For validation errors
     * @throws \RuntimeException If no active schedule found
     */
    public function updateSchedule(string $entityType, int $entityId, array $data): void
    {
        $this->validateEntityType($entityType);

        // Find the active schedule for this entity
        $schedule = $this->scheduleRepo->findByEntity($entityType, $entityId);
        if (!$schedule) {
            throw new \RuntimeException('Recurrence schedule not found');
        }

        $scheduleId = (int) $schedule['id'];

        // Validate frequency if provided
        if (!empty($data['frequency']) && !in_array($data['frequency'], self::VALID_FREQUENCIES, true)) {
            throw new \InvalidArgumentException(
                'Invalid frequency. Must be one of: ' . implode(', ', self::VALID_FREQUENCIES)
            );
        }

        // Validate dates if provided
        $startDate = $data['start_date'] ?? $schedule['start_date'];
        $endDate = array_key_exists('end_date', $data) ? $data['end_date'] : $schedule['end_date'];

        if (!empty($startDate)) {
            $parsedStart = \DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
            if (!$parsedStart || $parsedStart->format('Y-m-d') !== $startDate) {
                throw new \InvalidArgumentException("Invalid start_date format. Expected Y-m-d.");
            }
        }

        if (!empty($endDate)) {
            $parsedEnd = \DateTimeImmutable::createFromFormat('Y-m-d', $endDate);
            if (!$parsedEnd || $parsedEnd->format('Y-m-d') !== $endDate) {
                throw new \InvalidArgumentException("Invalid end_date format. Expected Y-m-d.");
            }

            $effectiveStart = $data['start_date'] ?? $schedule['start_date'];
            $parsedEffectiveStart = \DateTimeImmutable::createFromFormat('Y-m-d', $effectiveStart);
            if ($parsedEnd < $parsedEffectiveStart) {
                throw new \InvalidArgumentException('End date must be on or after start date');
            }
        }

        // Store previous state for audit
        $previousState = [
            'frequency'        => $schedule['frequency'],
            'default_amount'   => $schedule['default_amount'],
            'default_currency' => $schedule['default_currency'],
            'start_date'       => $schedule['start_date'],
            'end_date'         => $schedule['end_date'],
        ];

        // Build the update payload (only include fields that are provided)
        $updatePayload = [];
        if (isset($data['frequency'])) {
            $updatePayload['frequency'] = $data['frequency'];
        }
        if (isset($data['default_amount'])) {
            $updatePayload['default_amount'] = $data['default_amount'];
        }
        if (isset($data['default_currency'])) {
            $updatePayload['default_currency'] = $data['default_currency'];
        }
        if (isset($data['start_date'])) {
            $updatePayload['start_date'] = $data['start_date'];
        }
        if (array_key_exists('end_date', $data)) {
            $updatePayload['end_date'] = $data['end_date'];
        }

        if (empty($updatePayload)) {
            return; // Nothing to update
        }

        $this->db->beginTransaction();
        try {
            // 1. Update the schedule record
            $this->scheduleRepo->update($scheduleId, $updatePayload);

            // 2. If amount changed: update scheduled_amount on future pending instances only
            //    Preserve advance-paid instances unchanged (is_advance_payment = 1)
            if (isset($data['default_amount'])) {
                $newAmount = (float) $data['default_amount'];
                $oldAmount = (float) $schedule['default_amount'];

                if ($newAmount !== $oldAmount) {
                    $today = date('Y-m-d');

                    // Get all future pending instances for this schedule
                    $pendingInstances = $this->instanceRepo->findBySchedule($scheduleId, [
                        'status'    => 'pending',
                        'date_from' => $today,
                    ]);

                    foreach ($pendingInstances as $instance) {
                        // Skip advance-paid instances (they should never be pending, but guard anyway)
                        if (!empty($instance['is_advance_payment'])) {
                            continue;
                        }

                        // Update scheduled_amount for future pending instances
                        $this->instanceRepo->updateStatus((int) $instance['id'], [
                            'scheduled_amount' => $newAmount,
                        ]);
                    }
                }
            }

            // 3. If end_date set/changed: cancel pending instances with due_date > end_date
            $newEndDate = array_key_exists('end_date', $data) ? $data['end_date'] : null;
            if ($newEndDate !== null && !empty($newEndDate)) {
                // Find pending instances beyond the new end_date
                $allPendingInstances = $this->instanceRepo->findBySchedule($scheduleId, [
                    'status' => 'pending',
                ]);

                foreach ($allPendingInstances as $instance) {
                    if ($instance['due_date'] > $newEndDate) {
                        // Cancel (skip) pending instances beyond end_date
                        $this->instanceRepo->updateStatus((int) $instance['id'], [
                            'status' => 'skipped',
                        ]);
                    }
                }
            }

            // 4. Log audit entry with previous and new state
            $newState = [];
            foreach ($updatePayload as $key => $value) {
                $newState[$key] = $value;
            }

            $this->auditRepo->create([
                'entity_type'    => 'schedule',
                'entity_id'      => $scheduleId,
                'action_type'    => 'schedule_modified',
                'previous_state' => $previousState,
                'new_state'      => $newState,
                'metadata'       => [
                    'entity_type' => $entityType,
                    'entity_id'   => $entityId,
                ],
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * List all active recurrence schedules for the current business.
     *
     * @param array $filters Supported keys: entity_type
     * @return array List of schedules
     */
    public function listSchedules(array $filters = []): array
    {
        return $this->scheduleRepo->findActiveByBusiness($filters);
    }

    // ─── Instance Status Management ─────────────────────────────────────────

    /**
     * Update the status of a recurrence instance.
     *
     * When status = 'paid':
     *   - Validates instance is in 'pending' or 'overdue' status
     *   - Requires paymentData['account_id'] (source account)
     *   - Creates a Payment_Record via PaymentRepository
     *   - Deducts amount from source account balance
     *   - Updates instance with paid status, paid_date, actual_amount, payment_record_id
     *   - Logs audit entry
     *
     * When status = 'skipped':
     *   - Validates instance is in 'pending' status
     *   - Updates status to 'skipped'
     *   - Logs audit entry
     *
     * Payment operations are wrapped in a transaction.
     *
     * @param int    $instanceId  The instance ID to update
     * @param string $status      Target status: 'paid' or 'skipped'
     * @param array  $paymentData Optional payment data (required for 'paid'):
     *                            account_id (required), amount (optional override),
     *                            method (optional, defaults to 'bank_transfer'),
     *                            exchange_rate (optional)
     * @throws \InvalidArgumentException For validation errors
     * @throws \RuntimeException For business logic errors
     */
    public function updateInstanceStatus(int $instanceId, string $status, array $paymentData = []): void
    {
        // Find the instance
        $instance = $this->instanceRepo->findById($instanceId);
        if (!$instance) {
            throw new \RuntimeException('Recurring instance not found');
        }

        if ($status === 'paid') {
            $this->markInstancePaid($instance, $paymentData);
        } elseif ($status === 'skipped') {
            $this->markInstanceSkipped($instance);
        } else {
            throw new \InvalidArgumentException("Invalid status transition. Must be 'paid' or 'skipped'.");
        }
    }

    /**
     * Mark an instance as paid: create payment record, deduct from account, update instance.
     *
     * @param array $instance    The instance record
     * @param array $paymentData Payment details: account_id (required), amount, method, exchange_rate
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    private function markInstancePaid(array $instance, array $paymentData): void
    {
        $instanceId = (int) $instance['id'];
        $currentStatus = $instance['status'];

        // Validate current status allows payment
        if (!in_array($currentStatus, ['pending', 'overdue'], true)) {
            throw new \RuntimeException('Instance is already marked as paid');
        }

        // Require account_id
        if (empty($paymentData['account_id'])) {
            throw new \InvalidArgumentException('Missing field: account_id');
        }

        $accountId = (int) $paymentData['account_id'];
        $ctx = AuthContext::get();

        // Validate the source account exists and belongs to this business
        $account = $this->accountRepo->findById($accountId, $ctx->business_id);
        if (!$account) {
            throw new \RuntimeException('Account not found');
        }

        // Determine actual amount: use paymentData override or scheduled_amount
        $actualAmount = isset($paymentData['amount']) && $paymentData['amount'] !== ''
            ? (float) $paymentData['amount']
            : (float) $instance['scheduled_amount'];

        $currency = $instance['currency'];
        $method = $paymentData['method'] ?? 'bank_transfer';

        // Currency validation: ensure instance currency matches account currency
        $txCurrency = strtolower(trim($currency));
        $acctCurrency = strtolower($account['currency']);
        if ($txCurrency !== $acctCurrency) {
            throw new \InvalidArgumentException(
                "Currency mismatch: schedule currency is '{$txCurrency}' but account currency is '{$acctCurrency}'"
            );
        }

        // Resolve exchange rate for base currency conversion
        $providedRate = isset($paymentData['exchange_rate']) && $paymentData['exchange_rate'] !== ''
            ? (float) $paymentData['exchange_rate']
            : null;
        $exchangeRate = $this->exchangeRateService->resolveRate($currency, $providedRate);
        $amountBc = round($actualAmount * $exchangeRate, 2);

        // Resolve entity name for the payment record
        $entityName = $this->resolveEntityName(
            $instance['entity_type'],
            (int) $instance['recurrence_schedule_id']
        );

        // Determine allocation reference type based on entity type
        $allocationReferenceType = $instance['entity_type'] === 'SALARY'
            ? 'RECURRING_SALARY'
            : 'RECURRING_EXPENSE';

        // Generate a unique payment number
        $paymentNumber = $this->generatePaymentNumber(
            $instance['entity_type'] === 'SALARY' ? 'recurring_salary' : 'recurring_expense'
        );

        // Resolve payee_id from the source entity
        $schedule = $this->scheduleRepo->findById((int) $instance['recurrence_schedule_id']);
        if ($instance['entity_type'] === 'SALARY') {
            // For salary: entity_id IS the payee_id
            $payeeId = (int) $schedule['entity_id'];
        } else {
            // For expense: look up the source expense's payee_id
            $sourceExpense = $this->expenseRepo->findById((int) $schedule['entity_id'], $ctx->business_id);
            $payeeId = $sourceExpense ? (int) $sourceExpense['payee_id'] : null;
        }

        $today = date('Y-m-d');

        $this->db->beginTransaction();
        try {
            // Create Payment Slip (header)
            $paymentRecordId = $this->paymentRepo->create([
                'business_id'    => $ctx->business_id,
                'account_id'     => $accountId,
                'payee_id'       => $payeeId,
                'payment_number' => $paymentNumber,
                'payment_date'   => $today,
                'currency'       => $currency,
                'exchange_rate'  => $exchangeRate,
                'amount_fc'      => $actualAmount,
                'amount_bc'      => $amountBc,
                'method'         => $method,
                'notes'          => "Recurring payment: {$entityName}",
                'created_by'     => $ctx->user_id,
            ]);

            // Create payment allocation (detail row linking slip to instance)
            $this->paymentAllocationRepo->createBatch($paymentRecordId, [[
                'reference_type' => $allocationReferenceType,
                'reference_id'   => $instanceId,
                'amount_fc'      => $actualAmount,
                'amount_bc'      => $amountBc,
            ]]);

            // Deduct from source account balance (negative delta)
            $this->accountRepo->updateBalance($accountId, -$amountBc, $this->db, -$actualAmount);

            // Update instance status
            $this->instanceRepo->updateStatus($instanceId, [
                'status'            => 'paid',
                'paid_date'         => $today,
                'actual_amount'     => $actualAmount,
                'payment_record_id' => $paymentRecordId,
            ]);

            // Log audit entry
            $this->auditRepo->create([
                'entity_type'    => 'instance',
                'entity_id'      => $instanceId,
                'action_type'    => 'status_changed',
                'previous_state' => [
                    'status'        => $currentStatus,
                    'actual_amount' => $instance['actual_amount'],
                    'paid_date'     => $instance['paid_date'],
                ],
                'new_state' => [
                    'status'            => 'paid',
                    'actual_amount'     => $actualAmount,
                    'paid_date'         => $today,
                    'payment_record_id' => $paymentRecordId,
                ],
                'metadata' => [
                    'account_id'       => $accountId,
                    'payment_number'   => $paymentNumber,
                    'scheduled_amount' => (float) $instance['scheduled_amount'],
                    'exchange_rate'    => $exchangeRate,
                    'method'           => $method,
                ],
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Mark an instance as skipped: update status and log audit.
     *
     * @param array $instance The instance record
     * @throws \RuntimeException If instance is not in 'pending' status
     */
    private function markInstanceSkipped(array $instance): void
    {
        $instanceId = (int) $instance['id'];
        $currentStatus = $instance['status'];

        // Only pending instances can be skipped
        if ($currentStatus !== 'pending') {
            throw new \RuntimeException(
                "Cannot transition from '{$currentStatus}' to 'skipped'"
            );
        }

        // Update instance status
        $this->instanceRepo->updateStatus($instanceId, [
            'status' => 'skipped',
        ]);

        // Log audit entry
        $this->auditRepo->create([
            'entity_type'    => 'instance',
            'entity_id'      => $instanceId,
            'action_type'    => 'status_changed',
            'previous_state' => [
                'status' => $currentStatus,
            ],
            'new_state' => [
                'status' => 'skipped',
            ],
            'metadata' => [
                'reason' => 'user_skipped',
            ],
        ]);
    }

    /**
     * Pay a recurring instance early (before its due date).
     *
     * Validates:
     * - Instance exists and belongs to the current business
     * - Instance status is 'pending'
     * - Instance due_date is in the future (> today)
     *
     * Creates a Payment_Record, deducts from source account, marks instance as paid
     * with is_advance_payment = true, and logs an audit entry.
     *
     * @param int   $instanceId  The instance ID to pay early
     * @param array $paymentData Payment details:
     *                           - account_id (required): source account
     *                           - amount (optional): override amount (defaults to scheduled_amount)
     *                           - method (optional): payment method (defaults to 'bank_transfer')
     *                           - exchange_rate (optional): override exchange rate
     * @throws \InvalidArgumentException For validation errors
     * @throws \RuntimeException For business logic errors
     */
    public function payEarly(int $instanceId, array $paymentData): void
    {
        // Find the instance
        $instance = $this->instanceRepo->findById($instanceId);
        if (!$instance) {
            throw new \RuntimeException('Recurring instance not found');
        }

        // Validate: status must be 'pending'
        if ($instance['status'] !== 'pending') {
            throw new \RuntimeException('Instance is already marked as paid');
        }

        // Validate: due_date must be in the future (> today)
        $today = date('Y-m-d');
        if ($instance['due_date'] <= $today) {
            throw new \InvalidArgumentException(
                'Early payment is only allowed for instances with a future due date'
            );
        }

        // Require account_id
        if (empty($paymentData['account_id'])) {
            throw new \InvalidArgumentException('Missing field: account_id');
        }

        $accountId = (int) $paymentData['account_id'];
        $ctx = AuthContext::get();

        // Validate the source account exists and belongs to this business
        $account = $this->accountRepo->findById($accountId, $ctx->business_id);
        if (!$account) {
            throw new \RuntimeException('Account not found');
        }

        // Determine actual amount: use paymentData override or scheduled_amount
        $actualAmount = isset($paymentData['amount']) && $paymentData['amount'] !== ''
            ? (float) $paymentData['amount']
            : (float) $instance['scheduled_amount'];

        $currency = $instance['currency'];
        $method = $paymentData['method'] ?? 'bank_transfer';

        // Currency validation: ensure instance currency matches account currency
        $txCurrency = strtolower(trim($currency));
        $acctCurrency = strtolower($account['currency']);
        if ($txCurrency !== $acctCurrency) {
            throw new \InvalidArgumentException(
                "Currency mismatch: schedule currency is '{$txCurrency}' but account currency is '{$acctCurrency}'"
            );
        }

        // Resolve exchange rate for base currency conversion
        $providedRate = isset($paymentData['exchange_rate']) && $paymentData['exchange_rate'] !== ''
            ? (float) $paymentData['exchange_rate']
            : null;
        $exchangeRate = $this->exchangeRateService->resolveRate($currency, $providedRate);
        $amountBc = round($actualAmount * $exchangeRate, 2);

        // Resolve entity name for the payment record
        $entityName = $this->resolveEntityName(
            $instance['entity_type'],
            (int) $instance['recurrence_schedule_id']
        );

        // Determine reference type based on entity type
        $referenceType = $instance['entity_type'] === 'SALARY'
            ? 'recurring_salary'
            : 'recurring_expense';

        // Generate a unique payment number
        $paymentNumber = $this->generatePaymentNumber($referenceType);

        // Resolve payee_id from the source entity
        $schedule = $this->scheduleRepo->findById((int) $instance['recurrence_schedule_id']);
        if ($instance['entity_type'] === 'SALARY') {
            // For salary: entity_id IS the payee_id directly
            $payeeId = (int) $schedule['entity_id'];
        } else {
            // For expense: query payee_id from the expenses table
            $stmt = $this->db->prepare('SELECT payee_id FROM expenses WHERE id = :id');
            $stmt->execute(['id' => (int) $schedule['entity_id']]);
            $payeeId = $stmt->fetchColumn() ?: null;
            $payeeId = $payeeId !== null ? (int) $payeeId : null;
        }

        $this->db->beginTransaction();
        try {
            // Create Payment Slip (header — no reference_type/reference_id on slips)
            $paymentRecordId = $this->paymentRepo->create([
                'business_id'    => $ctx->business_id,
                'account_id'     => $accountId,
                'payee_id'       => $payeeId,
                'payment_number' => $paymentNumber,
                'payment_date'   => $today,
                'currency'       => $currency,
                'exchange_rate'  => $exchangeRate,
                'amount_fc'      => $actualAmount,
                'amount_bc'      => $amountBc,
                'method'         => $method,
                'notes'          => "Early payment: {$entityName} (due {$instance['due_date']})",
                'created_by'     => $ctx->user_id,
            ]);

            // Create payment allocation (detail row linking slip to instance)
            $allocationReferenceType = $instance['entity_type'] === 'SALARY'
                ? 'RECURRING_SALARY'
                : 'RECURRING_EXPENSE';
            $this->paymentAllocationRepo->createBatch($paymentRecordId, [[
                'reference_type' => $allocationReferenceType,
                'reference_id'   => $instanceId,
                'amount_fc'      => $actualAmount,
                'amount_bc'      => $amountBc,
            ]]);

            // Deduct from source account balance (negative delta)
            $this->accountRepo->updateBalance($accountId, -$amountBc, $this->db, -$actualAmount);

            // Update instance: status='paid', paid_date=today, actual_amount, payment_record_id, is_advance_payment=1
            $this->instanceRepo->updateStatus($instanceId, [
                'status'             => 'paid',
                'paid_date'          => $today,
                'actual_amount'      => $actualAmount,
                'payment_record_id'  => $paymentRecordId,
                'is_advance_payment' => 1,
            ]);

            // Log audit entry with early payment metadata
            $this->auditRepo->create([
                'entity_type'    => 'instance',
                'entity_id'      => $instanceId,
                'action_type'    => 'status_changed',
                'previous_state' => [
                    'status'             => 'pending',
                    'actual_amount'      => $instance['actual_amount'],
                    'paid_date'          => $instance['paid_date'],
                    'is_advance_payment' => (int) $instance['is_advance_payment'],
                ],
                'new_state' => [
                    'status'             => 'paid',
                    'actual_amount'      => $actualAmount,
                    'paid_date'          => $today,
                    'payment_record_id'  => $paymentRecordId,
                    'is_advance_payment' => 1,
                ],
                'metadata' => [
                    'account_id'        => $accountId,
                    'payment_number'    => $paymentNumber,
                    'scheduled_amount'  => (float) $instance['scheduled_amount'],
                    'exchange_rate'     => $exchangeRate,
                    'method'            => $method,
                    'is_early_payment'  => true,
                    'due_date'          => $instance['due_date'],
                    'days_early'        => (int) (new \DateTimeImmutable($instance['due_date']))->diff(new \DateTimeImmutable($today))->days,
                ],
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Execute a bulk advance payment for multiple future periods of a recurrence schedule.
     *
     * Validates:
     * - periods is between 2 and 12
     * - schedule exists and is active
     * - respects end_date limit (adjusts periods count if needed)
     *
     * For each future period:
     * - Calculates the due date using ScheduleGeneratorService
     * - Creates the instance if it doesn't already exist
     * - Marks all instances as paid with is_advance_payment = true
     *
     * Creates a single BulkAdvancePayment record and a single Payment_Record for the total.
     * Links all instances to the BAP record via bulk_advance_payment_id.
     * Logs an audit entry for the bulk operation.
     *
     * @param int   $scheduleId  The recurrence schedule ID
     * @param int   $periods     Number of future periods to pay (2-12)
     * @param array $paymentData Payment details:
     *                           - account_id (required): source account
     *                           - payment_method (optional): defaults to 'bank_transfer'
     *                           - exchange_rate (optional): override exchange rate
     * @return array Summary: ['bulk_advance_payment_id', 'periods_paid', 'total_amount', 'total_amount_bc', 'instance_ids']
     * @throws \InvalidArgumentException For validation errors
     * @throws \RuntimeException For business logic errors
     */
    public function bulkAdvancePayment(int $scheduleId, int $periods, array $paymentData): array
    {
        // Validate periods range
        if ($periods < 2 || $periods > 12) {
            throw new \InvalidArgumentException('Number of periods must be between 2 and 12');
        }

        // Validate account_id is provided
        if (empty($paymentData['account_id'])) {
            throw new \InvalidArgumentException('Missing field: account_id');
        }

        // Find the schedule
        $schedule = $this->scheduleRepo->findById($scheduleId);
        if (!$schedule) {
            throw new \RuntimeException('Recurrence schedule not found');
        }

        // Validate schedule is active
        if (empty($schedule['is_active'])) {
            throw new \RuntimeException('Cannot create bulk advance payment on an inactive schedule');
        }

        $ctx = AuthContext::get();
        $accountId = (int) $paymentData['account_id'];

        // Validate the source account exists and belongs to this business
        $account = $this->accountRepo->findById($accountId, $ctx->business_id);
        if (!$account) {
            throw new \RuntimeException('Account not found');
        }

        $currency = $schedule['default_currency'];
        $perPeriodAmount = (float) $schedule['default_amount'];
        $method = $paymentData['payment_method'] ?? 'bank_transfer';

        // Currency validation: ensure schedule currency matches account currency
        $txCurrency = strtolower(trim($currency));
        $acctCurrency = strtolower($account['currency']);
        if ($txCurrency !== $acctCurrency) {
            throw new \InvalidArgumentException(
                "Currency mismatch: schedule currency is '{$txCurrency}' but account currency is '{$acctCurrency}'"
            );
        }

        // Calculate future due dates starting from the last generated instance or today
        $today = date('Y-m-d');
        $frequency = $schedule['frequency'];
        $startDate = $schedule['start_date'];
        $endDate = $schedule['end_date'];

        // Find the last instance for this schedule to determine where to start generating
        $existingInstances = $this->instanceRepo->findBySchedule($scheduleId);
        $lastDueDate = $today;
        if (!empty($existingInstances)) {
            // Get the latest due_date from existing instances
            $latestInstance = end($existingInstances); // sorted ASC by due_date
            if ($latestInstance['due_date'] >= $today) {
                $lastDueDate = $latestInstance['due_date'];
            }
        }

        // Generate future due dates for the requested number of periods
        // Start from the next due date after the last known date
        $futureDueDates = [];
        $currentDate = $lastDueDate;

        // We need to find the next N due dates that are in the future (> today)
        // and don't already have a paid instance
        $originalDay = (int) (new \DateTimeImmutable($startDate))->format('j');
        $candidateDate = $today;

        // Calculate all due dates from start_date, find those > today
        $allDueDates = $this->generatorService->calculateDueDatesInRange(
            $frequency,
            $startDate,
            // Calculate a generous upper bound
            (new \DateTimeImmutable($today))->modify('+5 years')->format('Y-m-d')
        );

        // Filter to only future dates (> today) that are not already paid
        $futureCandidates = [];
        foreach ($allDueDates as $dueDate) {
            if ($dueDate <= $today) {
                continue;
            }

            // Respect end_date
            if (!empty($endDate) && $dueDate > $endDate) {
                break;
            }

            $futureCandidates[] = $dueDate;

            // Stop once we have enough candidates
            if (count($futureCandidates) >= $periods) {
                break;
            }
        }

        // If end_date limits the number of periods, adjust
        $actualPeriods = count($futureCandidates);
        if ($actualPeriods === 0) {
            throw new \RuntimeException('No future periods available for advance payment');
        }

        // Resolve exchange rate
        $providedRate = isset($paymentData['exchange_rate']) && $paymentData['exchange_rate'] !== ''
            ? (float) $paymentData['exchange_rate']
            : null;
        $exchangeRate = $this->exchangeRateService->resolveRate($currency, $providedRate);

        // Calculate totals
        $totalAmount = round($perPeriodAmount * $actualPeriods, 2);
        $totalAmountBc = round($totalAmount * $exchangeRate, 2);

        // Resolve entity name for the payment record
        $entityName = $this->resolveEntityName(
            $schedule['entity_type'],
            $scheduleId
        );

        // Determine reference type for payment number generation
        $referenceType = $schedule['entity_type'] === 'SALARY'
            ? 'recurring_salary'
            : 'recurring_expense';

        // Resolve payee_id for the payment slip
        if ($schedule['entity_type'] === 'SALARY') {
            // For salary schedules, entity_id IS the payee_id
            $payeeId = (int) $schedule['entity_id'];
        } else {
            // For expense schedules, look up the payee_id from the source expense
            $stmt = $this->db->prepare("SELECT payee_id FROM expenses WHERE id = :id");
            $stmt->execute(['id' => (int) $schedule['entity_id']]);
            $payeeId = $stmt->fetchColumn();
            $payeeId = $payeeId ? (int) $payeeId : null;
        }

        // Generate payment number
        $paymentNumber = $this->generatePaymentNumber($referenceType);

        $this->db->beginTransaction();
        try {
            // 1. Create a single Payment_Record for the total amount
            $paymentRecordId = $this->paymentRepo->create([
                'business_id'    => $ctx->business_id,
                'account_id'     => $accountId,
                'payee_id'       => $payeeId,
                'payment_number' => $paymentNumber,
                'payment_date'   => $today,
                'currency'       => $currency,
                'exchange_rate'  => $exchangeRate,
                'amount_fc'      => $totalAmount,
                'amount_bc'      => $totalAmountBc,
                'method'         => $method,
                'notes'          => "Bulk advance payment: {$entityName} ({$actualPeriods} periods)",
                'created_by'     => $ctx->user_id,
            ]);

            // 2. Deduct total from source account balance
            $this->accountRepo->updateBalance($accountId, -$totalAmountBc, $this->db, -$totalAmount);

            // 3. Create BulkAdvancePayment record
            $bapId = $this->bulkAdvanceRepo->create([
                'recurrence_schedule_id' => $scheduleId,
                'total_amount'           => $totalAmount,
                'total_amount_bc'        => $totalAmountBc,
                'currency'               => $currency,
                'periods_count'          => $actualPeriods,
                'payment_date'           => $today,
                'payment_record_id'      => $paymentRecordId,
                'created_by'             => $ctx->user_id,
            ]);

            // 3b. Create payment allocation (single allocation for the entire bulk payment)
            $allocationReferenceType = $schedule['entity_type'] === 'SALARY'
                ? 'RECURRING_SALARY'
                : 'RECURRING_EXPENSE';
            $this->paymentAllocationRepo->createBatch($paymentRecordId, [[
                'reference_type' => $allocationReferenceType,
                'reference_id'   => $bapId,
                'amount_fc'      => $totalAmount,
                'amount_bc'      => $totalAmountBc,
            ]]);

            // 4. For each future due date: create or find instance, mark as paid
            $instanceIds = [];
            foreach ($futureCandidates as $dueDate) {
                // Check if instance already exists for this schedule + due date
                $existingForDate = $this->instanceRepo->findBySchedule($scheduleId, [
                    'date_from' => $dueDate,
                    'date_to'   => $dueDate,
                ]);

                $instanceId = null;

                if (!empty($existingForDate)) {
                    // Instance exists — use it
                    $existing = $existingForDate[0];
                    $instanceId = (int) $existing['id'];

                    // Only update if it's pending (don't overwrite already-paid instances)
                    if ($existing['status'] === 'pending') {
                        $this->instanceRepo->updateStatus($instanceId, [
                            'status'                 => 'paid',
                            'paid_date'              => $today,
                            'actual_amount'          => $perPeriodAmount,
                            'payment_record_id'      => $paymentRecordId,
                            'is_advance_payment'     => 1,
                            'bulk_advance_payment_id' => $bapId,
                        ]);
                    }
                } else {
                    // Instance doesn't exist — create it as paid
                    $boundaries = $this->generatorService->calculatePeriodBoundaries($frequency, $dueDate);

                    $instanceId = $this->instanceRepo->create([
                        'recurrence_schedule_id'  => $scheduleId,
                        'entity_type'             => $schedule['entity_type'],
                        'due_date'                => $dueDate,
                        'scheduled_amount'        => $perPeriodAmount,
                        'actual_amount'           => $perPeriodAmount,
                        'currency'                => $currency,
                        'status'                  => 'paid',
                        'paid_date'               => $today,
                        'notification_sent'       => 1,
                        'is_advance_payment'      => 1,
                        'bulk_advance_payment_id' => $bapId,
                        'payment_record_id'       => $paymentRecordId,
                        'period_start'            => $boundaries['period_start'],
                        'period_end'              => $boundaries['period_end'],
                    ]);
                }

                $instanceIds[] = $instanceId;
            }

            // 5. Log audit entry for the bulk advance payment
            $this->auditRepo->create([
                'entity_type'    => 'schedule',
                'entity_id'      => $scheduleId,
                'action_type'    => 'bulk_advance_payment',
                'previous_state' => null,
                'new_state'      => [
                    'bulk_advance_payment_id' => $bapId,
                    'periods_count'           => $actualPeriods,
                    'total_amount'            => $totalAmount,
                    'total_amount_bc'         => $totalAmountBc,
                    'currency'                => $currency,
                    'payment_record_id'       => $paymentRecordId,
                    'instance_ids'            => $instanceIds,
                ],
                'metadata' => [
                    'account_id'      => $accountId,
                    'payment_number'  => $paymentNumber,
                    'exchange_rate'   => $exchangeRate,
                    'method'          => $method,
                    'per_period_amount' => $perPeriodAmount,
                    'requested_periods' => $periods,
                    'actual_periods'    => $actualPeriods,
                ],
            ]);

            // 6. Create Prepaid Asset via AccountingEntryService (accrual accounting)
            $perPeriodAmountBc = round($perPeriodAmount * $exchangeRate, 2);
            $prepaidAssetId = $this->accountingEntryService->createPrepaidAsset($bapId, [
                'total_amount'         => $totalAmount,
                'total_amount_bc'      => $totalAmountBc,
                'per_period_amount'    => $perPeriodAmount,
                'per_period_amount_bc' => $perPeriodAmountBc,
                'currency'             => $currency,
                'periods_total'        => $actualPeriods,
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return [
            'bulk_advance_payment_id' => $bapId,
            'periods_paid'            => $actualPeriods,
            'total_amount'            => $totalAmount,
            'total_amount_bc'         => $totalAmountBc,
            'currency'                => $currency,
            'payment_record_id'       => $paymentRecordId,
            'prepaid_asset_id'        => $prepaidAssetId,
            'instance_ids'            => $instanceIds,
        ];
    }

    /**
     * Generate a unique payment number for recurring payments.
     *
     * Format: REC-{TYPE_PREFIX}-{YYYYMMDD}-{RANDOM}
     *
     * @param string $referenceType 'recurring_expense' or 'recurring_salary'
     * @return string
     */
    private function generatePaymentNumber(string $referenceType): string
    {
        $prefix = $referenceType === 'recurring_salary' ? 'SAL' : 'EXP';
        $date = date('Ymd');
        $random = str_pad((string) mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);

        return "REC-{$prefix}-{$date}-{$random}";
    }

    // ─── Engine Evaluation ───────────────────────────────────────────────────

    /**
     * Orchestrate the full recurrence engine evaluation cycle.
     *
     * 1. Generate due instances for all active schedules
     * 2. Transition overdue instances (pending past due_date → overdue)
     * 3. Send due notifications for upcoming instances
     *
     * @return array Summary: ['generated' => int, 'overdue' => int]
     */
    public function evaluateAll(): array
    {
        // Step 1: Generate new pending instances for due dates up to today
        $generatedInstances = $this->generateDueInstances();

        // Step 2: Transition overdue instances
        $overdueInstances = $this->transitionOverdueInstances();

        // Step 3: Send due notifications
        $this->sendDueNotifications();

        // Step 4: Perform expense recognition for prepaid assets with due periods
        $recognized = $this->performExpenseRecognition();

        return [
            'generated'  => count($generatedInstances),
            'overdue'    => count($overdueInstances),
            'recognized' => count($recognized),
        ];
    }

    /**
     * Generate pending instances for all active schedules whose due dates
     * have arrived (up to today). Idempotent — skips dates that already
     * have an instance.
     *
     * For each active schedule:
     *   1. Calculate all due dates from start_date up to today (respecting end_date)
     *   2. For each due date, check if an instance already exists
     *   3. If not, create a pending instance with period boundaries
     *
     * Wrapped in a transaction for atomicity.
     *
     * @return array List of newly created instance records
     */
    public function generateDueInstances(): array
    {
        $today = date('Y-m-d');
        $newInstances = [];

        // Get all active schedules for the current business
        $schedules = $this->scheduleRepo->findActiveByBusiness();

        $this->db->beginTransaction();
        try {
            foreach ($schedules as $schedule) {
                $scheduleId = (int) $schedule['id'];
                $frequency = $schedule['frequency'];
                $startDate = $schedule['start_date'];
                $endDate = $schedule['end_date'];

                // Determine the upper bound for generation: min(today, end_date)
                $upperBound = $today;
                if (!empty($endDate) && $endDate < $today) {
                    $upperBound = $endDate;
                }

                // Skip if start_date is in the future
                if ($startDate > $today) {
                    continue;
                }

                // Calculate all due dates from start_date up to the upper bound
                $dueDates = $this->generatorService->calculateDueDatesInRange(
                    $frequency,
                    $startDate,
                    $upperBound
                );

                foreach ($dueDates as $dueDate) {
                    // Respect end_date: don't generate past it
                    if (!empty($endDate) && $dueDate > $endDate) {
                        continue;
                    }

                    // Idempotent check: skip if instance already exists for this schedule + due date
                    if ($this->instanceRepo->existsForScheduleAndDate($scheduleId, $dueDate)) {
                        continue;
                    }

                    // Calculate period boundaries for this instance
                    $boundaries = $this->generatorService->calculatePeriodBoundaries($frequency, $dueDate);

                    // Create a new pending instance
                    $instanceId = $this->instanceRepo->create([
                        'recurrence_schedule_id' => $scheduleId,
                        'entity_type'            => $schedule['entity_type'],
                        'due_date'               => $dueDate,
                        'scheduled_amount'       => $schedule['default_amount'],
                        'currency'               => $schedule['default_currency'],
                        'status'                 => 'pending',
                        'period_start'           => $boundaries['period_start'],
                        'period_end'             => $boundaries['period_end'],
                    ]);

                    $newInstances[] = [
                        'id'                     => $instanceId,
                        'recurrence_schedule_id' => $scheduleId,
                        'entity_type'            => $schedule['entity_type'],
                        'due_date'               => $dueDate,
                        'scheduled_amount'       => $schedule['default_amount'],
                        'currency'               => $schedule['default_currency'],
                        'status'                 => 'pending',
                        'period_start'           => $boundaries['period_start'],
                        'period_end'             => $boundaries['period_end'],
                    ];
                }
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $newInstances;
    }

    /**
     * Find all pending instances with due_date < today, transition them to "overdue",
     * and log an audit entry for each transition.
     *
     * @return array The list of instances that were transitioned to overdue
     */
    public function transitionOverdueInstances(): array
    {
        $overdueInstances = $this->instanceRepo->findOverdue();
        $transitioned = [];

        foreach ($overdueInstances as $instance) {
            $instanceId = (int) $instance['id'];

            // Update status to overdue
            $this->instanceRepo->updateStatus($instanceId, [
                'status' => 'overdue',
            ]);

            // Log audit entry for the transition
            $this->auditRepo->create([
                'entity_type'    => 'instance',
                'entity_id'      => $instanceId,
                'action_type'    => 'status_changed',
                'previous_state' => [
                    'status' => 'pending',
                ],
                'new_state' => [
                    'status' => 'overdue',
                ],
                'metadata' => [
                    'due_date'    => $instance['due_date'],
                    'entity_type' => $instance['entity_type'],
                    'reason'      => 'automatic_overdue_transition',
                ],
            ]);

            $transitioned[] = $instance;
        }

        return $transitioned;
    }

    /**
     * Find pending instances due within 3 days where notification_sent = false,
     * and create notifications via NotificationService.
     *
     * Notification type is 'EXPENSE_DUE' for expense_category entities
     * and 'SALARY_DUE' for salary entities.
     */
    public function sendDueNotifications(): void
    {
        $instances = $this->instanceRepo->findDueForNotification(3);

        foreach ($instances as $instance) {
            $entityName = $this->resolveEntityName(
                $instance['entity_type'],
                (int) $instance['recurrence_schedule_id']
            );

            $notificationType = $instance['entity_type'] === 'SALARY' ? 'SALARY_DUE' : 'EXPENSE_DUE';
            $title = $instance['entity_type'] === 'SALARY'
                ? 'Salary Payment Due'
                : 'Expense Payment Due';

            $body = sprintf(
                '%s — %s %s due on %s',
                $entityName,
                number_format((float) $instance['scheduled_amount'], 2),
                $instance['currency'],
                $instance['due_date']
            );

            // Get all user IDs in the current business to notify
            $recipientIds = $this->getBusinessUserIds();

            if (!empty($recipientIds)) {
                $this->notificationService->notify(
                    $notificationType,
                    $title,
                    $body,
                    $recipientIds,
                    'recurrence_instance',
                    (int) $instance['id']
                );
            }

            // Mark notification as sent
            $this->instanceRepo->updateStatus((int) $instance['id'], [
                'notification_sent' => 1,
            ]);
        }
    }

    /**
     * Find all overdue instances and send daily reminder notifications for each.
     * Uses EXPENSE_OVERDUE / SALARY_OVERDUE notification types.
     * Includes entity name, due date, amount, and days_overdue in the notification body.
     */
    public function sendOverdueReminders(): void
    {
        $overdueInstances = $this->instanceRepo->findByBusinessFiltered(['status' => 'overdue']);

        foreach ($overdueInstances as $instance) {
            $entityName = $this->resolveEntityName(
                $instance['entity_type'],
                (int) $instance['recurrence_schedule_id']
            );

            $dueDate = new \DateTimeImmutable($instance['due_date']);
            $today = new \DateTimeImmutable('today');
            $daysOverdue = (int) $today->diff($dueDate)->days;

            $notificationType = $instance['entity_type'] === 'SALARY' ? 'SALARY_OVERDUE' : 'EXPENSE_OVERDUE';
            $title = $instance['entity_type'] === 'SALARY'
                ? 'Overdue Salary Reminder'
                : 'Overdue Expense Reminder';

            $body = sprintf(
                '%s — %s %s is %d day(s) overdue (was due %s)',
                $entityName,
                number_format((float) $instance['scheduled_amount'], 2),
                $instance['currency'],
                $daysOverdue,
                $instance['due_date']
            );

            $recipientIds = $this->getBusinessUserIds();

            if (!empty($recipientIds)) {
                $this->notificationService->notify(
                    $notificationType,
                    $title,
                    $body,
                    $recipientIds,
                    'recurrence_instance',
                    (int) $instance['id']
                );
            }
        }
    }

    /**
     * Perform expense recognition for prepaid assets whose periods have arrived.
     *
     * Finds all active prepaid assets, then checks their linked instances
     * (via bulk_advance_payment_id) for due dates that have arrived (due_date <= today).
     * For each unrecognized period, calls AccountingEntryService::performExpenseRecognition()
     * to generate the debit Expense / credit Prepaid_Asset accounting entry.
     *
     * @return array List of recognized entries (prepaid_asset_id + due_date pairs)
     */
    public function performExpenseRecognition(): array
    {
        $today = date('Y-m-d');
        $recognized = [];

        // Find all active prepaid assets for the current business
        $activePrepaidAssets = $this->accountingEntryService->listPrepaidAssets(['status' => 'active']);

        foreach ($activePrepaidAssets as $asset) {
            $prepaidAssetId = (int) $asset['id'];
            $bulkAdvancePaymentId = (int) $asset['bulk_advance_payment_id'];
            $periodsRecognized = (int) $asset['periods_recognized'];
            $periodsTotal = (int) $asset['periods_total'];

            // Skip if already fully amortized
            if ($periodsRecognized >= $periodsTotal) {
                continue;
            }

            // Find all instances linked to this bulk advance payment, ordered by due_date ASC
            $instances = $this->instanceRepo->findByBulkAdvancePaymentId($bulkAdvancePaymentId);

            // Count how many periods have due_date <= today (these should be recognized)
            $dueInstances = array_filter($instances, function ($inst) use ($today) {
                return $inst['due_date'] <= $today;
            });

            // The number of periods that should be recognized is the count of due instances
            $shouldBeRecognized = count($dueInstances);

            // Recognize any periods that haven't been recognized yet
            if ($shouldBeRecognized > $periodsRecognized) {
                // Get the instances that need recognition (skip already-recognized ones)
                $sortedDueInstances = array_values($dueInstances);
                $toRecognize = array_slice($sortedDueInstances, $periodsRecognized);

                foreach ($toRecognize as $instance) {
                    $this->accountingEntryService->performExpenseRecognition(
                        $prepaidAssetId,
                        $instance['due_date'],
                        (float) $asset['per_period_amount'],
                        (float) $asset['per_period_amount_bc']
                    );

                    $recognized[] = [
                        'prepaid_asset_id' => $prepaidAssetId,
                        'due_date'         => $instance['due_date'],
                        'amount'           => (float) $asset['per_period_amount'],
                        'amount_bc'        => (float) $asset['per_period_amount_bc'],
                    ];
                }
            }
        }

        return $recognized;
    }

    // ─── Payment Reversal ────────────────────────────────────────────────────

    /**
     * Reverse a bulk advance payment: revert all linked instances to pending,
     * clear payment metadata, restore account balance, reverse prepaid asset,
     * delete the payment record, and log audit entries.
     *
     * All operations are wrapped in a single transaction for atomicity.
     *
     * @param int $bulkAdvancePaymentId The bulk advance payment ID to reverse
     * @throws \RuntimeException If BAP not found or already reversed
     */
    public function reverseAdvancePayment(int $bulkAdvancePaymentId): void
    {
        $ctx = AuthContext::get();

        // Find the BulkAdvancePayment record
        $bap = $this->bulkAdvanceRepo->findById($bulkAdvancePaymentId);
        if (!$bap) {
            throw new \RuntimeException('Bulk advance payment not found');
        }

        // Find all instances linked to this BAP
        $instances = $this->instanceRepo->findByBulkAdvancePaymentId($bulkAdvancePaymentId);
        if (empty($instances)) {
            throw new \RuntimeException('No instances found for this bulk advance payment');
        }

        // Find the payment record linked to the BAP
        $paymentRecordId = $bap['payment_record_id'] ? (int) $bap['payment_record_id'] : null;
        $paymentRecord = null;
        if ($paymentRecordId) {
            $paymentRecord = $this->paymentRepo->findById($paymentRecordId, $ctx->business_id);
        }

        $this->db->beginTransaction();
        try {
            // Restore account balance from the payment record
            if ($paymentRecord) {
                $accountId = (int) $paymentRecord['account_id'];
                $amountBc = (float) $paymentRecord['amount_bc'];
                $amountFc = (float) $paymentRecord['amount_fc'];

                // Restore balance (positive delta to reverse the original deduction)
                $this->accountRepo->updateBalance($accountId, $amountBc, $this->db, $amountFc);

                // Delete payment allocations before deleting the payment record
                $this->paymentAllocationRepo->deleteBySlip($paymentRecordId);

                // Delete the payment record
                $this->paymentRepo->delete($paymentRecordId, $ctx->business_id);
            }

            // Revert each instance to pending and clear payment metadata
            foreach ($instances as $instance) {
                $instanceId = (int) $instance['id'];
                $previousState = [
                    'status'                  => $instance['status'],
                    'paid_date'               => $instance['paid_date'],
                    'actual_amount'           => $instance['actual_amount'],
                    'payment_record_id'       => $instance['payment_record_id'],
                    'is_advance_payment'      => (int) $instance['is_advance_payment'],
                    'bulk_advance_payment_id' => $instance['bulk_advance_payment_id'],
                ];

                // Revert instance: status→pending, clear payment metadata
                $this->instanceRepo->updateStatus($instanceId, [
                    'status'                  => 'pending',
                    'paid_date'               => null,
                    'actual_amount'           => null,
                    'payment_record_id'       => null,
                    'is_advance_payment'      => 0,
                    'bulk_advance_payment_id' => null,
                ]);

                // Log audit entry for each instance reversal
                $this->auditRepo->create([
                    'entity_type'    => 'instance',
                    'entity_id'      => $instanceId,
                    'action_type'    => 'status_changed',
                    'previous_state' => $previousState,
                    'new_state'      => [
                        'status'                  => 'pending',
                        'paid_date'               => null,
                        'actual_amount'           => null,
                        'payment_record_id'       => null,
                        'is_advance_payment'      => 0,
                        'bulk_advance_payment_id' => null,
                    ],
                    'metadata' => [
                        'reason'                   => 'bulk_advance_payment_reversal',
                        'bulk_advance_payment_id'  => $bulkAdvancePaymentId,
                        'reversed_by'              => $ctx->user_id,
                    ],
                ]);
            }

            // Reverse the prepaid asset if one exists for this BAP
            $linkedAsset = $this->prepaidAssetRepo->findByBulkAdvancePayment($bulkAdvancePaymentId);
            if ($linkedAsset && $linkedAsset['status'] !== 'reversed') {
                $this->accountingEntryService->reversePrepaidAsset((int) $linkedAsset['id']);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Reverse a single instance payment: revert to pending, clear payment metadata,
     * restore account balance, delete the payment record, and log audit.
     *
     * This handles the case when a Payment_Record is deleted from the Payments module
     * or when a user requests reversal of a single instance payment.
     *
     * All operations are wrapped in a single transaction for atomicity.
     *
     * @param int $instanceId The instance ID to reverse
     * @throws \RuntimeException If instance not found or not in paid status
     */
    public function reverseInstancePayment(int $instanceId): void
    {
        $ctx = AuthContext::get();

        // Find the instance
        $instance = $this->instanceRepo->findById($instanceId);
        if (!$instance) {
            throw new \RuntimeException('Recurring instance not found');
        }

        // Verify it's paid
        if ($instance['status'] !== 'paid') {
            throw new \RuntimeException(
                "Cannot reverse payment: instance status is '{$instance['status']}', expected 'paid'"
            );
        }

        $paymentRecordId = $instance['payment_record_id'] ? (int) $instance['payment_record_id'] : null;
        $paymentRecord = null;
        if ($paymentRecordId) {
            $paymentRecord = $this->paymentRepo->findById($paymentRecordId, $ctx->business_id);
        }

        $previousState = [
            'status'                  => $instance['status'],
            'paid_date'               => $instance['paid_date'],
            'actual_amount'           => $instance['actual_amount'],
            'payment_record_id'       => $instance['payment_record_id'],
            'is_advance_payment'      => (int) $instance['is_advance_payment'],
            'bulk_advance_payment_id' => $instance['bulk_advance_payment_id'],
        ];

        $this->db->beginTransaction();
        try {
            // Restore account balance from the payment record
            if ($paymentRecord) {
                $accountId = (int) $paymentRecord['account_id'];
                $amountBc = (float) $paymentRecord['amount_bc'];
                $amountFc = (float) $paymentRecord['amount_fc'];

                // Restore balance (positive delta to reverse the original deduction)
                $this->accountRepo->updateBalance($accountId, $amountBc, $this->db, $amountFc);

                // Delete payment allocations before deleting the payment record
                $this->paymentAllocationRepo->deleteBySlip($paymentRecordId);

                // Delete the payment record
                $this->paymentRepo->delete($paymentRecordId, $ctx->business_id);
            }

            // Revert instance: status→pending, clear all payment metadata
            $this->instanceRepo->updateStatus($instanceId, [
                'status'                  => 'pending',
                'paid_date'               => null,
                'actual_amount'           => null,
                'payment_record_id'       => null,
                'is_advance_payment'      => 0,
                'bulk_advance_payment_id' => null,
            ]);

            // Log audit entry
            $this->auditRepo->create([
                'entity_type'    => 'instance',
                'entity_id'      => $instanceId,
                'action_type'    => 'status_changed',
                'previous_state' => $previousState,
                'new_state'      => [
                    'status'                  => 'pending',
                    'paid_date'               => null,
                    'actual_amount'           => null,
                    'payment_record_id'       => null,
                    'is_advance_payment'      => 0,
                    'bulk_advance_payment_id' => null,
                ],
                'metadata' => [
                    'reason'      => 'payment_reversal',
                    'reversed_by' => $ctx->user_id,
                ],
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // ─── Query Methods (used by Controller) ─────────────────────────────────

    /**
     * List recurrence instances with optional filters.
     *
     * Delegates to RecurrenceInstanceRepository::findByBusinessFiltered().
     *
     * @param array $filters Supported: status, entity_type, due_date_from, due_date_to, recurrence_schedule_id
     * @return array
     */
    public function listInstances(array $filters = []): array
    {
        return $this->instanceRepo->findByBusinessFiltered($filters);
    }

    /**
     * Get the payment record associated with a recurring instance.
     *
     * @param int $instanceId
     * @return array|null Payment record data or null if not found/no payment
     */
    public function getInstancePayment(int $instanceId): ?array
    {
        $instance = $this->instanceRepo->findById($instanceId);
        if (!$instance) {
            throw new \RuntimeException('Recurring instance not found');
        }

        if (empty($instance['payment_record_id'])) {
            return null;
        }

        return $this->paymentRepo->findById((int) $instance['payment_record_id']);
    }

    /**
     * List all bulk advance payments for the current business.
     *
     * @param array $filters Supported: entity_type, date_from, date_to
     * @return array
     */
    public function listAdvancePayments(array $filters = []): array
    {
        return $this->bulkAdvanceRepo->findByBusiness($filters);
    }

    /**
     * Get a single bulk advance payment by ID.
     *
     * @param int $id
     * @return array|null
     */
    public function getAdvancePayment(int $id): ?array
    {
        return $this->bulkAdvanceRepo->findById($id);
    }

    // ─── Validation Helpers ──────────────────────────────────────────────────

    /**
     * Validate that the entity type is one of the allowed values.
     *
     * @throws \InvalidArgumentException
     */
    private function validateEntityType(string $entityType): void
    {
        if (!in_array($entityType, self::VALID_ENTITY_TYPES, true)) {
            throw new \InvalidArgumentException(
                'Invalid entity type. Must be one of: ' . implode(', ', self::VALID_ENTITY_TYPES)
            );
        }
    }

    /**
     * Validate required schedule data fields.
     *
     * @throws \InvalidArgumentException
     */
    private function validateScheduleData(array $data): void
    {
        $required = ['frequency', 'default_amount', 'default_currency', 'start_date'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || (is_string($data[$field]) && trim($data[$field]) === '')) {
                throw new \InvalidArgumentException("Missing field: {$field}");
            }
        }

        if (!in_array($data['frequency'], self::VALID_FREQUENCIES, true)) {
            throw new \InvalidArgumentException(
                'Invalid frequency. Must be one of: ' . implode(', ', self::VALID_FREQUENCIES)
            );
        }

        if (!is_numeric($data['default_amount']) || (float) $data['default_amount'] <= 0) {
            throw new \InvalidArgumentException('Default amount must be greater than zero');
        }
    }

    /**
     * Validate that the referenced entity exists and meets type constraints.
     *
     * - expense_category: must exist and have is_fixed = 1
     * - salary: payee must exist and be of type 'Employee'
     *
     * @throws \InvalidArgumentException
     */
    private function validateEntity(string $entityType, int $entityId): void
    {
        $tenantId = AuthContext::tenantId();
        $businessId = AuthContext::businessId();

        if ($entityType === 'EXPENSE') {
            // Validate that the expense exists and belongs to this business
            $stmt = $this->db->prepare("
                SELECT id FROM expenses
                WHERE id = :id AND business_id = :business_id
                LIMIT 1
            ");
            $stmt->execute(['id' => $entityId, 'business_id' => $businessId]);
            if (!$stmt->fetch(\PDO::FETCH_ASSOC)) {
                throw new \InvalidArgumentException('Expense not found');
            }
        } elseif ($entityType === 'SALARY') {
            // For salary, entityId is the people_id (person/employee)
            $payee = $this->peopleRepo->findPayee($entityId, $businessId);
            if (!$payee) {
                throw new \InvalidArgumentException('Payee not found');
            }

            // Check that the payee has the 'Employee' type
            $typeIds = $this->peopleRepo->getPayeeTypeIds((int) $payee['id']);
            if (empty($typeIds)) {
                throw new \InvalidArgumentException('Salary recurrence can only be configured for employees');
            }

            // Look up the type names to verify at least one is 'Employee'
            $isEmployee = $this->checkPayeeIsEmployee((int) $payee['id']);
            if (!$isEmployee) {
                throw new \InvalidArgumentException('Salary recurrence can only be configured for employees');
            }
        }
    }

    /**
     * Check if a payee has the 'Employee' type assigned.
     *
     * @param int $payeeId The payee record ID
     * @return bool
     */
    private function checkPayeeIsEmployee(int $payeeId): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) AS cnt
            FROM people_types ptp
            JOIN payees py ON py.people_id = ptp.people_id
            JOIN payee_types pt ON pt.id = ptp.payee_type_id
            WHERE py.id = :payee_id
              AND (pt.code = 'EMPLOYEE' OR pt.name = 'Employee')
            LIMIT 1
        ");
        $stmt->execute(['payee_id' => $payeeId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int) $result['cnt'] > 0;
    }

    /**
     * Resolve the human-readable entity name for a recurrence instance.
     * For EXPENSE: queries the expenses table with JOINs to payee and category,
     *   returns "Payee Name — Category Name" or "Expense #ID" as fallback.
     * For SALARY: resolves through payee to get the employee/person name.
     */
    private function resolveEntityName(string $entityType, int $scheduleId): string
    {
        $schedule = $this->scheduleRepo->findById($scheduleId);
        if (!$schedule) {
            return 'Unknown';
        }

        $entityId = (int) $schedule['entity_id'];

        if ($entityType === 'EXPENSE') {
            $stmt = $this->db->prepare("
                SELECT p.name AS payee_name, ec.name AS category_name
                FROM expenses e
                LEFT JOIN payees py ON py.id = e.payee_id
                LEFT JOIN people p ON p.id = py.people_id
                LEFT JOIN expense_categories ec ON ec.id = e.expense_category_id
                WHERE e.id = :entity_id
                LIMIT 1
            ");
            $stmt->execute(['entity_id' => $entityId]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($result) {
                $parts = array_filter([$result['payee_name'], $result['category_name']]);
                return !empty($parts) ? implode(' — ', $parts) : "Expense #{$entityId}";
            }
            return "Expense #{$entityId}";
        }

        if ($entityType === 'SALARY') {
            // entity_id for salary is the payee_id, resolve through payee to get person name
            $stmt = $this->db->prepare("
                SELECT p.name
                FROM payees py
                JOIN people p ON p.id = py.people_id
                WHERE py.id = :payee_id
                LIMIT 1
            ");
            $stmt->execute(['payee_id' => $entityId]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $result ? ($result['name'] ?? 'Unknown Employee') : 'Unknown Employee';
        }

        return 'Unknown';
    }

    /**
     * Get all user IDs associated with the current business.
     *
     * @return array<int> User IDs
     */
    private function getBusinessUserIds(): array
    {
        $businessId = AuthContext::businessId();

        $stmt = $this->db->prepare("
            SELECT DISTINCT ur.user_id
            FROM user_roles ur
            JOIN users u ON u.id = ur.user_id AND u.is_active = 1
            WHERE ur.business_id = :business_id
        ");
        $stmt->execute(['business_id' => $businessId]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Mark all pending instances with due_date < today as 'overdue' across ALL businesses.
     * This method is designed for cron/batch usage and does NOT rely on AuthContext.
     *
     * @return int Number of instances marked as overdue
     */
    public function markOverdueInstances(): int
    {
        $today = date('Y-m-d');

        $stmt = $this->db->prepare("
            UPDATE recurrence_instances
            SET status = 'overdue',
                updated_at = NOW()
            WHERE status = 'pending'
              AND due_date < :today
        ");
        $stmt->execute(['today' => $today]);

        return $stmt->rowCount();
    }

    /**
     * Validate date constraints:
     * - start_date must be a valid date
     * - end_date (if provided) must be >= start_date
     *
     * @throws \InvalidArgumentException
     */
    private function validateDates(array $data): void
    {
        $startDate = $data['start_date'];
        $parsedStart = \DateTimeImmutable::createFromFormat('Y-m-d', $startDate);
        if (!$parsedStart || $parsedStart->format('Y-m-d') !== $startDate) {
            throw new \InvalidArgumentException("Invalid start_date format. Expected Y-m-d.");
        }

        if (!empty($data['end_date'])) {
            $endDate = $data['end_date'];
            $parsedEnd = \DateTimeImmutable::createFromFormat('Y-m-d', $endDate);
            if (!$parsedEnd || $parsedEnd->format('Y-m-d') !== $endDate) {
                throw new \InvalidArgumentException("Invalid end_date format. Expected Y-m-d.");
            }

            if ($parsedEnd < $parsedStart) {
                throw new \InvalidArgumentException('End date must be on or after start date');
            }
        }
    }
}
