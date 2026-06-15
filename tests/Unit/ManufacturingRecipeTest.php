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
 * Unit tests for ManufacturingService recipe operations.
 *
 * Tests recipe creation happy path (Property 2 example-based),
 * recipe update cost recalculation, recipe deletion deactivating
 * manufactured item (Property 10), recipe deletion rejection when
 * production orders exist (Property 11), and self-referencing
 * component rejection.
 *
 * Validates: Requirements 1.2, 1.3, 1.11, 2.5, 3.1, 3.2, 3.3
 */
class ManufacturingRecipeTest extends TestCase
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
    // Test: Recipe creation happy path (Property 2 example-based)
    // ---------------------------------------------------------------

    /**
     * Property 2: Recipe creation produces a correctly-typed manufactured item.
     *
     * Given a valid recipe with known component costs and quantities:
     * - Component A: cost_price=10.00, quantity=2
     * - Component B: cost_price=5.00, quantity=3
     * - Yield: 5 units
     * Expected unit cost = (10*2 + 5*3) / 5 = 35/5 = 7.00
     *
     * Verify:
     * - Item is created with type "Manufactured"
     * - Item cost_price equals calculated unit cost (7.00)
     * - Recipe is inserted with correct data
     * - Recipe items are inserted
     * - Audit log is recorded
     *
     * Validates: Requirements 1.2, 1.3
     */
    public function testCreateRecipeHappyPath(): void
    {
        $insertedItem = null;
        $insertedRecipe = null;
        $insertedRecipeItems = null;
        $auditLogged = false;

        // Build mocks
        $itemRepo = $this->createMock(ItemRepository::class);
        $mfgRepo = $this->createMock(ManufacturingRepository::class);
        $audit = $this->createMock(AuditService::class);
        $itemService = $this->createMock(ItemService::class);
        $pdo = $this->createMock(PDO::class);

        // PDO transaction methods
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // PDO prepare for getManufacturedTypeId query
        $typeStmt = $this->createMock(PDOStatement::class);
        $typeStmt->method('execute')->willReturn(true);
        $typeStmt->method('fetchColumn')->willReturn(3); // type ID = 3

        $pdo->method('prepare')->willReturn($typeStmt);

        // Component items exist in the tenant/business
        $itemRepo->method('getById')->willReturnCallback(
            function (int $id, int $tenantId) {
                $items = [
                    100 => ['id' => 100, 'name' => 'Component A', 'cost_price' => 10.00, 'business_id' => 1, 'tenant_id' => 1],
                    200 => ['id' => 200, 'name' => 'Component B', 'cost_price' => 5.00, 'business_id' => 1, 'tenant_id' => 1],
                ];
                return $items[$id] ?? null;
            }
        );

        // Item insert captures data and returns item ID
        $itemRepo->method('insert')->willReturnCallback(
            function (array $data) use (&$insertedItem) {
                $insertedItem = $data;
                return 500; // new item ID
            }
        );

        // Recipe insert captures data and returns recipe ID
        $mfgRepo->method('insertRecipe')->willReturnCallback(
            function (array $data) use (&$insertedRecipe) {
                $insertedRecipe = $data;
                return 10; // new recipe ID
            }
        );

        // Recipe items insert captures data
        $mfgRepo->method('insertRecipeItems')->willReturnCallback(
            function (int $recipeId, array $items) use (&$insertedRecipeItems) {
                $insertedRecipeItems = ['recipe_id' => $recipeId, 'items' => $items];
            }
        );

        // Audit log
        $audit->expects($this->once())->method('log')
            ->willReturnCallback(function () use (&$auditLogged) {
                $auditLogged = true;
            });

        // Build service with injected dependencies
        $service = $this->buildManufacturingService($pdo, $mfgRepo, $itemService, $audit, $itemRepo);

        // Execute
        $result = $service->createRecipe([
            'name'           => 'Finished Product',
            'components'     => [
                ['item_id' => 100, 'quantity' => 2],
                ['item_id' => 200, 'quantity' => 3],
            ],
            'yield_quantity' => 5,
            'sale_price'     => 15.00,
        ]);

        // Assertions
        $this->assertEquals(10, $result['recipe_id']);
        $this->assertEquals(500, $result['item_id']);

        // Item was created with correct type and cost
        $this->assertNotNull($insertedItem);
        $this->assertEquals(3, $insertedItem['type']); // Manufactured type ID
        $this->assertEquals('Finished Product', $insertedItem['name']);
        $this->assertEquals(7.00, $insertedItem['cost']); // (10*2 + 5*3) / 5 = 7.00
        $this->assertEquals(15.00, $insertedItem['sale']);
        $this->assertEquals($this->tenantId, $insertedItem['tenant']);
        $this->assertEquals($this->businessId, $insertedItem['business']);

        // Recipe was inserted with correct data
        $this->assertNotNull($insertedRecipe);
        $this->assertEquals(500, $insertedRecipe['manufactured_item_id']);
        $this->assertEquals(5, $insertedRecipe['yield_quantity']);
        $this->assertEquals($this->tenantId, $insertedRecipe['tenant_id']);
        $this->assertEquals($this->businessId, $insertedRecipe['business_id']);

        // Recipe items were inserted
        $this->assertNotNull($insertedRecipeItems);
        $this->assertEquals(10, $insertedRecipeItems['recipe_id']);
        $this->assertCount(2, $insertedRecipeItems['items']);
        $this->assertEquals(100, $insertedRecipeItems['items'][0]['component_item_id']);
        $this->assertEquals(2, $insertedRecipeItems['items'][0]['quantity']);
        $this->assertEquals(200, $insertedRecipeItems['items'][1]['component_item_id']);
        $this->assertEquals(3, $insertedRecipeItems['items'][1]['quantity']);

        // Audit was logged
        $this->assertTrue($auditLogged);
    }

    // ---------------------------------------------------------------
    // Test: Recipe update recalculates cost correctly
    // ---------------------------------------------------------------

    /**
     * Recipe update recalculates the manufactured item cost_price.
     *
     * Original recipe: Component A (cost=10, qty=2), yield=5 → cost=4.00
     * Updated recipe: Component A (cost=10, qty=4), Component B (cost=5, qty=2), yield=2
     * New cost = (10*4 + 5*2) / 2 = 50/2 = 25.00
     *
     * Verify:
     * - updateCostPrice is called with the new cost (25.00)
     * - Recipe items are replaced (old deleted, new inserted)
     * - Audit log records before/after
     *
     * Validates: Requirements 2.5
     */
    public function testUpdateRecipeRecalculatesCost(): void
    {
        $updatedCostPrice = null;
        $deletedRecipeItems = false;
        $newRecipeItems = null;

        $itemRepo = $this->createMock(ItemRepository::class);
        $mfgRepo = $this->createMock(ManufacturingRepository::class);
        $audit = $this->createMock(AuditService::class);
        $itemService = $this->createMock(ItemService::class);
        $pdo = $this->createMock(PDO::class);

        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Existing recipe
        $mfgRepo->method('getRecipeById')->willReturn([
            'id'                    => 10,
            'tenant_id'             => $this->tenantId,
            'business_id'           => $this->businessId,
            'manufactured_item_id'  => 500,
            'manufactured_item_name' => 'Finished Product',
            'yield_quantity'        => 5,
        ]);

        // Old recipe items (for audit)
        $mfgRepo->method('getRecipeItems')->willReturn([
            ['component_item_id' => 100, 'quantity' => 2, 'cost_price' => 10.00, 'component_item_name' => 'Component A'],
        ]);

        // Component items exist
        $itemRepo->method('getById')->willReturnCallback(
            function (int $id, int $tenantId) {
                $items = [
                    100 => ['id' => 100, 'name' => 'Component A', 'cost_price' => 10.00, 'business_id' => 1, 'tenant_id' => 1],
                    200 => ['id' => 200, 'name' => 'Component B', 'cost_price' => 5.00, 'business_id' => 1, 'tenant_id' => 1],
                ];
                return $items[$id] ?? null;
            }
        );

        // Track updateCostPrice call
        $itemRepo->method('updateCostPrice')->willReturnCallback(
            function (int $itemId, int $tenantId, float $costPrice) use (&$updatedCostPrice) {
                $updatedCostPrice = ['item_id' => $itemId, 'cost_price' => $costPrice];
            }
        );

        // Track recipe items deletion
        $mfgRepo->method('deleteRecipeItems')->willReturnCallback(
            function (int $recipeId) use (&$deletedRecipeItems) {
                $deletedRecipeItems = true;
            }
        );

        // Track new recipe items insertion
        $mfgRepo->method('insertRecipeItems')->willReturnCallback(
            function (int $recipeId, array $items) use (&$newRecipeItems) {
                $newRecipeItems = $items;
            }
        );

        $mfgRepo->method('updateRecipe')->willReturnCallback(function () {});
        $itemRepo->method('update')->willReturnCallback(function () {});
        $audit->expects($this->once())->method('log');

        $service = $this->buildManufacturingService($pdo, $mfgRepo, $itemService, $audit, $itemRepo);

        $service->updateRecipe(10, [
            'name'           => 'Finished Product',
            'components'     => [
                ['item_id' => 100, 'quantity' => 4],
                ['item_id' => 200, 'quantity' => 2],
            ],
            'yield_quantity' => 2,
        ]);

        // Cost was recalculated: (10*4 + 5*2) / 2 = 25.00
        $this->assertNotNull($updatedCostPrice);
        $this->assertEquals(500, $updatedCostPrice['item_id']);
        $this->assertEquals(25.00, $updatedCostPrice['cost_price']);

        // Old recipe items were deleted
        $this->assertTrue($deletedRecipeItems);

        // New recipe items were inserted
        $this->assertNotNull($newRecipeItems);
        $this->assertCount(2, $newRecipeItems);
        $this->assertEquals(100, $newRecipeItems[0]['component_item_id']);
        $this->assertEquals(4, $newRecipeItems[0]['quantity']);
        $this->assertEquals(200, $newRecipeItems[1]['component_item_id']);
        $this->assertEquals(2, $newRecipeItems[1]['quantity']);
    }

    // ---------------------------------------------------------------
    // Test: Recipe deletion deactivates manufactured item (Property 10)
    // ---------------------------------------------------------------

    /**
     * Property 10: Recipe deletion deactivates manufactured item.
     *
     * Given a recipe with no production orders, deleting it should:
     * - Delete recipe items
     * - Delete the recipe
     * - Deactivate the manufactured item (is_active = false)
     * - Record audit log
     *
     * Validates: Requirements 3.1, 3.3
     */
    public function testDeleteRecipeDeactivatesManufacturedItem(): void
    {
        $deactivatedItemId = null;
        $deletedRecipeId = null;
        $deletedRecipeItemsId = null;

        $itemRepo = $this->createMock(ItemRepository::class);
        $mfgRepo = $this->createMock(ManufacturingRepository::class);
        $audit = $this->createMock(AuditService::class);
        $itemService = $this->createMock(ItemService::class);
        $pdo = $this->createMock(PDO::class);

        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Recipe exists
        $mfgRepo->method('getRecipeById')->willReturn([
            'id'                    => 10,
            'tenant_id'             => $this->tenantId,
            'business_id'           => $this->businessId,
            'manufactured_item_id'  => 500,
            'manufactured_item_name' => 'Finished Product',
            'yield_quantity'        => 5,
        ]);

        // No production orders
        $mfgRepo->method('countProductionOrdersForRecipe')->willReturn(0);

        // Track recipe items deletion
        $mfgRepo->method('deleteRecipeItems')->willReturnCallback(
            function (int $recipeId) use (&$deletedRecipeItemsId) {
                $deletedRecipeItemsId = $recipeId;
            }
        );

        // Track recipe deletion
        $mfgRepo->method('deleteRecipe')->willReturnCallback(
            function (int $id, int $tenantId) use (&$deletedRecipeId) {
                $deletedRecipeId = $id;
            }
        );

        // Track item deactivation
        $itemRepo->method('deactivate')->willReturnCallback(
            function (int $id, int $tenantId) use (&$deactivatedItemId) {
                $deactivatedItemId = $id;
            }
        );

        $audit->expects($this->once())->method('log');

        $service = $this->buildManufacturingService($pdo, $mfgRepo, $itemService, $audit, $itemRepo);

        $service->deleteRecipe(10);

        // Recipe items were deleted
        $this->assertEquals(10, $deletedRecipeItemsId);

        // Recipe was deleted
        $this->assertEquals(10, $deletedRecipeId);

        // Manufactured item was deactivated
        $this->assertEquals(500, $deactivatedItemId);
    }

    // ---------------------------------------------------------------
    // Test: Recipe deletion rejected when production orders exist (Property 11)
    // ---------------------------------------------------------------

    /**
     * Property 11: Recipe with production orders cannot be deleted.
     *
     * Given a recipe that has been used in 3 production orders,
     * attempting to delete it should throw an InvalidArgumentException
     * with a descriptive message and leave the recipe unchanged.
     *
     * Validates: Requirements 3.2
     */
    public function testDeleteRecipeRejectedWhenProductionOrdersExist(): void
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        $mfgRepo = $this->createMock(ManufacturingRepository::class);
        $audit = $this->createMock(AuditService::class);
        $itemService = $this->createMock(ItemService::class);
        $pdo = $this->createMock(PDO::class);

        // Recipe exists
        $mfgRepo->method('getRecipeById')->willReturn([
            'id'                    => 10,
            'tenant_id'             => $this->tenantId,
            'business_id'           => $this->businessId,
            'manufactured_item_id'  => 500,
            'manufactured_item_name' => 'Finished Product',
            'yield_quantity'        => 5,
        ]);

        // Has 3 production orders
        $mfgRepo->method('countProductionOrdersForRecipe')->willReturn(3);

        // These should NOT be called
        $mfgRepo->expects($this->never())->method('deleteRecipeItems');
        $mfgRepo->expects($this->never())->method('deleteRecipe');
        $itemRepo->expects($this->never())->method('deactivate');
        $audit->expects($this->never())->method('log');

        $service = $this->buildManufacturingService($pdo, $mfgRepo, $itemService, $audit, $itemRepo);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot delete recipe: it has been used in 3 production order(s)');

        $service->deleteRecipe(10);
    }

    // ---------------------------------------------------------------
    // Test: Self-referencing component rejection
    // ---------------------------------------------------------------

    /**
     * A recipe cannot use its own manufactured item as a component.
     *
     * When updating a recipe and one of the components has the same item_id
     * as the manufactured_item_id, the operation should be rejected.
     *
     * Validates: Requirements 1.11
     */
    public function testSelfReferencingComponentRejectedOnUpdate(): void
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        $mfgRepo = $this->createMock(ManufacturingRepository::class);
        $audit = $this->createMock(AuditService::class);
        $itemService = $this->createMock(ItemService::class);
        $pdo = $this->createMock(PDO::class);

        // Existing recipe with manufactured_item_id = 500
        $mfgRepo->method('getRecipeById')->willReturn([
            'id'                    => 10,
            'tenant_id'             => $this->tenantId,
            'business_id'           => $this->businessId,
            'manufactured_item_id'  => 500,
            'manufactured_item_name' => 'Finished Product',
            'yield_quantity'        => 5,
        ]);

        // Component items exist (including the manufactured item itself)
        $itemRepo->method('getById')->willReturnCallback(
            function (int $id, int $tenantId) {
                $items = [
                    100 => ['id' => 100, 'name' => 'Component A', 'cost_price' => 10.00, 'business_id' => 1, 'tenant_id' => 1],
                    500 => ['id' => 500, 'name' => 'Finished Product', 'cost_price' => 7.00, 'business_id' => 1, 'tenant_id' => 1],
                ];
                return $items[$id] ?? null;
            }
        );

        // These should NOT be called (validation fails before any DB writes)
        $mfgRepo->expects($this->never())->method('updateRecipe');
        $mfgRepo->expects($this->never())->method('deleteRecipeItems');
        $mfgRepo->expects($this->never())->method('insertRecipeItems');

        $service = $this->buildManufacturingService($pdo, $mfgRepo, $itemService, $audit, $itemRepo);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A recipe cannot use its own manufactured item as a component');

        $service->updateRecipe(10, [
            'name'           => 'Finished Product',
            'components'     => [
                ['item_id' => 100, 'quantity' => 2],
                ['item_id' => 500, 'quantity' => 1], // Self-reference!
            ],
            'yield_quantity' => 5,
        ]);
    }

    // ---------------------------------------------------------------
    // Test: Recipe creation with self-referencing component rejected
    // ---------------------------------------------------------------

    /**
     * During recipe creation, the manufactured item doesn't exist yet,
     * so self-reference check is not applicable on create (only on update).
     * However, we verify that a component that doesn't exist is rejected.
     *
     * Validates: Requirements 1.11 (indirectly - component validation)
     */
    public function testCreateRecipeRejectsNonExistentComponent(): void
    {
        $itemRepo = $this->createMock(ItemRepository::class);
        $mfgRepo = $this->createMock(ManufacturingRepository::class);
        $audit = $this->createMock(AuditService::class);
        $itemService = $this->createMock(ItemService::class);
        $pdo = $this->createMock(PDO::class);

        // Component 100 exists, component 999 does not
        $itemRepo->method('getById')->willReturnCallback(
            function (int $id, int $tenantId) {
                if ($id === 100) {
                    return ['id' => 100, 'name' => 'Component A', 'cost_price' => 10.00, 'business_id' => 1, 'tenant_id' => 1];
                }
                return null; // Not found
            }
        );

        $service = $this->buildManufacturingService($pdo, $mfgRepo, $itemService, $audit, $itemRepo);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Component item not found or not accessible');

        $service->createRecipe([
            'name'           => 'Test Product',
            'components'     => [
                ['item_id' => 100, 'quantity' => 2],
                ['item_id' => 999, 'quantity' => 1], // Does not exist
            ],
            'yield_quantity' => 5,
        ]);
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
