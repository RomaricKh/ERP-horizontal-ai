<?php

namespace Tests\Property;

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
 * Feature: people-balance-tracking, Property 1: PO receive-then-cancel round-trip
 *
 * For any Purchase Order with a supplier and a positive total_amount_bc,
 * transitioning to RECEIVED and then to CANCELLED should return the
 * supplier's payee balance to its original value (net delta = 0).
 *
 * Validates: Requirements 1.1, 1.2, 8.1
 */
class PurchaseOrderBalancePropertyTest extends TestCase
{
    private const ITERATIONS = 120;

    /**
     * Data provider generating 120 random PO amounts and supplier IDs.
     *
     * Each case: [supplierId, totalAmountBc]
     */
    public static function receiveThenCancelProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $supplierId    = mt_rand(1, 10000);
            // Random amount between 0.01 and 999999.99
            $totalAmountBc = round(mt_rand(1, 99999999) / 100, 2);
            $cases["supplier_{$supplierId}_amount_{$totalAmountBc}_iter_{$i}"] = [
                $supplierId,
                $totalAmountBc,
            ];
        }
        return $cases;
    }

    /**
     * Property 1: Purchase Order receive-then-cancel round-trip
     *
     * After RECEIVED then CANCELLED, the net delta passed to
     * updatePayeeBalance() must be zero.
     *
     * **Validates: Requirements 1.1, 1.2, 8.1**
     *
     * @dataProvider receiveThenCancelProvider
     */
    public function testReceiveThenCancelNetDeltaIsZero(
        int $supplierId,
        float $totalAmountBc
    ): void {
        $poId       = mt_rand(1, 10000);
        $businessId = mt_rand(1, 100);

        // Set up AuthContext
        AuthContext::set((object) [
            'user_id'     => 1,
            'tenant_id'   => 1,
            'business_id' => $businessId,
        ]);

        // PO record template
        $poRecord = [
            'id'              => $poId,
            'business_id'     => $businessId,
            'supplier_id'     => $supplierId,
            'po_number'       => 'PO-2025-001',
            'po_date'         => '2025-01-01',
            'currency'        => 'USD',
            'exchange_rate'   => 1.0,
            'total_amount_fc' => $totalAmountBc,
            'total_amount_bc' => $totalAmountBc,
            'notes'           => null,
            'created_at'      => '2025-01-01',
            'updated_at'      => '2025-01-01',
            'created_by'      => 1,
        ];

        // Track balance deltas
        $balanceDeltas = [];

        // -- Build stubs using anonymous subclasses --

        // PO repository: returns APPROVED on first findById, RECEIVED on second
        $poRepo = new class($poRecord) extends PurchaseOrderRepository {
            /** @var array */
            private $record;
            /** @var int */
            private $callCount = 0;

            public function __construct(array $record)
            {
                // Skip parent constructor (needs PDO)
                $this->record = $record;
            }

            public function findById(int $id, int $businessId): ?array
            {
                $status = $this->callCount === 0 ? 'APPROVED' : 'RECEIVED';
                $this->callCount++;
                return array_merge($this->record, ['status' => $status]);
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

        // Item service stub
        $itemService = new class extends ItemService {
            public function __construct()
            {
                // Skip parent constructor
            }

            public function adjustStock(int $itemId, string $type, float $quantity, ?string $reference, ?string $notes): void
            {
                // no-op
            }
        };

        // PDO stub
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Mock PDOStatement for the payment status check (RECEIVED→CANCELLED guard)
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn(0.0); // UNPAID — cancellation allowed
        $pdo->method('prepare')->willReturn($stmt);

        // Build service with injected mocks
        $service = $this->buildServiceWithMocks($pdo, $poRepo, $peopleRepo, $audit, $itemService);

        // Step 1: Transition APPROVED → RECEIVED (increases balance)
        $service->updateStatus($poId, 'RECEIVED');

        // Step 2: Transition RECEIVED → CANCELLED (should reverse balance)
        $service->updateStatus($poId, 'CANCELLED');

        // Assert: exactly 2 balance calls were made
        $this->assertCount(
            2,
            $balanceDeltas,
            'Expected exactly 2 balance updates (one for RECEIVED, one for CANCELLED)'
        );

        // Assert: both calls targeted the correct supplier
        $this->assertSame(
            $supplierId,
            $balanceDeltas[0]['payeeId'],
            'RECEIVED balance update must target the correct supplier'
        );
        $this->assertSame(
            $supplierId,
            $balanceDeltas[1]['payeeId'],
            'CANCELLED balance update must target the correct supplier'
        );

        // Assert: first delta is -amount (RECEIVED: business owes supplier)
        $this->assertEqualsWithDelta(
            -$totalAmountBc,
            $balanceDeltas[0]['delta'],
            0.001,
            'First delta (RECEIVED) should be -totalAmountBc'
        );

        // Assert: second delta is +amount (CANCELLED reverses balance)
        $this->assertEqualsWithDelta(
            $totalAmountBc,
            $balanceDeltas[1]['delta'],
            0.001,
            'Second delta (CANCELLED) should be +totalAmountBc'
        );

        // Assert: net delta is zero (round-trip property)
        $netDelta = $balanceDeltas[0]['delta'] + $balanceDeltas[1]['delta'];
        $this->assertEqualsWithDelta(
            0.0,
            $netDelta,
            0.001,
            sprintf(
                'Net balance delta must be zero after receive-then-cancel. '
                . 'Supplier: %d, Amount: %.2f, Deltas: [%.2f, %.2f], Net: %.2f',
                $supplierId,
                $totalAmountBc,
                $balanceDeltas[0]['delta'],
                $balanceDeltas[1]['delta'],
                $netDelta
            )
        );
    }

    /**
     * Data provider generating 120 random PO amounts and supplier IDs
     * for non-balance-impacting cancellation from APPROVED status.
     *
     * Each case: [supplierId, totalAmountBc]
     */
    public static function cancelFromApprovedProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $supplierId    = mt_rand(1, 10000);
            // Random amount between 0.01 and 999999.99
            $totalAmountBc = round(mt_rand(1, 99999999) / 100, 2);
            $cases["supplier_{$supplierId}_amount_{$totalAmountBc}_iter_{$i}"] = [
                $supplierId,
                $totalAmountBc,
            ];
        }
        return $cases;
    }

    /**
     * Property 9 (PO part): Non-balance-impacting cancellation is a no-op
     *
     * Cancelling a PO from APPROVED status (not RECEIVED) must NOT call
     * updatePayeeBalance(), because no balance increase happened at APPROVED.
     *
     * **Validates: Requirements 1.3, 8.3**
     *
     * @dataProvider cancelFromApprovedProvider
     */
    public function testCancelFromApprovedDoesNotUpdateBalance(
        int $supplierId,
        float $totalAmountBc
    ): void {
        $poId       = mt_rand(1, 10000);
        $businessId = mt_rand(1, 100);

        // Set up AuthContext
        AuthContext::set((object) [
            'user_id'     => 1,
            'tenant_id'   => 1,
            'business_id' => $businessId,
        ]);

        // PO record in APPROVED status
        $poRecord = [
            'id'              => $poId,
            'business_id'     => $businessId,
            'supplier_id'     => $supplierId,
            'po_number'       => 'PO-2025-002',
            'po_date'         => '2025-01-01',
            'currency'        => 'USD',
            'exchange_rate'   => 1.0,
            'total_amount_fc' => $totalAmountBc,
            'total_amount_bc' => $totalAmountBc,
            'notes'           => null,
            'created_at'      => '2025-01-01',
            'updated_at'      => '2025-01-01',
            'created_by'      => 1,
        ];

        // Track balance deltas
        $balanceDeltas = [];

        // PO repository: always returns PO in APPROVED status
        $poRepo = new class($poRecord) extends PurchaseOrderRepository {
            /** @var array */
            private $record;

            public function __construct(array $record)
            {
                $this->record = $record;
            }

            public function findById(int $id, int $businessId): ?array
            {
                return array_merge($this->record, ['status' => 'APPROVED']);
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

        // People repository: tracks all updatePayeeBalance calls
        $peopleRepo = new class($balanceDeltas) extends PeopleRepository {
            /** @var array */
            private $deltas;

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

        // Item service stub
        $itemService = new class extends ItemService {
            public function __construct()
            {
            }

            public function adjustStock(int $itemId, string $type, float $quantity, ?string $reference, ?string $notes): void
            {
            }
        };

        // PDO stub
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Build service with injected mocks
        $service = $this->buildServiceWithMocks($pdo, $poRepo, $peopleRepo, $audit, $itemService);

        // Transition APPROVED → CANCELLED
        $service->updateStatus($poId, 'CANCELLED');

        // Assert: no balance calls were made
        $this->assertCount(
            0,
            $balanceDeltas,
            sprintf(
                'Cancelling a PO from APPROVED must NOT call updatePayeeBalance(). '
                . 'Supplier: %d, Amount: %.2f, but got %d balance call(s)',
                $supplierId,
                $totalAmountBc,
                count($balanceDeltas)
            )
        );
    }

    /**
     * Build a PurchaseOrderService with mocked dependencies injected via reflection.
     */
    private function buildServiceWithMocks(
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
