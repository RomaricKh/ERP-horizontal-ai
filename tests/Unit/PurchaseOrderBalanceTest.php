<?php

namespace Tests\Unit;

use App\Repository\PeopleRepository;
use App\Repository\PurchaseOrderRepository;
use App\Services\AuditService;
use App\Services\ExchangeRateService;
use App\Services\ItemService;
use App\Services\PurchaseOrderService;
use App\Support\AuthContext;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for PurchaseOrderService balance edge cases.
 *
 * Validates: Requirements 1.2, 1.3, 1.4
 */
class PurchaseOrderBalanceTest extends TestCase
{
    private int $businessId = 1;
    private int $poId       = 100;

    protected function setUp(): void
    {
        AuthContext::set((object) [
            'user_id'     => 1,
            'tenant_id'   => 1,
            'business_id' => $this->businessId,
        ]);
    }

    /**
     * Test: CANCELLED from RECEIVED with null supplier_id — no balance call.
     *
     * When a PO has no supplier assigned (supplier_id is null), cancelling
     * from RECEIVED must not call updatePayeeBalance().
     *
     * Validates: Requirements 1.2, 1.4
     */
    public function testCancelFromReceivedWithNullSupplierIdDoesNotCallBalance(): void
    {
        $balanceDeltas = [];

        $poRecord = $this->makePoRecord([
            'supplier_id'     => null,
            'total_amount_bc' => 500.00,
            'status'          => 'RECEIVED',
        ]);

        $poRepo     = $this->makePoRepoStub($poRecord, 'RECEIVED');
        $peopleRepo = $this->makePeopleRepoStub($balanceDeltas);
        $service    = $this->buildService($poRepo, $peopleRepo);

        $service->updateStatus($this->poId, 'CANCELLED');

        $this->assertCount(
            0,
            $balanceDeltas,
            'No balance call expected when supplier_id is null'
        );
    }

    /**
     * Test: CANCELLED from RECEIVED with zero total_amount_bc — no balance call.
     *
     * When a PO has a zero total_amount_bc, the guard condition ($totalBc > 0)
     * should prevent any balance update.
     *
     * Validates: Requirements 1.2, 1.4
     */
    public function testCancelFromReceivedWithZeroAmountDoesNotCallBalance(): void
    {
        $balanceDeltas = [];

        $poRecord = $this->makePoRecord([
            'supplier_id'     => 42,
            'total_amount_bc' => 0.00,
            'status'          => 'RECEIVED',
        ]);

        $poRepo     = $this->makePoRepoStub($poRecord, 'RECEIVED');
        $peopleRepo = $this->makePeopleRepoStub($balanceDeltas);
        $service    = $this->buildService($poRepo, $peopleRepo);

        $service->updateStatus($this->poId, 'CANCELLED');

        $this->assertCount(
            0,
            $balanceDeltas,
            'No balance call expected when total_amount_bc is zero'
        );
    }

    /**
     * Test: CANCELLED from APPROVED — no balance call.
     *
     * Cancelling a PO from APPROVED (not RECEIVED) must not touch the
     * supplier balance because no balance increase happened at APPROVED.
     *
     * Validates: Requirement 1.3
     */
    public function testCancelFromApprovedDoesNotCallBalance(): void
    {
        $balanceDeltas = [];

        $poRecord = $this->makePoRecord([
            'supplier_id'     => 42,
            'total_amount_bc' => 1500.00,
            'status'          => 'APPROVED',
        ]);

        $poRepo     = $this->makePoRepoStub($poRecord, 'APPROVED');
        $peopleRepo = $this->makePeopleRepoStub($balanceDeltas);
        $service    = $this->buildService($poRepo, $peopleRepo);

        $service->updateStatus($this->poId, 'CANCELLED');

        $this->assertCount(
            0,
            $balanceDeltas,
            'No balance call expected when cancelling from APPROVED status'
        );
    }

    /**
     * Test: RECEIVED correctly decreases supplier balance (business owes supplier).
     *
     * When a PO transitions to RECEIVED with a valid supplier and positive
     * total_amount_bc, the supplier balance must decrease by that amount
     * (negative delta = business owes the supplier).
     *
     * Validates: Requirement 1.2
     */
    public function testReceivedDecreasesSupplierBalance(): void
    {
        $balanceDeltas = [];
        $supplierId    = 77;
        $totalBc       = 2500.50;

        $poRecord = $this->makePoRecord([
            'supplier_id'     => $supplierId,
            'total_amount_bc' => $totalBc,
            'status'          => 'APPROVED',
        ]);

        $poRepo     = $this->makePoRepoStub($poRecord, 'APPROVED');
        $peopleRepo = $this->makePeopleRepoStub($balanceDeltas);
        $service    = $this->buildService($poRepo, $peopleRepo);

        $service->updateStatus($this->poId, 'RECEIVED');

        $this->assertCount(
            1,
            $balanceDeltas,
            'Expected exactly 1 balance call for RECEIVED transition'
        );
        $this->assertSame(
            $supplierId,
            $balanceDeltas[0]['payeeId'],
            'Balance update must target the correct supplier'
        );
        $this->assertEqualsWithDelta(
            -$totalBc,
            $balanceDeltas[0]['delta'],
            0.001,
            'RECEIVED should decrease supplier balance by total_amount_bc (business owes supplier)'
        );
    }

    // ---------------------------------------------------------------
    // Helper methods
    // ---------------------------------------------------------------

    /**
     * Build a base PO record array, merging in overrides.
     */
    private function makePoRecord(array $overrides = []): array
    {
        return array_merge([
            'id'              => $this->poId,
            'business_id'     => $this->businessId,
            'supplier_id'     => null,
            'po_number'       => 'PO-TEST-001',
            'po_date'         => '2025-01-15',
            'currency'        => 'USD',
            'exchange_rate'   => 1.0,
            'total_amount_fc' => 0.00,
            'total_amount_bc' => 0.00,
            'notes'           => null,
            'created_at'      => '2025-01-15',
            'updated_at'      => '2025-01-15',
            'created_by'      => 1,
        ], $overrides);
    }

    /**
     * Create a PO repository stub that always returns the given record
     * with the specified status.
     */
    private function makePoRepoStub(array $record, string $status): PurchaseOrderRepository
    {
        return new class($record, $status) extends PurchaseOrderRepository {
            private array  $record;
            private string $status;

            public function __construct(array $record, string $status)
            {
                $this->record = $record;
                $this->status = $status;
            }

            public function findById(int $id, int $businessId): ?array
            {
                return array_merge($this->record, ['status' => $this->status]);
            }

            public function updateStatus(int $id, int $businessId, string $status): void
            {
                // no-op
            }

            public function getLines(int $poId): array
            {
                return [];
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
     * Build a PurchaseOrderService with mocked dependencies injected via reflection.
     *
     * @param float $totalPaid  Simulated total paid amount returned by payment_slips query (for cancellation guard)
     */
    private function buildService(
        PurchaseOrderRepository $poRepo,
        PeopleRepository $peopleRepo,
        float $totalPaid = 0.0
    ): PurchaseOrderService {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Mock prepare/execute/fetchColumn for the cancellation payment guard query
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn($totalPaid);
        $pdo->method('prepare')->willReturn($stmt);

        $audit = new class extends AuditService {
            public function __construct() {}
            public function log(string $eventType, string $table, $recordId,
                                string $action, ?array $oldValues = null, ?array $newValues = null,
                                ?int $userId = null, ?int $tenantId = null, ?int $businessId = null, bool $force = false, bool $tenantScope = false): void {}
        };

        $itemService = new class extends ItemService {
            public function __construct() {}
            public function adjustStock(int $itemId, string $type, float $quantity, ?string $reference, ?string $notes): void {}
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

        $ref     = new ReflectionClass(PurchaseOrderService::class);
        $service = $ref->newInstanceWithoutConstructor();

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
