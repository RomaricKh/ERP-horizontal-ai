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
 * Integration tests for tenant and business data isolation in the manufacturing module.
 *
 * Tests that recipes and production orders created in one tenant/business context
 * are not accessible from another tenant/business context. This validates Property 13
 * (Tenant and business data isolation) with example-based tests.
 *
 * The approach:
 * 1. Set AuthContext to Tenant A, create recipes and production orders
 * 2. Switch AuthContext to Tenant B, verify queries return null/empty
 * 3. Verify getRecipeById, getAllRecipes, getProductionOrderById, getAllProductionOrders
 *    all respect tenant scoping
 *
 * Validates: Requirements 8.1, 8.2, 8.3
 */
class ManufacturingTenantIsolationTest extends TestCase
{
    private int $tenantA   = 1;
    private int $businessA = 1;
    private int $userA     = 1;

    private int $tenantB   = 2;
    private int $businessB = 2;
    private int $userB     = 2;

    protected function setUp(): void
    {
        // Start with Tenant A context
        AuthContext::set((object) [
            'user_id'     => $this->userA,
            'tenant_id'   => $this->tenantA,
            'business_id' => $this->businessA,
        ]);
    }

    // ---------------------------------------------------------------
    // Test: Recipe created in Tenant A is not accessible from Tenant B
    // ---------------------------------------------------------------

    /**
     * When a recipe is created in Tenant A's context, querying for that recipe
     * from Tenant B's context should return null (getRecipeById) or exclude it
     * from the list (getAllRecipes).
     *
     * Scenario:
     * - Tenant A creates a recipe (id=1) with manufactured item (id=999)
     * - Switch to Tenant B context
     * - getRecipeById(1) returns null
     * - getAllRecipes returns empty array
     * - getRecipeByItemId(999) returns null (via getRecipe service method)
     *
     * Validates: Requirements 8.1
     */
    public function testRecipeCreatedInTenantANotAccessibleFromTenantB(): void
    {
        $recipeId = 1;
        $manufacturedItemId = 999;

        // Build mocks that simulate tenant-scoped data
        $mfgRepo = $this->createMock(ManufacturingRepository::class);
        $itemRepo = $this->createMock(ItemRepository::class);
        $audit = $this->createMock(AuditService::class);
        $pdo = $this->createMock(PDO::class);

        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // getRecipeById: only returns data when tenant_id and business_id match Tenant A
        $mfgRepo->method('getRecipeById')->willReturnCallback(
            function (int $id, int $tenantId, int $businessId) use ($recipeId) {
                if ($id === $recipeId && $tenantId === $this->tenantA && $businessId === $this->businessA) {
                    return [
                        'id'                     => $recipeId,
                        'tenant_id'              => $this->tenantA,
                        'business_id'            => $this->businessA,
                        'manufactured_item_id'   => 999,
                        'yield_quantity'         => 2,
                        'notes'                  => null,
                        'is_active'              => 1,
                        'created_by'             => $this->userA,
                        'manufactured_item_name' => 'Product Alpha',
                        'unit'                   => 'pcs',
                        'sale_price'             => null,
                        'markup_percent'         => null,
                    ];
                }
                // Different tenant/business → not found
                return null;
            }
        );

        // getAllRecipes: only returns recipes for the matching tenant/business
        $mfgRepo->method('getAllRecipes')->willReturnCallback(
            function (int $tenantId, int $businessId) use ($recipeId) {
                if ($tenantId === $this->tenantA && $businessId === $this->businessA) {
                    return [[
                        'id'                             => $recipeId,
                        'tenant_id'                      => $this->tenantA,
                        'business_id'                    => $this->businessA,
                        'manufactured_item_id'           => 999,
                        'yield_quantity'                 => 2,
                        'manufactured_item_name'         => 'Product Alpha',
                        'manufactured_item_cost_price'   => 25.00,
                    ]];
                }
                // Different tenant/business → empty
                return [];
            }
        );

        // getRecipeByItemId: only returns for matching tenant/business
        $mfgRepo->method('getRecipeByItemId')->willReturnCallback(
            function (int $itemId, int $tenantId, int $businessId) use ($recipeId, $manufacturedItemId) {
                if ($itemId === $manufacturedItemId && $tenantId === $this->tenantA && $businessId === $this->businessA) {
                    return [
                        'id'                     => $recipeId,
                        'tenant_id'              => $this->tenantA,
                        'business_id'            => $this->businessA,
                        'manufactured_item_id'   => $manufacturedItemId,
                        'yield_quantity'         => 2,
                        'manufactured_item_name' => 'Product Alpha',
                    ];
                }
                return null;
            }
        );

        // getRecipeItems: returns items for the recipe
        $mfgRepo->method('getRecipeItems')->willReturn([
            ['component_item_id' => 100, 'quantity' => 4.0, 'cost_price' => 10.00, 'component_item_name' => 'Component A'],
            ['component_item_id' => 200, 'quantity' => 2.0, 'cost_price' => 5.00, 'component_item_name' => 'Component B'],
        ]);

        $itemService = $this->createMinimalItemService();
        $service = $this->buildManufacturingService($pdo, $mfgRepo, $itemService, $audit, $itemRepo);

        // ═══════════════════════════════════════════════════════════════
        // PHASE 1: Verify Tenant A can access the recipe
        // ═══════════════════════════════════════════════════════════════

        AuthContext::set((object) [
            'user_id'     => $this->userA,
            'tenant_id'   => $this->tenantA,
            'business_id' => $this->businessA,
        ]);

        // getRecipe should return the recipe
        $recipe = $service->getRecipe($recipeId);
        $this->assertNotNull($recipe);
        $this->assertEquals($recipeId, $recipe['id']);
        $this->assertEquals('Product Alpha', $recipe['manufactured_item_name']);

        // listRecipes should include the recipe
        $recipes = $service->listRecipes();
        $this->assertCount(1, $recipes);
        $this->assertEquals($recipeId, $recipes[0]['id']);

        // getRecipeByItemId should return the recipe
        $recipeByItem = $service->getRecipeByItemId($manufacturedItemId);
        $this->assertNotNull($recipeByItem);
        $this->assertEquals($recipeId, $recipeByItem['id']);

        // ═══════════════════════════════════════════════════════════════
        // PHASE 2: Switch to Tenant B and verify isolation
        // ═══════════════════════════════════════════════════════════════

        AuthContext::set((object) [
            'user_id'     => $this->userB,
            'tenant_id'   => $this->tenantB,
            'business_id' => $this->businessB,
        ]);

        // getRecipe should throw (recipe not found for Tenant B)
        $thrownNotFound = false;
        try {
            $service->getRecipe($recipeId);
        } catch (\RuntimeException $e) {
            $thrownNotFound = true;
            $this->assertStringContainsString('Recipe not found', $e->getMessage());
        }
        $this->assertTrue($thrownNotFound, 'Recipe from Tenant A should not be accessible from Tenant B');

        // listRecipes should return empty array for Tenant B
        $recipesB = $service->listRecipes();
        $this->assertEmpty($recipesB, 'Tenant B should see no recipes from Tenant A');

        // getRecipeByItemId should return null for Tenant B
        $recipeByItemB = $service->getRecipeByItemId($manufacturedItemId);
        $this->assertNull($recipeByItemB, 'Recipe by item ID should not be accessible from Tenant B');
    }

    // ---------------------------------------------------------------
    // Test: Production order created in Tenant A is not accessible from Tenant B
    // ---------------------------------------------------------------

    /**
     * When a production order is created in Tenant A's context, querying for
     * that order from Tenant B's context should return null (getProductionOrderById)
     * or exclude it from the list (getAllProductionOrders).
     *
     * Scenario:
     * - Tenant A has a production order (id=42)
     * - Switch to Tenant B context
     * - getProductionOrderById(42) returns null → service throws "not found"
     * - getAllProductionOrders returns empty array
     *
     * Validates: Requirements 8.2
     */
    public function testProductionOrderCreatedInTenantANotAccessibleFromTenantB(): void
    {
        $orderId = 42;
        $manufacturedItemId = 999;

        $mfgRepo = $this->createMock(ManufacturingRepository::class);
        $itemRepo = $this->createMock(ItemRepository::class);
        $audit = $this->createMock(AuditService::class);
        $pdo = $this->createMock(PDO::class);

        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // getProductionOrderById: only returns data for Tenant A
        $mfgRepo->method('getProductionOrderById')->willReturnCallback(
            function (int $id, int $tenantId, int $businessId) use ($orderId) {
                if ($id === $orderId && $tenantId === $this->tenantA && $businessId === $this->businessA) {
                    return [
                        'id'                      => $orderId,
                        'tenant_id'               => $this->tenantA,
                        'business_id'             => $this->businessA,
                        'recipe_id'               => 1,
                        'manufactured_item_id'    => 999,
                        'production_quantity'     => 6.0,
                        'manufacturing_cost'      => 150.0,
                        'unit_manufacturing_cost' => 25.0,
                        'status'                  => 'COMPLETED',
                        'production_date'         => '2024-01-15 10:00:00',
                        'created_by'              => $this->userA,
                        'manufactured_item_name'  => 'Product Alpha',
                        'recipe_yield_quantity'   => 2,
                    ];
                }
                // Different tenant/business → not found
                return null;
            }
        );

        // getAllProductionOrders: only returns orders for matching tenant/business
        $mfgRepo->method('getAllProductionOrders')->willReturnCallback(
            function (int $tenantId, int $businessId, ?array $filters = null) use ($orderId) {
                if ($tenantId === $this->tenantA && $businessId === $this->businessA) {
                    return [[
                        'id'                      => $orderId,
                        'tenant_id'               => $this->tenantA,
                        'business_id'             => $this->businessA,
                        'recipe_id'               => 1,
                        'manufactured_item_id'    => 999,
                        'production_quantity'     => 6.0,
                        'manufacturing_cost'      => 150.0,
                        'unit_manufacturing_cost' => 25.0,
                        'status'                  => 'COMPLETED',
                        'production_date'         => '2024-01-15 10:00:00',
                        'created_by'              => $this->userA,
                        'manufactured_item_name'  => 'Product Alpha',
                    ]];
                }
                // Different tenant/business → empty
                return [];
            }
        );

        // getProductionOrderItems: returns items for the order
        $mfgRepo->method('getProductionOrderItems')->willReturn([
            ['component_item_id' => 100, 'quantity_consumed' => 12.0, 'unit_cost' => 10.00],
            ['component_item_id' => 200, 'quantity_consumed' => 6.0, 'unit_cost' => 5.00],
        ]);

        $itemService = $this->createMinimalItemService();
        $service = $this->buildManufacturingService($pdo, $mfgRepo, $itemService, $audit, $itemRepo);

        // ═══════════════════════════════════════════════════════════════
        // PHASE 1: Verify Tenant A can access the production order
        // ═══════════════════════════════════════════════════════════════

        AuthContext::set((object) [
            'user_id'     => $this->userA,
            'tenant_id'   => $this->tenantA,
            'business_id' => $this->businessA,
        ]);

        // getProductionOrder should return the order
        $order = $service->getProductionOrder($orderId);
        $this->assertNotNull($order);
        $this->assertEquals($orderId, $order['id']);
        $this->assertEquals('COMPLETED', $order['status']);
        $this->assertEqualsWithDelta(6.0, $order['production_quantity'], 0.0001);

        // listProductionOrders should include the order
        $orders = $service->listProductionOrders();
        $this->assertCount(1, $orders);
        $this->assertEquals($orderId, $orders[0]['id']);

        // ═══════════════════════════════════════════════════════════════
        // PHASE 2: Switch to Tenant B and verify isolation
        // ═══════════════════════════════════════════════════════════════

        AuthContext::set((object) [
            'user_id'     => $this->userB,
            'tenant_id'   => $this->tenantB,
            'business_id' => $this->businessB,
        ]);

        // getProductionOrder should throw (order not found for Tenant B)
        $thrownNotFound = false;
        try {
            $service->getProductionOrder($orderId);
        } catch (\RuntimeException $e) {
            $thrownNotFound = true;
            $this->assertStringContainsString('Production order not found', $e->getMessage());
        }
        $this->assertTrue($thrownNotFound, 'Production order from Tenant A should not be accessible from Tenant B');

        // listProductionOrders should return empty array for Tenant B
        $ordersB = $service->listProductionOrders();
        $this->assertEmpty($ordersB, 'Tenant B should see no production orders from Tenant A');
    }

    // ---------------------------------------------------------------
    // Test: Component item validation rejects cross-tenant items
    // ---------------------------------------------------------------

    /**
     * When creating a recipe, component items must belong to the same tenant
     * and business. Attempting to reference a component from another tenant
     * should be rejected.
     *
     * Scenario:
     * - Tenant B tries to create a recipe using a component item (id=100)
     *   that belongs to Tenant A
     * - The validation should reject with "Component item not found or not accessible"
     *
     * Validates: Requirements 8.3
     */
    public function testComponentItemValidationRejectsCrossTenantItems(): void
    {
        $mfgRepo = $this->createMock(ManufacturingRepository::class);
        $itemRepo = $this->createMock(ItemRepository::class);
        $audit = $this->createMock(AuditService::class);
        $pdo = $this->createMock(PDO::class);

        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Item 100 belongs to Tenant A - when Tenant B queries, it returns null
        $itemRepo->method('getById')->willReturnCallback(
            function (int $itemId, int $tenantId) {
                // Item only exists for Tenant A
                if ($itemId === 100 && $tenantId === $this->tenantA) {
                    return [
                        'id'          => 100,
                        'name'        => 'Component A',
                        'cost_price'  => 10.00,
                        'business_id' => $this->businessA,
                        'tenant_id'   => $this->tenantA,
                    ];
                }
                // For Tenant B, item is not found
                return null;
            }
        );

        $itemService = $this->createMinimalItemService();
        $service = $this->buildManufacturingService($pdo, $mfgRepo, $itemService, $audit, $itemRepo);

        // Set context to Tenant B
        AuthContext::set((object) [
            'user_id'     => $this->userB,
            'tenant_id'   => $this->tenantB,
            'business_id' => $this->businessB,
        ]);

        // Attempt to create a recipe using Tenant A's component item
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Component item not found or not accessible');

        $service->createRecipe([
            'name'           => 'Cross-Tenant Product',
            'components'     => [
                ['item_id' => 100, 'quantity' => 3.0],
            ],
            'yield_quantity' => 1,
        ]);
    }

    // ---------------------------------------------------------------
    // Test: Same business isolation within different tenants
    // ---------------------------------------------------------------

    /**
     * Even if two tenants happen to have the same business_id value,
     * data isolation is maintained because queries scope by BOTH tenant_id
     * AND business_id.
     *
     * Scenario:
     * - Tenant A (tenant_id=1, business_id=1) has a recipe
     * - Tenant C (tenant_id=3, business_id=1) queries for the same recipe ID
     * - The recipe should not be accessible because tenant_id differs
     *
     * Validates: Requirements 8.1, 8.2
     */
    public function testIsolationMaintainedEvenWithSameBusinessId(): void
    {
        $tenantC = 3;
        $businessC = 1; // Same business_id as Tenant A, different tenant
        $userC = 3;

        $recipeId = 1;
        $orderId = 42;

        $mfgRepo = $this->createMock(ManufacturingRepository::class);
        $itemRepo = $this->createMock(ItemRepository::class);
        $audit = $this->createMock(AuditService::class);
        $pdo = $this->createMock(PDO::class);

        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Recipe only accessible for Tenant A + Business A combination
        $mfgRepo->method('getRecipeById')->willReturnCallback(
            function (int $id, int $tenantId, int $businessId) use ($recipeId) {
                if ($id === $recipeId && $tenantId === $this->tenantA && $businessId === $this->businessA) {
                    return [
                        'id'                     => $recipeId,
                        'tenant_id'              => $this->tenantA,
                        'business_id'            => $this->businessA,
                        'manufactured_item_id'   => 999,
                        'yield_quantity'         => 2,
                        'manufactured_item_name' => 'Product Alpha',
                        'notes'                  => null,
                        'is_active'              => 1,
                        'created_by'             => $this->userA,
                        'unit'                   => 'pcs',
                        'sale_price'             => null,
                        'markup_percent'         => null,
                    ];
                }
                return null;
            }
        );

        // Production order only accessible for Tenant A + Business A
        $mfgRepo->method('getProductionOrderById')->willReturnCallback(
            function (int $id, int $tenantId, int $businessId) use ($orderId) {
                if ($id === $orderId && $tenantId === $this->tenantA && $businessId === $this->businessA) {
                    return [
                        'id'                      => $orderId,
                        'tenant_id'               => $this->tenantA,
                        'business_id'             => $this->businessA,
                        'recipe_id'               => 1,
                        'manufactured_item_id'    => 999,
                        'production_quantity'     => 6.0,
                        'manufacturing_cost'      => 150.0,
                        'unit_manufacturing_cost' => 25.0,
                        'status'                  => 'COMPLETED',
                        'production_date'         => '2024-01-15 10:00:00',
                        'created_by'              => $this->userA,
                        'manufactured_item_name'  => 'Product Alpha',
                        'recipe_yield_quantity'   => 2,
                    ];
                }
                return null;
            }
        );

        // getAllRecipes: only for Tenant A
        $mfgRepo->method('getAllRecipes')->willReturnCallback(
            function (int $tenantId, int $businessId) use ($recipeId) {
                if ($tenantId === $this->tenantA && $businessId === $this->businessA) {
                    return [[
                        'id'                           => $recipeId,
                        'tenant_id'                    => $this->tenantA,
                        'business_id'                  => $this->businessA,
                        'manufactured_item_id'         => 999,
                        'yield_quantity'               => 2,
                        'manufactured_item_name'       => 'Product Alpha',
                        'manufactured_item_cost_price' => 25.00,
                    ]];
                }
                return [];
            }
        );

        // getAllProductionOrders: only for Tenant A
        $mfgRepo->method('getAllProductionOrders')->willReturnCallback(
            function (int $tenantId, int $businessId, ?array $filters = null) use ($orderId) {
                if ($tenantId === $this->tenantA && $businessId === $this->businessA) {
                    return [[
                        'id'                      => $orderId,
                        'tenant_id'               => $this->tenantA,
                        'business_id'             => $this->businessA,
                        'manufactured_item_name'  => 'Product Alpha',
                        'production_quantity'     => 6.0,
                        'status'                  => 'COMPLETED',
                    ]];
                }
                return [];
            }
        );

        $mfgRepo->method('getRecipeItems')->willReturn([
            ['component_item_id' => 100, 'quantity' => 4.0, 'cost_price' => 10.00, 'component_item_name' => 'Component A'],
        ]);

        $mfgRepo->method('getProductionOrderItems')->willReturn([
            ['component_item_id' => 100, 'quantity_consumed' => 12.0, 'unit_cost' => 10.00],
        ]);

        $itemService = $this->createMinimalItemService();
        $service = $this->buildManufacturingService($pdo, $mfgRepo, $itemService, $audit, $itemRepo);

        // ═══════════════════════════════════════════════════════════════
        // PHASE 1: Verify Tenant A can access data
        // ═══════════════════════════════════════════════════════════════

        AuthContext::set((object) [
            'user_id'     => $this->userA,
            'tenant_id'   => $this->tenantA,
            'business_id' => $this->businessA,
        ]);

        $recipe = $service->getRecipe($recipeId);
        $this->assertNotNull($recipe);
        $this->assertEquals($recipeId, $recipe['id']);

        $order = $service->getProductionOrder($orderId);
        $this->assertNotNull($order);
        $this->assertEquals($orderId, $order['id']);

        // ═══════════════════════════════════════════════════════════════
        // PHASE 2: Tenant C (same business_id, different tenant_id) cannot access
        // ═══════════════════════════════════════════════════════════════

        AuthContext::set((object) [
            'user_id'     => $userC,
            'tenant_id'   => $tenantC,
            'business_id' => $businessC,
        ]);

        // Recipe not accessible
        $thrownRecipe = false;
        try {
            $service->getRecipe($recipeId);
        } catch (\RuntimeException $e) {
            $thrownRecipe = true;
            $this->assertStringContainsString('Recipe not found', $e->getMessage());
        }
        $this->assertTrue($thrownRecipe, 'Recipe should not be accessible from different tenant even with same business_id');

        // Production order not accessible
        $thrownOrder = false;
        try {
            $service->getProductionOrder($orderId);
        } catch (\RuntimeException $e) {
            $thrownOrder = true;
            $this->assertStringContainsString('Production order not found', $e->getMessage());
        }
        $this->assertTrue($thrownOrder, 'Production order should not be accessible from different tenant even with same business_id');

        // Lists return empty
        $recipesC = $service->listRecipes();
        $this->assertEmpty($recipesC, 'Tenant C should see no recipes');

        $ordersC = $service->listProductionOrders();
        $this->assertEmpty($ordersC, 'Tenant C should see no production orders');
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
    // Helper: Build ManufacturingService with injected dependencies
    // ---------------------------------------------------------------

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
