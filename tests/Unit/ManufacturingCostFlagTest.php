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
 * Unit tests for ManufacturingService cost change flag and auto-markup operations.
 *
 * Tests cost change flag is set during cascading recalculation,
 * flag dismissal clears the flag, and auto-markup applies correct sale price.
 *
 * Validates: Requirements 6.8, 11.1–11.5
 */
class ManufacturingCostFlagTest extends TestCase
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
    // Test: Cost change flag is set during cascading recalculation
    // ---------------------------------------------------------------

    /**
     * When a component item's cost changes and cascading recalculation runs,
     * a cost change flag is set on each affected manufactured item with the
     * old and new cost values.
     *
     * Given a recipe:
     * - Component A (item_id=50): cost_price=10.00, quantity=4
     * - Component B (item_id=60): cost_price=8.00, quantity=2
     * - Yield: 2 units
     * - Manufactured item (item_id=900): current cost_price=35.00
     *
     * After recalculation (component 50 cost changed):
     * - New cost = (10.00 × 4 + 8.00 × 2) / 2 = (40 + 16) / 2 = 28.00
     * - Cost change flag set with old_cost=35.00, new_cost=28.00
     *
     * Validates: Requirements 6.8, 11.1
     */
    public function testCostChangeFlagIsSetDuringCascadingRecalculation(): void
    {
        $costFlagCalls = [];
        $costPriceUpdates = [];

        $changedComponentId = 50;
        $manufacturedItemId = 900;
        $yieldQuantity = 2;
        $oldCostPrice = 35.00;

        // Recipe items with their current cost prices
        $recipeItems = [
            ['component_item_id' => 50, 'quantity' => 4.0, 'cost_price' => 10.00, 'component_item_name' => 'Component A', 'unit' => 'pcs', 'id' => 1, 'recipe_id' => 1],
            ['component_item_id' => 60, 'quantity' => 2.0, 'cost_price' => 8.00, 'component_item_name' => 'Component B', 'unit' => 'pcs', 'id' => 2, 'recipe_id' => 1],
        ];

        // Expected new cost: (10*4 + 8*2) / 2 = 56/2 = 28.00
        $expectedNewCost = 28.00;

        // Mock ManufacturingRepository
        $mfgRepo = $this->createMock(ManufacturingRepository::class);

        $mfgRepo->method('getRecipesByComponentItem')
            ->willReturnCallback(function ($itemId, $tid) use ($changedComponentId) {
                if ($itemId === $changedComponentId && $tid === $this->tenantId) {
                    return [[
                        'id'                   => 1,
                        'tenant_id'            => $this->tenantId,
                        'business_id'          => $this->businessId,
                        'manufactured_item_id' => 900,
                        'yield_quantity'       => 2,
                        'notes'                => null,
                        'is_active'            => 1,
                        'created_by'           => $this->userId,
                        'created_at'           => '2024-01-01 00:00:00',
                        'updated_at'           => null,
                    ]];
                }
                return [];
            });

        $mfgRepo->method('getRecipeItems')
            ->willReturn($recipeItems);

        // Track setCostChangeFlag calls
        $mfgRepo->method('setCostChangeFlag')
            ->willReturnCallback(function ($itemId, $oldCost, $newCost) use (&$costFlagCalls) {
                $costFlagCalls[] = [
                    'item_id'  => $itemId,
                    'old_cost' => $oldCost,
                    'new_cost' => $newCost,
                ];
            });

        // No auto-markup configured
        $mfgRepo->method('getAutoMarkupConfig')
            ->willReturn(null);

        // Mock ItemRepository
        $itemRepo = $this->createMock(ItemRepository::class);

        $itemRepo->method('getById')
            ->willReturnCallback(function ($itemId, $tid) use ($manufacturedItemId, $oldCostPrice) {
                if ($itemId === $manufacturedItemId && $tid === $this->tenantId) {
                    return [
                        'id'          => $manufacturedItemId,
                        'tenant_id'   => $this->tenantId,
                        'business_id' => $this->businessId,
                        'name'        => 'Manufactured Item',
                        'cost_price'  => $oldCostPrice,
                        'sale_price'  => null,
                        'is_active'   => 1,
                    ];
                }
                return null;
            });

        // Track updateCostPrice calls
        $itemRepo->method('updateCostPrice')
            ->willReturnCallback(function ($itemId, $tid, $newCost) use (&$costPriceUpdates) {
                $costPriceUpdates[] = [
                    'item_id'  => $itemId,
                    'new_cost' => $newCost,
                ];
            });

        $service = $this->buildManufacturingService(
            $this->createMock(PDO::class),
            $mfgRepo,
            $this->createMinimalItemService(),
            $this->createMinimalAuditService(),
            $itemRepo
        );

        // Execute cascading recalculation
        $service->recalculateAffectedRecipes($changedComponentId);

        // Verify cost change flag was set
        $this->assertCount(1, $costFlagCalls, 'Expected exactly one setCostChangeFlag call');
        $this->assertEquals($manufacturedItemId, $costFlagCalls[0]['item_id']);
        $this->assertEqualsWithDelta($oldCostPrice, $costFlagCalls[0]['old_cost'], 0.0001);
        $this->assertEqualsWithDelta($expectedNewCost, $costFlagCalls[0]['new_cost'], 0.0001);

        // Verify cost price was updated
        $this->assertCount(1, $costPriceUpdates);
        $this->assertEquals($manufacturedItemId, $costPriceUpdates[0]['item_id']);
        $this->assertEqualsWithDelta($expectedNewCost, $costPriceUpdates[0]['new_cost'], 0.0001);
    }

    // ---------------------------------------------------------------
    // Test: Flag dismissal clears the flag
    // ---------------------------------------------------------------

    /**
     * Dismissing a cost change flag delegates to the repository's
     * dismissCostChangeFlag method with the correct item ID.
     *
     * Validates: Requirements 11.2, 11.3
     */
    public function testDismissCostChangeFlagClearsTheFlag(): void
    {
        $dismissedItemIds = [];

        $itemId = 900;

        // Mock ManufacturingRepository
        $mfgRepo = $this->createMock(ManufacturingRepository::class);

        // Track dismissCostChangeFlag calls
        $mfgRepo->expects($this->once())
            ->method('dismissCostChangeFlag')
            ->willReturnCallback(function ($id) use (&$dismissedItemIds) {
                $dismissedItemIds[] = $id;
            });

        $service = $this->buildManufacturingService(
            $this->createMock(PDO::class),
            $mfgRepo,
            $this->createMinimalItemService(),
            $this->createMinimalAuditService(),
            $this->createMock(ItemRepository::class)
        );

        // Execute dismissal
        $service->dismissCostChangeFlag($itemId);

        // Verify the correct item ID was passed
        $this->assertCount(1, $dismissedItemIds);
        $this->assertEquals($itemId, $dismissedItemIds[0]);
    }

    // ---------------------------------------------------------------
    // Test: Auto-markup applies correct sale price
    // ---------------------------------------------------------------

    /**
     * When a manufactured item has auto-markup configured and a cascading
     * recalculation updates its cost, the sale price is automatically
     * updated to new_cost × (1 + markup_percent / 100).
     *
     * Given a recipe:
     * - Component A (item_id=50): cost_price=20.00, quantity=5
     * - Component B (item_id=60): cost_price=10.00, quantity=3
     * - Yield: 2 units
     * - Manufactured item (item_id=800): current cost_price=50.00
     * - Auto-markup: 30%
     *
     * After recalculation:
     * - New cost = (20.00 × 5 + 10.00 × 3) / 2 = (100 + 30) / 2 = 65.00
     * - New sale price = 65.00 × (1 + 30/100) = 65.00 × 1.30 = 84.50
     *
     * Validates: Requirements 11.4, 11.5
     */
    public function testAutoMarkupAppliesCorrectSalePrice(): void
    {
        $itemUpdateCalls = [];

        $changedComponentId = 50;
        $manufacturedItemId = 800;
        $yieldQuantity = 2;
        $oldCostPrice = 50.00;
        $markupPercent = 30.00;

        // Recipe items with their current cost prices
        $recipeItems = [
            ['component_item_id' => 50, 'quantity' => 5.0, 'cost_price' => 20.00, 'component_item_name' => 'Component A', 'unit' => 'pcs', 'id' => 1, 'recipe_id' => 1],
            ['component_item_id' => 60, 'quantity' => 3.0, 'cost_price' => 10.00, 'component_item_name' => 'Component B', 'unit' => 'pcs', 'id' => 2, 'recipe_id' => 1],
        ];

        // Expected new cost: (20*5 + 10*3) / 2 = 130/2 = 65.00
        $expectedNewCost = 65.00;
        // Expected sale price: 65.00 × 1.30 = 84.50
        $expectedSalePrice = 84.50;

        // Mock ManufacturingRepository
        $mfgRepo = $this->createMock(ManufacturingRepository::class);

        $mfgRepo->method('getRecipesByComponentItem')
            ->willReturnCallback(function ($itemId, $tid) use ($changedComponentId, $manufacturedItemId, $yieldQuantity) {
                if ($itemId === $changedComponentId && $tid === $this->tenantId) {
                    return [[
                        'id'                   => 1,
                        'tenant_id'            => $this->tenantId,
                        'business_id'          => $this->businessId,
                        'manufactured_item_id' => $manufacturedItemId,
                        'yield_quantity'       => $yieldQuantity,
                        'notes'                => null,
                        'is_active'            => 1,
                        'created_by'           => $this->userId,
                        'created_at'           => '2024-01-01 00:00:00',
                        'updated_at'           => null,
                    ]];
                }
                return [];
            });

        $mfgRepo->method('getRecipeItems')
            ->willReturn($recipeItems);

        $mfgRepo->method('setCostChangeFlag')
            ->willReturnCallback(function () {});

        // Return auto-markup config
        $mfgRepo->method('getAutoMarkupConfig')
            ->willReturnCallback(function ($itemId) use ($manufacturedItemId, $markupPercent) {
                if ($itemId === $manufacturedItemId) {
                    return [
                        'item_id'        => $manufacturedItemId,
                        'markup_percent' => $markupPercent,
                        'is_enabled'     => 1,
                    ];
                }
                return null;
            });

        // Mock ItemRepository
        $itemRepo = $this->createMock(ItemRepository::class);

        $itemRepo->method('getById')
            ->willReturnCallback(function ($itemId, $tid) use ($manufacturedItemId, $oldCostPrice) {
                if ($itemId === $manufacturedItemId && $tid === $this->tenantId) {
                    return [
                        'id'          => $manufacturedItemId,
                        'tenant_id'   => $this->tenantId,
                        'business_id' => $this->businessId,
                        'name'        => 'Manufactured Item',
                        'cost_price'  => $oldCostPrice,
                        'sale_price'  => 65.00,
                        'is_active'   => 1,
                    ];
                }
                return null;
            });

        $itemRepo->method('updateCostPrice')
            ->willReturnCallback(function () {});

        // Track itemRepo->update calls (used for sale_price updates)
        $itemRepo->method('update')
            ->willReturnCallback(function ($itemId, $tid, $data) use (&$itemUpdateCalls) {
                $itemUpdateCalls[] = [
                    'item_id'   => $itemId,
                    'tenant_id' => $tid,
                    'data'      => $data,
                ];
            });

        $service = $this->buildManufacturingService(
            $this->createMock(PDO::class),
            $mfgRepo,
            $this->createMinimalItemService(),
            $this->createMinimalAuditService(),
            $itemRepo
        );

        // Execute cascading recalculation
        $service->recalculateAffectedRecipes($changedComponentId);

        // Verify sale price was updated via itemRepo->update
        $this->assertCount(1, $itemUpdateCalls, 'Expected exactly one itemRepo->update call for auto-markup');
        $this->assertEquals($manufacturedItemId, $itemUpdateCalls[0]['item_id']);
        $this->assertEquals($this->tenantId, $itemUpdateCalls[0]['tenant_id']);
        $this->assertArrayHasKey('sale_price', $itemUpdateCalls[0]['data']);
        $this->assertEqualsWithDelta(
            $expectedSalePrice,
            $itemUpdateCalls[0]['data']['sale_price'],
            0.0001,
            sprintf(
                'Sale price should be %.4f (cost %.4f × 1.30), got %.4f',
                $expectedSalePrice,
                $expectedNewCost,
                $itemUpdateCalls[0]['data']['sale_price']
            )
        );
    }

    // ---------------------------------------------------------------
    // Test: Multiple recipes affected by cascading recalculation
    // ---------------------------------------------------------------

    /**
     * When a component is used in multiple recipes, cascading recalculation
     * sets cost change flags on ALL affected manufactured items.
     *
     * Given:
     * - Recipe 1: Component A (item_id=50, cost=10, qty=2), yield=1, manufactured_item=900, old_cost=15.00
     * - Recipe 2: Component A (item_id=50, cost=10, qty=3), Component C (item_id=70, cost=5, qty=1), yield=1, manufactured_item=901, old_cost=40.00
     *
     * After recalculation:
     * - Recipe 1 new cost: (10×2) / 1 = 20.00 → flag(900, 15.00, 20.00)
     * - Recipe 2 new cost: (10×3 + 5×1) / 1 = 35.00 → flag(901, 40.00, 35.00)
     *
     * Validates: Requirements 6.5, 6.6, 6.7, 6.8
     */
    public function testMultipleRecipesGetCostFlagsDuringCascadingRecalculation(): void
    {
        $costFlagCalls = [];
        $costPriceUpdates = [];

        $changedComponentId = 50;

        $affectedRecipes = [
            [
                'id'                   => 1,
                'tenant_id'            => $this->tenantId,
                'business_id'          => $this->businessId,
                'manufactured_item_id' => 900,
                'yield_quantity'       => 1,
                'notes'                => null,
                'is_active'            => 1,
                'created_by'           => $this->userId,
                'created_at'           => '2024-01-01 00:00:00',
                'updated_at'           => null,
            ],
            [
                'id'                   => 2,
                'tenant_id'            => $this->tenantId,
                'business_id'          => $this->businessId,
                'manufactured_item_id' => 901,
                'yield_quantity'       => 1,
                'notes'                => null,
                'is_active'            => 1,
                'created_by'           => $this->userId,
                'created_at'           => '2024-01-01 00:00:00',
                'updated_at'           => null,
            ],
        ];

        $recipeItemsMap = [
            1 => [
                ['component_item_id' => 50, 'quantity' => 2.0, 'cost_price' => 10.00, 'component_item_name' => 'Component A', 'unit' => 'pcs', 'id' => 1, 'recipe_id' => 1],
            ],
            2 => [
                ['component_item_id' => 50, 'quantity' => 3.0, 'cost_price' => 10.00, 'component_item_name' => 'Component A', 'unit' => 'pcs', 'id' => 3, 'recipe_id' => 2],
                ['component_item_id' => 70, 'quantity' => 1.0, 'cost_price' => 5.00, 'component_item_name' => 'Component C', 'unit' => 'pcs', 'id' => 4, 'recipe_id' => 2],
            ],
        ];

        $manufacturedItemsMap = [
            900 => ['id' => 900, 'tenant_id' => $this->tenantId, 'business_id' => $this->businessId, 'name' => 'Product 1', 'cost_price' => 15.00, 'sale_price' => null, 'is_active' => 1],
            901 => ['id' => 901, 'tenant_id' => $this->tenantId, 'business_id' => $this->businessId, 'name' => 'Product 2', 'cost_price' => 40.00, 'sale_price' => null, 'is_active' => 1],
        ];

        // Mock ManufacturingRepository
        $mfgRepo = $this->createMock(ManufacturingRepository::class);

        $mfgRepo->method('getRecipesByComponentItem')
            ->willReturnCallback(function ($itemId, $tid) use ($changedComponentId, $affectedRecipes) {
                if ($itemId === $changedComponentId && $tid === $this->tenantId) {
                    return $affectedRecipes;
                }
                return [];
            });

        $mfgRepo->method('getRecipeItems')
            ->willReturnCallback(function ($recipeId) use ($recipeItemsMap) {
                return $recipeItemsMap[$recipeId] ?? [];
            });

        $mfgRepo->method('setCostChangeFlag')
            ->willReturnCallback(function ($itemId, $oldCost, $newCost) use (&$costFlagCalls) {
                $costFlagCalls[] = [
                    'item_id'  => $itemId,
                    'old_cost' => $oldCost,
                    'new_cost' => $newCost,
                ];
            });

        $mfgRepo->method('getAutoMarkupConfig')
            ->willReturn(null);

        // Mock ItemRepository
        $itemRepo = $this->createMock(ItemRepository::class);

        $itemRepo->method('getById')
            ->willReturnCallback(function ($itemId, $tid) use ($manufacturedItemsMap) {
                if ($tid === $this->tenantId && isset($manufacturedItemsMap[$itemId])) {
                    return $manufacturedItemsMap[$itemId];
                }
                return null;
            });

        $itemRepo->method('updateCostPrice')
            ->willReturnCallback(function ($itemId, $tid, $newCost) use (&$costPriceUpdates) {
                $costPriceUpdates[] = [
                    'item_id'  => $itemId,
                    'new_cost' => $newCost,
                ];
            });

        $service = $this->buildManufacturingService(
            $this->createMock(PDO::class),
            $mfgRepo,
            $this->createMinimalItemService(),
            $this->createMinimalAuditService(),
            $itemRepo
        );

        // Execute cascading recalculation
        $service->recalculateAffectedRecipes($changedComponentId);

        // Verify two cost flags were set
        $this->assertCount(2, $costFlagCalls, 'Expected two setCostChangeFlag calls for two affected recipes');

        // Recipe 1: manufactured item 900, old=15.00, new=20.00
        $flag900 = array_values(array_filter($costFlagCalls, fn($f) => $f['item_id'] === 900));
        $this->assertCount(1, $flag900);
        $this->assertEqualsWithDelta(15.00, $flag900[0]['old_cost'], 0.0001);
        $this->assertEqualsWithDelta(20.00, $flag900[0]['new_cost'], 0.0001);

        // Recipe 2: manufactured item 901, old=40.00, new=35.00
        $flag901 = array_values(array_filter($costFlagCalls, fn($f) => $f['item_id'] === 901));
        $this->assertCount(1, $flag901);
        $this->assertEqualsWithDelta(40.00, $flag901[0]['old_cost'], 0.0001);
        $this->assertEqualsWithDelta(35.00, $flag901[0]['new_cost'], 0.0001);

        // Verify cost prices were updated
        $this->assertCount(2, $costPriceUpdates);
    }

    // ---------------------------------------------------------------
    // Helper: Create a minimal ItemService (no-op)
    // ---------------------------------------------------------------

    private function createMinimalItemService(): ItemService
    {
        return new class extends ItemService {
            public function __construct() {}
        };
    }

    // ---------------------------------------------------------------
    // Helper: Create a minimal AuditService (no-op)
    // ---------------------------------------------------------------

    private function createMinimalAuditService(): AuditService
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
