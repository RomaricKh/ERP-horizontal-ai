<?php

namespace Tests\Property;

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
 * Feature: manufacturing-module, Property 8: Cascading recalculation updates all affected manufactured items
 *
 * For any component item whose cost_price changes, every recipe that uses that
 * component SHALL have its manufactured item's cost_price updated to the newly
 * calculated unit manufacturing cost, and a cost change flag SHALL be set with
 * the old and new cost values.
 *
 * **Validates: Requirements 6.5, 6.6, 6.7, 6.8**
 */
class ManufacturingCascadingPropertyTest extends TestCase
{
    private const ITERATIONS = 100;

    private int $tenantId = 1;
    private int $businessId = 1;
    private int $userId = 1;

    protected function setUp(): void
    {
        AuthContext::set((object) [
            'user_id'     => $this->userId,
            'tenant_id'   => $this->tenantId,
            'business_id' => $this->businessId,
        ]);
    }

    /**
     * Data provider generating 100 random cascading recalculation scenarios.
     *
     * Each case: [recipes, changedComponentId]
     * - recipes: array of recipe definitions, each with:
     *   - manufactured_item_id: int
     *   - yield_quantity: int > 0
     *   - old_cost_price: float (current manufactured item cost)
     *   - components: array of [item_id, quantity, cost_price]
     * - changedComponentId: the component item whose cost changed (present in at least one recipe)
     */
    public static function cascadingRecalculationProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // The component item whose cost changed
            $changedComponentId = 50;

            // Generate between 1 and 4 affected recipes
            $numRecipes = mt_rand(1, 4);
            $recipes = [];

            for ($r = 0; $r < $numRecipes; $r++) {
                $recipeId = $r + 1;
                $manufacturedItemId = 900 + $r;
                $yieldQuantity = mt_rand(1, 50);

                // Generate between 1 and 5 components per recipe
                $numComponents = mt_rand(1, 5);
                $components = [];

                // Ensure the changed component is included in this recipe
                $changedComponentIncluded = false;

                for ($c = 0; $c < $numComponents; $c++) {
                    if ($c === 0 && !$changedComponentIncluded) {
                        // First component is always the changed one
                        $itemId = $changedComponentId;
                        $changedComponentIncluded = true;
                    } else {
                        // Other components have different IDs
                        $itemId = 100 + ($r * 10) + $c;
                    }

                    // Quantity per batch: between 0.5 and 100.0
                    $quantity = round(mt_rand(50, 10000) / 100, 2);
                    // Cost price (current): between 1.00 and 500.00
                    $costPrice = round(mt_rand(100, 50000) / 100, 2);

                    $components[] = [
                        'item_id'    => $itemId,
                        'quantity'   => $quantity,
                        'cost_price' => $costPrice,
                    ];
                }

                // Calculate old cost price (what the manufactured item currently has)
                // Use a random old cost that differs from the new calculated cost
                $oldCostPrice = round(mt_rand(100, 50000) / 100, 2);

                $recipes[] = [
                    'recipe_id'            => $recipeId,
                    'manufactured_item_id' => $manufacturedItemId,
                    'yield_quantity'       => $yieldQuantity,
                    'old_cost_price'       => $oldCostPrice,
                    'components'           => $components,
                ];
            }

            $cases["iter_{$i}_recipes_{$numRecipes}"] = [
                $recipes,
                $changedComponentId,
            ];
        }
        return $cases;
    }

    /**
     * Property 8: Cascading recalculation updates all affected manufactured items
     *
     * For any component item whose cost_price changes, every recipe that uses
     * that component SHALL have its manufactured item's cost_price updated to
     * the newly calculated unit manufacturing cost, and a cost change flag SHALL
     * be set with the old and new cost values.
     *
     * **Validates: Requirements 6.5, 6.6, 6.7, 6.8**
     *
     * @dataProvider cascadingRecalculationProvider
     */
    public function testCascadingRecalculationUpdatesAllAffectedItems(
        array $recipes,
        int $changedComponentId
    ): void {
        // Track calls to updateCostPrice and setCostChangeFlag
        $costPriceUpdates = [];
        $costFlagCalls = [];

        // Build the ManufacturingService with mocked dependencies
        $service = $this->buildServiceWithCascadingTracking(
            $recipes,
            $changedComponentId,
            $costPriceUpdates,
            $costFlagCalls
        );

        // Execute cascading recalculation
        $service->recalculateAffectedRecipes($changedComponentId);

        // Verify: each recipe's manufactured item should have its cost_price updated
        foreach ($recipes as $recipe) {
            $manufacturedItemId = $recipe['manufactured_item_id'];
            $yieldQuantity = $recipe['yield_quantity'];

            // Calculate expected new cost: sum(cost_price × quantity) / yield_quantity
            $expectedCost = 0.0;
            foreach ($recipe['components'] as $component) {
                $expectedCost += $component['cost_price'] * $component['quantity'];
            }
            $expectedCost = $expectedCost / $yieldQuantity;

            // Verify updateCostPrice was called for this manufactured item
            $updates = array_filter($costPriceUpdates, function ($u) use ($manufacturedItemId) {
                return $u['item_id'] === $manufacturedItemId;
            });

            $this->assertCount(
                1,
                $updates,
                "Expected exactly one updateCostPrice call for manufactured item {$manufacturedItemId}"
            );

            $update = reset($updates);
            $this->assertEqualsWithDelta(
                $expectedCost,
                $update['new_cost'],
                0.0001,
                sprintf(
                    'Manufactured item %d: cost_price should be %.6f, got %.6f',
                    $manufacturedItemId,
                    $expectedCost,
                    $update['new_cost']
                )
            );

            // Verify setCostChangeFlag was called with correct old and new cost
            $flags = array_filter($costFlagCalls, function ($f) use ($manufacturedItemId) {
                return $f['item_id'] === $manufacturedItemId;
            });

            $this->assertCount(
                1,
                $flags,
                "Expected exactly one setCostChangeFlag call for manufactured item {$manufacturedItemId}"
            );

            $flag = reset($flags);
            $this->assertEqualsWithDelta(
                $recipe['old_cost_price'],
                $flag['old_cost'],
                0.0001,
                sprintf(
                    'Manufactured item %d: old cost in flag should be %.6f, got %.6f',
                    $manufacturedItemId,
                    $recipe['old_cost_price'],
                    $flag['old_cost']
                )
            );
            $this->assertEqualsWithDelta(
                $expectedCost,
                $flag['new_cost'],
                0.0001,
                sprintf(
                    'Manufactured item %d: new cost in flag should be %.6f, got %.6f',
                    $manufacturedItemId,
                    $expectedCost,
                    $flag['new_cost']
                )
            );
        }

        // Verify total number of updates matches number of recipes
        $this->assertCount(
            count($recipes),
            $costPriceUpdates,
            'Total updateCostPrice calls should equal number of affected recipes'
        );

        $this->assertCount(
            count($recipes),
            $costFlagCalls,
            'Total setCostChangeFlag calls should equal number of affected recipes'
        );
    }

    /**
     * Data provider generating 100 random auto-markup recalculation scenarios.
     *
     * Each case: [recipes, changedComponentId]
     * - recipes: array of recipe definitions, each with:
     *   - manufactured_item_id: int
     *   - yield_quantity: int > 0
     *   - old_cost_price: float (current manufactured item cost)
     *   - markup_percent: float between 1 and 100
     *   - components: array of [item_id, quantity, cost_price]
     * - changedComponentId: the component item whose cost changed
     */
    public static function autoMarkupRecalculationProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $changedComponentId = 50;

            // Generate between 1 and 3 affected recipes with auto-markup
            $numRecipes = mt_rand(1, 3);
            $recipes = [];

            for ($r = 0; $r < $numRecipes; $r++) {
                $recipeId = $r + 1;
                $manufacturedItemId = 800 + $r;
                $yieldQuantity = mt_rand(1, 50);

                // Random markup percent between 1 and 100
                $markupPercent = round(mt_rand(100, 10000) / 100, 2);

                // Generate between 1 and 5 components per recipe
                $numComponents = mt_rand(1, 5);
                $components = [];

                for ($c = 0; $c < $numComponents; $c++) {
                    if ($c === 0) {
                        $itemId = $changedComponentId;
                    } else {
                        $itemId = 200 + ($r * 10) + $c;
                    }

                    $quantity = round(mt_rand(50, 10000) / 100, 2);
                    $costPrice = round(mt_rand(100, 50000) / 100, 2);

                    $components[] = [
                        'item_id'    => $itemId,
                        'quantity'   => $quantity,
                        'cost_price' => $costPrice,
                    ];
                }

                $oldCostPrice = round(mt_rand(100, 50000) / 100, 2);

                $recipes[] = [
                    'recipe_id'            => $recipeId,
                    'manufactured_item_id' => $manufacturedItemId,
                    'yield_quantity'       => $yieldQuantity,
                    'old_cost_price'       => $oldCostPrice,
                    'markup_percent'       => $markupPercent,
                    'components'           => $components,
                ];
            }

            $cases["iter_{$i}_recipes_{$numRecipes}"] = [
                $recipes,
                $changedComponentId,
            ];
        }
        return $cases;
    }

    /**
     * Property 9: Auto-markup applies correct sale price on cascading recalculation
     *
     * For any manufactured item with auto-markup enabled (markup_percent > 0),
     * when a cascading recalculation updates its cost_price, the sale_price SHALL
     * be updated to new_cost_price × (1 + markup_percent / 100).
     *
     * **Validates: Requirements 11.5**
     *
     * @dataProvider autoMarkupRecalculationProvider
     */
    public function testAutoMarkupAppliesCorrectSalePriceOnRecalculation(
        array $recipes,
        int $changedComponentId
    ): void {
        // Track calls to itemRepo->update to verify sale_price
        $itemUpdateCalls = [];

        // Build the ManufacturingService with auto-markup tracking
        $service = $this->buildServiceWithAutoMarkupTracking(
            $recipes,
            $changedComponentId,
            $itemUpdateCalls
        );

        // Execute cascading recalculation
        $service->recalculateAffectedRecipes($changedComponentId);

        // Verify: each recipe's manufactured item should have sale_price updated
        foreach ($recipes as $recipe) {
            $manufacturedItemId = $recipe['manufactured_item_id'];
            $yieldQuantity = $recipe['yield_quantity'];
            $markupPercent = $recipe['markup_percent'];

            // Calculate expected new cost: sum(cost_price × quantity) / yield_quantity
            $expectedCost = 0.0;
            foreach ($recipe['components'] as $component) {
                $expectedCost += $component['cost_price'] * $component['quantity'];
            }
            $expectedCost = $expectedCost / $yieldQuantity;

            // Calculate expected sale price: new_cost × (1 + markup_percent / 100)
            $expectedSalePrice = $expectedCost * (1 + $markupPercent / 100);

            // Find the update call for this manufactured item
            $updates = array_filter($itemUpdateCalls, function ($u) use ($manufacturedItemId) {
                return $u['item_id'] === $manufacturedItemId;
            });

            $this->assertCount(
                1,
                $updates,
                "Expected exactly one itemRepo->update call for manufactured item {$manufacturedItemId}"
            );

            $update = reset($updates);
            $this->assertArrayHasKey(
                'sale_price',
                $update['data'],
                "Update call for item {$manufacturedItemId} should include sale_price"
            );

            $this->assertEqualsWithDelta(
                $expectedSalePrice,
                $update['data']['sale_price'],
                0.0001,
                sprintf(
                    'Manufactured item %d: sale_price should be %.6f (cost %.6f × (1 + %.2f/100)), got %.6f',
                    $manufacturedItemId,
                    $expectedSalePrice,
                    $expectedCost,
                    $markupPercent,
                    $update['data']['sale_price']
                )
            );
        }

        // Verify total number of update calls matches number of recipes
        $this->assertCount(
            count($recipes),
            $itemUpdateCalls,
            'Total itemRepo->update calls should equal number of affected recipes with auto-markup'
        );
    }

    /**
     * Build a ManufacturingService with mocked dependencies that tracks
     * itemRepo->update calls for auto-markup sale_price verification.
     *
     * @param array  $recipes             Recipe definitions with markup_percent
     * @param int    $changedComponentId   The component item whose cost changed
     * @param array  &$itemUpdateCalls    Reference to array capturing itemRepo->update calls
     * @return ManufacturingService
     */
    private function buildServiceWithAutoMarkupTracking(
        array $recipes,
        int $changedComponentId,
        array &$itemUpdateCalls
    ): ManufacturingService {
        $tenantId = $this->tenantId;
        $businessId = $this->businessId;

        // Build affected recipes data as returned by getRecipesByComponentItem
        $affectedRecipesData = [];
        foreach ($recipes as $recipe) {
            $affectedRecipesData[] = [
                'id'                   => $recipe['recipe_id'],
                'tenant_id'            => $tenantId,
                'business_id'          => $businessId,
                'manufactured_item_id' => $recipe['manufactured_item_id'],
                'yield_quantity'       => $recipe['yield_quantity'],
                'notes'                => null,
                'is_active'            => 1,
                'created_by'           => $this->userId,
                'created_at'           => date('Y-m-d H:i:s'),
                'updated_at'           => null,
            ];
        }

        // Build recipe items data for each recipe
        $recipeItemsMap = [];
        foreach ($recipes as $recipe) {
            $items = [];
            foreach ($recipe['components'] as $idx => $component) {
                $items[] = [
                    'id'                  => ($recipe['recipe_id'] * 100) + $idx,
                    'recipe_id'           => $recipe['recipe_id'],
                    'component_item_id'   => $component['item_id'],
                    'quantity'            => $component['quantity'],
                    'component_item_name' => 'Component_' . $component['item_id'],
                    'unit'                => 'pcs',
                    'cost_price'          => $component['cost_price'],
                ];
            }
            $recipeItemsMap[$recipe['recipe_id']] = $items;
        }

        // Build manufactured item data for getById
        $manufacturedItemsMap = [];
        foreach ($recipes as $recipe) {
            $manufacturedItemsMap[$recipe['manufactured_item_id']] = [
                'id'          => $recipe['manufactured_item_id'],
                'tenant_id'   => $tenantId,
                'business_id' => $businessId,
                'name'        => 'Manufactured_' . $recipe['manufactured_item_id'],
                'cost_price'  => $recipe['old_cost_price'],
                'sale_price'  => null,
                'is_active'   => 1,
            ];
        }

        // Build auto-markup config map
        $autoMarkupMap = [];
        foreach ($recipes as $recipe) {
            $autoMarkupMap[$recipe['manufactured_item_id']] = [
                'item_id'        => $recipe['manufactured_item_id'],
                'markup_percent' => $recipe['markup_percent'],
                'is_enabled'     => 1,
            ];
        }

        // Mock ManufacturingRepository
        $mfgRepo = $this->createMock(ManufacturingRepository::class);

        $mfgRepo->method('getRecipesByComponentItem')
            ->willReturnCallback(function ($itemId, $tid) use ($changedComponentId, $tenantId, $affectedRecipesData) {
                if ($itemId === $changedComponentId && $tid === $tenantId) {
                    return $affectedRecipesData;
                }
                return [];
            });

        $mfgRepo->method('getRecipeItems')
            ->willReturnCallback(function ($recipeId) use ($recipeItemsMap) {
                return $recipeItemsMap[$recipeId] ?? [];
            });

        $mfgRepo->method('setCostChangeFlag')
            ->willReturnCallback(function () {});

        // Return auto-markup config for each manufactured item
        $mfgRepo->method('getAutoMarkupConfig')
            ->willReturnCallback(function ($itemId) use ($autoMarkupMap) {
                return $autoMarkupMap[$itemId] ?? null;
            });

        // Mock ItemRepository that tracks update calls
        $itemRepo = $this->createMock(ItemRepository::class);

        $itemRepo->method('getById')
            ->willReturnCallback(function ($itemId, $tid) use ($manufacturedItemsMap, $tenantId) {
                if ($tid === $tenantId && isset($manufacturedItemsMap[$itemId])) {
                    return $manufacturedItemsMap[$itemId];
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

        // Mock ItemService (not used in recalculateAffectedRecipes directly)
        $itemService = new class extends ItemService {
            public function __construct() {}
        };

        // Mock AuditService
        $auditService = new class extends AuditService {
            public function __construct() {}
            public function log(
                string $eventType, string $table, $recordId,
                string $action, ?array $oldValues = null, ?array $newValues = null,
                ?int $userId = null, ?int $tenantId = null, ?int $businessId = null
            ): void {}
        };

        // Mock PDO
        $pdo = $this->createMock(PDO::class);

        // Build ManufacturingService using reflection to inject mocked dependencies
        $ref = new ReflectionClass(ManufacturingService::class);
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
            'audit'          => $auditService,
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

    /**
     * Build a ManufacturingService with mocked dependencies that tracks
     * updateCostPrice and setCostChangeFlag calls during cascading recalculation.
     *
     * @param array  $recipes             Recipe definitions
     * @param int    $changedComponentId   The component item whose cost changed
     * @param array  &$costPriceUpdates   Reference to array capturing updateCostPrice calls
     * @param array  &$costFlagCalls      Reference to array capturing setCostChangeFlag calls
     * @return ManufacturingService
     */
    private function buildServiceWithCascadingTracking(
        array $recipes,
        int $changedComponentId,
        array &$costPriceUpdates,
        array &$costFlagCalls
    ): ManufacturingService {
        $tenantId = $this->tenantId;
        $businessId = $this->businessId;

        // Build affected recipes data as returned by getRecipesByComponentItem
        $affectedRecipesData = [];
        foreach ($recipes as $recipe) {
            $affectedRecipesData[] = [
                'id'                   => $recipe['recipe_id'],
                'tenant_id'            => $tenantId,
                'business_id'          => $businessId,
                'manufactured_item_id' => $recipe['manufactured_item_id'],
                'yield_quantity'       => $recipe['yield_quantity'],
                'notes'                => null,
                'is_active'            => 1,
                'created_by'           => $this->userId,
                'created_at'           => date('Y-m-d H:i:s'),
                'updated_at'           => null,
            ];
        }

        // Build recipe items data for each recipe
        $recipeItemsMap = [];
        foreach ($recipes as $recipe) {
            $items = [];
            foreach ($recipe['components'] as $idx => $component) {
                $items[] = [
                    'id'                  => ($recipe['recipe_id'] * 100) + $idx,
                    'recipe_id'           => $recipe['recipe_id'],
                    'component_item_id'   => $component['item_id'],
                    'quantity'            => $component['quantity'],
                    'component_item_name' => 'Component_' . $component['item_id'],
                    'unit'                => 'pcs',
                    'cost_price'          => $component['cost_price'],
                ];
            }
            $recipeItemsMap[$recipe['recipe_id']] = $items;
        }

        // Build manufactured item data for getById
        $manufacturedItemsMap = [];
        foreach ($recipes as $recipe) {
            $manufacturedItemsMap[$recipe['manufactured_item_id']] = [
                'id'          => $recipe['manufactured_item_id'],
                'tenant_id'   => $tenantId,
                'business_id' => $businessId,
                'name'        => 'Manufactured_' . $recipe['manufactured_item_id'],
                'cost_price'  => $recipe['old_cost_price'],
                'sale_price'  => null,
                'is_active'   => 1,
            ];
        }

        // Mock ManufacturingRepository
        $mfgRepo = $this->createMock(ManufacturingRepository::class);

        $mfgRepo->method('getRecipesByComponentItem')
            ->willReturnCallback(function ($itemId, $tid) use ($changedComponentId, $tenantId, $affectedRecipesData) {
                if ($itemId === $changedComponentId && $tid === $tenantId) {
                    return $affectedRecipesData;
                }
                return [];
            });

        $mfgRepo->method('getRecipeItems')
            ->willReturnCallback(function ($recipeId) use ($recipeItemsMap) {
                return $recipeItemsMap[$recipeId] ?? [];
            });

        // Track setCostChangeFlag calls
        $mfgRepo->method('setCostChangeFlag')
            ->willReturnCallback(function ($itemId, $oldCost, $newCost) use (&$costFlagCalls) {
                $costFlagCalls[] = [
                    'item_id'  => $itemId,
                    'old_cost' => $oldCost,
                    'new_cost' => $newCost,
                ];
            });

        // No auto-markup configured for this test
        $mfgRepo->method('getAutoMarkupConfig')
            ->willReturn(null);

        // Mock ItemRepository that tracks updateCostPrice calls
        $itemRepo = $this->createMock(ItemRepository::class);

        $itemRepo->method('getById')
            ->willReturnCallback(function ($itemId, $tid) use ($manufacturedItemsMap, $tenantId) {
                if ($tid === $tenantId && isset($manufacturedItemsMap[$itemId])) {
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

        // Mock ItemService (not used in recalculateAffectedRecipes directly)
        $itemService = new class extends ItemService {
            public function __construct() {}
        };

        // Mock AuditService
        $auditService = new class extends AuditService {
            public function __construct() {}
            public function log(
                string $eventType, string $table, $recordId,
                string $action, ?array $oldValues = null, ?array $newValues = null,
                ?int $userId = null, ?int $tenantId = null, ?int $businessId = null
            ): void {}
        };

        // Mock PDO
        $pdo = $this->createMock(PDO::class);

        // Build ManufacturingService using reflection to inject mocked dependencies
        $ref = new ReflectionClass(ManufacturingService::class);
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
            'audit'          => $auditService,
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
