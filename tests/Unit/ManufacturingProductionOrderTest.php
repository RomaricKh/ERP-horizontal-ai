<?php

namespace Tests\Unit;

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
 * Unit tests for ManufacturingService production order operations.
 *
 * Tests production order creation with known recipe and quantities,
 * reversal restoring stock to original levels, double reversal rejection,
 * and insufficient stock returning descriptive error with all short items.
 *
 * Validates: Requirements 5.1–5.8, 10.1–10.6
 */
class ManufacturingProductionOrderTest extends TestCase
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
    // Test: Production order creation with known recipe and quantities
    // ---------------------------------------------------------------

    /**
     * Production order creation deducts components and adds manufactured item.
     *
     * Given a recipe:
     * - Component A (item_id=100): cost_price=10.00, recipe_qty=4, stock=100
     * - Component B (item_id=200): cost_price=5.00, recipe_qty=2, stock=50
     * - Yield: 2 units
     * - Manufactured item: item_id=999
     *
     * Production quantity: 6 units
     *
     * Required quantities:
     * - Component A: (4 / 2) × 6 = 12
     * - Component B: (2 / 2) × 6 = 6
     *
     * Manufacturing cost: (10 × 12) + (5 × 6) = 120 + 30 = 150
     * Unit manufacturing cost: 150 / 6 = 25.00
     *
     * Verify:
     * - Component A stock decreased by 12 (OUT)
     * - Component B stock decreased by 6 (OUT)
     * - Manufactured item stock increased by 6 (IN)
     * - Production order is inserted with correct cost values
     * - Audit log is recorded
     *
     * Validates: Requirements 5.1, 5.2, 5.5, 5.6, 5.7
     */
    public function testCreateProductionOrderHappyPath(): void
    {
        $stockAdjustments = [];
        $insertedOrder = null;
        $insertedOrderItems = null;
        $auditLogged = false;

        $recipeId = 1;
        $manufacturedItemId = 999;
        $productionQuantity = 6.0;

        $components = [
            ['item_id' => 100, 'quantity' => 4.0, 'cost_price' => 10.00, 'stock' => 100.0, 'name' => 'Component A'],
            ['item_id' => 200, 'quantity' => 2.0, 'cost_price' => 5.00, 'stock' => 50.0, 'name' => 'Component B'],
        ];
        $yieldQuantity = 2;

        // Build mocks
        $itemRepo = $this->createMock(ItemRepository::class);
        $mfgRepo = $this->createMock(ManufacturingRepository::class);
        $audit = $this->createMock(AuditService::class);
        $pdo = $this->createMock(PDO::class);

        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Recipe exists
        $mfgRepo->method('getRecipeById')->willReturn([
            'id'                     => $recipeId,
            'tenant_id'              => $this->tenantId,
            'business_id'            => $this->businessId,
            'manufactured_item_id'   => $manufacturedItemId,
            'yield_quantity'         => $yieldQuantity,
            'manufactured_item_name' => 'Finished Product',
            'unit'                   => 'kg',
        ]);

        // Recipe items
        $mfgRepo->method('getRecipeItems')->willReturn([
            ['component_item_id' => 100, 'quantity' => 4.0, 'cost_price' => 10.00, 'component_item_name' => 'Component A'],
            ['component_item_id' => 200, 'quantity' => 2.0, 'cost_price' => 5.00, 'component_item_name' => 'Component B'],
        ]);

        // Stock entries
        $itemRepo->method('getStockEntry')->willReturnCallback(
            function (int $itemId, int $bid) use ($components) {
                foreach ($components as $c) {
                    if ($c['item_id'] === $itemId) {
                        return ['id' => $itemId, 'quantity' => $c['stock']];
                    }
                }
                return ['id' => $itemId, 'quantity' => 0.0];
            }
        );

        // Track production order insertion
        $mfgRepo->method('insertProductionOrder')->willReturnCallback(
            function (array $data) use (&$insertedOrder) {
                $insertedOrder = $data;
                return 42;
            }
        );

        // Track production order items insertion
        $mfgRepo->method('insertProductionOrderItems')->willReturnCallback(
            function (int $orderId, array $items) use (&$insertedOrderItems) {
                $insertedOrderItems = ['order_id' => $orderId, 'items' => $items];
            }
        );

        // Track audit log
        $audit->expects($this->once())->method('log')
            ->willReturnCallback(function () use (&$auditLogged) {
                $auditLogged = true;
            });

        // Mock ItemService that tracks adjustStock calls
        $itemService = $this->createTrackingItemService($stockAdjustments);

        $service = $this->buildManufacturingService($pdo, $mfgRepo, $itemService, $audit, $itemRepo);

        // Execute
        $orderId = $service->createProductionOrder([
            'recipe_id'           => $recipeId,
            'production_quantity' => $productionQuantity,
        ]);

        // Assertions
        $this->assertEquals(42, $orderId);

        // Verify stock adjustments
        // Component A: OUT 12
        $compAAdjustments = array_filter($stockAdjustments, fn($a) => $a['item_id'] === 100);
        $this->assertCount(1, $compAAdjustments);
        $compA = reset($compAAdjustments);
        $this->assertEquals('OUT', $compA['type']);
        $this->assertEqualsWithDelta(12.0, $compA['quantity'], 0.0001);

        // Component B: OUT 6
        $compBAdjustments = array_filter($stockAdjustments, fn($a) => $a['item_id'] === 200);
        $this->assertCount(1, $compBAdjustments);
        $compB = reset($compBAdjustments);
        $this->assertEquals('OUT', $compB['type']);
        $this->assertEqualsWithDelta(6.0, $compB['quantity'], 0.0001);

        // Manufactured item: IN 6
        $mfgAdjustments = array_filter($stockAdjustments, fn($a) => $a['item_id'] === $manufacturedItemId);
        $this->assertCount(1, $mfgAdjustments);
        $mfg = reset($mfgAdjustments);
        $this->assertEquals('IN', $mfg['type']);
        $this->assertEqualsWithDelta(6.0, $mfg['quantity'], 0.0001);

        // Verify production order data
        $this->assertNotNull($insertedOrder);
        $this->assertEquals($recipeId, $insertedOrder['recipe_id']);
        $this->assertEquals($manufacturedItemId, $insertedOrder['manufactured_item_id']);
        $this->assertEqualsWithDelta(6.0, $insertedOrder['production_quantity'], 0.0001);
        $this->assertEqualsWithDelta(150.0, $insertedOrder['manufacturing_cost'], 0.0001);
        $this->assertEqualsWithDelta(25.0, $insertedOrder['unit_manufacturing_cost'], 0.0001);
        $this->assertEquals('COMPLETED', $insertedOrder['status']);

        // Verify production order items
        $this->assertNotNull($insertedOrderItems);
        $this->assertEquals(42, $insertedOrderItems['order_id']);
        $this->assertCount(2, $insertedOrderItems['items']);
        $this->assertEquals(100, $insertedOrderItems['items'][0]['component_item_id']);
        $this->assertEqualsWithDelta(12.0, $insertedOrderItems['items'][0]['quantity_consumed'], 0.0001);
        $this->assertEquals(200, $insertedOrderItems['items'][1]['component_item_id']);
        $this->assertEqualsWithDelta(6.0, $insertedOrderItems['items'][1]['quantity_consumed'], 0.0001);

        // Audit was logged
        $this->assertTrue($auditLogged);
    }

    // ---------------------------------------------------------------
    // Test: Reversal restores stock to original levels
    // ---------------------------------------------------------------

    /**
     * Reversing a production order restores all stock to pre-execution state.
     *
     * Given a completed production order:
     * - Component A consumed 12 units
     * - Component B consumed 6 units
     * - Manufactured item produced 6 units
     *
     * After reversal:
     * - Component A: IN 12 (restored)
     * - Component B: IN 6 (restored)
     * - Manufactured item: OUT 6 (removed)
     * - Status updated to REVERSED
     *
     * Validates: Requirements 10.1, 10.2, 10.3, 10.5
     */
    public function testReverseProductionOrderRestoresStock(): void
    {
        $stockAdjustments = [];
        $statusUpdated = null;
        $auditLogged = false;

        $orderId = 42;
        $manufacturedItemId = 999;
        $productionQuantity = 6.0;

        $itemRepo = $this->createMock(ItemRepository::class);
        $mfgRepo = $this->createMock(ManufacturingRepository::class);
        $audit = $this->createMock(AuditService::class);
        $pdo = $this->createMock(PDO::class);

        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Production order exists with COMPLETED status
        $mfgRepo->method('getProductionOrderById')->willReturn([
            'id'                    => $orderId,
            'tenant_id'             => $this->tenantId,
            'business_id'           => $this->businessId,
            'recipe_id'             => 1,
            'manufactured_item_id'  => $manufacturedItemId,
            'production_quantity'   => $productionQuantity,
            'status'                => 'COMPLETED',
            'production_date'       => '2024-01-15 10:00:00',
            'created_by'            => $this->userId,
        ]);

        // Production order items (what was consumed)
        $mfgRepo->method('getProductionOrderItems')->willReturn([
            ['component_item_id' => 100, 'quantity_consumed' => 12.0, 'unit_cost' => 10.00],
            ['component_item_id' => 200, 'quantity_consumed' => 6.0, 'unit_cost' => 5.00],
        ]);

        // Track status update
        $mfgRepo->method('updateProductionOrderStatus')->willReturnCallback(
            function (int $id, string $status, int $tenantId, int $businessId) use (&$statusUpdated) {
                $statusUpdated = ['id' => $id, 'status' => $status];
            }
        );

        // Track audit log
        $audit->expects($this->once())->method('log')
            ->willReturnCallback(function () use (&$auditLogged) {
                $auditLogged = true;
            });

        $itemService = $this->createTrackingItemService($stockAdjustments);

        $service = $this->buildManufacturingService($pdo, $mfgRepo, $itemService, $audit, $itemRepo);

        // Execute reversal
        $service->reverseProductionOrder($orderId);

        // Verify stock adjustments for reversal
        // Component A: IN 12 (restored)
        $compAAdjustments = array_filter($stockAdjustments, fn($a) => $a['item_id'] === 100);
        $this->assertCount(1, $compAAdjustments);
        $compA = reset($compAAdjustments);
        $this->assertEquals('IN', $compA['type']);
        $this->assertEqualsWithDelta(12.0, $compA['quantity'], 0.0001);

        // Component B: IN 6 (restored)
        $compBAdjustments = array_filter($stockAdjustments, fn($a) => $a['item_id'] === 200);
        $this->assertCount(1, $compBAdjustments);
        $compB = reset($compBAdjustments);
        $this->assertEquals('IN', $compB['type']);
        $this->assertEqualsWithDelta(6.0, $compB['quantity'], 0.0001);

        // Manufactured item: OUT 6 (removed)
        $mfgAdjustments = array_filter($stockAdjustments, fn($a) => $a['item_id'] === $manufacturedItemId);
        $this->assertCount(1, $mfgAdjustments);
        $mfg = reset($mfgAdjustments);
        $this->assertEquals('OUT', $mfg['type']);
        $this->assertEqualsWithDelta(6.0, $mfg['quantity'], 0.0001);

        // Status was updated to REVERSED
        $this->assertNotNull($statusUpdated);
        $this->assertEquals($orderId, $statusUpdated['id']);
        $this->assertEquals('REVERSED', $statusUpdated['status']);

        // Audit was logged
        $this->assertTrue($auditLogged);
    }

    // ---------------------------------------------------------------
    // Test: Double reversal is rejected
    // ---------------------------------------------------------------

    /**
     * Attempting to reverse an already-reversed production order is rejected.
     *
     * Given a production order with status REVERSED, a second reversal
     * attempt should throw InvalidArgumentException with a descriptive
     * message and no stock adjustments should occur.
     *
     * Validates: Requirements 10.6
     */
    public function testDoubleReversalIsRejected(): void
    {
        $stockAdjustments = [];

        $orderId = 42;

        $itemRepo = $this->createMock(ItemRepository::class);
        $mfgRepo = $this->createMock(ManufacturingRepository::class);
        $audit = $this->createMock(AuditService::class);
        $pdo = $this->createMock(PDO::class);

        // Production order exists with REVERSED status
        $mfgRepo->method('getProductionOrderById')->willReturn([
            'id'                    => $orderId,
            'tenant_id'             => $this->tenantId,
            'business_id'           => $this->businessId,
            'recipe_id'             => 1,
            'manufactured_item_id'  => 999,
            'production_quantity'   => 6.0,
            'status'                => 'REVERSED',
            'production_date'       => '2024-01-15 10:00:00',
            'created_by'            => $this->userId,
        ]);

        // These should NOT be called
        $mfgRepo->expects($this->never())->method('getProductionOrderItems');
        $mfgRepo->expects($this->never())->method('updateProductionOrderStatus');
        $audit->expects($this->never())->method('log');

        $itemService = $this->createTrackingItemService($stockAdjustments);

        $service = $this->buildManufacturingService($pdo, $mfgRepo, $itemService, $audit, $itemRepo);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Production order has already been reversed');

        $service->reverseProductionOrder($orderId);

        // No stock adjustments should have occurred
        $this->assertEmpty($stockAdjustments);
    }

    // ---------------------------------------------------------------
    // Test: Insufficient stock returns descriptive error with all short items
    // ---------------------------------------------------------------

    /**
     * When multiple components have insufficient stock, the error message
     * lists ALL short items (not just the first one found).
     *
     * Given a recipe:
     * - Component A (item_id=100): recipe_qty=4, yield=2, stock=5
     * - Component B (item_id=200): recipe_qty=2, yield=2, stock=2
     * - Component C (item_id=300): recipe_qty=6, yield=2, stock=100
     *
     * Production quantity: 10 units
     *
     * Required quantities:
     * - Component A: (4/2) × 10 = 20 → stock 5, short by 15
     * - Component B: (2/2) × 10 = 10 → stock 2, short by 8
     * - Component C: (6/2) × 10 = 30 → stock 100, sufficient
     *
     * Error should mention both Component A and Component B.
     *
     * Validates: Requirements 5.3, 5.4, 5.8
     */
    public function testInsufficientStockReturnsDescriptiveErrorWithAllShortItems(): void
    {
        $stockAdjustments = [];

        $recipeId = 1;
        $manufacturedItemId = 999;
        $productionQuantity = 10.0;
        $yieldQuantity = 2;

        $itemRepo = $this->createMock(ItemRepository::class);
        $mfgRepo = $this->createMock(ManufacturingRepository::class);
        $audit = $this->createMock(AuditService::class);
        $pdo = $this->createMock(PDO::class);

        // Recipe exists
        $mfgRepo->method('getRecipeById')->willReturn([
            'id'                     => $recipeId,
            'tenant_id'              => $this->tenantId,
            'business_id'            => $this->businessId,
            'manufactured_item_id'   => $manufacturedItemId,
            'yield_quantity'         => $yieldQuantity,
            'manufactured_item_name' => 'Finished Product',
            'unit'                   => 'kg',
        ]);

        // Recipe items
        $mfgRepo->method('getRecipeItems')->willReturn([
            ['component_item_id' => 100, 'quantity' => 4.0, 'cost_price' => 10.00, 'component_item_name' => 'Component A'],
            ['component_item_id' => 200, 'quantity' => 2.0, 'cost_price' => 5.00, 'component_item_name' => 'Component B'],
            ['component_item_id' => 300, 'quantity' => 6.0, 'cost_price' => 3.00, 'component_item_name' => 'Component C'],
        ]);

        // Stock entries - A and B are insufficient, C is sufficient
        $itemRepo->method('getStockEntry')->willReturnCallback(
            function (int $itemId, int $bid) {
                $stocks = [
                    100 => 5.0,   // Component A: need 20, have 5
                    200 => 2.0,   // Component B: need 10, have 2
                    300 => 100.0, // Component C: need 30, have 100
                ];
                if (isset($stocks[$itemId])) {
                    return ['id' => $itemId, 'quantity' => $stocks[$itemId]];
                }
                return null;
            }
        );

        // These should NOT be called (order rejected before execution)
        $mfgRepo->expects($this->never())->method('insertProductionOrder');
        $mfgRepo->expects($this->never())->method('insertProductionOrderItems');
        $audit->expects($this->never())->method('log');

        $itemService = $this->createTrackingItemService($stockAdjustments);

        // Build a stock validator that checks against the mocked stock levels
        $stockValidator = new class extends \App\Services\StockValidator {
            /** @var array<int, array{name: string, stock: float}> */
            public array $stockMap = [];

            public function __construct() {}

            public function assertSufficientStock(int $itemId, int $businessId, float $quantity): void {
                $available = $this->stockMap[$itemId]['stock'] ?? 0.0;
                if ($available < $quantity) {
                    $name = $this->stockMap[$itemId]['name'] ?? "Item #{$itemId}";
                    throw new \RuntimeException(
                        "Insufficient stock for '{$name}': available {$available}, requested {$quantity}"
                    );
                }
            }

            public function assertAvailable(int $itemId, float $requestedQty, int $businessId): void {}

            public function checkBatch(array $requirements, int $businessId): array {
                $shortfalls = [];
                foreach ($requirements as $req) {
                    $itemId = (int) $req['item_id'];
                    $needed = (float) $req['required'];
                    $available = $this->stockMap[$itemId]['stock'] ?? 0.0;
                    if ($available < $needed) {
                        $name = $this->stockMap[$itemId]['name'] ?? "Item #{$itemId}";
                        $shortfalls[] = "Insufficient stock for: {$name} (need {$needed}, have {$available})";
                    }
                }
                return $shortfalls;
            }
        };
        $stockValidator->stockMap = [
            100 => ['name' => 'Component A', 'stock' => 5.0],
            200 => ['name' => 'Component B', 'stock' => 2.0],
            300 => ['name' => 'Component C', 'stock' => 100.0],
        ];

        $service = $this->buildManufacturingService($pdo, $mfgRepo, $itemService, $audit, $itemRepo, $stockValidator);

        try {
            $service->createProductionOrder([
                'recipe_id'           => $recipeId,
                'production_quantity' => $productionQuantity,
            ]);
            $this->fail('Expected InvalidArgumentException was not thrown');
        } catch (\InvalidArgumentException $e) {
            $message = $e->getMessage();

            // Error should mention both short items
            $this->assertStringContainsString('Component A', $message);
            $this->assertStringContainsString('Component B', $message);

            // Error should include need/have details
            $this->assertStringContainsString('need 20', $message);
            $this->assertStringContainsString('have 5', $message);
            $this->assertStringContainsString('need 10', $message);
            $this->assertStringContainsString('have 2', $message);

            // Component C should NOT be in the error (it has sufficient stock)
            $this->assertStringNotContainsString('Component C', $message);
        }

        // No stock adjustments should have occurred
        $this->assertEmpty($stockAdjustments);
    }

    // ---------------------------------------------------------------
    // Test: Production order rejected when recipe not found
    // ---------------------------------------------------------------

    /**
     * Creating a production order with a non-existent recipe is rejected.
     *
     * Validates: Requirements 5.1
     */
    public function testProductionOrderRejectedWhenRecipeNotFound(): void
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        $mfgRepo = $this->createMock(ManufacturingRepository::class);
        $audit = $this->createMock(AuditService::class);
        $pdo = $this->createMock(PDO::class);

        // Recipe does not exist
        $mfgRepo->method('getRecipeById')->willReturn(null);

        // These should NOT be called
        $mfgRepo->expects($this->never())->method('insertProductionOrder');
        $audit->expects($this->never())->method('log');

        $stockAdjustments = [];
        $itemService = $this->createTrackingItemService($stockAdjustments);

        $service = $this->buildManufacturingService($pdo, $mfgRepo, $itemService, $audit, $itemRepo);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Recipe not found');

        $service->createProductionOrder([
            'recipe_id'           => 999,
            'production_quantity' => 5.0,
        ]);
    }

    // ---------------------------------------------------------------
    // Test: Production order rejected when production quantity is zero or negative
    // ---------------------------------------------------------------

    /**
     * Creating a production order with zero or negative quantity is rejected.
     *
     * Validates: Requirements 5.1
     */
    public function testProductionOrderRejectedWhenQuantityInvalid(): void
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        $mfgRepo = $this->createMock(ManufacturingRepository::class);
        $audit = $this->createMock(AuditService::class);
        $pdo = $this->createMock(PDO::class);

        // Recipe exists
        $mfgRepo->method('getRecipeById')->willReturn([
            'id'                     => 1,
            'tenant_id'              => $this->tenantId,
            'business_id'            => $this->businessId,
            'manufactured_item_id'   => 999,
            'yield_quantity'         => 2,
            'manufactured_item_name' => 'Finished Product',
            'unit'                   => 'kg',
        ]);

        // These should NOT be called
        $mfgRepo->expects($this->never())->method('insertProductionOrder');
        $audit->expects($this->never())->method('log');

        $stockAdjustments = [];
        $itemService = $this->createTrackingItemService($stockAdjustments);

        $service = $this->buildManufacturingService($pdo, $mfgRepo, $itemService, $audit, $itemRepo);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Production quantity must be greater than zero');

        $service->createProductionOrder([
            'recipe_id'           => 1,
            'production_quantity' => 0,
        ]);
    }

    // ---------------------------------------------------------------
    // Helper: Create a tracking ItemService mock
    // ---------------------------------------------------------------

    /**
     * Creates an ItemService mock that tracks all adjustStock calls.
     */
    private function createTrackingItemService(array &$stockAdjustments): ItemService
    {
        $itemService = new class($stockAdjustments) extends ItemService {
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

        return $itemService;
    }

    // ---------------------------------------------------------------
    // Helper: Build ManufacturingService with injected dependencies
    // ---------------------------------------------------------------

    /**
     * Creates a ManufacturingService instance with mocked dependencies
     * injected via reflection (since the constructor creates them internally).
     */
    private function buildManufacturingService(
        PDO $pdo,
        ManufacturingRepository $mfgRepo,
        ItemService $itemService,
        AuditService $audit,
        ItemRepository $itemRepo,
        ?\App\Services\StockValidator $stockValidator = null
    ): ManufacturingService {
        $ref     = new ReflectionClass(ManufacturingService::class);
        $service = $ref->newInstanceWithoutConstructor();

        if ($stockValidator === null) {
            $stockValidator = new class extends \App\Services\StockValidator {
                public function __construct() {}
                public function assertSufficientStock(int $itemId, int $businessId, float $quantity): void {}
                public function assertAvailable(int $itemId, float $requestedQty, int $businessId): void {}
                public function checkBatch(array $requirements, int $businessId): array { return []; }
            };
        }

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
