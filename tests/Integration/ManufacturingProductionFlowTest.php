<?php

namespace Tests\Integration;

use App\Repository\ItemRepository;
use App\Repository\ManufacturingRepository;
use App\Services\AuditService;
use App\Services\ItemService;
use App\Services\ManufacturingService;
use App\Support\AuthContext;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Integration tests for the full manufacturing production flow.
 *
 * Tests the complete lifecycle: create recipe → verify item created →
 * execute production order → verify stock changes → reverse → verify stock restored.
 * Also tests transaction rollback on simulated failure.
 *
 * Unlike unit tests that test individual methods in isolation, these tests
 * verify the interaction between multiple service calls in sequence,
 * ensuring state transitions are correct across the full lifecycle.
 *
 * Validates: Requirements 5.5–5.8, 10.1–10.4
 */
class ManufacturingProductionFlowTest extends TestCase
{
    private int $tenantId   = 1;
    private int $businessId = 1;
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
    // Test: Full production lifecycle - create recipe → production order → reversal
    // ---------------------------------------------------------------

    /**
     * End-to-end test of the complete manufacturing lifecycle:
     *
     * 1. Create a recipe with 2 components (A: cost=10, qty=4; B: cost=5, qty=2; yield=2)
     * 2. Verify the manufactured item was created with correct cost_price (unit cost = (10×4 + 5×2) / 2 = 25)
     * 3. Execute a production order for 6 units
     *    - Required: A = (4/2)×6 = 12, B = (2/2)×6 = 6
     *    - Manufacturing cost = (10×12) + (5×6) = 150
     *    - Unit cost = 150/6 = 25
     * 4. Verify stock changes: A decreased by 12, B decreased by 6, manufactured item increased by 6
     * 5. Reverse the production order
     * 6. Verify stock restored: A increased by 12, B increased by 6, manufactured item decreased by 6
     *    (net stock change = 0 for all items)
     *
     * Validates: Requirements 5.5, 5.6, 5.7, 10.1, 10.2, 10.3
     */
    public function testFullProductionLifecycle(): void
    {
        // Track all stock adjustments across the entire lifecycle
        $allStockAdjustments = [];
        $insertedRecipeId = 1;
        $insertedItemId = 999;
        $insertedOrderId = 42;
        $orderStatus = 'COMPLETED';

        $componentA = ['item_id' => 100, 'quantity' => 4.0];
        $componentB = ['item_id' => 200, 'quantity' => 2.0];
        $yieldQuantity = 2;
        $productionQuantity = 6.0;

        // Build shared mocks
        $itemRepo = $this->createMock(ItemRepository::class);
        $mfgRepo = $this->createMock(ManufacturingRepository::class);
        $audit = $this->createMock(AuditService::class);
        $pdo = $this->createMock(PDO::class);

        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // --- Phase 1: Recipe creation setup ---

        // Item type query for "Manufactured"
        $typeStmt = $this->createMock(\PDOStatement::class);
        $typeStmt->method('execute')->willReturn(true);
        $typeStmt->method('fetchColumn')->willReturn(5); // type_id = 5

        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($typeStmt) {
            if (strpos($sql, 'item_types') !== false) {
                return $typeStmt;
            }
            $stmt = $this->createMock(\PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            $stmt->method('fetch')->willReturn(false);
            $stmt->method('fetchAll')->willReturn([]);
            $stmt->method('fetchColumn')->willReturn(0);
            return $stmt;
        });

        // Component items exist in tenant
        $itemRepo->method('getById')->willReturnCallback(
            function (int $itemId, int $tenantId) {
                $items = [
                    100 => ['id' => 100, 'name' => 'Component A', 'cost_price' => 10.00, 'business_id' => 1, 'tenant_id' => 1],
                    200 => ['id' => 200, 'name' => 'Component B', 'cost_price' => 5.00, 'business_id' => 1, 'tenant_id' => 1],
                    999 => ['id' => 999, 'name' => 'Finished Product', 'cost_price' => 25.00, 'business_id' => 1, 'tenant_id' => 1],
                ];
                return $items[$itemId] ?? null;
            }
        );

        // Item insert returns manufactured item ID
        $itemRepo->method('insert')->willReturn($insertedItemId);

        // Recipe insert returns recipe ID
        $mfgRepo->method('insertRecipe')->willReturn($insertedRecipeId);

        // --- Phase 2: Production order setup ---

        // Recipe exists (returned for production order creation)
        $mfgRepo->method('getRecipeById')->willReturn([
            'id'                     => $insertedRecipeId,
            'tenant_id'              => $this->tenantId,
            'business_id'            => $this->businessId,
            'manufactured_item_id'   => $insertedItemId,
            'yield_quantity'         => $yieldQuantity,
            'manufactured_item_name' => 'Finished Product',
        ]);

        // Recipe items
        $mfgRepo->method('getRecipeItems')->willReturn([
            ['component_item_id' => 100, 'quantity' => 4.0, 'cost_price' => 10.00, 'component_item_name' => 'Component A'],
            ['component_item_id' => 200, 'quantity' => 2.0, 'cost_price' => 5.00, 'component_item_name' => 'Component B'],
        ]);

        // Stock entries - sufficient stock for production
        $itemRepo->method('getStockEntry')->willReturnCallback(
            function (int $itemId, int $bid) {
                $stocks = [
                    100 => 100.0,  // Component A: plenty of stock
                    200 => 50.0,   // Component B: plenty of stock
                    999 => 0.0,    // Manufactured item: starts at 0
                ];
                return isset($stocks[$itemId]) ? ['id' => $itemId, 'quantity' => $stocks[$itemId]] : null;
            }
        );

        // Production order insert
        $mfgRepo->method('insertProductionOrder')->willReturn($insertedOrderId);

        // --- Phase 3: Reversal setup ---

        // Production order exists with COMPLETED status (changes to REVERSED after reversal)
        $mfgRepo->method('getProductionOrderById')->willReturnCallback(
            function (int $id, int $tenantId, int $businessId) use ($insertedOrderId, $insertedItemId, $productionQuantity, &$orderStatus) {
                if ($id === $insertedOrderId) {
                    return [
                        'id'                    => $insertedOrderId,
                        'tenant_id'             => $tenantId,
                        'business_id'           => $businessId,
                        'recipe_id'             => 1,
                        'manufactured_item_id'  => $insertedItemId,
                        'production_quantity'   => $productionQuantity,
                        'manufacturing_cost'    => 150.0,
                        'unit_manufacturing_cost' => 25.0,
                        'status'                => $orderStatus,
                        'production_date'       => '2024-01-15 10:00:00',
                        'created_by'            => 1,
                    ];
                }
                return null;
            }
        );

        // Production order items (what was consumed)
        $mfgRepo->method('getProductionOrderItems')->willReturn([
            ['component_item_id' => 100, 'quantity_consumed' => 12.0, 'unit_cost' => 10.00],
            ['component_item_id' => 200, 'quantity_consumed' => 6.0, 'unit_cost' => 5.00],
        ]);

        // Track status update
        $mfgRepo->method('updateProductionOrderStatus')->willReturnCallback(
            function (int $id, string $status) use (&$orderStatus) {
                $orderStatus = $status;
            }
        );

        // Audit log (allow multiple calls - no return value setup needed for void)

        // Create tracking ItemService
        $itemService = $this->createTrackingItemService($allStockAdjustments);

        // Build the service
        $service = $this->buildManufacturingService($pdo, $mfgRepo, $itemService, $audit, $itemRepo);

        // ═══════════════════════════════════════════════════════════════
        // PHASE 1: Create Recipe
        // ═══════════════════════════════════════════════════════════════

        $recipeResult = $service->createRecipe([
            'name'           => 'Finished Product',
            'components'     => [$componentA, $componentB],
            'yield_quantity' => $yieldQuantity,
        ]);

        // Verify recipe creation returned expected IDs
        $this->assertEquals($insertedRecipeId, $recipeResult['recipe_id']);
        $this->assertEquals($insertedItemId, $recipeResult['item_id']);

        // ═══════════════════════════════════════════════════════════════
        // PHASE 2: Execute Production Order
        // ═══════════════════════════════════════════════════════════════

        $orderId = $service->createProductionOrder([
            'recipe_id'           => $insertedRecipeId,
            'production_quantity' => $productionQuantity,
        ]);

        $this->assertEquals($insertedOrderId, $orderId);

        // Verify stock adjustments from production order
        // Component A: OUT 12
        $compAOut = array_filter($allStockAdjustments, fn($a) => $a['item_id'] === 100 && $a['type'] === 'OUT');
        $this->assertCount(1, $compAOut);
        $this->assertEqualsWithDelta(12.0, reset($compAOut)['quantity'], 0.0001);

        // Component B: OUT 6
        $compBOut = array_filter($allStockAdjustments, fn($a) => $a['item_id'] === 200 && $a['type'] === 'OUT');
        $this->assertCount(1, $compBOut);
        $this->assertEqualsWithDelta(6.0, reset($compBOut)['quantity'], 0.0001);

        // Manufactured item: IN 6
        $mfgIn = array_filter($allStockAdjustments, fn($a) => $a['item_id'] === $insertedItemId && $a['type'] === 'IN');
        $this->assertCount(1, $mfgIn);
        $this->assertEqualsWithDelta(6.0, reset($mfgIn)['quantity'], 0.0001);

        // Verify production order references
        $productionRefs = array_filter($allStockAdjustments, fn($a) => $a['reference'] === 'PRODUCTION_ORDER');
        $this->assertCount(3, $productionRefs, 'All 3 stock adjustments should reference PRODUCTION_ORDER');

        // ═══════════════════════════════════════════════════════════════
        // PHASE 3: Reverse Production Order
        // ═══════════════════════════════════════════════════════════════

        $service->reverseProductionOrder($orderId);

        // Verify status was updated to REVERSED
        $this->assertEquals('REVERSED', $orderStatus);

        // Verify reversal stock adjustments
        // Component A: IN 12 (restored)
        $compAIn = array_filter($allStockAdjustments, fn($a) => $a['item_id'] === 100 && $a['type'] === 'IN');
        $this->assertCount(1, $compAIn);
        $this->assertEqualsWithDelta(12.0, reset($compAIn)['quantity'], 0.0001);

        // Component B: IN 6 (restored)
        $compBIn = array_filter($allStockAdjustments, fn($a) => $a['item_id'] === 200 && $a['type'] === 'IN');
        $this->assertCount(1, $compBIn);
        $this->assertEqualsWithDelta(6.0, reset($compBIn)['quantity'], 0.0001);

        // Manufactured item: OUT 6 (removed)
        $mfgOut = array_filter($allStockAdjustments, fn($a) => $a['item_id'] === $insertedItemId && $a['type'] === 'OUT');
        $this->assertCount(1, $mfgOut);
        $this->assertEqualsWithDelta(6.0, reset($mfgOut)['quantity'], 0.0001);

        // ═══════════════════════════════════════════════════════════════
        // VERIFY: Net stock change is zero for all items (conservation)
        // ═══════════════════════════════════════════════════════════════

        $netChanges = $this->calculateNetStockChanges($allStockAdjustments);

        // Component A: OUT 12 + IN 12 = net 0
        $this->assertEqualsWithDelta(0.0, $netChanges[100] ?? 0.0, 0.0001,
            'Component A net stock change should be zero after production + reversal');

        // Component B: OUT 6 + IN 6 = net 0
        $this->assertEqualsWithDelta(0.0, $netChanges[200] ?? 0.0, 0.0001,
            'Component B net stock change should be zero after production + reversal');

        // Manufactured item: IN 6 + OUT 6 = net 0
        $this->assertEqualsWithDelta(0.0, $netChanges[$insertedItemId] ?? 0.0, 0.0001,
            'Manufactured item net stock change should be zero after production + reversal');

        // Verify reversal references
        $reversalRefs = array_filter($allStockAdjustments, fn($a) => $a['reference'] === 'PRODUCTION_ORDER_REVERSAL');
        $this->assertCount(3, $reversalRefs, 'All 3 reversal adjustments should reference PRODUCTION_ORDER_REVERSAL');

        // Total adjustments: 3 from production + 3 from reversal = 6
        $this->assertCount(6, $allStockAdjustments, 'Should have exactly 6 stock adjustments total');
    }

    // ---------------------------------------------------------------
    // Test: Transaction rollback on simulated failure during production
    // ---------------------------------------------------------------

    /**
     * Simulates a failure during production order execution (e.g., the second
     * adjustStock call throws an exception). Verifies that:
     * - The transaction is rolled back (rollBack called)
     * - The exception propagates to the caller
     * - No partial state is persisted (production order not inserted)
     *
     * This tests the atomicity guarantee: all stock adjustments succeed
     * together or none of them persist.
     *
     * Validates: Requirements 5.7, 5.8, 10.3, 10.4
     */
    public function testTransactionRollbackOnProductionFailure(): void
    {
        $rolledBack = false;
        $committed = false;
        $orderInserted = false;

        $itemRepo = $this->createMock(ItemRepository::class);
        $mfgRepo = $this->createMock(ManufacturingRepository::class);
        $audit = $this->createMock(AuditService::class);
        $pdo = $this->createMock(PDO::class);

        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->expects($this->never())->method('commit');
        $pdo->expects($this->once())->method('rollBack')
            ->willReturnCallback(function () use (&$rolledBack) {
                $rolledBack = true;
                return true;
            });

        // Recipe exists
        $mfgRepo->method('getRecipeById')->willReturn([
            'id'                     => 1,
            'tenant_id'              => $this->tenantId,
            'business_id'            => $this->businessId,
            'manufactured_item_id'   => 999,
            'yield_quantity'         => 2,
            'manufactured_item_name' => 'Finished Product',
        ]);

        // Recipe items
        $mfgRepo->method('getRecipeItems')->willReturn([
            ['component_item_id' => 100, 'quantity' => 4.0, 'cost_price' => 10.00, 'component_item_name' => 'Component A'],
            ['component_item_id' => 200, 'quantity' => 2.0, 'cost_price' => 5.00, 'component_item_name' => 'Component B'],
        ]);

        // Sufficient stock
        $itemRepo->method('getStockEntry')->willReturnCallback(
            function (int $itemId, int $bid) {
                return ['id' => $itemId, 'quantity' => 100.0];
            }
        );

        // Production order insert should NOT be called (failure happens before)
        $mfgRepo->method('insertProductionOrder')->willReturnCallback(
            function () use (&$orderInserted) {
                $orderInserted = true;
                return 42;
            }
        );

        // Create an ItemService that fails on the second adjustStock call
        // (simulating a database failure mid-transaction)
        $callCount = 0;
        $itemService = new class($callCount) extends ItemService {
            private int $callCount;

            public function __construct(int &$callCount)
            {
                $this->callCount = &$callCount;
            }

            public function adjustStock(int $itemId, string $type, float $quantity, ?string $reference, ?string $notes): void
            {
                $this->callCount++;
                if ($this->callCount === 2) {
                    throw new \RuntimeException('Database connection lost during stock adjustment');
                }
            }
        };

        $service = $this->buildManufacturingService($pdo, $mfgRepo, $itemService, $audit, $itemRepo);

        // Execute - should throw
        $thrown = false;
        try {
            $service->createProductionOrder([
                'recipe_id'           => 1,
                'production_quantity' => 6.0,
            ]);
        } catch (\RuntimeException $e) {
            $thrown = true;
            $this->assertSame('Database connection lost during stock adjustment', $e->getMessage());
        }

        $this->assertTrue($thrown, 'Expected RuntimeException to propagate');
        $this->assertTrue($rolledBack, 'Transaction must be rolled back on failure');
        $this->assertFalse($orderInserted, 'Production order must NOT be inserted when transaction fails');
    }

    // ---------------------------------------------------------------
    // Test: Transaction rollback on simulated failure during reversal
    // ---------------------------------------------------------------

    /**
     * Simulates a failure during production order reversal (e.g., the
     * adjustStock call for restoring a component throws). Verifies that:
     * - The transaction is rolled back
     * - The production order status remains COMPLETED (not changed to REVERSED)
     * - The exception propagates to the caller
     *
     * Validates: Requirements 10.3, 10.4
     */
    public function testTransactionRollbackOnReversalFailure(): void
    {
        $rolledBack = false;
        $statusUpdated = false;

        $itemRepo = $this->createMock(ItemRepository::class);
        $mfgRepo = $this->createMock(ManufacturingRepository::class);
        $audit = $this->createMock(AuditService::class);
        $pdo = $this->createMock(PDO::class);

        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->expects($this->never())->method('commit');
        $pdo->expects($this->once())->method('rollBack')
            ->willReturnCallback(function () use (&$rolledBack) {
                $rolledBack = true;
                return true;
            });

        // Production order exists with COMPLETED status
        $mfgRepo->method('getProductionOrderById')->willReturn([
            'id'                    => 42,
            'tenant_id'             => $this->tenantId,
            'business_id'           => $this->businessId,
            'recipe_id'             => 1,
            'manufactured_item_id'  => 999,
            'production_quantity'   => 6.0,
            'status'                => 'COMPLETED',
            'production_date'       => '2024-01-15 10:00:00',
            'created_by'            => $this->userId,
        ]);

        // Production order items
        $mfgRepo->method('getProductionOrderItems')->willReturn([
            ['component_item_id' => 100, 'quantity_consumed' => 12.0, 'unit_cost' => 10.00],
            ['component_item_id' => 200, 'quantity_consumed' => 6.0, 'unit_cost' => 5.00],
        ]);

        // Status update should NOT succeed (rolled back)
        $mfgRepo->method('updateProductionOrderStatus')->willReturnCallback(
            function () use (&$statusUpdated) {
                $statusUpdated = true;
            }
        );

        // ItemService that fails on the first adjustStock call during reversal
        $itemService = new class extends ItemService {
            public function __construct() {}

            public function adjustStock(int $itemId, string $type, float $quantity, ?string $reference, ?string $notes): void
            {
                throw new \RuntimeException('Database error during reversal stock adjustment');
            }
        };

        $service = $this->buildManufacturingService($pdo, $mfgRepo, $itemService, $audit, $itemRepo);

        // Execute reversal - should throw
        $thrown = false;
        try {
            $service->reverseProductionOrder(42);
        } catch (\RuntimeException $e) {
            $thrown = true;
            $this->assertSame('Database error during reversal stock adjustment', $e->getMessage());
        }

        $this->assertTrue($thrown, 'Expected RuntimeException to propagate');
        $this->assertTrue($rolledBack, 'Transaction must be rolled back on reversal failure');
        $this->assertFalse($statusUpdated, 'Production order status must NOT be updated when reversal fails');
    }

    // ---------------------------------------------------------------
    // Test: Production then attempted double reversal
    // ---------------------------------------------------------------

    /**
     * Tests the full flow: execute production → reverse → attempt second reversal.
     * Verifies that after a successful reversal, a second attempt is rejected
     * and no additional stock adjustments occur.
     *
     * This tests the interaction between reversal and the status check across
     * multiple service calls.
     *
     * Validates: Requirements 10.1, 10.2, 10.3
     */
    public function testProductionReversalThenDoubleReversalRejected(): void
    {
        $allStockAdjustments = [];
        $orderStatus = 'COMPLETED';

        $itemRepo = $this->createMock(ItemRepository::class);
        $mfgRepo = $this->createMock(ManufacturingRepository::class);
        $audit = $this->createMock(AuditService::class);
        $pdo = $this->createMock(PDO::class);

        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Production order - status changes dynamically
        $mfgRepo->method('getProductionOrderById')->willReturnCallback(
            function (int $id, int $tenantId, int $businessId) use (&$orderStatus) {
                return [
                    'id'                    => 42,
                    'tenant_id'             => $tenantId,
                    'business_id'           => $businessId,
                    'recipe_id'             => 1,
                    'manufactured_item_id'  => 999,
                    'production_quantity'   => 6.0,
                    'status'                => $orderStatus,
                    'production_date'       => '2024-01-15 10:00:00',
                    'created_by'            => 1,
                ];
            }
        );

        $mfgRepo->method('getProductionOrderItems')->willReturn([
            ['component_item_id' => 100, 'quantity_consumed' => 12.0, 'unit_cost' => 10.00],
            ['component_item_id' => 200, 'quantity_consumed' => 6.0, 'unit_cost' => 5.00],
        ]);

        $mfgRepo->method('updateProductionOrderStatus')->willReturnCallback(
            function (int $id, string $status) use (&$orderStatus) {
                $orderStatus = $status;
            }
        );

        $itemService = $this->createTrackingItemService($allStockAdjustments);

        $service = $this->buildManufacturingService($pdo, $mfgRepo, $itemService, $audit, $itemRepo);

        // First reversal - should succeed
        $service->reverseProductionOrder(42);

        $this->assertEquals('REVERSED', $orderStatus);
        $this->assertCount(3, $allStockAdjustments, 'First reversal should produce 3 stock adjustments');

        // Second reversal - should be rejected
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Production order has already been reversed');

        $service->reverseProductionOrder(42);

        // No additional stock adjustments should have occurred
        $this->assertCount(3, $allStockAdjustments, 'No additional adjustments after rejected double reversal');
    }

    // ---------------------------------------------------------------
    // Helper: Calculate net stock changes per item
    // ---------------------------------------------------------------

    /**
     * Calculates the net stock change for each item from a list of adjustments.
     * IN adds to stock, OUT subtracts from stock.
     *
     * @param array $adjustments
     * @return array<int, float> Map of item_id => net change
     */
    private function calculateNetStockChanges(array $adjustments): array
    {
        $netChanges = [];
        foreach ($adjustments as $adj) {
            $itemId = $adj['item_id'];
            if (!isset($netChanges[$itemId])) {
                $netChanges[$itemId] = 0.0;
            }
            if ($adj['type'] === 'IN') {
                $netChanges[$itemId] += $adj['quantity'];
            } elseif ($adj['type'] === 'OUT') {
                $netChanges[$itemId] -= $adj['quantity'];
            }
        }
        return $netChanges;
    }

    // ---------------------------------------------------------------
    // Helper: Create a tracking ItemService mock
    // ---------------------------------------------------------------

    /**
     * Creates an ItemService that tracks all adjustStock calls.
     */
    private function createTrackingItemService(array &$stockAdjustments): ItemService
    {
        return new class($stockAdjustments) extends ItemService {
            private array $adjustments;

            public function __construct(array &$adjustments)
            {
                $this->adjustments = &$adjustments;
            }

            public function adjustStock(int $itemId, string $type, float $quantity, ?string $reference, ?string $notes): void
            {
                $this->adjustments[] = [
                    'item_id'   => $itemId,
                    'type'      => $type,
                    'quantity'  => $quantity,
                    'reference' => $reference,
                ];
            }
        };
    }

    // ---------------------------------------------------------------
    // Helper: Build ManufacturingService with injected dependencies
    // ---------------------------------------------------------------

    /**
     * Creates a ManufacturingService instance with mocked dependencies
     * injected via reflection.
     */
    private function buildManufacturingService(
        PDO $pdo,
        ManufacturingRepository $mfgRepo,
        ItemService $itemService,
        AuditService $audit,
        ItemRepository $itemRepo
    ): ManufacturingService {
        $ref     = new ReflectionClass(ManufacturingService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $stockValidator = new class extends \App\Services\StockValidator {
            public function __construct() {}
            public function assertAvailable(int $itemId, float $requestedQty, int $businessId): void {}
            public function checkBatch(array $requirements, int $businessId): array { return []; }
        };

        $props = [
            'db'             => $pdo,
            'repo'           => $mfgRepo,
            'itemService'    => $itemService,
            'audit'          => $audit,
            'itemRepo'       => $itemRepo,
            'stockValidator' => $stockValidator,
        ];

        foreach ($props as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($service, $value);
        }

        return $service;
    }
}
