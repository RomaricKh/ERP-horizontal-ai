<?php

namespace Tests\Unit;

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
 * Unit tests for ExpenseService balance edge cases.
 *
 * Validates: Requirements 3.2, 3.3, 3.5
 */
class ExpenseBalanceTest extends TestCase
{
    private int $businessId = 1;
    private int $expenseId  = 100;

    protected function setUp(): void
    {
        AuthContext::set((object) [
            'user_id'     => 1,
            'tenant_id'   => 1,
            'business_id' => $this->businessId,
        ]);
    }

    /**
     * Test: create with null payee_id — no balance call.
     *
     * When an expense is created without a payee assigned (payee_id is null),
     * updatePayeeBalance() must not be called.
     *
     * Validates: Requirement 3.5
     */
    public function testCreateWithNullPayeeIdDoesNotCallBalance(): void
    {
        $balanceDeltas = [];

        $expenseRepo = $this->makeExpenseRepoStubForCreate($this->expenseId);
        $peopleRepo  = $this->makePeopleRepoStub($balanceDeltas);
        $service     = $this->buildService($expenseRepo, $peopleRepo);

        $service->create([
            'payee_id'      => null,
            'expense_date'  => '2025-01-15',
            'currency'      => 'USD',
            'exchange_rate'  => 1.0,
            'amount_fc'     => 500.00,
        ]);

        $this->assertCount(
            0,
            $balanceDeltas,
            'No balance call expected when payee_id is null on create'
        );
    }

    /**
     * Test: update with payee removed (set → null) — old payee decreased.
     *
     * When an expense previously had a payee and the payee is removed (set to null),
     * the old payee's balance must be decreased by the old amount_bc.
     *
     * Validates: Requirements 3.3, 3.5
     */
    public function testUpdateWithPayeeRemovedDecreasesOldPayeeBalance(): void
    {
        $balanceDeltas = [];
        $oldPayeeId    = 42;
        $oldAmountBc   = 750.00;

        $beforeRecord = $this->makeExpenseRecord([
            'payee_id'  => $oldPayeeId,
            'amount_fc' => $oldAmountBc,
            'amount_bc' => $oldAmountBc,
        ]);

        $expenseRepo = $this->makeExpenseRepoStubForUpdate($beforeRecord);
        $peopleRepo  = $this->makePeopleRepoStub($balanceDeltas);
        $service     = $this->buildService($expenseRepo, $peopleRepo);

        $service->update($this->expenseId, [
            'payee_id'      => null,
            'expense_date'  => '2025-01-15',
            'currency'      => 'USD',
            'exchange_rate'  => 1.0,
            'amount_fc'     => 750.00,
        ]);

        $this->assertCount(
            1,
            $balanceDeltas,
            'Expected exactly 1 balance call when payee is removed'
        );
        $this->assertSame(
            $oldPayeeId,
            $balanceDeltas[0]['payeeId'],
            'Balance update must target the old payee'
        );
        $this->assertEqualsWithDelta(
            $oldAmountBc,
            $balanceDeltas[0]['delta'],
            0.001,
            'Old payee balance must increase by old amount_bc when payee is removed (reverse original expense)'
        );
    }

    /**
     * Test: update with payee added (null → set) — new payee increased.
     *
     * When an expense previously had no payee and a payee is now assigned,
     * the new payee's balance must be increased by the new amount_bc.
     *
     * Validates: Requirements 3.3, 3.5
     */
    public function testUpdateWithPayeeAddedIncreasesNewPayeeBalance(): void
    {
        $balanceDeltas = [];
        $newPayeeId    = 55;
        $newAmountBc   = 1200.00;

        $beforeRecord = $this->makeExpenseRecord([
            'payee_id'  => null,
            'amount_fc' => 800.00,
            'amount_bc' => 800.00,
        ]);

        $expenseRepo = $this->makeExpenseRepoStubForUpdate($beforeRecord);
        $peopleRepo  = $this->makePeopleRepoStub($balanceDeltas);
        $service     = $this->buildService($expenseRepo, $peopleRepo);

        $service->update($this->expenseId, [
            'payee_id'      => $newPayeeId,
            'expense_date'  => '2025-01-15',
            'currency'      => 'USD',
            'exchange_rate'  => 1.0,
            'amount_fc'     => $newAmountBc,
        ]);

        $this->assertCount(
            1,
            $balanceDeltas,
            'Expected exactly 1 balance call when payee is added'
        );
        $this->assertSame(
            $newPayeeId,
            $balanceDeltas[0]['payeeId'],
            'Balance update must target the new payee'
        );
        $this->assertEqualsWithDelta(
            -$newAmountBc,
            $balanceDeltas[0]['delta'],
            0.001,
            'New payee balance must decrease by new amount_bc when payee is added (business owes new payee)'
        );
    }

    /**
     * Test: delete with null payee_id — no balance call.
     *
     * When an expense with no payee assigned is deleted,
     * updatePayeeBalance() must not be called.
     *
     * Validates: Requirement 3.5
     */
    public function testDeleteWithNullPayeeIdDoesNotCallBalance(): void
    {
        $balanceDeltas = [];

        $beforeRecord = $this->makeExpenseRecord([
            'payee_id'  => null,
            'amount_fc' => 300.00,
            'amount_bc' => 300.00,
        ]);

        $expenseRepo = $this->makeExpenseRepoStubForDelete($beforeRecord);
        $peopleRepo  = $this->makePeopleRepoStub($balanceDeltas);
        $service     = $this->buildService($expenseRepo, $peopleRepo);

        $service->delete($this->expenseId);

        $this->assertCount(
            0,
            $balanceDeltas,
            'No balance call expected when payee_id is null on delete'
        );
    }

    // ---------------------------------------------------------------
    // Helper methods
    // ---------------------------------------------------------------

    /**
     * Build a base expense record array, merging in overrides.
     */
    private function makeExpenseRecord(array $overrides = []): array
    {
        return array_merge([
            'id'                   => $this->expenseId,
            'business_id'          => $this->businessId,
            'payee_id'             => null,
            'payee_name'           => null,
            'expense_category_id'  => null,
            'category_name'        => null,
            'expense_date'         => '2025-01-15',
            'currency'             => 'USD',
            'exchange_rate'        => 1.0,
            'amount_fc'            => 0.00,
            'amount_bc'            => 0.00,
            'notes'                => null,
            'created_at'           => '2025-01-15',
            'updated_at'           => '2025-01-15',
        ], $overrides);
    }

    /**
     * Create an Expense repository stub for create operations.
     * Returns the given ID from create(), findById returns null (not needed for create).
     */
    private function makeExpenseRepoStubForCreate(int $returnId): ExpenseRepository
    {
        return new class($returnId) extends ExpenseRepository {
            private int $returnId;

            public function __construct(int $returnId)
            {
                $this->returnId = $returnId;
            }

            public function create(array $data): int
            {
                return $this->returnId;
            }

            public function findById(int $id, int $businessId): ?array
            {
                return null;
            }
        };
    }

    /**
     * Create an Expense repository stub for update operations.
     * findById returns the given before record, update is no-op.
     */
    private function makeExpenseRepoStubForUpdate(array $beforeRecord): ExpenseRepository
    {
        return new class($beforeRecord) extends ExpenseRepository {
            private array $record;

            public function __construct(array $record)
            {
                $this->record = $record;
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
    }

    /**
     * Create an Expense repository stub for delete operations.
     * findById returns the given before record, delete is no-op.
     */
    private function makeExpenseRepoStubForDelete(array $beforeRecord): ExpenseRepository
    {
        return new class($beforeRecord) extends ExpenseRepository {
            private array $record;

            public function __construct(array $record)
            {
                $this->record = $record;
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
    }

    /**
     * Create a People repository stub that tracks all updatePayeeBalance calls.
     */
    private function makePeopleRepoStub(array &$deltas): PeopleRepository
    {
        return new class($deltas) extends PeopleRepository {
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
    }

    /**
     * Build an ExpenseService with mocked dependencies injected via reflection.
     */
    private function buildService(
        ExpenseRepository $expenseRepo,
        PeopleRepository $peopleRepo
    ): ExpenseService {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        $audit = new class extends AuditService {
            public function __construct() {}
            public function log(string $eventType, string $table, $recordId,
                                string $action, ?array $oldValues = null, ?array $newValues = null,
                                ?int $userId = null, ?int $tenantId = null, ?int $businessId = null, bool $force = false, bool $tenantScope = false): void {}
        };

        $exchangeRateService = new class extends ExchangeRateService {
            public function __construct() {}
            public function resolveRate(string $currency, ?float $providedRate): float
            {
                if ($providedRate !== null) {
                    return $providedRate;
                }
                throw new \InvalidArgumentException("Exchange rate is required for currency {$currency}");
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
