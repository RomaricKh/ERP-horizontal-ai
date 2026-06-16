<?php
namespace App\Services;

use App\Repository\ExpenseRepository;
use App\Repository\ExpenseCategoryRepository;
use App\Repository\PeopleRepository;
use App\Services\ExchangeRateService;
use App\Support\AuthContext;
use PDO;

class ExpenseService {
    private ExpenseRepository         $repo;
    private ExpenseCategoryRepository $categoryRepo;
    private PeopleRepository          $peopleRepo;
    private ExchangeRateService       $exchangeRateService;
    private AuditService              $audit;
    private PDO                       $db;

    public function __construct(PDO $db) {
        $this->db                  = $db;
        $this->repo                = new ExpenseRepository($db);
        $this->categoryRepo        = new ExpenseCategoryRepository($db);
        $this->peopleRepo          = new PeopleRepository($db);
        $this->exchangeRateService = new ExchangeRateService($db);
        $this->audit               = new AuditService($db);
    }

    /* =====================================================================
     * EXPENSE CATEGORIES
     * ===================================================================== */

    /**
     * Return all expense categories for the current business (module-gated).
     */
    public function listCategories(): array {
        $ctx = AuthContext::get();
        return $this->categoryRepo->findForBusiness($ctx->tenant_id, $ctx->business_id);
    }

    /**
     * Create a new expense category.
     *
     * @throws \InvalidArgumentException if name is missing
     */
    public function createCategory(string $name, bool $isFixed): int {
        $ctx  = AuthContext::get();
        $name = trim($name);

        if ($name === '') {
            throw new \InvalidArgumentException('Category name is required');
        }

        $id = $this->repo->createCategory([
            'tenant_id'   => $ctx->tenant_id,
            'business_id' => $ctx->business_id,
            'name'        => $name,
            'is_fixed'    => $isFixed,
            'created_by'  => $ctx->user_id,
        ]);

        $this->audit->log(
            'CREATE',
            'expense_categories',
            $id,
            'CREATE',
            null,
            ['name' => $name, 'is_fixed' => $isFixed],
            $ctx->user_id,
            $ctx->tenant_id,
            $ctx->business_id
        );

        return $id;
    }

    /**
     * Update an existing expense category.
     *
     * @throws \RuntimeException if not found
     */
    public function updateCategory(int $id, string $name, bool $isFixed): void {
        $ctx    = AuthContext::get();
        $name   = trim($name);
        $before = $this->repo->findCategoryById($id, $ctx->tenant_id);

        if (!$before) {
            throw new \RuntimeException('Category not found');
        }
        if ($name === '') {
            throw new \InvalidArgumentException('Category name is required');
        }

        $this->repo->updateCategory($id, $ctx->tenant_id, [
            'name'     => $name,
            'is_fixed' => $isFixed,
        ]);

        $this->audit->log(
            'UPDATE',
            'expense_categories',
            $id,
            'UPDATE',
            $before,
            ['name' => $name, 'is_fixed' => $isFixed],
            $ctx->user_id,
            $ctx->tenant_id,
            $ctx->business_id
        );
    }

    /**
     * Delete an expense category.
     * Guard: refuse if the category has expenses.
     *
     * @throws \RuntimeException if not found or has expenses
     */
    public function deleteCategory(int $id): void {
        $ctx    = AuthContext::get();
        $before = $this->repo->findCategoryById($id, $ctx->tenant_id);

        if (!$before) {
            throw new \RuntimeException('Category not found');
        }

        $this->db->beginTransaction();
        try {
            if ($this->repo->countExpensesForCategory($id) > 0) {
                throw new \RuntimeException('Cannot delete: category has linked expenses');
            }

            $this->repo->deleteCategory($id, $ctx->tenant_id);

            $this->audit->log(
                'DELETE',
                'expense_categories',
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
     * EXPENSES
     * ===================================================================== */

    /**
     * Return all expenses for the current business.
     */
    public function list(): array {
        $ctx = AuthContext::get();
        return $this->repo->findAllForBusiness($ctx->business_id);
    }

    /**
     * Return a single expense by id.
     *
     * @throws \RuntimeException if not found
     */
    public function getById(int $id): array {
        $ctx     = AuthContext::get();
        $expense = $this->repo->findById($id, $ctx->business_id);
        if (!$expense) {
            throw new \RuntimeException('Expense not found');
        }
        return $expense;
    }

    /**
     * Create a new expense.
     * Calculates amount_bc = amount_fc * exchange_rate.
     *
     * Required keys: expense_date, currency, exchange_rate, amount_fc
     * Optional keys: payee_id, expense_category_id, notes
     *
     * @throws \InvalidArgumentException if required fields are missing
     */
    public function create(array $data): int {
        $ctx = AuthContext::get();

        if (empty($data['expense_date'] ?? '')) {
            throw new \InvalidArgumentException('Expense date is required');
        }
        if (empty(trim($data['currency'] ?? ''))) {
            throw new \InvalidArgumentException('Currency is required');
        }
        if (!isset($data['amount_fc']) || $data['amount_fc'] === '') {
            throw new \InvalidArgumentException('Amount is required');
        }

        // Resolve exchange rate: use provided value or fall back to latest from exchange_rates table
        $providedRate = (!isset($data['exchange_rate']) || $data['exchange_rate'] === '' || $data['exchange_rate'] === null)
            ? null
            : (float) $data['exchange_rate'];
        $resolvedRate = $this->exchangeRateService->resolveRate(trim($data['currency']), $providedRate);

        $amountFc    = (float) $data['amount_fc'];
        $exchangeRate = $resolvedRate;
        $amountBc    = round($amountFc * $exchangeRate, 2);

        $this->db->beginTransaction();
        try {
            $payeeId = isset($data['payee_id']) && $data['payee_id'] !== '' ? (int) $data['payee_id'] : null;

            $id = $this->repo->create([
                'business_id'          => $ctx->business_id,
                'payee_id'             => $payeeId,
                'expense_category_id'  => isset($data['expense_category_id']) && $data['expense_category_id'] !== '' ? (int) $data['expense_category_id'] : null,
                'expense_date'         => $data['expense_date'],
                'currency'             => trim($data['currency']),
                'exchange_rate'        => $exchangeRate,
                'amount_fc'            => $amountFc,
                'amount_bc'            => $amountBc,
                'notes'                => $data['notes'] ?? null,
                'reference_number'     => $data['reference_number'] ?? null,
                'reference_date'       => $data['reference_date'] ?? null,
                'attachment_path'      => $data['attachment_path'] ?? null,
            ]);

            // Decrease payee balance (we owe the payee for this expense)
            if ($payeeId) {
                $this->peopleRepo->updatePayeeBalance($payeeId, -$amountBc);
            }

            $this->audit->log(
                'CREATE',
                'expenses',
                $id,
                'CREATE',
                null,
                [
                    'expense_date'        => $data['expense_date'],
                    'currency'            => trim($data['currency']),
                    'exchange_rate'       => $exchangeRate,
                    'amount_fc'           => $amountFc,
                    'amount_bc'           => $amountBc,
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
     * Update an existing expense.
     * Recalculates amount_bc = amount_fc * exchange_rate.
     *
     * @throws \RuntimeException if not found
     */
    public function update(int $id, array $data): void {
        $ctx    = AuthContext::get();
        $before = $this->repo->findById($id, $ctx->business_id);

        if (!$before) {
            throw new \RuntimeException('Expense not found');
        }

        // Resolve exchange rate: use provided value or fall back to latest from exchange_rates table
        $providedRate = (!isset($data['exchange_rate']) || $data['exchange_rate'] === '' || $data['exchange_rate'] === null)
            ? null
            : (float) $data['exchange_rate'];
        $resolvedRate = $this->exchangeRateService->resolveRate(trim($data['currency']), $providedRate);

        $amountFc    = (float) $data['amount_fc'];
        $exchangeRate = $resolvedRate;
        $amountBc    = round($amountFc * $exchangeRate, 2);

        $this->db->beginTransaction();
        try {
            $newPayeeId = isset($data['payee_id']) && $data['payee_id'] !== '' ? (int) $data['payee_id'] : null;

            $this->repo->update($id, $ctx->business_id, [
                'payee_id'             => $newPayeeId,
                'expense_category_id'  => isset($data['expense_category_id']) && $data['expense_category_id'] !== '' ? (int) $data['expense_category_id'] : null,
                'expense_date'         => $data['expense_date'],
                'currency'             => trim($data['currency']),
                'exchange_rate'        => $exchangeRate,
                'amount_fc'            => $amountFc,
                'amount_bc'            => $amountBc,
                'notes'                => $data['notes'] ?? null,
                'reference_number'     => $data['reference_number'] ?? null,
                'reference_date'       => $data['reference_date'] ?? null,
                'attachment_path'      => $data['attachment_path'] ?? null,
            ]);

            // Adjust payee balance based on payee/amount changes
            $oldPayeeId = isset($before['payee_id']) ? (int) $before['payee_id'] : null;
            $oldAmountBc = (float) ($before['amount_bc'] ?? 0);

            if ($oldPayeeId && $newPayeeId && $oldPayeeId === $newPayeeId) {
                // Same payee — adjust by delta if amount changed (negative = we owe more)
                $delta = $amountBc - $oldAmountBc;
                if ($delta != 0) {
                    $this->peopleRepo->updatePayeeBalance($oldPayeeId, -$delta);
                }
            } elseif ($oldPayeeId && $newPayeeId && $oldPayeeId !== $newPayeeId) {
                // Payee changed — reverse old (increase back), apply new (decrease)
                $this->peopleRepo->updatePayeeBalance($oldPayeeId, $oldAmountBc);
                $this->peopleRepo->updatePayeeBalance($newPayeeId, -$amountBc);
            } elseif ($oldPayeeId && !$newPayeeId) {
                // Payee removed — reverse old (increase back)
                $this->peopleRepo->updatePayeeBalance($oldPayeeId, $oldAmountBc);
            } elseif (!$oldPayeeId && $newPayeeId) {
                // Payee added — apply new (decrease)
                $this->peopleRepo->updatePayeeBalance($newPayeeId, -$amountBc);
            }

            $this->audit->log(
                'UPDATE',
                'expenses',
                $id,
                'UPDATE',
                $before,
                [
                    'expense_date'        => $data['expense_date'],
                    'currency'            => trim($data['currency']),
                    'exchange_rate'       => $exchangeRate,
                    'amount_fc'           => $amountFc,
                    'amount_bc'           => $amountBc,
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
     * Delete an expense.
     *
     * @throws \RuntimeException if not found
     */
    public function delete(int $id): void {
        $ctx    = AuthContext::get();
        $before = $this->repo->findById($id, $ctx->business_id);

        if (!$before) {
            throw new \RuntimeException('Expense not found');
        }

        // Guard: cannot delete expense that has payment allocations
        $allocationCount = $this->countPaymentAllocationsForExpense($id);
        if ($allocationCount > 0) {
            throw new \RuntimeException('Cannot delete this expense: it has payment allocations. Remove the payments first.');
        }

        $this->db->beginTransaction();
        try {
            // Deactivate any linked recurrence schedule
            $this->cleanupRecurrenceForExpense($id);

            $this->repo->delete($id, $ctx->business_id);

            // Reverse payee balance (increase back since we no longer owe)
            $payeeId = isset($before['payee_id']) ? (int) $before['payee_id'] : null;
            if ($payeeId) {
                $amountBc = (float) ($before['amount_bc'] ?? 0);
                $this->peopleRepo->updatePayeeBalance($payeeId, $amountBc);
            }

            $this->audit->log(
                'DELETE',
                'expenses',
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
     * Count payment allocations referencing this expense.
     */
    private function countPaymentAllocationsForExpense(int $expenseId): int {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM payment_allocations
            WHERE reference_type = 'EXPENSE' AND reference_id = :id
        ");
        $stmt->execute(['id' => $expenseId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Deactivate recurrence schedule and cancel pending instances for a deleted expense.
     */
    private function cleanupRecurrenceForExpense(int $expenseId): void {
        // Find active schedule for this expense
        $stmt = $this->db->prepare("
            SELECT id FROM recurrence_schedules
            WHERE entity_type = 'EXPENSE' AND entity_id = :id
        ");
        $stmt->execute(['id' => $expenseId]);
        $scheduleId = $stmt->fetchColumn();

        if (!$scheduleId) return;

        // Delete all instances for this schedule
        $this->db->prepare("
            DELETE FROM recurrence_instances WHERE recurrence_schedule_id = :schedule_id
        ")->execute(['schedule_id' => $scheduleId]);

        // Delete the schedule itself
        $this->db->prepare("
            DELETE FROM recurrence_schedules WHERE id = :id
        ")->execute(['id' => $scheduleId]);
    }
}
