<?php

namespace App\Controllers;

use App\Config\Database;
use App\Services\RecurrenceService;
use App\Services\AccountingEntryService;
use App\Services\RecurrenceAuditService;

class RecurrenceController extends BaseController
{
    private RecurrenceService $recurrenceService;
    private AccountingEntryService $accountingEntryService;
    private RecurrenceAuditService $auditService;

    public function __construct()
    {
        $db = Database::getConnection();
        $this->recurrenceService = new RecurrenceService($db);
        $this->accountingEntryService = new AccountingEntryService($db);
        $this->auditService = new RecurrenceAuditService($db);
    }

    /* =====================================================================
     * SCHEDULE MANAGEMENT
     * ===================================================================== */

    /**
     * GET /api/recurrences
     * List all active recurrence schedules for the current business.
     * Supports query filters: entity_type, is_active
     */
    public function listSchedules(): void
    {
        try {
            $this->auth();
            $filters = [];

            if (isset($_GET['entity_type'])) {
                $filters['entity_type'] = $_GET['entity_type'];
            }
            if (isset($_GET['is_active'])) {
                $filters['is_active'] = $_GET['is_active'];
            }

            $schedules = $this->recurrenceService->listSchedules($filters);

            // Enrich schedules with entity_name, payee_id, exchange_rate
            $db = \App\Config\Database::getConnection();
            foreach ($schedules as &$schedule) {
                $schedule['entity_name'] = $this->resolveEntityNameFromSchedule($db, $schedule);
                // Also resolve payee_id and exchange_rate for prefill
                $entityId = (int) ($schedule['entity_id'] ?? 0);
                if (($schedule['entity_type'] ?? '') === 'EXPENSE' && $entityId) {
                    $stmt = $db->prepare("SELECT payee_id, exchange_rate FROM expenses WHERE id = :id LIMIT 1");
                    $stmt->execute(['id' => $entityId]);
                    $exp = $stmt->fetch(\PDO::FETCH_ASSOC);
                    $schedule['payee_id'] = $exp ? ((int) $exp['payee_id'] ?: null) : null;
                    $schedule['exchange_rate'] = $exp ? (float) ($exp['exchange_rate'] ?? 1.0) : 1.0;
                } elseif (($schedule['entity_type'] ?? '') === 'SALARY') {
                    $schedule['payee_id'] = $entityId;
                    $schedule['exchange_rate'] = 1.0;
                } else {
                    $schedule['payee_id'] = null;
                    $schedule['exchange_rate'] = 1.0;
                }
            }

            $this->json(true, ['schedules' => $schedules]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch recurrence schedules');
        }
    }

    /**
     * POST /api/expenses/{id}/recurrence
     * Create a recurrence schedule for a specific expense.
     */
    public function createExpenseRecurrence(int $expenseId): void
    {
        try {
            $this->auth();
            $data = $this->input(['frequency', 'default_amount', 'default_currency', 'start_date', 'end_date']);

            $id = $this->recurrenceService->createSchedule('EXPENSE', $expenseId, $data);
            $this->json(true, ['id' => $id], null, 201);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            $code = str_contains($e->getMessage(), 'not found') ? 404 : 409;
            $this->json(false, null, $e->getMessage(), $code);
        } catch (\Throwable $e) {
            $this->serverError('Failed to create expense recurrence: ' . $e->getMessage());
        }
    }

    /**
     * DELETE /api/expenses/{id}/recurrence
     * Deactivate the recurrence schedule for a specific expense.
     */
    public function deleteExpenseRecurrence(int $expenseId): void
    {
        try {
            $this->auth();
            $this->recurrenceService->deactivateSchedule('EXPENSE', $expenseId);
            $this->json(true, null, 'Recurrence schedule deactivated');
        } catch (\RuntimeException $e) {
            $code = str_contains($e->getMessage(), 'not found') ? 404 : 409;
            $this->json(false, null, $e->getMessage(), $code);
        } catch (\Throwable $e) {
            $this->serverError('Failed to delete expense recurrence');
        }
    }

    /**
     * GET /expenses/{id}/recurrence
     * Get the recurrence schedule for a specific expense.
     */
    public function getExpenseRecurrence(int $expenseId): void
    {
        try {
            $this->auth();
            $schedule = $this->recurrenceService->getSchedule('EXPENSE', $expenseId);

            if (!$schedule) {
                $this->notFound('Recurrence schedule not found');
                return;
            }

            $this->json(true, $schedule);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch expense recurrence');
        }
    }

    /**
     * POST /api/salaries/employees/{payee_id}/recurrence
     * Create a recurrence schedule for an employee salary.
     */
    public function createSalaryRecurrence(int $payeeId): void
    {
        try {
            $this->auth();
            $data = $this->input(['frequency', 'default_amount', 'default_currency', 'start_date']);

            $id = $this->recurrenceService->createSchedule('SALARY', $payeeId, $data);
            $this->json(true, ['id' => $id], null, 201);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            $code = str_contains($e->getMessage(), 'not found') ? 404 : 409;
            $this->json(false, null, $e->getMessage(), $code);
        } catch (\Throwable $e) {
            $this->serverError('Failed to create salary recurrence');
        }
    }

    /**
     * GET /api/salaries/employees/{payee_id}/recurrence
     * Get the recurrence schedule for an employee.
     */
    public function getSalaryRecurrence(int $payeeId): void
    {
        try {
            $this->auth();
            $schedule = $this->recurrenceService->getSchedule('SALARY', $payeeId);

            if (!$schedule) {
                $this->notFound('Recurrence schedule not found');
                return;
            }

            $this->json(true, ['schedule' => $schedule]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch salary recurrence');
        }
    }

    /**
     * DELETE /api/salaries/employees/{payee_id}/recurrence
     * Deactivate the recurrence schedule for an employee salary.
     */
    public function deleteSalaryRecurrence(int $payeeId): void
    {
        try {
            $this->auth();
            $this->recurrenceService->deactivateSchedule('SALARY', $payeeId);
            $this->json(true, null, 'Recurrence schedule deactivated');
        } catch (\RuntimeException $e) {
            $code = str_contains($e->getMessage(), 'not found') ? 404 : 409;
            $this->json(false, null, $e->getMessage(), $code);
        } catch (\Throwable $e) {
            $this->serverError('Failed to delete salary recurrence');
        }
    }

    /* =====================================================================
     * INSTANCE MANAGEMENT
     * ===================================================================== */

    /**
     * GET /api/recurring-instances
     * List recurrence instances with optional filters.
     * Enriches each instance with entity_name from the related expense/salary.
     */
    public function listInstances(): void
    {
        try {
            $this->auth();
            $filters = [];

            if (isset($_GET['status'])) {
                $filters['status'] = $_GET['status'];
            }
            if (isset($_GET['entity_type'])) {
                $filters['entity_type'] = $_GET['entity_type'];
            }
            if (isset($_GET['due_date_from'])) {
                $filters['due_date_from'] = $_GET['due_date_from'];
            }
            if (isset($_GET['due_date_to'])) {
                $filters['due_date_to'] = $_GET['due_date_to'];
            }
            if (isset($_GET['recurrence_schedule_id'])) {
                $filters['recurrence_schedule_id'] = (int) $_GET['recurrence_schedule_id'];
            }

            $instances = $this->recurrenceService->listInstances($filters);

            // Enrich instances with entity_name and days_overdue
            $db = \App\Config\Database::getConnection();
            $today = date('Y-m-d');

            foreach ($instances as &$instance) {
                // Calculate days_overdue for overdue instances (both already-overdue and pending past due)
                if ($instance['status'] === 'overdue' && $instance['due_date'] < $today) {
                    $instance['days_overdue'] = (int) ((strtotime($today) - strtotime($instance['due_date'])) / 86400);
                } elseif ($instance['status'] === 'pending' && $instance['due_date'] < $today) {
                    $instance['status'] = 'overdue';
                    $instance['days_overdue'] = (int) ((strtotime($today) - strtotime($instance['due_date'])) / 86400);
                } else {
                    $instance['days_overdue'] = 0;
                }

                // Get entity_name and payee_id from the schedule's linked entity
                $enrichment = $this->resolveEntityDetails($db, $instance);
                $instance['entity_name'] = $enrichment['entity_name'];
                $instance['payee_id'] = $enrichment['payee_id'];
                $instance['exchange_rate'] = $enrichment['exchange_rate'];
            }

            $this->json(true, ['instances' => $instances]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch recurring instances');
        }
    }

    /**
     * PUT /api/recurring-instances/{id}/status
     * Update the status of a recurrence instance (paid or skipped).
     */
    public function updateInstanceStatus(int $id): void
    {
        try {
            $this->auth();
            $data = $this->input(['status']);

            $status = $data['status'];
            $paymentData = [];

            if ($status === 'paid') {
                if (empty($data['account_id'])) {
                    $this->json(false, null, 'Missing field: account_id', 400);
                    return;
                }
                $paymentData['account_id'] = (int) $data['account_id'];

                if (isset($data['amount'])) {
                    $paymentData['amount'] = (float) $data['amount'];
                }
                if (isset($data['method'])) {
                    $paymentData['method'] = $data['method'];
                }
                if (isset($data['exchange_rate'])) {
                    $paymentData['exchange_rate'] = (float) $data['exchange_rate'];
                }
            }

            $this->recurrenceService->updateInstanceStatus($id, $status, $paymentData);
            $this->json(true, null, "Instance status updated to '{$status}'");
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            $code = str_contains($e->getMessage(), 'not found') ? 404 : 409;
            $this->json(false, null, $e->getMessage(), $code);
        } catch (\Throwable $e) {
            $this->serverError('Failed to update instance status');
        }
    }

    /**
     * GET /api/recurring-instances/{id}/payment
     * Get the payment record associated with a recurring instance.
     */
    public function getInstancePayment(int $id): void
    {
        try {
            $this->auth();

            // Use the instance repository via the service to find the instance
            $schedule = $this->recurrenceService->getInstancePayment($id);

            if (!$schedule) {
                $this->notFound('Instance or payment record not found');
                return;
            }

            $this->json(true, ['payment' => $schedule]);
        } catch (\RuntimeException $e) {
            $code = str_contains($e->getMessage(), 'not found') ? 404 : 409;
            $this->json(false, null, $e->getMessage(), $code);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch instance payment');
        }
    }

    /**
     * POST /api/recurring-instances/bulk-advance
     * Create a bulk advance payment for multiple future periods.
     */
    public function bulkAdvancePayment(): void
    {
        try {
            $this->auth();
            $data = $this->input(['schedule_id', 'periods', 'account_id']);

            $scheduleId = (int) $data['schedule_id'];
            $periods = (int) $data['periods'];

            if ($periods < 2 || $periods > 12) {
                $this->json(false, null, 'Number of periods must be between 2 and 12', 400);
                return;
            }

            $paymentData = [
                'account_id' => (int) $data['account_id'],
            ];

            if (isset($data['method'])) {
                $paymentData['method'] = $data['method'];
            }
            if (isset($data['exchange_rate'])) {
                $paymentData['exchange_rate'] = (float) $data['exchange_rate'];
            }

            $result = $this->recurrenceService->bulkAdvancePayment($scheduleId, $periods, $paymentData);
            $this->json(true, $result, null, 201);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            $code = str_contains($e->getMessage(), 'not found') ? 404 : 409;
            $this->json(false, null, $e->getMessage(), $code);
        } catch (\Throwable $e) {
            $this->serverError('Failed to create bulk advance payment');
        }
    }

    /* =====================================================================
     * ADVANCE PAYMENT TRACKING
     * ===================================================================== */

    /**
     * GET /api/recurring-instances/advance-payments
     * List all bulk advance payments for the current business.
     * Supports query filters: entity_type, date_from, date_to
     */
    public function listAdvancePayments(): void
    {
        try {
            $this->auth();
            $filters = [];

            if (isset($_GET['entity_type'])) {
                $filters['entity_type'] = $_GET['entity_type'];
            }
            if (isset($_GET['date_from'])) {
                $filters['date_from'] = $_GET['date_from'];
            }
            if (isset($_GET['date_to'])) {
                $filters['date_to'] = $_GET['date_to'];
            }

            $payments = $this->recurrenceService->listAdvancePayments($filters);
            $this->json(true, ['advance_payments' => $payments]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch advance payments');
        }
    }

    /**
     * GET /api/recurring-instances/advance-payments/{id}
     * Get a single bulk advance payment by ID, including all linked instances.
     */
    public function getAdvancePayment(int $id): void
    {
        try {
            $this->auth();
            $payment = $this->recurrenceService->getAdvancePayment($id);

            if (!$payment) {
                $this->notFound('Advance payment not found');
                return;
            }

            // Include all instances linked to this bulk advance payment
            $db = \App\Config\Database::getConnection();
            $instanceRepo = new \App\Repository\RecurrenceInstanceRepository($db);
            $linkedInstances = $instanceRepo->findByBulkAdvancePaymentId($id);

            $payment['instances'] = $linkedInstances;

            $this->json(true, ['advance_payment' => $payment]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch advance payment');
        }
    }

    /* =====================================================================
     * PREPAID ASSETS & ACCOUNTING
     * ===================================================================== */

    /**
     * GET /api/prepaid-assets
     * List prepaid assets for the current business.
     * Supports query filters: status, from_date, to_date
     */
    public function listPrepaidAssets(): void
    {
        try {
            $this->auth();
            $filters = [];

            if (isset($_GET['status'])) {
                $filters['status'] = $_GET['status'];
            }
            if (isset($_GET['from_date'])) {
                $filters['from_date'] = $_GET['from_date'];
            }
            if (isset($_GET['to_date'])) {
                $filters['to_date'] = $_GET['to_date'];
            }

            $assets = $this->accountingEntryService->listPrepaidAssets($filters);
            $this->json(true, ['prepaid_assets' => $assets]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch prepaid assets');
        }
    }

    /**
     * GET /api/prepaid-assets/{id}
     * Get a single prepaid asset with its amortization schedule.
     */
    public function getPrepaidAsset(int $id): void
    {
        try {
            $this->auth();
            $asset = $this->accountingEntryService->getPrepaidAsset($id);
            $this->json(true, ['prepaid_asset' => $asset]);
        } catch (\RuntimeException $e) {
            $this->notFound($e->getMessage());
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch prepaid asset');
        }
    }

    /**
     * GET /api/accounting-entries
     * List accounting entries for the current business.
     * Supports query filters: entry_type, reference_type, reference_id,
     *                         date_from, date_to
     */
    public function listAccountingEntries(): void
    {
        try {
            $this->auth();
            $filters = [];

            if (isset($_GET['entry_type'])) {
                $filters['entry_type'] = $_GET['entry_type'];
            }
            if (isset($_GET['reference_type'])) {
                $filters['reference_type'] = $_GET['reference_type'];
            }
            if (isset($_GET['reference_id'])) {
                $filters['reference_id'] = (int) $_GET['reference_id'];
            }
            if (isset($_GET['date_from'])) {
                $filters['date_from'] = $_GET['date_from'];
            }
            if (isset($_GET['date_to'])) {
                $filters['date_to'] = $_GET['date_to'];
            }

            $entries = $this->accountingEntryService->listAccountingEntries($filters);
            $this->json(true, ['accounting_entries' => $entries]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch accounting entries');
        }
    }

    /* =====================================================================
     * AUDIT LOG
     * ===================================================================== */

    /**
     * GET /api/recurring-audit-log
     * List audit log entries for the current business.
     * Supports query filters: entity_type, entity_id, action_type, user_id,
     *                         from_date, to_date, limit, offset
     */
    public function listAuditLog(): void
    {
        try {
            $this->auth();
            $filters = [];

            if (isset($_GET['entity_type'])) {
                $filters['entity_type'] = $_GET['entity_type'];
            }
            if (isset($_GET['entity_id'])) {
                $filters['entity_id'] = (int) $_GET['entity_id'];
            }
            if (isset($_GET['action_type'])) {
                $filters['action_type'] = $_GET['action_type'];
            }
            if (isset($_GET['user_id'])) {
                $filters['user_id'] = (int) $_GET['user_id'];
            }
            if (isset($_GET['from_date'])) {
                $filters['from_date'] = $_GET['from_date'];
            }
            if (isset($_GET['to_date'])) {
                $filters['to_date'] = $_GET['to_date'];
            }
            if (isset($_GET['limit'])) {
                $filters['limit'] = (int) $_GET['limit'];
            }
            if (isset($_GET['offset'])) {
                $filters['offset'] = (int) $_GET['offset'];
            }

            $result = $this->auditService->getLog($filters);
            $this->json(true, $result);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch audit log');
        }
    }

    /* =====================================================================
     * ENGINE EVALUATION
     * ===================================================================== */

    /**
     * POST /api/recurrences/evaluate
     * Trigger the recurrence engine evaluation.
     * Generates due instances, transitions overdue, sends notifications,
     * and performs expense recognition.
     */
    public function evaluate(): void
    {
        try {
            $this->auth();
            $result = $this->recurrenceService->evaluateAll();
            $this->json(true, $result);
        } catch (\Throwable $e) {
            $this->serverError('Failed to evaluate recurrences: ' . $e->getMessage());
        }
    }

    /* =====================================================================
     * PRIVATE HELPERS
     * ===================================================================== */

    /**
     * Resolve entity details (name, payee_id, exchange_rate) from a recurrence instance.
     */
    private function resolveEntityDetails(\PDO $db, array $instance): array
    {
        $scheduleId = $instance['recurrence_schedule_id'] ?? null;
        $default = ['entity_name' => '—', 'payee_id' => null, 'exchange_rate' => 1.0];
        if (!$scheduleId) return $default;

        $stmt = $db->prepare("SELECT entity_type, entity_id FROM recurrence_schedules WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $scheduleId]);
        $schedule = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$schedule) return $default;

        $result = $default;
        $result['entity_name'] = $this->resolveEntityNameFromSchedule($db, $schedule);

        $entityId = (int) $schedule['entity_id'];

        if ($schedule['entity_type'] === 'EXPENSE') {
            $stmt = $db->prepare("SELECT payee_id, exchange_rate FROM expenses WHERE id = :id LIMIT 1");
            $stmt->execute(['id' => $entityId]);
            $expense = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($expense) {
                $result['payee_id'] = $expense['payee_id'] ? (int) $expense['payee_id'] : null;
                $result['exchange_rate'] = (float) ($expense['exchange_rate'] ?? 1.0);
            }
        } elseif ($schedule['entity_type'] === 'SALARY') {
            $result['payee_id'] = $entityId;
            $result['exchange_rate'] = 1.0;
        }

        return $result;
    }

    /**
     * Resolve entity name directly from a schedule record.
     */
    private function resolveEntityNameFromSchedule(\PDO $db, array $schedule): string
    {
        $entityId = (int) ($schedule['entity_id'] ?? 0);
        $entityType = $schedule['entity_type'] ?? '';

        if ($entityType === 'EXPENSE') {
            $stmt = $db->prepare("
                SELECT e.id, COALESCE(p.name, '') as payee_name, COALESCE(ec.name, '') as category_name
                FROM expenses e
                LEFT JOIN payees py ON py.id = e.payee_id
                LEFT JOIN people p ON p.id = py.people_id
                LEFT JOIN expense_categories ec ON ec.id = e.expense_category_id
                WHERE e.id = :id
                LIMIT 1
            ");
            $stmt->execute(['id' => $entityId]);
            $expense = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($expense) {
                $parts = array_filter([$expense['payee_name'], $expense['category_name']]);
                return implode(' — ', $parts) ?: "Expense #$entityId";
            }
            return "Expense #$entityId";
        }

        if ($entityType === 'SALARY') {
            $stmt = $db->prepare("
                SELECT p.name FROM payees py
                JOIN people p ON p.id = py.people_id
                WHERE py.id = :id
                LIMIT 1
            ");
            $stmt->execute(['id' => $entityId]);
            $name = $stmt->fetchColumn();
            return $name ?: "Employee #$entityId";
        }

        return '—';
    }
}
