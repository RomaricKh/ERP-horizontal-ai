<?php

namespace Tests\Integration;

use App\Repository\PurchaseOrderRepository;
use App\Repository\PeopleRepository;
use App\Services\AuditService;
use App\Services\ExchangeRateService;
use App\Services\ItemService;
use App\Services\PurchaseOrderService;
use App\Support\AuthContext;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Integration tests for end-to-end PO receipt price update flow.
 *
 * Verifies that PriceUpdater is correctly invoked during PO RECEIVED transition,
 * that deduplication works for multi-line POs, that cancellation does not revert
 * prices, and that transaction rollback works when PriceUpdater throws.
 *
 * Validates: Requirements 1.1, 1.2, 1.3, 2.1, 3.1, 3.2, 4.1, 4.2, 6.1, 6.2
 */
class PoPriceUpdateIntegrationTest extends TestCase
{
    private int $businessId = 1;
    private int $tenantId   = 1;
    private int $userId     = 1;

    protected function setUp(): void
    {
        AuthContext::set((object) [
            'user_id'     => $this->userId,
            'tenant_id'   => $this->tenantId,
            'business_id' => $this->businessId,
        ]);
    }

    // ---------------------------------------------------------------
    // Test: Receiving a PO updates item cost prices, inserts history,
    //       and creates audit entries
    // ---------------------------------------------------------------

    /**
     * Full flow: PO with lines where unit_price_bc differs from item's cost_price.
     * After RECEIVED, verify:
     * - ItemRepository::updateCostPrice was called with correct values
     * - ItemPriceRepository::insert was called with correct data
     * - AuditService::log was called with PRICE_UPDATE action
     *
     * Validates: Requirements 1.1, 1.2, 1.3, 2.1, 3.1, 4.1
     */
    public function testReceivePOUpdatesItemCostPrice(): void
    {
        $executedQueries = [];
        $executedParams  = [];

        // Item 10 has cost_price 50.00, PO line has unit_price_bc 75.00 (different)
        // Item 20 has cost_price 100.00, PO line has unit_price_bc 100.00 (same - should skip)
        $items = [
            10 => [
                'id' => 10, 'cost_price' => 50.00, 'sale_price' => 120.00,
                'business_id' => $this->businessId, 'currency' => 'USD',
                'tenant_id' => $this->tenantId, 'name' => 'Widget A',
                'item_type_id' => 1, 'type_name' => 'Product',
            ],
            20 => [
                'id' => 20, 'cost_price' => 100.00, 'sale_price' => 200.00,
                'business_id' => $this->businessId, 'currency' => 'USD',
                'tenant_id' => $this->tenantId, 'name' => 'Widget B',
                'item_type_id' => 1, 'type_name' => 'Product',
            ],
        ];

        $pdo = $this->buildTrackingPdo($items, $executedQueries, $executedParams);

        $poLines = [
            ['id' => 1, 'item_id' => 10, 'quantity' => 5, 'unit_price_fc' => 75.00, 'unit_price_bc' => 75.00],
            ['id' => 2, 'item_id' => 20, 'quantity' => 3, 'unit_price_fc' => 100.00, 'unit_price_bc' => 100.00],
        ];

        $poRepo = $this->buildPoRepoStub('APPROVED', $poLines);
        $peopleRepo = $this->makeNoOpPeopleRepo();
        $audit = $this->makeNoOpAuditService();
        $itemService = $this->makeNoOpItemService();

        $service = $this->buildPurchaseOrderService($pdo, $poRepo, $peopleRepo, $audit, $itemService);
        $service->updateStatus(100, 'RECEIVED');

        // Verify updateCostPrice was called for item 10 (price differs)
        $costPriceUpdates = array_filter($executedQueries, function ($q) {
            return strpos($q, 'UPDATE items') !== false && strpos($q, 'cost_price') !== false;
        });
        $this->assertNotEmpty($costPriceUpdates, 'Expected cost_price UPDATE for item with different price');

        // Verify the correct price was used for item 10
        $updateParams = array_filter($executedParams, function ($p) {
            return isset($p['cost_price']) && $p['cost_price'] == 75.00;
        });
        $this->assertNotEmpty($updateParams, 'Expected cost_price to be set to 75.00 for item 10');

        // Verify item_prices INSERT was called
        $priceInserts = array_filter($executedQueries, function ($q) {
            return strpos($q, 'INSERT INTO item_prices') !== false;
        });
        $this->assertNotEmpty($priceInserts, 'Expected item_prices INSERT for price change');

        // Verify audit_logs INSERT was called with PRICE_UPDATE
        $auditInserts = array_filter($executedParams, function ($p) {
            return isset($p['action']) && $p['action'] === 'PRICE_UPDATE';
        });
        $this->assertNotEmpty($auditInserts, 'Expected audit entry with PRICE_UPDATE action');

        // Verify item 20 was NOT updated (price is the same)
        $item20Updates = array_filter($executedParams, function ($p) {
            return isset($p['cost_price']) && isset($p['id']) && $p['id'] == 20;
        });
        $this->assertEmpty($item20Updates, 'Item 20 should NOT be updated since price matches');
    }

    // ---------------------------------------------------------------
    // Test: Multi-line PO with duplicate item_ids deduplicates
    // ---------------------------------------------------------------

    /**
     * PO with multiple lines for same item_id (different prices).
     * After RECEIVED, verify only the LAST line's price was used for the update
     * and only one history record per unique item_id.
     *
     * Validates: Requirements 4.2, 2.1
     */
    public function testReceivePOWithDuplicateItemIdsDeduplicates(): void
    {
        $executedQueries = [];
        $executedParams  = [];

        // Item 10 has cost_price 50.00
        // PO has two lines for item 10: first at 60.00, second at 80.00
        // Last line wins → should update to 80.00
        $items = [
            10 => [
                'id' => 10, 'cost_price' => 50.00, 'sale_price' => 120.00,
                'business_id' => $this->businessId, 'currency' => 'USD',
                'tenant_id' => $this->tenantId, 'name' => 'Widget A',
                'item_type_id' => 1, 'type_name' => 'Product',
            ],
        ];

        $pdo = $this->buildTrackingPdo($items, $executedQueries, $executedParams);

        $poLines = [
            ['id' => 1, 'item_id' => 10, 'quantity' => 5, 'unit_price_fc' => 60.00, 'unit_price_bc' => 60.00],
            ['id' => 2, 'item_id' => 10, 'quantity' => 3, 'unit_price_fc' => 80.00, 'unit_price_bc' => 80.00],
        ];

        $poRepo = $this->buildPoRepoStub('APPROVED', $poLines);
        $peopleRepo = $this->makeNoOpPeopleRepo();
        $audit = $this->makeNoOpAuditService();
        $itemService = $this->makeNoOpItemService();

        $service = $this->buildPurchaseOrderService($pdo, $poRepo, $peopleRepo, $audit, $itemService);
        $service->updateStatus(100, 'RECEIVED');

        // Verify only ONE cost_price update was made (deduplication)
        $costPriceUpdates = array_filter($executedParams, function ($p) {
            return isset($p['cost_price']) && isset($p['id']) && $p['id'] == 10;
        });
        $this->assertCount(1, $costPriceUpdates, 'Expected exactly one cost_price update for deduplicated item');

        // Verify the LAST line's price (80.00) was used
        $updateParam = reset($costPriceUpdates);
        $this->assertEquals(80.00, $updateParam['cost_price'], 'Last line price (80.00) should win');

        // Verify only ONE item_prices INSERT for item 10
        $priceInsertParams = array_filter($executedParams, function ($p) {
            return isset($p['item_id']) && $p['item_id'] == 10 && isset($p['cost_price']) && isset($p['valid_from']);
        });
        $this->assertCount(1, $priceInsertParams, 'Expected exactly one item_prices record per unique item_id');
    }

    // ---------------------------------------------------------------
    // Test: Cancelling a RECEIVED PO does NOT revert prices
    // ---------------------------------------------------------------

    /**
     * Transition PO to RECEIVED (prices update), then to CANCELLED.
     * Verify no additional updateCostPrice calls during cancellation.
     * Verify no additional item_prices inserts during cancellation.
     *
     * Validates: Requirements 6.1, 6.2
     */
    public function testCancelReceivedPODoesNotRevertPrices(): void
    {
        $executedQueries = [];
        $executedParams  = [];

        $items = [
            10 => [
                'id' => 10, 'cost_price' => 50.00, 'sale_price' => 120.00,
                'business_id' => $this->businessId, 'currency' => 'USD',
                'tenant_id' => $this->tenantId, 'name' => 'Widget A',
                'item_type_id' => 1, 'type_name' => 'Product',
            ],
        ];

        $pdo = $this->buildTrackingPdo($items, $executedQueries, $executedParams);

        $poLines = [
            ['id' => 1, 'item_id' => 10, 'quantity' => 5, 'unit_price_fc' => 75.00, 'unit_price_bc' => 75.00],
        ];

        // First: transition to RECEIVED
        $statusSequence = ['APPROVED', 'RECEIVED']; // findById returns APPROVED first, then RECEIVED
        $callCount = 0;

        $poRepo = new class($this->businessId, $poLines, $statusSequence) extends PurchaseOrderRepository {
            private int $businessId;
            private array $lines;
            private array $statusSequence;
            private int $callCount = 0;

            public function __construct(int $businessId, array $lines, array $statusSequence) {
                $this->businessId = $businessId;
                $this->lines = $lines;
                $this->statusSequence = $statusSequence;
            }

            public function findById(int $id, int $businessId): ?array {
                $status = $this->statusSequence[$this->callCount] ?? 'RECEIVED';
                $this->callCount++;
                return [
                    'id' => $id, 'business_id' => $this->businessId,
                    'supplier_id' => 42, 'po_number' => 'PO-TEST-001',
                    'po_date' => '2025-01-15', 'currency' => 'USD',
                    'exchange_rate' => 1.0, 'total_amount_fc' => 375.00,
                    'total_amount_bc' => 375.00, 'status' => $status,
                    'notes' => null, 'created_at' => '2025-01-15',
                    'updated_at' => '2025-01-15', 'created_by' => 1,
                ];
            }

            public function updateStatus(int $id, int $businessId, string $status): void {}
            public function getLines(int $poId): array { return $this->lines; }
        };

        $peopleRepo = $this->makeNoOpPeopleRepo();
        $audit = $this->makeNoOpAuditService();
        $itemService = $this->makeNoOpItemService();

        $service = $this->buildPurchaseOrderService($pdo, $poRepo, $peopleRepo, $audit, $itemService);

        // First call: APPROVED → RECEIVED (triggers price update)
        $service->updateStatus(100, 'RECEIVED');

        // Count price-related operations after RECEIVED
        $costUpdatesAfterReceive = count(array_filter($executedParams, function ($p) {
            return isset($p['cost_price']) && isset($p['id']);
        }));
        $priceInsertsAfterReceive = count(array_filter($executedQueries, function ($q) {
            return strpos($q, 'INSERT INTO item_prices') !== false;
        }));

        // Second call: RECEIVED → CANCELLED (should NOT trigger price updates)
        $service->updateStatus(100, 'CANCELLED');

        // Count price-related operations after CANCELLED
        $costUpdatesAfterCancel = count(array_filter($executedParams, function ($p) {
            return isset($p['cost_price']) && isset($p['id']);
        }));
        $priceInsertsAfterCancel = count(array_filter($executedQueries, function ($q) {
            return strpos($q, 'INSERT INTO item_prices') !== false;
        }));

        // No additional cost_price updates during cancellation
        $this->assertEquals(
            $costUpdatesAfterReceive,
            $costUpdatesAfterCancel,
            'Cancellation must NOT trigger additional cost_price updates'
        );

        // No additional item_prices inserts during cancellation
        $this->assertEquals(
            $priceInsertsAfterReceive,
            $priceInsertsAfterCancel,
            'Cancellation must NOT insert additional item_prices records'
        );
    }

    // ---------------------------------------------------------------
    // Test: Transaction rollback when PriceUpdater fails
    // ---------------------------------------------------------------

    /**
     * Make PriceUpdater throw an exception (by making PDO::prepare throw for
     * the item SELECT query used by ItemRepository::getById).
     * Verify rollBack() is called and commit() is NOT called.
     *
     * Validates: Requirements 3.1, 3.2
     */
    public function testReceivePORollsBackWhenPriceUpdateFails(): void
    {
        $rolledBack = false;
        $committed  = false;

        $poLines = [
            ['id' => 1, 'item_id' => 10, 'quantity' => 5, 'unit_price_fc' => 75.00, 'unit_price_bc' => 75.00],
        ];

        // PDO that throws on the item SELECT query (simulating a DB failure during price update)
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->expects($this->never())->method('commit');
        $pdo->expects($this->once())->method('rollBack')
            ->willReturnCallback(function () use (&$rolledBack) {
                $rolledBack = true;
                return true;
            });

        // PDO::prepare will throw on the SELECT query that PriceUpdater uses
        $callCount = 0;
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$callCount) {
            $callCount++;
            // Allow the first few prepare calls (status update, stock queries)
            // but throw when PriceUpdater tries to query items
            if (strpos($sql, 'SELECT i.*') !== false && strpos($sql, 'item_types') !== false) {
                throw new \RuntimeException('Database connection lost during price update');
            }
            // Return a no-op statement for other queries
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            $stmt->method('fetch')->willReturn(false);
            $stmt->method('fetchAll')->willReturn([]);
            $stmt->method('rowCount')->willReturn(1);
            return $stmt;
        });

        $poRepo = $this->buildPoRepoStub('APPROVED', $poLines);
        $peopleRepo = $this->makeNoOpPeopleRepo();
        $audit = $this->makeNoOpAuditService();
        $itemService = $this->makeNoOpItemService();

        $service = $this->buildPurchaseOrderService($pdo, $poRepo, $peopleRepo, $audit, $itemService);

        $thrown = false;
        try {
            $service->updateStatus(100, 'RECEIVED');
        } catch (\RuntimeException $e) {
            $thrown = true;
            $this->assertSame('Database connection lost during price update', $e->getMessage());
        }

        $this->assertTrue($thrown, 'Expected RuntimeException to be thrown');
        $this->assertTrue($rolledBack, 'Transaction must be rolled back when price update fails');
    }

    // ---------------------------------------------------------------
    // Helper: Build a tracking PDO mock that records queries
    // ---------------------------------------------------------------

    /**
     * Creates a PDO mock that:
     * - Tracks all executed SQL queries and their parameters
     * - Returns item data for SELECT queries matching known items
     * - Returns success for UPDATE/INSERT queries
     */
    private function buildTrackingPdo(array $items, array &$executedQueries, array &$executedParams): PDO
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->method('lastInsertId')->willReturn('1');

        $pdo->method('prepare')->willReturnCallback(
            function (string $sql) use ($items, &$executedQueries, &$executedParams) {
                $executedQueries[] = $sql;

                $stmt = $this->createMock(PDOStatement::class);
                $capturedParams = [];

                $stmt->method('execute')->willReturnCallback(
                    function ($params = null) use (&$capturedParams, &$executedParams, $sql, $items, $stmt) {
                        if ($params !== null) {
                            $capturedParams = $params;
                            $executedParams[] = $params;
                        }
                        return true;
                    }
                );

                $stmt->method('bindValue')->willReturn(true);
                $stmt->method('rowCount')->willReturn(1);

                // For SELECT queries on items table, return matching item data
                if (strpos($sql, 'SELECT i.*') !== false || strpos($sql, 'SELECT i.id') !== false) {
                    $stmt->method('fetch')->willReturnCallback(
                        function () use (&$capturedParams, $items) {
                            $itemId = $capturedParams['id'] ?? null;
                            if ($itemId !== null && isset($items[(int)$itemId])) {
                                return $items[(int)$itemId];
                            }
                            return false;
                        }
                    );
                } else {
                    $stmt->method('fetch')->willReturn(false);
                }

                $stmt->method('fetchAll')->willReturn([]);
                $stmt->method('fetchColumn')->willReturn(0);

                return $stmt;
            }
        );

        return $pdo;
    }

    // ---------------------------------------------------------------
    // Helper: Build PO repository stub
    // ---------------------------------------------------------------

    private function buildPoRepoStub(string $status, array $lines): PurchaseOrderRepository
    {
        $businessId = $this->businessId;

        return new class($businessId, $status, $lines) extends PurchaseOrderRepository {
            private int $businessId;
            private string $status;
            private array $lines;

            public function __construct(int $businessId, string $status, array $lines) {
                $this->businessId = $businessId;
                $this->status = $status;
                $this->lines = $lines;
            }

            public function findById(int $id, int $businessId): ?array {
                return [
                    'id' => $id, 'business_id' => $this->businessId,
                    'supplier_id' => 42, 'po_number' => 'PO-TEST-001',
                    'po_date' => '2025-01-15', 'currency' => 'USD',
                    'exchange_rate' => 1.0, 'total_amount_fc' => 1000.00,
                    'total_amount_bc' => 1000.00, 'status' => $this->status,
                    'notes' => null, 'created_at' => '2025-01-15',
                    'updated_at' => '2025-01-15', 'created_by' => 1,
                ];
            }

            public function updateStatus(int $id, int $businessId, string $status): void {}
            public function getLines(int $poId): array { return $this->lines; }
        };
    }

    // ---------------------------------------------------------------
    // Helper: No-op PeopleRepository
    // ---------------------------------------------------------------

    private function makeNoOpPeopleRepo(): PeopleRepository
    {
        return new class extends PeopleRepository {
            public function __construct() {}
            public function updatePayeeBalance(int $payeeId, float $delta): void {}
        };
    }

    // ---------------------------------------------------------------
    // Helper: No-op AuditService
    // ---------------------------------------------------------------

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
    // Helper: No-op ItemService
    // ---------------------------------------------------------------

    private function makeNoOpItemService(): ItemService
    {
        return new class extends ItemService {
            public function __construct() {}
            public function adjustStock(int $itemId, string $type, float $quantity, ?string $reference, ?string $notes): void {}
        };
    }

    // ---------------------------------------------------------------
    // Helper: Build PurchaseOrderService with injected dependencies
    // ---------------------------------------------------------------

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
            public function resolveRate(string $currency, ?float $providedRate): float {
                return $providedRate ?? 1.0;
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
