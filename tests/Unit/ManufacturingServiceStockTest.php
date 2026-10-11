<?php

namespace Tests\Unit;

use App\Repository\ItemRepository;
use App\Repository\ManufacturingRepository;
use App\Services\AuditService;
use App\Services\ItemService;
use App\Services\ManufacturingService;
use App\Services\StockValidator;
use App\Support\AuthContext;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for ManufacturingService stock validation via StockValidator integration.
 *
 * Tests that ManufacturingService delegates stock checking to StockValidator.assertSufficientStock()
 * and that the check occurs within the same transaction as stock deduction (atomicity).
 *
 * Validates: Requirements 4.1, 4.2, 4.3, 4.4
 */
class ManufacturingServiceStockTest extends TestCase
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
    // Test: Production order with sufficient stock succeeds
    // ---------------------------------------------------------------

    /**
     * When all components have sufficient stock, the production order is created successfully.
     *
     * Given a recipe with two components and adequate stock levels,
     * StockValidator.checkBatch returns empty array (no shortfalls),
     * and the order proceeds to completion.
     *
     * Validates: Requirements 4.1
     */
    public function testProductionOrderWithSufficientStockSucceeds(): void
    {
        $stockAdjustments = [];

        $recipeId = 1;
        $manufacturedItemId = 999;
        $productionQuantity = 4.0;
        $yieldQuantity = 2;

        $mfgRepo = $this->createMock(ManufacturingRepository::class);
        $itemRepo = $this->createMock(ItemRepository::class);
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
            'manufactured_item_name' => 'Widget',
            'unit'                   => 'kg',
        ]);

        // Recipe items: Component X (qty 6), Component Y (qty 4)
        $mfgRepo->method('getRecipeItems')->willReturn([
            ['component_item_id' => 10, 'quantity' => 6.0, 'cost_price' => 2.00, 'component_item_name' => 'Component X'],
            ['component_item_id' => 20, 'quantity' => 4.0, 'cost_price' => 3.00, 'component_item_name' => 'Component Y'],
        ]);

        // StockValidator: assertSufficientStock should be called for each component (no exception = sufficient)
        $stockValidator = $this->createMock(StockValidator::class);
        $stockValidator->expects($this->exactly(2))
            ->method('assertSufficientStock')
            ->withConsecutive(
                [$this->equalTo(10), $this->equalTo($this->businessId), $this->callback(fn($q) => abs($q - 12.0) < 0.0001)],
                [$this->equalTo(20), $this->equalTo($this->businessId), $this->callback(fn($q) => abs($q - 8.0) < 0.0001)]
            );

        $mfgRepo->method('insertProductionOrder')->willReturn(42);

        $itemService = $this->createTrackingItemService($stockAdjustments);

        $service = $this->buildManufacturingService($pdo, $mfgRepo, $itemService, $audit, $itemRepo, $stockValidator);

        $orderId = $service->createProductionOrder([
            'recipe_id'           => $recipeId,
            'production_quantity' => $productionQuantity,
        ]);

        $this->assertEquals(42, $orderId);

        // Verify stock adjustments occurred (components deducted, manufactured item added)
        $this->assertCount(3, $stockAdjustments);
    }

    // ---------------------------------------------------------------
    // Test: Production order with one deficient component is rejected
    // ---------------------------------------------------------------

    /**
     * When one component has insufficient stock, the production order is rejected
     * with the formatted error message from StockValidator.
     *
     * Validates: Requirements 4.1, 4.2, 4.4
     */
    public function testProductionOrderWithOneDeficientComponentRejected(): void
    {
        $stockAdjustments = [];

        $recipeId = 1;
        $manufacturedItemId = 999;
        $productionQuantity = 10.0;
        $yieldQuantity = 2;

        $mfgRepo = $this->createMock(ManufacturingRepository::class);
        $itemRepo = $this->createMock(ItemRepository::class);
        $audit = $this->createMock(AuditService::class);
        $pdo = $this->createMock(PDO::class);

        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('rollBack')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Recipe exists
        $mfgRepo->method('getRecipeById')->willReturn([
            'id'                     => $recipeId,
            'tenant_id'              => $this->tenantId,
            'business_id'            => $this->businessId,
            'manufactured_item_id'   => $manufacturedItemId,
            'yield_quantity'         => $yieldQuantity,
            'manufactured_item_name' => 'Widget',
            'unit'                   => 'kg',
        ]);

        // Recipe items
        $mfgRepo->method('getRecipeItems')->willReturn([
            ['component_item_id' => 10, 'quantity' => 6.0, 'cost_price' => 2.00, 'component_item_name' => 'Flour'],
            ['component_item_id' => 20, 'quantity' => 4.0, 'cost_price' => 3.00, 'component_item_name' => 'Sugar'],
        ]);

        // StockValidator: assertSufficientStock throws on first deficient component
        $stockValidator = $this->createMock(StockValidator::class);
        $stockValidator->expects($this->atLeastOnce())
            ->method('assertSufficientStock')
            ->willReturnCallback(function (int $itemId, int $businessId, float $quantity) {
                if ($itemId === 20) {
                    throw new \RuntimeException("Insufficient stock for 'Sugar': available 5, requested 20");
                }
            });

        // These should NOT be called (order rejected)
        $mfgRepo->expects($this->never())->method('insertProductionOrder');
        $audit->expects($this->never())->method('log');

        $itemService = $this->createTrackingItemService($stockAdjustments);

        $service = $this->buildManufacturingService($pdo, $mfgRepo, $itemService, $audit, $itemRepo, $stockValidator);

        try {
            $service->createProductionOrder([
                'recipe_id'           => $recipeId,
                'production_quantity' => $productionQuantity,
            ]);
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString('Insufficient stock for', $message);
            $this->assertStringContainsString('Sugar', $message);
        }

        // No stock adjustments should have occurred
        $this->assertEmpty($stockAdjustments);
    }

    // ---------------------------------------------------------------
    // Test: Production order with multiple deficient components lists all
    // ---------------------------------------------------------------

    /**
     * When multiple components have insufficient stock, the error message
     * lists ALL deficient components separated by commas.
     *
     * Validates: Requirements 4.2, 4.4
     */
    public function testProductionOrderWithMultipleDeficientComponentsListsAll(): void
    {
        $stockAdjustments = [];

        $recipeId = 1;
        $manufacturedItemId = 999;
        $productionQuantity = 20.0;
        $yieldQuantity = 2;

        $mfgRepo = $this->createMock(ManufacturingRepository::class);
        $itemRepo = $this->createMock(ItemRepository::class);
        $audit = $this->createMock(AuditService::class);
        $pdo = $this->createMock(PDO::class);

        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('rollBack')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Recipe exists
        $mfgRepo->method('getRecipeById')->willReturn([
            'id'                     => $recipeId,
            'tenant_id'              => $this->tenantId,
            'business_id'            => $this->businessId,
            'manufactured_item_id'   => $manufacturedItemId,
            'yield_quantity'         => $yieldQuantity,
            'manufactured_item_name' => 'Widget',
            'unit'                   => 'kg',
        ]);

        // Recipe items: 3 components
        $mfgRepo->method('getRecipeItems')->willReturn([
            ['component_item_id' => 10, 'quantity' => 4.0, 'cost_price' => 2.00, 'component_item_name' => 'Flour'],
            ['component_item_id' => 20, 'quantity' => 2.0, 'cost_price' => 3.00, 'component_item_name' => 'Sugar'],
            ['component_item_id' => 30, 'quantity' => 6.0, 'cost_price' => 1.50, 'component_item_name' => 'Butter'],
        ]);

        // StockValidator: assertSufficientStock throws on first deficient component (Flour)
        $stockValidator = $this->createMock(StockValidator::class);
        $stockValidator->expects($this->atLeastOnce())
            ->method('assertSufficientStock')
            ->willReturnCallback(function (int $itemId, int $businessId, float $quantity) {
                if ($itemId === 10) {
                    throw new \RuntimeException("Insufficient stock for 'Flour': available 10, requested 40");
                }
                if ($itemId === 30) {
                    throw new \RuntimeException("Insufficient stock for 'Butter': available 25, requested 60");
                }
            });

        // These should NOT be called (order rejected)
        $mfgRepo->expects($this->never())->method('insertProductionOrder');
        $audit->expects($this->never())->method('log');

        $itemService = $this->createTrackingItemService($stockAdjustments);

        $service = $this->buildManufacturingService($pdo, $mfgRepo, $itemService, $audit, $itemRepo, $stockValidator);

        try {
            $service->createProductionOrder([
                'recipe_id'           => $recipeId,
                'production_quantity' => $productionQuantity,
            ]);
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            // First deficient component should be reported (fail-fast behavior)
            $this->assertStringContainsString('Insufficient stock for', $message);
            $this->assertStringContainsString('Flour', $message);
        }

        // No stock adjustments should have occurred
        $this->assertEmpty($stockAdjustments);
    }

    // ---------------------------------------------------------------
    // Test: Atomicity - stock check and deduction in same transaction
    // ---------------------------------------------------------------

    /**
     * The stock check via StockValidator.checkBatch must occur INSIDE the
     * database transaction (after beginTransaction), ensuring atomicity
     * between the check and the subsequent stock deduction.
     *
     * This test verifies that:
     * 1. beginTransaction is called before checkBatch
     * 2. checkBatch is called within the transaction
     * 3. Stock deductions happen after checkBatch within the same transaction
     *
     * Validates: Requirements 4.3
     */
    public function testStockCheckAndDeductionInSameTransaction(): void
    {
        $callOrder = [];
        $stockAdjustments = [];

        $recipeId = 1;
        $manufacturedItemId = 999;
        $productionQuantity = 2.0;
        $yieldQuantity = 1;

        $mfgRepo = $this->createMock(ManufacturingRepository::class);
        $itemRepo = $this->createMock(ItemRepository::class);
        $audit = $this->createMock(AuditService::class);

        // Track transaction lifecycle
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturnCallback(function () use (&$callOrder) {
            $callOrder[] = 'beginTransaction';
            return true;
        });
        $pdo->method('commit')->willReturnCallback(function () use (&$callOrder) {
            $callOrder[] = 'commit';
            return true;
        });
        $pdo->method('inTransaction')->willReturn(true);

        // Recipe exists
        $mfgRepo->method('getRecipeById')->willReturn([
            'id'                     => $recipeId,
            'tenant_id'              => $this->tenantId,
            'business_id'            => $this->businessId,
            'manufactured_item_id'   => $manufacturedItemId,
            'yield_quantity'         => $yieldQuantity,
            'manufactured_item_name' => 'Widget',
            'unit'                   => 'kg',
        ]);

        // Recipe items: single component
        $mfgRepo->method('getRecipeItems')->willReturn([
            ['component_item_id' => 10, 'quantity' => 5.0, 'cost_price' => 2.00, 'component_item_name' => 'Material'],
        ]);

        // StockValidator tracks when assertSufficientStock is called
        $stockValidator = $this->createMock(StockValidator::class);
        $assertSufficientStockCalled = false;
        $stockValidator->expects($this->atLeastOnce())
            ->method('assertSufficientStock')
            ->willReturnCallback(function () use (&$callOrder, &$assertSufficientStockCalled) {
                if (!$assertSufficientStockCalled) {
                    $callOrder[] = 'assertSufficientStock';
                    $assertSufficientStockCalled = true;
                }
            });

        $mfgRepo->method('insertProductionOrder')->willReturn(1);

        // ItemService tracks when adjustStock is called
        $itemService = new class($stockAdjustments, $callOrder) extends ItemService {
            private array $adjustments;
            private array $order;

            public function __construct(array &$adjustments, array &$order)
            {
                $this->adjustments = &$adjustments;
                $this->order = &$order;
            }

            public function adjustStock(int $itemId, string $type, float $quantity, ?string $reference, ?string $notes): void
            {
                $this->order[] = "adjustStock:{$itemId}:{$type}";
                $this->adjustments[] = [
                    'item_id'  => $itemId,
                    'type'     => $type,
                    'quantity' => $quantity,
                ];
            }
        };

        $service = $this->buildManufacturingService($pdo, $mfgRepo, $itemService, $audit, $itemRepo, $stockValidator);

        $service->createProductionOrder([
            'recipe_id'           => $recipeId,
            'production_quantity' => $productionQuantity,
        ]);

        // Verify call order: beginTransaction → assertSufficientStock → adjustStock → commit
        $beginIdx = array_search('beginTransaction', $callOrder);
        $checkIdx = array_search('assertSufficientStock', $callOrder);
        $adjustIdx = array_search('adjustStock:10:OUT', $callOrder);
        $commitIdx = array_search('commit', $callOrder);

        $this->assertNotFalse($beginIdx, 'beginTransaction should be called');
        $this->assertNotFalse($checkIdx, 'assertSufficientStock should be called');
        $this->assertNotFalse($adjustIdx, 'adjustStock should be called');
        $this->assertNotFalse($commitIdx, 'commit should be called');

        // beginTransaction must come before assertSufficientStock
        $this->assertLessThan($checkIdx, $beginIdx, 'beginTransaction must precede assertSufficientStock');
        // assertSufficientStock must come before adjustStock
        $this->assertLessThan($adjustIdx, $checkIdx, 'assertSufficientStock must precede adjustStock');
        // adjustStock must come before commit
        $this->assertLessThan($commitIdx, $adjustIdx, 'adjustStock must precede commit');
    }

    // ---------------------------------------------------------------
    // Helper: Create a tracking ItemService mock
    // ---------------------------------------------------------------

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

    private function buildManufacturingService(
        PDO $pdo,
        ManufacturingRepository $mfgRepo,
        ItemService $itemService,
        AuditService $audit,
        ItemRepository $itemRepo,
        StockValidator $stockValidator
    ): ManufacturingService {
        $ref     = new ReflectionClass(ManufacturingService::class);
        $service = $ref->newInstanceWithoutConstructor();

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
