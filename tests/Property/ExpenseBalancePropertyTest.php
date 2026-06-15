<?php

namespace Tests\Property;

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
 * Feature: people-balance-tracking, Property 3: Expense create-then-delete round-trip
 *
 * For any Expense with a payee and a positive amount_bc,
 * creating and then deleting the expense should return the
 * payee balance to its original value (net delta = 0).
 *
 * Validates: Requirements 3.1, 3.4
 */
class ExpenseBalancePropertyTest extends TestCase
{
    private const ITERATIONS = 120;

    /**
     * Data provider generating 120 random expense amounts, exchange rates, and payee IDs.
     *
     * Each case: [payeeId, amountFc, exchangeRate]
     */
    public static function createThenDeleteProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $payeeId      = mt_rand(1, 10000);
            // Random amount_fc between 0.01 and 99999.99
            $amountFc     = round(mt_rand(1, 9999999) / 100, 2);
            // Random exchange rate between 0.01 and 100.00
            $exchangeRate = round(mt_rand(1, 10000) / 100, 2);
            $cases["payee_{$payeeId}_fc_{$amountFc}_rate_{$exchangeRate}_iter_{$i}"] = [
                $payeeId,
                $amountFc,
                $exchangeRate,
            ];
        }
        return $cases;
    }

    /**
     * Property 3: Expense create-then-delete round-trip
     *
     * After create then delete, the net delta passed to
     * updatePayeeBalance() must be zero.
     *
     * **Validates: Requirements 3.1, 3.4**
     *
     * @dataProvider createThenDeleteProvider
     */
    public function testCreateThenDeleteNetDeltaIsZero(
        int $payeeId,
        float $amountFc,
        float $exchangeRate
    ): void {
        $expenseId  = mt_rand(1, 10000);
        $businessId = mt_rand(1, 100);

        // Compute expected amount_bc the same way ExpenseService does
        $expectedAmountBc = round($amountFc * $exchangeRate, 2);

        // Set up AuthContext
        AuthContext::set((object) [
            'user_id'     => 1,
            'tenant_id'   => 1,
            'business_id' => $businessId,
        ]);

        // Track balance deltas
        $balanceDeltas = [];

        // The record that findById will return after create
        $storedRecord = [
            'id'                   => $expenseId,
            'business_id'          => $businessId,
            'payee_id'             => $payeeId,
            'payee_name'           => 'Test Payee',
            'expense_category_id'  => null,
            'category_name'        => null,
            'expense_date'         => '2025-01-15',
            'currency'             => 'USD',
            'exchange_rate'        => $exchangeRate,
            'amount_fc'            => $amountFc,
            'amount_bc'            => $expectedAmountBc,
            'notes'                => null,
            'created_at'           => '2025-01-15',
            'updated_at'           => '2025-01-15',
        ];

        // -- Build stubs using anonymous subclasses --

        // Expense repository: create returns ID, findById returns stored record, delete is no-op
        $expenseRepo = new class($expenseId, $storedRecord) extends ExpenseRepository {
            /** @var int */
            private $returnId;
            /** @var array */
            private $record;

            public function __construct(int $returnId, array $record)
            {
                // Skip parent constructor (needs PDO)
                $this->returnId = $returnId;
                $this->record   = $record;
            }

            public function create(array $data): int
            {
                return $this->returnId;
            }

            public function findById(int $id, int $businessId): ?array
            {
                return $this->record;
            }

            public function delete(int $id, int $businessId): void
            {
                // no-op
            }
        };

        // People repository: tracks all updatePayeeBalance calls
        $peopleRepo = new class($balanceDeltas) extends PeopleRepository {
            /** @var array */
            private $deltas;

            public function __construct(array &$deltas)
            {
                // Skip parent constructor
                $this->deltas = &$deltas;
            }

            public function updatePayeeBalance(int $payeeId, float $delta): void
            {
                $this->deltas[] = ['payeeId' => $payeeId, 'delta' => $delta];
            }
        };

        // Audit service stub
        $audit = new class extends AuditService {
            public function __construct()
            {
                // Skip parent constructor
            }

            public function log(string $eventType, string $table, $recordId,
                                string $action, ?array $oldValues = null, ?array $newValues = null,
                                ?int $userId = null, ?int $tenantId = null, ?int $businessId = null, bool $force = false, bool $tenantScope = false): void
            {
                // no-op
            }
        };

        // PDO stub
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Build service with injected stubs
        $service = $this->buildServiceWithStubs($pdo, $expenseRepo, $peopleRepo, $audit);

        // Step 1: Create expense (increases payee balance by amount_bc)
        $service->create([
            'payee_id'      => $payeeId,
            'expense_date'  => '2025-01-15',
            'currency'      => 'USD',
            'exchange_rate'  => $exchangeRate,
            'amount_fc'     => $amountFc,
        ]);

        // Step 2: Delete expense (should decrease payee balance by amount_bc)
        $service->delete($expenseId);

        // Assert: exactly 2 balance calls were made
        $this->assertCount(
            2,
            $balanceDeltas,
            'Expected exactly 2 balance updates (one for create, one for delete)'
        );

        // Assert: both calls targeted the correct payee
        $this->assertSame(
            $payeeId,
            $balanceDeltas[0]['payeeId'],
            'Create balance update must target the correct payee'
        );
        $this->assertSame(
            $payeeId,
            $balanceDeltas[1]['payeeId'],
            'Delete balance update must target the correct payee'
        );

        // Assert: first delta is -amount_bc (create: business owes payee)
        $this->assertEqualsWithDelta(
            -$expectedAmountBc,
            $balanceDeltas[0]['delta'],
            0.001,
            'First delta (create) should be -amount_bc'
        );

        // Assert: second delta is +amount_bc (delete reverses balance)
        $this->assertEqualsWithDelta(
            $expectedAmountBc,
            $balanceDeltas[1]['delta'],
            0.001,
            'Second delta (delete) should be +amount_bc'
        );

        // Assert: net delta is zero (round-trip property)
        $netDelta = $balanceDeltas[0]['delta'] + $balanceDeltas[1]['delta'];
        $this->assertEqualsWithDelta(
            0.0,
            $netDelta,
            0.001,
            sprintf(
                'Net balance delta must be zero after create-then-delete. '
                . 'Payee: %d, AmountFC: %.2f, Rate: %.2f, AmountBC: %.2f, '
                . 'Deltas: [%.2f, %.2f], Net: %.2f',
                $payeeId,
                $amountFc,
                $exchangeRate,
                $expectedAmountBc,
                $balanceDeltas[0]['delta'],
                $balanceDeltas[1]['delta'],
                $netDelta
            )
        );
    }

    /**
     * Data provider generating 120 random old and new amount_bc values for edit delta testing.
     *
     * Each case: [payeeId, oldAmountBc, newAmountBc]
     * Uses exchange_rate=1.0 so amount_fc equals amount_bc.
     */
    public static function amountEditDeltaProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $payeeId    = mt_rand(1, 10000);
            // Random old amount_bc between 0.01 and 99999.99
            $oldAmountBc = round(mt_rand(1, 9999999) / 100, 2);
            // Random new amount_bc between 0.01 and 99999.99 (may equal old)
            $newAmountBc = round(mt_rand(1, 9999999) / 100, 2);
            $cases["payee_{$payeeId}_old_{$oldAmountBc}_new_{$newAmountBc}_iter_{$i}"] = [
                $payeeId,
                $oldAmountBc,
                $newAmountBc,
            ];
        }
        return $cases;
    }

    /**
     * Property 5: Expense amount edit adjusts balance by exact delta
     *
     * Feature: people-balance-tracking, Property 5: Expense amount edit adjusts balance by exact delta
     *
     * For any Expense with a payee, when the amount_bc changes from old to new,
     * the payee balance should change by exactly (new - old).
     *
     * Uses exchange_rate=1.0 so amount_fc equals amount_bc for simplicity.
     *
     * **Validates: Requirements 3.2, 9.1**
     *
     * @dataProvider amountEditDeltaProvider
     */
    public function testAmountEditAdjustsBalanceByExactDelta(
        int $payeeId,
        float $oldAmountBc,
        float $newAmountBc
    ): void {
        $expenseId  = mt_rand(1, 10000);
        $businessId = mt_rand(1, 100);

        $expectedDelta = round($newAmountBc - $oldAmountBc, 2);

        // Set up AuthContext
        AuthContext::set((object) [
            'user_id'     => 1,
            'tenant_id'   => 1,
            'business_id' => $businessId,
        ]);

        // Track balance deltas
        $balanceDeltas = [];

        // The "before" record that findById returns (the existing expense)
        $beforeRecord = [
            'id'                   => $expenseId,
            'business_id'          => $businessId,
            'payee_id'             => $payeeId,
            'payee_name'           => 'Test Payee',
            'expense_category_id'  => null,
            'category_name'        => null,
            'expense_date'         => '2025-01-15',
            'currency'             => 'USD',
            'exchange_rate'        => 1.0,
            'amount_fc'            => $oldAmountBc,
            'amount_bc'            => $oldAmountBc,
            'notes'                => null,
            'created_at'           => '2025-01-15',
            'updated_at'           => '2025-01-15',
        ];

        // Expense repository stub: findById returns the before record, update is no-op
        $expenseRepo = new class($expenseId, $beforeRecord) extends ExpenseRepository {
            private int $returnId;
            private array $record;

            public function __construct(int $returnId, array $record)
            {
                $this->returnId = $returnId;
                $this->record   = $record;
            }

            public function findById(int $id, int $businessId): ?array
            {
                return $this->record;
            }

            public function update(int $id, int $businessId, array $data): void
            {
                // no-op
            }
        };

        // People repository stub: tracks all updatePayeeBalance calls
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
        };

        // Audit service stub
        $audit = new class extends AuditService {
            public function __construct()
            {
            }

            public function log(string $eventType, string $table, $recordId,
                                string $action, ?array $oldValues = null, ?array $newValues = null,
                                ?int $userId = null, ?int $tenantId = null, ?int $businessId = null, bool $force = false, bool $tenantScope = false): void
            {
            }
        };

        // PDO stub
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Build service with injected stubs
        $service = $this->buildServiceWithStubs($pdo, $expenseRepo, $peopleRepo, $audit);

        // Call update with new amount (exchange_rate=1.0 so amount_fc = amount_bc)
        $service->update($expenseId, [
            'payee_id'      => $payeeId,
            'expense_date'  => '2025-01-15',
            'currency'      => 'USD',
            'exchange_rate'  => 1.0,
            'amount_fc'     => $newAmountBc,
        ]);

        // When old and new amounts are equal, no balance call should be made
        if ($expectedDelta == 0) {
            $this->assertCount(
                0,
                $balanceDeltas,
                'No balance update expected when amount_bc does not change'
            );
            return;
        }

        // Assert: exactly 1 balance call was made
        $this->assertCount(
            1,
            $balanceDeltas,
            sprintf(
                'Expected exactly 1 balance update for amount edit. Old: %.2f, New: %.2f',
                $oldAmountBc,
                $newAmountBc
            )
        );

        // Assert: the call targeted the correct payee
        $this->assertSame(
            $payeeId,
            $balanceDeltas[0]['payeeId'],
            'Balance update must target the correct payee'
        );

        // Assert: the delta is exactly -(new_amount_bc - old_amount_bc)
        $this->assertEqualsWithDelta(
            -$expectedDelta,
            $balanceDeltas[0]['delta'],
            0.001,
            sprintf(
                'Balance delta must be exactly -(new - old). '
                . 'Payee: %d, OldBC: %.2f, NewBC: %.2f, ExpectedDelta: %.2f, ActualDelta: %.2f',
                $payeeId,
                $oldAmountBc,
                $newAmountBc,
                -$expectedDelta,
                $balanceDeltas[0]['delta']
            )
        );
    }

    /**
     * Data provider generating 120 random payee A, payee B, old amount_bc, and new amount_bc
     * for payee change testing. Ensures payee A != payee B.
     *
     * Each case: [payeeA, payeeB, oldAmountBc, newAmountBc]
     * Uses exchange_rate=1.0 so amount_fc equals amount_bc.
     */
    public static function payeeChangeProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $payeeA = mt_rand(1, 10000);
            // Ensure payee B is different from payee A
            do {
                $payeeB = mt_rand(1, 10000);
            } while ($payeeB === $payeeA);
            // Random old amount_bc between 0.01 and 99999.99
            $oldAmountBc = round(mt_rand(1, 9999999) / 100, 2);
            // Random new amount_bc between 0.01 and 99999.99
            $newAmountBc = round(mt_rand(1, 9999999) / 100, 2);
            $cases["payeeA_{$payeeA}_payeeB_{$payeeB}_old_{$oldAmountBc}_new_{$newAmountBc}_iter_{$i}"] = [
                $payeeA,
                $payeeB,
                $oldAmountBc,
                $newAmountBc,
            ];
        }
        return $cases;
    }

    /**
     * Property 6: Expense payee change transfers balance
     *
     * Feature: people-balance-tracking, Property 6: Expense payee change transfers balance
     *
     * For any Expense with a payee, when the payee_id changes from payee A to payee B,
     * payee A's balance should decrease by the old amount_bc and payee B's balance
     * should increase by the new amount_bc.
     *
     * Uses exchange_rate=1.0 so amount_fc equals amount_bc for simplicity.
     *
     * **Validates: Requirements 3.3**
     *
     * @dataProvider payeeChangeProvider
     */
    public function testPayeeChangeTransfersBalance(
        int $payeeA,
        int $payeeB,
        float $oldAmountBc,
        float $newAmountBc
    ): void {
        $expenseId  = mt_rand(1, 10000);
        $businessId = mt_rand(1, 100);

        // Set up AuthContext
        AuthContext::set((object) [
            'user_id'     => 1,
            'tenant_id'   => 1,
            'business_id' => $businessId,
        ]);

        // Track balance deltas
        $balanceDeltas = [];

        // The "before" record: expense assigned to payee A with old amount
        $beforeRecord = [
            'id'                   => $expenseId,
            'business_id'          => $businessId,
            'payee_id'             => $payeeA,
            'payee_name'           => 'Payee A',
            'expense_category_id'  => null,
            'category_name'        => null,
            'expense_date'         => '2025-01-15',
            'currency'             => 'USD',
            'exchange_rate'        => 1.0,
            'amount_fc'            => $oldAmountBc,
            'amount_bc'            => $oldAmountBc,
            'notes'                => null,
            'created_at'           => '2025-01-15',
            'updated_at'           => '2025-01-15',
        ];

        // Expense repository stub: findById returns the before record, update is no-op
        $expenseRepo = new class($expenseId, $beforeRecord) extends ExpenseRepository {
            private int $returnId;
            private array $record;

            public function __construct(int $returnId, array $record)
            {
                $this->returnId = $returnId;
                $this->record   = $record;
            }

            public function findById(int $id, int $businessId): ?array
            {
                return $this->record;
            }

            public function update(int $id, int $businessId, array $data): void
            {
                // no-op
            }
        };

        // People repository stub: tracks all updatePayeeBalance calls
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
        };

        // Audit service stub
        $audit = new class extends AuditService {
            public function __construct()
            {
            }

            public function log(string $eventType, string $table, $recordId,
                                string $action, ?array $oldValues = null, ?array $newValues = null,
                                ?int $userId = null, ?int $tenantId = null, ?int $businessId = null, bool $force = false, bool $tenantScope = false): void
            {
            }
        };

        // PDO stub
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Build service with injected stubs
        $service = $this->buildServiceWithStubs($pdo, $expenseRepo, $peopleRepo, $audit);

        // Call update changing payee from A to B with new amount (exchange_rate=1.0)
        $service->update($expenseId, [
            'payee_id'      => $payeeB,
            'expense_date'  => '2025-01-15',
            'currency'      => 'USD',
            'exchange_rate'  => 1.0,
            'amount_fc'     => $newAmountBc,
        ]);

        // Assert: exactly 2 balance calls were made (one decrease for old payee, one increase for new payee)
        $this->assertCount(
            2,
            $balanceDeltas,
            sprintf(
                'Expected exactly 2 balance updates for payee change. '
                . 'PayeeA: %d, PayeeB: %d, OldBC: %.2f, NewBC: %.2f',
                $payeeA,
                $payeeB,
                $oldAmountBc,
                $newAmountBc
            )
        );

        // Assert: first call reverses old payee A by +old amount_bc (undo the original negative)
        $this->assertSame(
            $payeeA,
            $balanceDeltas[0]['payeeId'],
            'First balance update must target old payee A'
        );
        $this->assertEqualsWithDelta(
            $oldAmountBc,
            $balanceDeltas[0]['delta'],
            0.001,
            sprintf(
                'Old payee A balance must increase by old amount_bc (reverse original expense). '
                . 'PayeeA: %d, OldBC: %.2f, ActualDelta: %.2f',
                $payeeA,
                $oldAmountBc,
                $balanceDeltas[0]['delta']
            )
        );

        // Assert: second call assigns new payee B by -new amount_bc (business owes new payee)
        $this->assertSame(
            $payeeB,
            $balanceDeltas[1]['payeeId'],
            'Second balance update must target new payee B'
        );
        $this->assertEqualsWithDelta(
            -$newAmountBc,
            $balanceDeltas[1]['delta'],
            0.001,
            sprintf(
                'New payee B balance must decrease by new amount_bc (business owes new payee). '
                . 'PayeeB: %d, NewBC: %.2f, ActualDelta: %.2f',
                $payeeB,
                $newAmountBc,
                $balanceDeltas[1]['delta']
            )
        );
    }

    /**
     * Build an ExpenseService with stubbed dependencies injected via reflection.
     */
    private function buildServiceWithStubs(
        PDO $pdo,
        ExpenseRepository $expenseRepo,
        PeopleRepository $peopleRepo,
        AuditService $audit
    ): ExpenseService {
        $ref     = new ReflectionClass(ExpenseService::class);
        $service = $ref->newInstanceWithoutConstructor();

        // Create an ExchangeRateService stub that passes through the provided rate
        $exchangeRateService = new class extends ExchangeRateService {
            public function __construct()
            {
                // Skip parent constructor
            }

            public function resolveRate(string $currency, ?float $providedRate): float
            {
                if ($providedRate !== null) {
                    return $providedRate;
                }
                throw new \InvalidArgumentException("Exchange rate is required for currency {$currency}");
            }
        };

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
