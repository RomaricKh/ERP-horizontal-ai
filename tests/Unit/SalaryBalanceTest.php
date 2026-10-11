<?php

namespace Tests\Unit;

use App\Repository\PeopleRepository;
use App\Repository\SalaryRepository;
use App\Services\AuditService;
use App\Services\ExchangeRateService;
use App\Services\SalaryService;
use App\Support\AuthContext;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for SalaryService balance edge cases.
 *
 * Validates: Requirements 4.2, 4.3, 4.5
 */
class SalaryBalanceTest extends TestCase
{
    private int $businessId = 1;
    private int $salaryId   = 100;

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
     * When a salary is created without a payee assigned (payee_id is null),
     * updatePayeeBalance() must not be called.
     *
     * Validates: Requirement 4.5
     */
    public function testCreateWithNullPayeeIdDoesNotCallBalance(): void
    {
        $balanceDeltas = [];

        $salaryRepo = $this->makeSalaryRepoStubForCreate($this->salaryId);
        $peopleRepo = $this->makePeopleRepoStub($balanceDeltas);
        $service    = $this->buildService($salaryRepo, $peopleRepo);

        $service->create([
            'payee_id'        => null,
            'period_start'    => '2025-01-01',
            'period_end'      => '2025-01-31',
            'currency'        => 'USD',
            'exchange_rate'   => 1.0,
            'gross_amount_fc' => 5000.00,
            'tax_fc'          => 0,
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
     * When a salary previously had a payee and the payee is removed (set to null),
     * the old payee's balance must be decreased by the old net_amount_bc.
     *
     * Validates: Requirements 4.3, 4.5
     */
    public function testUpdateWithPayeeRemovedDecreasesOldPayeeBalance(): void
    {
        $balanceDeltas  = [];
        $oldPayeeId     = 42;
        $oldNetAmountBc = 750.00;

        $beforeRecord = $this->makeSalaryRecord([
            'payee_id'       => $oldPayeeId,
            'net_amount_bc'  => $oldNetAmountBc,
            'net_amount_fc'  => $oldNetAmountBc,
            'gross_amount_fc' => $oldNetAmountBc,
            'gross_amount_bc' => $oldNetAmountBc,
            'tax_fc'         => 0.0,
            'tax_bc'         => 0.0,
            'exchange_rate'  => 1.0,
        ]);

        $salaryRepo = $this->makeSalaryRepoStubForUpdate($beforeRecord);
        $peopleRepo = $this->makePeopleRepoStub($balanceDeltas);
        $service    = $this->buildService($salaryRepo, $peopleRepo);

        $service->update($this->salaryId, [
            'payee_id'        => null,
            'period_start'    => '2025-01-01',
            'period_end'      => '2025-01-31',
            'currency'        => 'USD',
            'exchange_rate'   => 1.0,
            'gross_amount_fc' => 750.00,
            'tax_fc'          => 0,
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
            $oldNetAmountBc,
            $balanceDeltas[0]['delta'],
            0.001,
            'Old payee balance must increase by old net_amount_bc when payee is removed (reverse original salary)'
        );
    }

    /**
     * Test: update with payee added (null → set) — new payee increased.
     *
     * When a salary previously had no payee and a payee is now assigned,
     * the new payee's balance must be increased by the new net_amount_bc.
     *
     * Validates: Requirements 4.3, 4.5
     */
    public function testUpdateWithPayeeAddedIncreasesNewPayeeBalance(): void
    {
        $balanceDeltas  = [];
        $newPayeeId     = 55;
        $newNetAmountBc = 1200.00;

        $beforeRecord = $this->makeSalaryRecord([
            'payee_id'        => null,
            'net_amount_bc'   => 800.00,
            'net_amount_fc'   => 800.00,
            'gross_amount_fc' => 800.00,
            'gross_amount_bc' => 800.00,
            'tax_fc'          => 0.0,
            'tax_bc'          => 0.0,
            'exchange_rate'   => 1.0,
        ]);

        $salaryRepo = $this->makeSalaryRepoStubForUpdate($beforeRecord);
        $peopleRepo = $this->makePeopleRepoStub($balanceDeltas);
        $service    = $this->buildService($salaryRepo, $peopleRepo);

        $service->update($this->salaryId, [
            'payee_id'        => $newPayeeId,
            'period_start'    => '2025-01-01',
            'period_end'      => '2025-01-31',
            'currency'        => 'USD',
            'exchange_rate'   => 1.0,
            'gross_amount_fc' => $newNetAmountBc,
            'tax_fc'          => 0,
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
            -$newNetAmountBc,
            $balanceDeltas[0]['delta'],
            0.001,
            'New payee balance must decrease by new net_amount_bc when payee is added (business owes new payee)'
        );
    }

    /**
     * Test: delete with null payee_id — no balance call.
     *
     * When a salary with no payee assigned is deleted,
     * updatePayeeBalance() must not be called.
     *
     * Validates: Requirement 4.5
     */
    public function testDeleteWithNullPayeeIdDoesNotCallBalance(): void
    {
        $balanceDeltas = [];

        $beforeRecord = $this->makeSalaryRecord([
            'payee_id'        => null,
            'net_amount_bc'   => 300.00,
            'net_amount_fc'   => 300.00,
            'gross_amount_fc' => 300.00,
            'gross_amount_bc' => 300.00,
            'tax_fc'          => 0.0,
            'tax_bc'          => 0.0,
            'exchange_rate'   => 1.0,
        ]);

        $salaryRepo = $this->makeSalaryRepoStubForDelete($beforeRecord);
        $peopleRepo = $this->makePeopleRepoStub($balanceDeltas);
        $service    = $this->buildService($salaryRepo, $peopleRepo);

        $service->delete($this->salaryId);

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
     * Build a base salary record array, merging in overrides.
     */
    private function makeSalaryRecord(array $overrides = []): array
    {
        return array_merge([
            'id'               => $this->salaryId,
            'business_id'      => $this->businessId,
            'payee_id'         => null,
            'period_start'     => '2025-01-01',
            'period_end'       => '2025-01-31',
            'currency'         => 'USD',
            'exchange_rate'    => 1.0,
            'gross_amount_fc'  => 0.00,
            'gross_amount_bc'  => 0.00,
            'tax_fc'           => 0.00,
            'tax_bc'           => 0.00,
            'net_amount_fc'    => 0.00,
            'net_amount_bc'    => 0.00,
            'notes'            => null,
            'created_at'       => '2025-01-15',
            'updated_at'       => '2025-01-15',
        ], $overrides);
    }

    /**
     * Create a Salary repository stub for create operations.
     * Returns the given ID from create(), findById returns null (not needed for create).
     */
    private function makeSalaryRepoStubForCreate(int $returnId): SalaryRepository
    {
        return new class($returnId) extends SalaryRepository {
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
     * Create a Salary repository stub for update operations.
     * findById returns the given before record, update is no-op.
     */
    private function makeSalaryRepoStubForUpdate(array $beforeRecord): SalaryRepository
    {
        return new class($beforeRecord) extends SalaryRepository {
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
     * Create a Salary repository stub for delete operations.
     * findById returns the given before record, delete is no-op.
     */
    private function makeSalaryRepoStubForDelete(array $beforeRecord): SalaryRepository
    {
        return new class($beforeRecord) extends SalaryRepository {
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

            public function sumPaymentAllocationsForSalary(int $salaryId): float
            {
                return 0.0; // No allocations — allow deletion
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

            public function ensurePayeeBelongsToBusiness(int $payeeId, int $businessId): void
            {
                // no-op for tests — assume payee belongs to business
            }
        };
    }

    /**
     * Build a SalaryService with mocked dependencies injected via reflection.
     */
    private function buildService(
        SalaryRepository $salaryRepo,
        PeopleRepository $peopleRepo
    ): SalaryService {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Mock prepare() to support checkPeriodOverlap and other inline SQL
        $stmtMock = $this->createMock(\PDOStatement::class);
        $stmtMock->method('execute')->willReturn(true);
        $stmtMock->method('fetchColumn')->willReturn(0); // No overlapping records
        $pdo->method('prepare')->willReturn($stmtMock);

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

        $ref     = new ReflectionClass(SalaryService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $props = [
            'db'                  => $pdo,
            'repo'                => $salaryRepo,
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
