<?php

namespace Tests\Unit;

use App\Repository\ItemRepository;
use App\Repository\ManufacturingRepository;
use App\Services\AuditService;
use App\Services\ItemService;
use App\Services\ManufacturingService;
use App\Support\AuthContext;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for decimal yield_quantity support in recipes.
 *
 * Verifies that recipes with fractional yield quantities (e.g., 1.5)
 * correctly calculate production cost per unit.
 *
 * Validates: Requirements REQ-008
 */
class RecipeDecimalYieldTest extends TestCase
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

    /**
     * Test: calculateUnitCost works correctly with decimal yield quantity 1.5.
     *
     * Given components:
     * - Component A: cost_price=10.00, quantity=2 → subtotal = 20.00
     * - Component B: cost_price=6.00, quantity=1  → subtotal = 6.00
     * Total batch cost = 26.00
     * Yield quantity = 1.5
     * Expected unit cost = 26.00 / 1.5 = 17.333...
     */
    public function testCalculateUnitCostWithDecimalYield(): void
    {
        $service = $this->buildServiceWithoutDb();

        $recipeItems = [
            ['cost_price' => 10.00, 'quantity' => 2],
            ['cost_price' => 6.00, 'quantity' => 1],
        ];

        $unitCost = $service->calculateUnitCost($recipeItems, 1.5);

        // 26.00 / 1.5 = 17.3333...
        $this->assertEqualsWithDelta(17.3333, $unitCost, 0.001);
    }

    /**
     * Test: calculateUnitCost with yield quantity 0.5 (less than 1).
     *
     * Given components:
     * - Component A: cost_price=5.00, quantity=1 → subtotal = 5.00
     * Total batch cost = 5.00
     * Yield quantity = 0.5
     * Expected unit cost = 5.00 / 0.5 = 10.00
     */
    public function testCalculateUnitCostWithYieldLessThanOne(): void
    {
        $service = $this->buildServiceWithoutDb();

        $recipeItems = [
            ['cost_price' => 5.00, 'quantity' => 1],
        ];

        $unitCost = $service->calculateUnitCost($recipeItems, 0.5);

        $this->assertEqualsWithDelta(10.00, $unitCost, 0.0001);
    }

    /**
     * Test: createRecipe with decimal yield_quantity 1.5 saves correctly
     * and calculates the correct unit cost.
     *
     * Given:
     * - Component A: cost_price=12.00, quantity=3 → subtotal = 36.00
     * - Component B: cost_price=8.00, quantity=2  → subtotal = 16.00
     * Total batch cost = 52.00
     * Yield quantity = 1.5
     * Expected unit cost = 52.00 / 1.5 = 34.6667
     *
     * Verify:
     * - Recipe is created with yield_quantity = 1.5
     * - Manufactured item cost_price = 34.6667 (rounded to 2 decimals by item repo)
     */
    public function testCreateRecipeWithDecimalYieldQuantity(): void
    {
        $insertedItem = null;
        $insertedRecipe = null;

        $itemRepo = $this->createMock(ItemRepository::class);
        $mfgRepo = $this->createMock(ManufacturingRepository::class);
        $audit = $this->createMock(AuditService::class);
        $itemService = $this->createMock(ItemService::class);
        $pdo = $this->createMock(PDO::class);

        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // PDO prepare for getManufacturedTypeId query
        $typeStmt = $this->createMock(PDOStatement::class);
        $typeStmt->method('execute')->willReturn(true);
        $typeStmt->method('fetchColumn')->willReturn(3);
        $pdo->method('prepare')->willReturn($typeStmt);

        // Component items exist
        $itemRepo->method('getById')->willReturnCallback(
            function (int $id, int $tenantId) {
                $items = [
                    100 => ['id' => 100, 'name' => 'Flour', 'cost_price' => 12.00, 'business_id' => 1, 'tenant_id' => 1],
                    200 => ['id' => 200, 'name' => 'Sugar', 'cost_price' => 8.00, 'business_id' => 1, 'tenant_id' => 1],
                ];
                return $items[$id] ?? null;
            }
        );

        // Capture item insert
        $itemRepo->method('insert')->willReturnCallback(
            function (array $data) use (&$insertedItem) {
                $insertedItem = $data;
                return 500;
            }
        );

        // Capture recipe insert
        $mfgRepo->method('insertRecipe')->willReturnCallback(
            function (array $data) use (&$insertedRecipe) {
                $insertedRecipe = $data;
                return 10;
            }
        );

        $mfgRepo->method('insertRecipeItems')->willReturnCallback(function () {});
        $audit->method('log')->willReturnCallback(function () {});

        $service = $this->buildManufacturingService($pdo, $mfgRepo, $itemService, $audit, $itemRepo);

        $result = $service->createRecipe([
            'name'           => 'Cake Batch',
            'components'     => [
                ['item_id' => 100, 'quantity' => 3],
                ['item_id' => 200, 'quantity' => 2],
            ],
            'yield_quantity' => 1.5,
            'sale_price'     => 50.00,
        ]);

        // Recipe was created
        $this->assertEquals(10, $result['recipe_id']);
        $this->assertEquals(500, $result['item_id']);

        // Recipe stored the decimal yield_quantity
        $this->assertNotNull($insertedRecipe);
        $this->assertEquals(1.5, $insertedRecipe['yield_quantity']);

        // Item cost was calculated correctly: (12*3 + 8*2) / 1.5 = 52/1.5 = 34.6667
        $this->assertNotNull($insertedItem);
        $expectedCost = (12.0 * 3 + 8.0 * 2) / 1.5;
        $this->assertEqualsWithDelta($expectedCost, $insertedItem['cost'], 0.01);
    }

    /**
     * Test: calculateUnitCost still rejects zero yield quantity.
     */
    public function testCalculateUnitCostRejectsZeroDecimalYield(): void
    {
        $service = $this->buildServiceWithoutDb();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Yield quantity must be greater than zero');

        $service->calculateUnitCost([['cost_price' => 10, 'quantity' => 1]], 0.0);
    }

    /**
     * Test: calculateUnitCost rejects negative decimal yield quantity.
     */
    public function testCalculateUnitCostRejectsNegativeDecimalYield(): void
    {
        $service = $this->buildServiceWithoutDb();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Yield quantity must be greater than zero');

        $service->calculateUnitCost([['cost_price' => 10, 'quantity' => 1]], -0.5);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function buildServiceWithoutDb(): ManufacturingService
    {
        $ref = new ReflectionClass(ManufacturingService::class);
        return $ref->newInstanceWithoutConstructor();
    }

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
