<?php

namespace Tests\Property\ExpensePayroll;

use App\Repository\ExpenseRepository;
use App\Repository\PeopleRepository;
use App\Services\AuditService;
use App\Services\ExchangeRateService;
use App\Services\ExpenseService;
use App\Support\AuthContext;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Feature: expenses-payroll-review, Property 7: Payee balance algebraic correctness
 *
 * For any sequence of expense/salary create, update, delete operations on a payee,
 * the cumulative balance adjustment equals the negative sum of all currently-active
 * amounts (amount_bc) attributed to that payee.
 *
 * Specifically:
 *   - Create: balance change = -amount_bc
 *   - Update same payee: balance change = -(new_amount_bc - old_amount_bc)
 *   - Update payee change: old gets +old_amount_bc, new gets -new_amount_bc
 *   - Delete: balance change = +amount_bc
 *
 * **Validates: Requirements 12.1, 12.2, 12.3, 12.4**
 */
class PayeeBalanceCorrectnessTest extends TestCase
{
    private const ITERATIONS = 100;
    private const MIN_OPS_PER_SEQUENCE = 5;
    private const MAX_OPS_PER_SEQUENCE = 20;

    private int $businessId = 1;
    private int $nextExpenseId = 1;

    protected function setUp(): void
    {
        AuthContext::set((object) [
            'user_id'     => 1,
            'tenant_id'   => 1,
            'business_id' => $this->businessId,
        ]);
        $this->nextExpenseId = 1;
    }

    /**
     * Data provider generating random operation sequences.
     * Each sequence contains 5-20 operations (create/update/delete).
     */
    public static function operationSequenceProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $numOps = mt_rand(self::MIN_OPS_PER_SEQUENCE, self::MAX_OPS_PER_SEQUENCE);
            $cases["sequence_{$i}_ops_{$numOps}"] = [$i, $numOps];
        }
        return $cases;
    }

    /**
     * Property 7: Payee balance algebraic correctness over random operation sequences.
     *
     * Generates a random sequence of create/update/delete operations and verifies
     * that the cumulative balance adjustment for each payee equals the expected
     * algebraic result.
     *
     * **Validates: Requirements 12.1, 12.2, 12.3, 12.4**
     *
     * @dataProvider operationSequenceProvider
     */
    public function testPayeeBalanceAlgebraicCorrectnessOverSequence(
        int $seed,
        int $numOps
    ): void {
        // Use seed for reproducibility
        mt_srand($seed * 7919);

        // Track all balance delta calls made by the service
        $balanceDeltas = [];

        // Track active expenses: id => ['payee_id' => int|null, 'amount_bc' => float]
        $activeExpenses = [];

        // Pool of payee IDs to use in operations
        $payeePool = [10, 20, 30, 40, 50];

        // Build stubs and service
        $service = $this->buildSequenceService($balanceDeltas, $activeExpenses);

        for ($op = 0; $op < $numOps; $op++) {
            $hasActiveExpenses = !empty($activeExpenses);
            $opType = $this->chooseOperation($hasActiveExpenses);

            switch ($opType) {
                case 'create':
                    $this->executeCreate($service, $payeePool, $activeExpenses);
                    break;

                case 'update':
                    $this->executeUpdate($service, $payeePool, $activeExpenses);
                    break;

                case 'delete':
                    $this->executeDelete($service, $activeExpenses);
                    break;
            }
        }

        // Compute expected net balance per payee from active expenses
        // After all operations, the net adjustment for each payee should equal
        // the negative sum of all currently-active amounts assigned to that payee.
        //
        // The reason: starting from balance=0, each create adds -amount_bc,
        // updates adjust the delta, and deletes reverse. The final net adjustment
        // should equal -SUM(active_amounts for that payee).
        $expectedNetByPayee = [];
        foreach ($activeExpenses as $expense) {
            if ($expense['payee_id'] !== null) {
                $payeeId = $expense['payee_id'];
                if (!isset($expectedNetByPayee[$payeeId])) {
                    $expectedNetByPayee[$payeeId] = 0.0;
                }
                $expectedNetByPayee[$payeeId] += -$expense['amount_bc'];
            }
        }

        // Compute actual net balance per payee from recorded delta calls
        $actualNetByPayee = [];
        foreach ($balanceDeltas as $call) {
            $payeeId = $call['payeeId'];
            if (!isset($actualNetByPayee[$payeeId])) {
                $actualNetByPayee[$payeeId] = 0.0;
            }
            $actualNetByPayee[$payeeId] += $call['delta'];
        }

        // Verify: all payees in expected should match actual
        $allPayees = array_unique(array_merge(
            array_keys($expectedNetByPayee),
            array_keys($actualNetByPayee)
        ));

        foreach ($allPayees as $payeeId) {
            $expected = $expectedNetByPayee[$payeeId] ?? 0.0;
            $actual = $actualNetByPayee[$payeeId] ?? 0.0;

            $this->assertEqualsWithDelta(
                $expected,
                $actual,
                0.01,
                sprintf(
                    'Payee %d net balance mismatch. Expected: %.2f, Actual: %.2f. '
                    . 'Seed: %d, Ops: %d',
                    $payeeId,
                    $expected,
                    $actual,
                    $seed,
                    $numOps
                )
            );
        }

        // Reset random seed
        mt_srand();
    }

    /**
     * Choose an operation type. If no active expenses exist, always create.
     * Otherwise, choose create (40%), update (40%), delete (20%).
     */
    private function chooseOperation(bool $hasActiveExpenses): string
    {
        if (!$hasActiveExpenses) {
            return 'create';
        }

        $rand = mt_rand(1, 100);
        if ($rand <= 40) {
            return 'create';
        } elseif ($rand <= 80) {
            return 'update';
        } else {
            return 'delete';
        }
    }

    /**
     * Execute a create operation with random payee and amount.
     */
    private function executeCreate(
        ExpenseService $service,
        array $payeePool,
        array &$activeExpenses
    ): void {
        // Randomly choose a payee (80% chance) or null (20% chance)
        $payeeId = mt_rand(1, 100) <= 80
            ? $payeePool[array_rand($payeePool)]
            : null;

        $amountFc = round(mt_rand(100, 999999) / 100, 2); // 1.00 to 9999.99
        $exchangeRate = round(mt_rand(10, 500) / 100, 2);  // 0.10 to 5.00

        $expenseId = $this->nextExpenseId++;
        $amountBc = round($amountFc * $exchangeRate, 2);

        // Store in active expenses BEFORE calling service (repo stub reads from here)
        $activeExpenses[$expenseId] = [
            'payee_id'  => $payeeId,
            'amount_bc' => $amountBc,
            'amount_fc' => $amountFc,
            'exchange_rate' => $exchangeRate,
        ];

        $service->create([
            'payee_id'      => $payeeId,
            'expense_date'  => '2025-01-15',
            'currency'      => 'USD',
            'exchange_rate'  => $exchangeRate,
            'amount_fc'     => $amountFc,
        ]);
    }

    /**
     * Execute an update operation on a random active expense.
     * Randomly changes amount, payee, or both.
     */
    private function executeUpdate(
        ExpenseService $service,
        array $payeePool,
        array &$activeExpenses
    ): void {
        if (empty($activeExpenses)) {
            return;
        }

        $expenseIds = array_keys($activeExpenses);
        $expenseId = $expenseIds[array_rand($expenseIds)];
        $current = $activeExpenses[$expenseId];

        // Decide what to change
        $changeType = mt_rand(1, 3);
        switch ($changeType) {
            case 1: // Change amount only
                $newAmountFc = round(mt_rand(100, 999999) / 100, 2);
                $newPayeeId = $current['payee_id'];
                $newExchangeRate = $current['exchange_rate'];
                break;

            case 2: // Change payee only
                $newAmountFc = $current['amount_fc'];
                $newExchangeRate = $current['exchange_rate'];
                // 70% different payee, 30% null
                if (mt_rand(1, 100) <= 70) {
                    do {
                        $newPayeeId = $payeePool[array_rand($payeePool)];
                    } while ($newPayeeId === $current['payee_id'] && mt_rand(1, 5) > 1);
                } else {
                    $newPayeeId = null;
                }
                break;

            case 3: // Change both
                $newAmountFc = round(mt_rand(100, 999999) / 100, 2);
                $newExchangeRate = round(mt_rand(10, 500) / 100, 2);
                if (mt_rand(1, 100) <= 70) {
                    do {
                        $newPayeeId = $payeePool[array_rand($payeePool)];
                    } while ($newPayeeId === $current['payee_id'] && mt_rand(1, 5) > 1);
                } else {
                    $newPayeeId = null;
                }
                break;
        }

        $newAmountBc = round($newAmountFc * $newExchangeRate, 2);

        // Update tracking state AFTER (service reads "before" from current state)
        $service->update($expenseId, [
            'payee_id'      => $newPayeeId,
            'expense_date'  => '2025-01-15',
            'currency'      => 'USD',
            'exchange_rate'  => $newExchangeRate,
            'amount_fc'     => $newAmountFc,
        ]);

        // Now update our tracking
        $activeExpenses[$expenseId] = [
            'payee_id'      => $newPayeeId,
            'amount_bc'     => $newAmountBc,
            'amount_fc'     => $newAmountFc,
            'exchange_rate' => $newExchangeRate,
        ];
    }

    /**
     * Execute a delete operation on a random active expense.
     */
    private function executeDelete(
        ExpenseService $service,
        array &$activeExpenses
    ): void {
        if (empty($activeExpenses)) {
            return;
        }

        $expenseIds = array_keys($activeExpenses);
        $expenseId = $expenseIds[array_rand($expenseIds)];

        $service->delete($expenseId);

        // Remove from active tracking
        unset($activeExpenses[$expenseId]);
    }

    /**
     * Build an ExpenseService with stubs that use the activeExpenses array
     * for state management during the operation sequence.
     */
    private function buildSequenceService(
        array &$balanceDeltas,
        array &$activeExpenses
    ): ExpenseService {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Mock prepare() for countPaymentAllocationsForExpense (returns 0 allocations)
        $stmtMock = $this->createMock(\PDOStatement::class);
        $stmtMock->method('execute')->willReturn(true);
        $stmtMock->method('fetchColumn')->willReturn(0);
        $pdo->method('prepare')->willReturn($stmtMock);

        // Expense repository that tracks creates and reads from activeExpenses
        $nextIdRef = &$this->nextExpenseId;
        $expenseRepo = new class($activeExpenses, $nextIdRef, $this->businessId) extends ExpenseRepository {
            private array $activeExpenses;
            private int $nextId;
            private int $businessId;

            public function __construct(array &$activeExpenses, int &$nextId, int $businessId)
            {
                $this->activeExpenses = &$activeExpenses;
                $this->nextId = &$nextId;
                $this->businessId = $businessId;
            }

            public function create(array $data): int
            {
                // The ID was pre-assigned in executeCreate before calling service
                // Return the current nextId - 1 (it was incremented already)
                return $this->nextId - 1;
            }

            public function findById(int $id, int $businessId): ?array
            {
                if (!isset($this->activeExpenses[$id])) {
                    return null;
                }
                $exp = $this->activeExpenses[$id];
                return [
                    'id'                   => $id,
                    'business_id'          => $this->businessId,
                    'payee_id'             => $exp['payee_id'],
                    'payee_name'           => 'Test Payee',
                    'expense_category_id'  => null,
                    'category_name'        => null,
                    'expense_date'         => '2025-01-15',
                    'currency'             => 'USD',
                    'exchange_rate'        => $exp['exchange_rate'],
                    'amount_fc'            => $exp['amount_fc'],
                    'amount_bc'            => $exp['amount_bc'],
                    'notes'                => null,
                    'created_at'           => '2025-01-15',
                    'updated_at'           => '2025-01-15',
                ];
            }

            public function update(int $id, int $businessId, array $data): void
            {
                // no-op — state tracking done in executeUpdate
            }

            public function delete(int $id, int $businessId): void
            {
                // no-op — state tracking done in executeDelete
            }
        };

        // People repository that tracks all updatePayeeBalance calls
        $peopleRepo = new class($balanceDeltas) extends PeopleRepository {
            private array $deltas;

            public function __construct(array &$deltas)
            {
                $this->deltas = &$deltas;
            }

            public function updatePayeeBalance(int $payeeId, float $delta): void
            {
                $this->deltas[] = ['payeeId' => $payeeId, 'delta' => $delta];
            }

            public function ensurePayeeBelongsToBusiness(int $payeeId, int $businessId): void
            {
                // no-op — assume all payees belong to business
            }
        };

        // Audit service stub
        $audit = new class extends AuditService {
            public function __construct() {}
            public function log(string $eventType, string $table, $recordId,
                                string $action, ?array $oldValues = null, ?array $newValues = null,
                                ?int $userId = null, ?int $tenantId = null, ?int $businessId = null,
                                bool $force = false, bool $tenantScope = false): void {}
        };

        // Exchange rate service that passes through provided rates
        $exchangeRateService = new class extends ExchangeRateService {
            public function __construct() {}
            public function resolveRate(string $currency, ?float $providedRate): float
            {
                if ($providedRate !== null) {
                    return $providedRate;
                }
                return 1.0;
            }
        };

        $ref     = new ReflectionClass(ExpenseService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $props = [
            'db'                  => $pdo,
            'repo'                => $expenseRepo,
            'peopleRepo'          => $peopleRepo,
            'exchangeRateService' => $exchangeRateService,
            'audit'               => $audit,
        ];

        foreach ($props as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($service, $value);
        }

        return $service;
    }
}
