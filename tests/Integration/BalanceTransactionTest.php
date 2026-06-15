<?php

namespace Tests\Integration;

use App\Repository\ExpenseRepository;
use App\Repository\PeopleRepository;
use App\Repository\PurchaseOrderRepository;
use App\Repository\SalaryRepository;
use App\Services\AuditService;
use App\Services\ExchangeRateService;
use App\Services\ExpenseService;
use App\Services\ItemService;
use App\Services\PurchaseOrderService;
use App\Services\SalaryService;
use App\Support\AuthContext;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Integration tests for transaction rollback on balance failure.
 *
 * Verifies that when PeopleRepository::updatePayeeBalance() throws an exception,
 * the entire transaction is rolled back (not committed), ensuring atomicity
 * of document operations and balance updates.
 *
 * Validates: Requirements 5.1, 5.2
 */
class BalanceTransactionTest extends TestCase
{
    private int $businessId = 1;

    protected function setUp(): void
    {
        AuthContext::set((object) [
            'user_id'     => 1,
            'tenant_id'   => 1,
            'business_id' => $this->businessId,
        ]);
    }

    // ---------------------------------------------------------------
    // Test: Expense create rolls back when updatePayeeBalance() throws
    // ---------------------------------------------------------------

    /**
     * If updatePayeeBalance() throws during expense create, the expense
     * insert is also rolled back — rollBack() is called, commit() is not.
     *
     * Validates: Requirements 5.1, 5.2
     */
    public function testExpenseCreateRollsBackWhenBalanceUpdateFails(): void
    {
        $committed  = false;
        $rolledBack = false;

        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->expects($this->never())->method('commit');
        $pdo->expects($this->once())->method('rollBack')
            ->willReturnCallback(function () use (&$rolledBack) {
                $rolledBack = true;
                return true;
            });

        $expenseRepo = new class extends ExpenseRepository {
            public bool $createCalled = false;

            public function __construct() {}

            public function create(array $data): int
            {
                $this->createCalled = true;
                return 999;
            }
        };

        $peopleRepo = $this->makeThrowingPeopleRepoStub();

        $audit = $this->makeNoOpAuditService();

        $service = $this->buildExpenseService($pdo, $expenseRepo, $peopleRepo, $audit);

        $thrown = false;
        try {
            $service->create([
                'payee_id'      => 42,
                'expense_date'  => '2025-01-15',
                'currency'      => 'USD',
                'exchange_rate' => 1.0,
                'amount_fc'     => 500.00,
            ]);
        } catch (\RuntimeException $e) {
            $thrown = true;
            $this->assertSame('Balance update failed', $e->getMessage());
        }

        $this->assertTrue($thrown, 'Expected RuntimeException to be thrown');
        $this->assertTrue($expenseRepo->createCalled, 'Expense insert should have been attempted');
        $this->assertTrue($rolledBack, 'Transaction must be rolled back when balance update fails');
    }

    // ---------------------------------------------------------------
    // Test: Salary create rolls back when updatePayeeBalance() throws
    // ---------------------------------------------------------------

    /**
     * If updatePayeeBalance() throws during salary create, the salary
     * insert is also rolled back — rollBack() is called, commit() is not.
     *
     * Validates: Requirements 5.1, 5.2
     */
    public function testSalaryCreateRollsBackWhenBalanceUpdateFails(): void
    {
        $rolledBack = false;

        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->expects($this->never())->method('commit');
        $pdo->expects($this->once())->method('rollBack')
            ->willReturnCallback(function () use (&$rolledBack) {
                $rolledBack = true;
                return true;
            });

        $salaryRepo = new class extends SalaryRepository {
            public bool $createCalled = false;

            public function __construct() {}

            public function create(array $data): int
            {
                $this->createCalled = true;
                return 999;
            }

            public function findById(int $id, int $businessId): ?array
            {
                return null;
            }
        };

        $peopleRepo = $this->makeThrowingPeopleRepoStub();

        $audit = $this->makeNoOpAuditService();

        $service = $this->buildSalaryService($pdo, $salaryRepo, $peopleRepo, $audit);

        $thrown = false;
        try {
            $service->create([
                'payee_id'        => 42,
                'period_start'    => '2025-01-01',
                'period_end'      => '2025-01-31',
                'currency'        => 'USD',
                'exchange_rate'   => 1.0,
                'gross_amount_fc' => 5000.00,
                'tax_fc'          => 500.00,
            ]);
        } catch (\RuntimeException $e) {
            $thrown = true;
            $this->assertSame('Balance update failed', $e->getMessage());
        }

        $this->assertTrue($thrown, 'Expected RuntimeException to be thrown');
        $this->assertTrue($salaryRepo->createCalled, 'Salary insert should have been attempted');
        $this->assertTrue($rolledBack, 'Transaction must be rolled back when balance update fails');
    }

    // ---------------------------------------------------------------
    // Test: PO status change rolls back when updatePayeeBalance() throws
    // ---------------------------------------------------------------

    /**
     * If updatePayeeBalance() throws during PO status change to RECEIVED,
     * the status change is also rolled back — rollBack() is called, commit() is not.
     *
     * Validates: Requirements 5.1, 5.2
     */
    public function testPoStatusChangeRollsBackWhenBalanceUpdateFails(): void
    {
        $rolledBack = false;

        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->expects($this->never())->method('commit');
        $pdo->expects($this->once())->method('rollBack')
            ->willReturnCallback(function () use (&$rolledBack) {
                $rolledBack = true;
                return true;
            });

        $poRepo = new class($this->businessId) extends PurchaseOrderRepository {
            public bool $statusUpdated = false;
            private int $businessId;

            public function __construct(int $businessId)
            {
                $this->businessId = $businessId;
            }

            public function findById(int $id, int $businessId): ?array
            {
                return [
                    'id'              => $id,
                    'business_id'     => $this->businessId,
                    'supplier_id'     => 42,
                    'po_number'       => 'PO-TEST-001',
                    'po_date'         => '2025-01-15',
                    'currency'        => 'USD',
                    'exchange_rate'   => 1.0,
                    'total_amount_fc' => 1000.00,
                    'total_amount_bc' => 1000.00,
                    'status'          => 'APPROVED',
                    'notes'           => null,
                    'created_at'      => '2025-01-15',
                    'updated_at'      => '2025-01-15',
                    'created_by'      => 1,
                ];
            }

            public function updateStatus(int $id, int $businessId, string $status): void
            {
                $this->statusUpdated = true;
            }

            public function getLines(int $poId): array
            {
                return [
                    ['item_id' => 1, 'quantity' => 10],
                ];
            }
        };

        $peopleRepo = $this->makeThrowingPeopleRepoStub();

        $audit = $this->makeNoOpAuditService();

        $itemService = new class extends ItemService {
            public function __construct() {}
            public function adjustStock(int $itemId, string $type, float $quantity, ?string $reference, ?string $notes): void {}
        };

        $service = $this->buildPurchaseOrderService($pdo, $poRepo, $peopleRepo, $audit, $itemService);

        $thrown = false;
        try {
            $service->updateStatus(100, 'RECEIVED');
        } catch (\RuntimeException $e) {
            $thrown = true;
            $this->assertSame('Balance update failed', $e->getMessage());
        }

        $this->assertTrue($thrown, 'Expected RuntimeException to be thrown');
        $this->assertTrue($poRepo->statusUpdated, 'PO status update should have been attempted');
        $this->assertTrue($rolledBack, 'Transaction must be rolled back when balance update fails');
    }

    // ---------------------------------------------------------------
    // Shared helper: PeopleRepository that throws on updatePayeeBalance
    // ---------------------------------------------------------------

    /**
     * Create a PeopleRepository stub that throws a RuntimeException
     * whenever updatePayeeBalance() is called.
     */
    private function makeThrowingPeopleRepoStub(): PeopleRepository
    {
        return new class extends PeopleRepository {
            public function __construct() {}

            public function updatePayeeBalance(int $payeeId, float $delta): void
            {
                throw new \RuntimeException('Balance update failed');
            }
        };
    }

    /**
     * Create a no-op AuditService stub.
     */
    private function makeNoOpAuditService(): AuditService
    {
        return new class extends AuditService {
            public function __construct() {}
            public function log(
                string $eventType, string $table, $recordId,
                string $action, ?array $oldValues = null, ?array $newValues = null,
                ?int $userId = null, ?int $tenantId = null, ?int $businessId = null
            ): void {}
        };
    }

    // ---------------------------------------------------------------
    // Service builders using reflection (same pattern as unit tests)
    // ---------------------------------------------------------------

    /**
     * Build an ExpenseService with injected dependencies via reflection.
     */
    private function buildExpenseService(
        PDO $pdo,
        ExpenseRepository $expenseRepo,
        PeopleRepository $peopleRepo,
        AuditService $audit
    ): ExpenseService {
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

    /**
     * Build a SalaryService with injected dependencies via reflection.
     */
    private function buildSalaryService(
        PDO $pdo,
        SalaryRepository $salaryRepo,
        PeopleRepository $peopleRepo,
        AuditService $audit
    ): SalaryService {
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

    /**
     * Build a PurchaseOrderService with injected dependencies via reflection.
     */
    private function buildPurchaseOrderService(
        PDO $pdo,
        PurchaseOrderRepository $poRepo,
        PeopleRepository $peopleRepo,
        AuditService $audit,
        ItemService $itemService
    ): PurchaseOrderService {
        $ref     = new ReflectionClass(PurchaseOrderService::class);
        $service = $ref->newInstanceWithoutConstructor();

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

        $props = [
            'db'                  => $pdo,
            'repo'                => $poRepo,
            'peopleRepo'          => $peopleRepo,
            'exchangeRateService' => $exchangeRateService,
            'audit'               => $audit,
            'itemService'         => $itemService,
        ];

        foreach ($props as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($service, $value);
        }

        return $service;
    }
}
