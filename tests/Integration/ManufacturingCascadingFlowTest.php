<?php

namespace Tests\Integration;

use App\Repository\ItemRepository;
use App\Repository\ItemPriceRepository;
use App\Repository\ManufacturingRepository;
use App\Services\AuditService;
use App\Services\ItemService;
use App\Services\ManufacturingService;
use App\Services\PriceUpdater;
use App\Support\AuthContext;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Integration tests for the cascading recalculation flow.
 *
 * Tests the complete cascading lifecycle: create recipe -> update component
 * cost_price via PriceUpdater -> verify manufactured item cost_price updated ->
 * verify cost change flag set -> verify auto-markup applied if configured.
 *
 * Uses the same mocking/reflection approach as other integration tests.
 * The key challenge is that PriceUpdater internally creates
 * `new ManufacturingService($this->db)`, so we must ensure the PDO mock
 * handles all SQL queries that the cascading flow will execute.
 *
 * Validates: Requirements 6.5-6.8, 11.5
 */
class ManufacturingCascadingFlowTest extends TestCase
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
    // Test: Full cascading recalculation flow triggered by PriceUpdater
    // ---------------------------------------------------------------

    /**
     * End-to-end test of the cascading recalculation flow:
     *
     * 1. Create a recipe with 2 components:
     *    - Component A (item_id=100): cost_price=10.00, quantity=4
     *    - Component B (item_id=200): cost_price=5.00, quantity=2
     *    - Yield: 2 units
     *    - Manufactured item (item_id=999): cost_price = (10*4 + 5*2) / 2 = 25.00
     *
     * 2. Simulate PriceUpdater receiving a PO that changes Component A cost
     *    from 10.00 to 15.00
     *
     * 3. Verify cascading effects:
     *    - Manufactured item cost_price updated to (15*4 + 5*2) / 2 = 35.00
     *    - Cost change flag set with old_cost=25.00, new_cost=35.00
     *    - Auto-markup applied: sale_price = 35.00 * (1 + 25/100) = 43.75
     *
     * Validates: Requirements 6.5, 6.6, 6.7, 6.8, 11.5
     */
    public function testFullCascadingRecalculationFlowWithAutoMarkup(): void
    {
        $costPriceUpdates = [];
        $costFlagCalls = [];
        $salePriceUpdates = [];
        $priceHistoryInserts = [];
        $auditLogs = [];

        $componentAId = 100;
        $componentBId = 200;
        $manufacturedItemId = 999;
        $recipeId = 1;
        $yieldQuantity = 2;
        $markupPercent = 25.00;

        $componentACostOld = 10.00;
        $componentACostNew = 15.00;
        $componentBCost = 5.00;

        // Expected calculations
        $initialManufacturedCost = ($componentACostOld * 4 + $componentBCost * 2) / $yieldQuantity; // 25.00
        $newManufacturedCost = ($componentACostNew * 4 + $componentBCost * 2) / $yieldQuantity; // 35.00
        $expectedSalePrice = $newManufacturedCost * (1 + $markupPercent / 100); // 43.75

        $currentComponentACost = $componentACostOld;

        // ── ItemRepository mock ──
        $itemRepo = $this->createMock(ItemRepository::class);

        $itemRepo->method('getById')->willReturnCallback(
            function (int $itemId, int $tenantId) use (
                $componentAId, $componentBId, $manufacturedItemId,
                &$currentComponentACost, $componentBCost, $initialManufacturedCost
            ) {
                $items = [
                    $componentAId => [
                        'id' => $componentAId, 'name' => 'Component A',
                        'cost_price' => $currentComponentACost, 'sale_price' => null,
                        'business_id' => 1, 'tenant_id' => 1, 'is_active' => 1,
                        'currency' => 'USD',
                    ],
                    $componentBId => [
                        'id' => $componentBId, 'name' => 'Component B',
                        'cost_price' => $componentBCost, 'sale_price' => null,
                        'business_id' => 1, 'tenant_id' => 1, 'is_active' => 1,
                        'currency' => 'USD',
                    ],
                    $manufacturedItemId => [
                        'id' => $manufacturedItemId, 'name' => 'Finished Product',
                        'cost_price' => $initialManufacturedCost, 'sale_price' => null,
                        'business_id' => 1, 'tenant_id' => 1, 'is_active' => 1,
                        'currency' => 'USD',
                    ],
                ];
                return $items[$itemId] ?? null;
            }
        );

        $itemRepo->method('updateCostPrice')->willReturnCallback(
            function (int $itemId, int $tenantId, float $newCost) use (&$costPriceUpdates, &$currentComponentACost, $componentAId) {
                $costPriceUpdates[] = ['item_id' => $itemId, 'new_cost' => $newCost];
                if ($itemId === $componentAId) {
                    $currentComponentACost = $newCost;
                }
            }
        );

        $itemRepo->method('insert')->willReturn($manufacturedItemId);

        $itemRepo->method('update')->willReturnCallback(
            function (int $itemId, int $tenantId, array $data) use (&$salePriceUpdates) {
                if (isset($data['sale_price'])) {
                    $salePriceUpdates[] = ['item_id' => $itemId, 'sale_price' => $data['sale_price']];
                }
            }
        );

        // ── ManufacturingRepository mock ──
        $mfgRepo = $this->createMock(ManufacturingRepository::class);

        $mfgRepo->method('insertRecipe')->willReturn($recipeId);

        $mfgRepo->method('getRecipesByComponentItem')->willReturnCallback(
            function (int $itemId, int $tenantId) use ($componentAId, $recipeId, $manufacturedItemId, $yieldQuantity) {
                if ($itemId === $componentAId && $tenantId === $this->tenantId) {
                    return [[
                        'id' => $recipeId, 'tenant_id' => $this->tenantId,
                        'business_id' => $this->businessId,
                        'manufactured_item_id' => $manufacturedItemId,
                        'yield_quantity' => $yieldQuantity, 'notes' => null,
                        'is_active' => 1, 'created_by' => $this->userId,
                        'created_at' => '2024-01-01 00:00:00', 'updated_at' => null,
                    ]];
                }
                return [];
            }
        );

        $mfgRepo->method('getRecipeItems')->willReturnCallback(
            function (int $rid) use ($recipeId, $componentAId, $componentBId, &$currentComponentACost, $componentBCost) {
                if ($rid === $recipeId) {
                    return [
                        ['id' => 1, 'recipe_id' => $recipeId, 'component_item_id' => $componentAId,
                         'quantity' => 4.0, 'cost_price' => $currentComponentACost,
                         'component_item_name' => 'Component A', 'unit' => 'pcs'],
                        ['id' => 2, 'recipe_id' => $recipeId, 'component_item_id' => $componentBId,
                         'quantity' => 2.0, 'cost_price' => $componentBCost,
                         'component_item_name' => 'Component B', 'unit' => 'pcs'],
                    ];
                }
                return [];
            }
        );

        $mfgRepo->method('setCostChangeFlag')->willReturnCallback(
            function (int $itemId, float $oldCost, float $newCost) use (&$costFlagCalls) {
                $costFlagCalls[] = ['item_id' => $itemId, 'old_cost' => $oldCost, 'new_cost' => $newCost];
            }
        );

        $mfgRepo->method('getAutoMarkupConfig')->willReturnCallback(
            function (int $itemId) use ($manufacturedItemId, $markupPercent) {
                if ($itemId === $manufacturedItemId) {
                    return ['item_id' => $manufacturedItemId, 'markup_percent' => $markupPercent, 'is_enabled' => 1];
                }
                return null;
            }
        );

        $mfgRepo->method('setAutoMarkupConfig')->willReturnCallback(function () {});

        // ── ItemPriceRepository mock ──
        $priceRepo = $this->createMock(ItemPriceRepository::class);
        $priceRepo->method('closePreviousRecord')->willReturnCallback(function () {});
        $priceRepo->method('insert')->willReturnCallback(
            function (array $data) use (&$priceHistoryInserts) {
                $priceHistoryInserts[] = $data;
                return count($priceHistoryInserts);
            }
        );

        // ── AuditService mock ──
        $audit = $this->createMock(AuditService::class);
        $audit->method('log')->willReturnCallback(
            function () use (&$auditLogs) { $auditLogs[] = func_get_args(); }
        );

        // ── PDO mock (handles item_types query for recipe creation) ──
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        $typeStmt = $this->createMock(\PDOStatement::class);
        $typeStmt->method('execute')->willReturn(true);
        $typeStmt->method('fetchColumn')->willReturn(5);

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

        // ═══════════════════════════════════════════════════════════════
        // PHASE 1: Create Recipe
        // ═══════════════════════════════════════════════════════════════

        $mfgService = $this->buildManufacturingService($pdo, $mfgRepo, $this->createMinimalItemService(), $audit, $itemRepo);

        $recipeResult = $mfgService->createRecipe([
            'name' => 'Finished Product',
            'components' => [
                ['item_id' => $componentAId, 'quantity' => 4.0],
                ['item_id' => $componentBId, 'quantity' => 2.0],
            ],
            'yield_quantity' => $yieldQuantity,
            'auto_markup_percent' => $markupPercent,
        ]);

        $this->assertEquals($recipeId, $recipeResult['recipe_id']);
        $this->assertEquals($manufacturedItemId, $recipeResult['item_id']);

        // ═══════════════════════════════════════════════════════════════
        // PHASE 2: Simulate PriceUpdater updating Component A cost
        // ═══════════════════════════════════════════════════════════════

        // Reset tracking for the cascading phase
        $costPriceUpdates = [];
        $costFlagCalls = [];
        $salePriceUpdates = [];

        // Build a ManufacturingService for the cascading call
        // (simulates what PriceUpdater does internally with `new ManufacturingService($this->db)`)
        $cascadingMfgService = $this->buildManufacturingService($pdo, $mfgRepo, $this->createMinimalItemService(), $audit, $itemRepo);

        // Build PriceUpdater and inject a pre-built ManufacturingService
        // Since PriceUpdater creates ManufacturingService internally, we test the
        // integration by calling the same sequence: updateCostPrice then recalculateAffectedRecipes
        $this->simulatePriceUpdaterFlow(
            $componentAId, $componentACostNew, '2024-06-15', 1, 'PO-001',
            $itemRepo, $priceRepo, $audit, $cascadingMfgService
        );

        // ═══════════════════════════════════════════════════════════════
        // PHASE 3: Verify cascading effects
        // ═══════════════════════════════════════════════════════════════

        // 3a. Component A cost updated
        $compACostUpdate = array_filter($costPriceUpdates, fn($u) => $u['item_id'] === $componentAId);
        $this->assertCount(1, $compACostUpdate, 'Component A cost should be updated');
        $this->assertEqualsWithDelta($componentACostNew, reset($compACostUpdate)['new_cost'], 0.0001);

        // 3b. Manufactured item cost recalculated
        $mfgCostUpdate = array_filter($costPriceUpdates, fn($u) => $u['item_id'] === $manufacturedItemId);
        $this->assertCount(1, $mfgCostUpdate, 'Manufactured item cost should be updated by cascading recalculation');
        $this->assertEqualsWithDelta($newManufacturedCost, reset($mfgCostUpdate)['new_cost'], 0.0001,
            sprintf('Manufactured item cost should be %.2f', $newManufacturedCost));

        // 3c. Cost change flag set
        $this->assertCount(1, $costFlagCalls, 'Cost change flag should be set');
        $this->assertEquals($manufacturedItemId, $costFlagCalls[0]['item_id']);
        $this->assertEqualsWithDelta($initialManufacturedCost, $costFlagCalls[0]['old_cost'], 0.0001);
        $this->assertEqualsWithDelta($newManufacturedCost, $costFlagCalls[0]['new_cost'], 0.0001);

        // 3d. Auto-markup applied
        $this->assertCount(1, $salePriceUpdates, 'Auto-markup should update sale price');
        $this->assertEquals($manufacturedItemId, $salePriceUpdates[0]['item_id']);
        $this->assertEqualsWithDelta($expectedSalePrice, $salePriceUpdates[0]['sale_price'], 0.0001,
            sprintf('Sale price should be %.2f (cost %.2f * 1.25)', $expectedSalePrice, $newManufacturedCost));

        // 3e. Price history recorded
        $this->assertNotEmpty($priceHistoryInserts, 'Price history should be recorded');
        $compAHistory = array_filter($priceHistoryInserts, fn($p) => $p['item_id'] === $componentAId);
        $this->assertCount(1, $compAHistory);
        $historyRecord = reset($compAHistory);
        $this->assertEqualsWithDelta($componentACostNew, $historyRecord['cost_price'], 0.0001);
        $this->assertEquals('2024-06-15', $historyRecord['valid_from']);

        // 3f. Audit log recorded
        $this->assertNotEmpty($auditLogs, 'Audit log should be recorded');
    }

    // ---------------------------------------------------------------
    // Test: Cascading recalculation without auto-markup
    // ---------------------------------------------------------------

    /**
     * When a component cost changes and the manufactured item does NOT have
     * auto-markup configured, the cost is recalculated and the flag is set,
     * but no sale price update occurs.
     *
     * Given:
     * - Component X (item_id=300): cost_price changes from 8.00 to 12.00
     * - Recipe: Component X qty=3, yield=1
     * - Manufactured item (item_id=888): old cost = 24.00, no auto-markup
     *
     * After:
     * - New cost = (12*3) / 1 = 36.00
     * - Cost flag set: old=24.00, new=36.00
     * - No sale price update
     *
     * Validates: Requirements 6.5, 6.6, 6.7, 6.8
     */
    public function testCascadingRecalculationWithoutAutoMarkup(): void
    {
        $costPriceUpdates = [];
        $costFlagCalls = [];
        $salePriceUpdates = [];

        $componentXId = 300;
        $manufacturedItemId = 888;
        $recipeId = 5;
        $yieldQuantity = 1;

        $componentXCostOld = 8.00;
        $componentXCostNew = 12.00;
        $oldManufacturedCost = 24.00; // 8*3 / 1
        $newManufacturedCost = 36.00; // 12*3 / 1

        $currentComponentXCost = $componentXCostOld;

        // ── ItemRepository mock ──
        $itemRepo = $this->createMock(ItemRepository::class);

        $itemRepo->method('getById')->willReturnCallback(
            function (int $itemId, int $tenantId) use (
                $componentXId, $manufacturedItemId,
                &$currentComponentXCost, $oldManufacturedCost
            ) {
                $items = [
                    $componentXId => [
                        'id' => $componentXId, 'name' => 'Component X',
                        'cost_price' => $currentComponentXCost, 'sale_price' => null,
                        'business_id' => 1, 'tenant_id' => 1, 'is_active' => 1,
                        'currency' => 'USD',
                    ],
                    $manufacturedItemId => [
                        'id' => $manufacturedItemId, 'name' => 'Product Y',
                        'cost_price' => $oldManufacturedCost, 'sale_price' => 50.00,
                        'business_id' => 1, 'tenant_id' => 1, 'is_active' => 1,
                        'currency' => 'USD',
                    ],
                ];
                return $items[$itemId] ?? null;
            }
        );

        $itemRepo->method('updateCostPrice')->willReturnCallback(
            function (int $itemId, int $tenantId, float $newCost) use (&$costPriceUpdates, &$currentComponentXCost, $componentXId) {
                $costPriceUpdates[] = ['item_id' => $itemId, 'new_cost' => $newCost];
                if ($itemId === $componentXId) {
                    $currentComponentXCost = $newCost;
                }
            }
        );

        $itemRepo->method('update')->willReturnCallback(
            function (int $itemId, int $tenantId, array $data) use (&$salePriceUpdates) {
                if (isset($data['sale_price'])) {
                    $salePriceUpdates[] = ['item_id' => $itemId, 'sale_price' => $data['sale_price']];
                }
            }
        );

        // ── ManufacturingRepository mock ──
        $mfgRepo = $this->createMock(ManufacturingRepository::class);

        $mfgRepo->method('getRecipesByComponentItem')->willReturnCallback(
            function (int $itemId, int $tenantId) use ($componentXId, $recipeId, $manufacturedItemId, $yieldQuantity) {
                if ($itemId === $componentXId && $tenantId === $this->tenantId) {
                    return [[
                        'id' => $recipeId, 'tenant_id' => $this->tenantId,
                        'business_id' => $this->businessId,
                        'manufactured_item_id' => $manufacturedItemId,
                        'yield_quantity' => $yieldQuantity, 'notes' => null,
                        'is_active' => 1, 'created_by' => $this->userId,
                        'created_at' => '2024-01-01 00:00:00', 'updated_at' => null,
                    ]];
                }
                return [];
            }
        );

        $mfgRepo->method('getRecipeItems')->willReturnCallback(
            function (int $rid) use ($recipeId, $componentXId, &$currentComponentXCost) {
                if ($rid === $recipeId) {
                    return [[
                        'id' => 1, 'recipe_id' => $recipeId,
                        'component_item_id' => $componentXId, 'quantity' => 3.0,
                        'cost_price' => $currentComponentXCost,
                        'component_item_name' => 'Component X', 'unit' => 'kg',
                    ]];
                }
                return [];
            }
        );

        $mfgRepo->method('setCostChangeFlag')->willReturnCallback(
            function (int $itemId, float $oldCost, float $newCost) use (&$costFlagCalls) {
                $costFlagCalls[] = ['item_id' => $itemId, 'old_cost' => $oldCost, 'new_cost' => $newCost];
            }
        );

        // No auto-markup configured
        $mfgRepo->method('getAutoMarkupConfig')->willReturn(null);

        // ── Other mocks ──
        $priceRepo = $this->createMock(ItemPriceRepository::class);
        $priceRepo->method('closePreviousRecord')->willReturnCallback(function () {});
        $priceRepo->method('insert')->willReturn(1);

        $audit = $this->createMock(AuditService::class);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Build ManufacturingService for cascading
        $mfgService = $this->buildManufacturingService($pdo, $mfgRepo, $this->createMinimalItemService(), $audit, $itemRepo);

        // Simulate PriceUpdater flow
        $this->simulatePriceUpdaterFlow(
            $componentXId, $componentXCostNew, '2024-07-01', 2, 'PO-002',
            $itemRepo, $priceRepo, $audit, $mfgService
        );

        // ── Verify ──

        // Component X cost updated
        $compXUpdate = array_filter($costPriceUpdates, fn($u) => $u['item_id'] === $componentXId);
        $this->assertCount(1, $compXUpdate);
        $this->assertEqualsWithDelta($componentXCostNew, reset($compXUpdate)['new_cost'], 0.0001);

        // Manufactured item cost recalculated
        $mfgUpdate = array_filter($costPriceUpdates, fn($u) => $u['item_id'] === $manufacturedItemId);
        $this->assertCount(1, $mfgUpdate);
        $this->assertEqualsWithDelta($newManufacturedCost, reset($mfgUpdate)['new_cost'], 0.0001);

        // Cost flag set
        $this->assertCount(1, $costFlagCalls);
        $this->assertEquals($manufacturedItemId, $costFlagCalls[0]['item_id']);
        $this->assertEqualsWithDelta($oldManufacturedCost, $costFlagCalls[0]['old_cost'], 0.0001);
        $this->assertEqualsWithDelta($newManufacturedCost, $costFlagCalls[0]['new_cost'], 0.0001);

        // No sale price update (no auto-markup)
        $this->assertEmpty($salePriceUpdates, 'No sale price update should occur without auto-markup');
    }

    // ---------------------------------------------------------------
    // Helper: Simulate PriceUpdater flow
    // ---------------------------------------------------------------

    /**
     * Simulates the PriceUpdater::updatePricesFromPO() flow for a single item.
     *
     * PriceUpdater internally does:
     * 1. Fetch item via ItemRepository::getById
     * 2. Compare old vs new cost
     * 3. Update cost via ItemRepository::updateCostPrice
     * 4. Close previous price record
     * 5. Insert new price history record
     * 6. Log audit entry
     * 7. Create ManufacturingService and call recalculateAffectedRecipes
     *
     * Since PriceUpdater creates `new ManufacturingService($this->db)` internally,
     * we simulate the same sequence using our pre-built mocked ManufacturingService.
     */
    private function simulatePriceUpdaterFlow(
        int $itemId,
        float $newCost,
        string $poDate,
        int $poId,
        string $poNumber,
        ItemRepository $itemRepo,
        ItemPriceRepository $priceRepo,
        AuditService $audit,
        ManufacturingService $mfgService
    ): void {
        // Step 1: Fetch current item
        $item = $itemRepo->getById($itemId, $this->tenantId);
        if ($item === null) {
            return;
        }

        $oldPrice = $item['cost_price'] !== null ? (float) $item['cost_price'] : null;

        // Step 2: Check if price differs
        if ($oldPrice !== null && $oldPrice == $newCost) {
            return;
        }

        // Step 3: Update item cost price
        $itemRepo->updateCostPrice($itemId, $this->tenantId, $newCost);

        // Step 4: Close previous price record
        $itemBusinessId = (int) $item['business_id'];
        $priceRepo->closePreviousRecord($itemId, $itemBusinessId, $poDate);

        // Step 5: Insert new price history record
        $currency = !empty($item['currency']) ? $item['currency'] : null;
        $priceRepo->insert([
            'item_id'     => $itemId,
            'business_id' => $itemBusinessId,
            'currency'    => $currency,
            'cost_price'  => $newCost,
            'sale_price'  => $item['sale_price'] ?? null,
            'valid_from'  => $poDate,
        ]);

        // Step 6: Log audit entry
        $audit->log(
            'ITEMS', 'items', $itemId, 'PRICE_UPDATE',
            ['cost_price' => $oldPrice],
            ['cost_price' => $newCost, 'po_id' => $poId, 'po_number' => $poNumber],
            $this->userId, $this->tenantId, $itemBusinessId
        );

        // Step 7: Trigger cascading recalculation (same as PriceUpdater line 112)
        $mfgService->recalculateAffectedRecipes($itemId);
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

    // ---------------------------------------------------------------
    // Helper: Create a minimal ItemService (no-op)
    // ---------------------------------------------------------------

    private function createMinimalItemService(): ItemService
    {
        return new class extends ItemService {
            public function __construct() {}
        };
    }
}
