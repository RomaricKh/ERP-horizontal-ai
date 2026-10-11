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
 * Feature: manufacturing-module, Property 5: Production order stock conservation
 *
 * For any successfully executed production order, the net stock change for each
 * component item SHALL be a decrease of exactly its required quantity, and the
 * net stock change for the manufactured item SHALL be an increase of exactly
 * the production quantity.
 *
 * **Validates: Requirements 5.5, 5.6**
 */
class ManufacturingStockConservationPropertyTest extends TestCase
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
     * Data provider generating 100 random production order scenarios.
     *
     * Each case: [components, yieldQuantity, productionQuantity]
     * - components: array of ['item_id' => int, 'quantity' => float, 'cost_price' => float, 'stock' => float]
     * - yieldQuantity: int > 0
     * - productionQuantity: float > 0
     * - All components have sufficient stock for the production order
     */
    public static function stockConservationProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Generate between 1 and 6 component items
            $numComponents = mt_rand(1, 6);
            $components = [];

            // Yield quantity: between 1 and 50
            $yieldQuantity = mt_rand(1, 50);

            // Production quantity: between 1 and 200
            $productionQuantity = round(mt_rand(1, 20000) / 100, 2);

            for ($j = 0; $j < $numComponents; $j++) {
                $itemId = 100 + $j; // Component item IDs: 100, 101, 102, ...
                // Recipe quantity per batch: between 0.5 and 50.0
                $recipeQty = round(mt_rand(50, 5000) / 100, 2);
                // Cost price: between 1.00 and 500.00
                $costPrice = round(mt_rand(100, 50000) / 100, 2);

                // Calculate required quantity for this production
                $requiredQty = ($recipeQty / $yieldQuantity) * $productionQuantity;

                // Ensure sufficient stock (add a random buffer of 1-100 units)
                $stock = $requiredQty + round(mt_rand(100, 10000) / 100, 2);

                $components[] = [
                    'item_id'    => $itemId,
                    'quantity'   => $recipeQty,
                    'cost_price' => $costPrice,
                    'stock'      => $stock,
                ];
            }

            $cases["iter_{$i}_components_{$numComponents}_yield_{$yieldQuantity}_prodQty_{$productionQuantity}"] = [
                $components,
                $yieldQuantity,
                $productionQuantity,
            ];
        }
        return $cases;
    }

    /**
     * Property 5: Production order stock conservation
     *
     * For any successfully executed production order:
     * - Each component's stock decreases by exactly (recipe_qty / yield_qty) × production_qty
     * - The manufactured item's stock increases by exactly production_qty
     *
     * **Validates: Requirements 5.5, 5.6**
     *
     * @dataProvider stockConservationProvider
     */
    public function testProductionOrderStockConservation(
        array $components,
        int $yieldQuantity,
        float $productionQuantity
    ): void {
        $manufacturedItemId = 999;
        $recipeId = 1;

        // Track all stock adjustments made by ItemService::adjustStock
        $stockAdjustments = [];

        // Build the ManufacturingService with mocked dependencies
        $service = $this->buildServiceWithTracking(
            $components,
            $yieldQuantity,
            $manufacturedItemId,
            $recipeId,
            $stockAdjustments
        );

        // Execute the production order
        $orderId = $service->createProductionOrder([
            'recipe_id'           => $recipeId,
            'production_quantity' => $productionQuantity,
        ]);

        // Verify stock conservation for each component
        foreach ($components as $component) {
            $itemId = $component['item_id'];
            $expectedDeduction = ($component['quantity'] / $yieldQuantity) * $productionQuantity;

            // Find the OUT adjustment for this component
            $outAdjustments = array_filter($stockAdjustments, function ($adj) use ($itemId) {
                return $adj['item_id'] === $itemId && $adj['type'] === 'OUT';
            });

            $this->assertCount(
                1,
                $outAdjustments,
                "Expected exactly one OUT adjustment for component item {$itemId}"
            );

            $adjustment = reset($outAdjustments);
            $this->assertEqualsWithDelta(
                $expectedDeduction,
                $adjustment['quantity'],
                0.0001,
                sprintf(
                    'Component %d: stock decrease should be %.6f = (%.2f / %d) × %.2f, got %.6f',
                    $itemId,
                    $expectedDeduction,
                    $component['quantity'],
                    $yieldQuantity,
                    $productionQuantity,
                    $adjustment['quantity']
                )
            );
        }

        // Verify manufactured item stock increase
        $inAdjustments = array_filter($stockAdjustments, function ($adj) use ($manufacturedItemId) {
            return $adj['item_id'] === $manufacturedItemId && $adj['type'] === 'IN';
        });

        $this->assertCount(
            1,
            $inAdjustments,
            "Expected exactly one IN adjustment for manufactured item {$manufacturedItemId}"
        );

        $mfgAdjustment = reset($inAdjustments);
        $this->assertEqualsWithDelta(
            $productionQuantity,
            $mfgAdjustment['quantity'],
            0.0001,
            sprintf(
                'Manufactured item stock increase should be %.6f (production_qty), got %.6f',
                $productionQuantity,
                $mfgAdjustment['quantity']
            )
        );

        // Verify total number of adjustments = components + 1 (manufactured item)
        $this->assertCount(
            count($components) + 1,
            $stockAdjustments,
            'Total stock adjustments should equal number of components + 1 (manufactured item)'
        );
    }

    /**
     * Data provider generating 100 random production order scenarios for reversal testing.
     *
     * Each case: [components, yieldQuantity, productionQuantity]
     * - components: array of ['item_id' => int, 'quantity' => float, 'cost_price' => float, 'stock' => float]
     * - yieldQuantity: int > 0
     * - productionQuantity: float > 0
     * - All components have sufficient stock for the production order
     */
    public static function reversalAsInverseProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $numComponents = mt_rand(1, 6);
            $components = [];

            $yieldQuantity = mt_rand(1, 50);
            $productionQuantity = round(mt_rand(1, 20000) / 100, 2);

            for ($j = 0; $j < $numComponents; $j++) {
                $itemId = 100 + $j;
                $recipeQty = round(mt_rand(50, 5000) / 100, 2);
                $costPrice = round(mt_rand(100, 50000) / 100, 2);

                $requiredQty = ($recipeQty / $yieldQuantity) * $productionQuantity;
                $stock = $requiredQty + round(mt_rand(100, 10000) / 100, 2);

                $components[] = [
                    'item_id'    => $itemId,
                    'quantity'   => $recipeQty,
                    'cost_price' => $costPrice,
                    'stock'      => $stock,
                ];
            }

            $cases["reversal_iter_{$i}_components_{$numComponents}_yield_{$yieldQuantity}_prodQty_{$productionQuantity}"] = [
                $components,
                $yieldQuantity,
                $productionQuantity,
            ];
        }
        return $cases;
    }

    /**
     * Property 6: Production order reversal is the inverse of execution
     *
     * For any production order that is executed and then reversed, the net stock
     * change for every involved item (components and manufactured item) SHALL be
     * zero — i.e., stock levels return to their pre-execution state.
     *
     * **Validates: Requirements 10.1, 10.2, 10.5**
     *
     * @dataProvider reversalAsInverseProvider
     */
    public function testProductionOrderReversalIsInverseOfExecution(
        array $components,
        int $yieldQuantity,
        float $productionQuantity
    ): void {
        $manufacturedItemId = 999;
        $recipeId = 1;
        $orderId = 1;

        // Track all stock adjustments made by ItemService::adjustStock
        $stockAdjustments = [];

        // Build the ManufacturingService with mocked dependencies for execution + reversal
        $service = $this->buildServiceWithReversalTracking(
            $components,
            $yieldQuantity,
            $manufacturedItemId,
            $recipeId,
            $orderId,
            $productionQuantity,
            $stockAdjustments
        );

        // Step 1: Execute the production order
        $service->createProductionOrder([
            'recipe_id'           => $recipeId,
            'production_quantity' => $productionQuantity,
        ]);

        // Step 2: Reverse the production order
        $service->reverseProductionOrder($orderId);

        // Step 3: Assert net stock change for every item is zero
        // Group adjustments by item_id and compute net change
        $netChanges = [];
        foreach ($stockAdjustments as $adj) {
            $itemId = $adj['item_id'];
            if (!isset($netChanges[$itemId])) {
                $netChanges[$itemId] = 0.0;
            }
            if ($adj['type'] === 'IN') {
                $netChanges[$itemId] += $adj['quantity'];
            } else { // OUT
                $netChanges[$itemId] -= $adj['quantity'];
            }
        }

        // Verify net change is zero for each component
        foreach ($components as $component) {
            $itemId = $component['item_id'];
            $this->assertArrayHasKey(
                $itemId,
                $netChanges,
                "Expected stock adjustments for component item {$itemId}"
            );
            $this->assertEqualsWithDelta(
                0.0,
                $netChanges[$itemId],
                0.0001,
                sprintf(
                    'Component %d: net stock change should be 0 after execute+reverse, got %.6f',
                    $itemId,
                    $netChanges[$itemId]
                )
            );
        }

        // Verify net change is zero for manufactured item
        $this->assertArrayHasKey(
            $manufacturedItemId,
            $netChanges,
            "Expected stock adjustments for manufactured item {$manufacturedItemId}"
        );
        $this->assertEqualsWithDelta(
            0.0,
            $netChanges[$manufacturedItemId],
            0.0001,
            sprintf(
                'Manufactured item %d: net stock change should be 0 after execute+reverse, got %.6f',
                $manufacturedItemId,
                $netChanges[$manufacturedItemId]
            )
        );
    }

    /**
     * Build a ManufacturingService with mocked dependencies that supports both
     * execution and reversal, tracking all stock adjustments.
     *
     * @param array  $components          Component definitions
     * @param int    $yieldQuantity        Recipe yield
     * @param int    $manufacturedItemId   The manufactured item ID
     * @param int    $recipeId             The recipe ID
     * @param int    $orderId              The production order ID to use
     * @param float  $productionQuantity   The production quantity
     * @param array  &$stockAdjustments   Reference to array that captures all adjustStock calls
     * @return ManufacturingService
     */
    private function buildServiceWithReversalTracking(
        array $components,
        int $yieldQuantity,
        int $manufacturedItemId,
        int $recipeId,
        int $orderId,
        float $productionQuantity,
        array &$stockAdjustments
    ): ManufacturingService {
        $tenantId = $this->tenantId;
        $businessId = $this->businessId;

        // Build recipe items as returned by ManufacturingRepository::getRecipeItems
        $recipeItems = [];
        foreach ($components as $component) {
            $recipeItems[] = [
                'id'                  => $component['item_id'],
                'recipe_id'           => $recipeId,
                'component_item_id'   => $component['item_id'],
                'quantity'            => $component['quantity'],
                'component_item_name' => 'Component_' . $component['item_id'],
                'unit'                => 'pcs',
                'cost_price'          => $component['cost_price'],
            ];
        }

        // Build recipe data
        $recipeData = [
            'id'                     => $recipeId,
            'tenant_id'              => $tenantId,
            'business_id'            => $businessId,
            'manufactured_item_id'   => $manufacturedItemId,
            'yield_quantity'         => $yieldQuantity,
            'notes'                  => null,
            'is_active'              => 1,
            'created_by'             => $this->userId,
            'manufactured_item_name' => 'Manufactured_Item',
            'unit'                   => 'kg',
        ];

        // Build production order data (returned by getProductionOrderById after execution)
        $productionOrderData = [
            'id'                    => $orderId,
            'tenant_id'             => $tenantId,
            'business_id'           => $businessId,
            'recipe_id'             => $recipeId,
            'manufactured_item_id'  => $manufacturedItemId,
            'production_quantity'   => $productionQuantity,
            'status'                => 'COMPLETED',
            'production_date'       => date('Y-m-d H:i:s'),
            'created_by'            => $this->userId,
        ];

        // Build production order items (returned by getProductionOrderItems after execution)
        $productionOrderItems = [];
        foreach ($components as $component) {
            $requiredQty = ((float) $component['quantity'] / $yieldQuantity) * $productionQuantity;
            $productionOrderItems[] = [
                'id'                  => $component['item_id'],
                'production_order_id' => $orderId,
                'component_item_id'   => $component['item_id'],
                'quantity_consumed'   => $requiredQty,
                'unit_cost'           => $component['cost_price'],
                'component_item_name' => 'Component_' . $component['item_id'],
                'unit'                => 'pcs',
            ];
        }

        // Mock ManufacturingRepository
        $mfgRepo = $this->createMock(ManufacturingRepository::class);
        $mfgRepo->method('getRecipeById')
            ->willReturnCallback(function ($id, $tid, $bid) use ($recipeId, $tenantId, $businessId, $recipeData) {
                if ($id === $recipeId && $tid === $tenantId && $bid === $businessId) {
                    return $recipeData;
                }
                return null;
            });
        $mfgRepo->method('getRecipeItems')
            ->willReturn($recipeItems);
        $mfgRepo->method('insertProductionOrder')
            ->willReturn($orderId);
        $mfgRepo->method('getProductionOrderById')
            ->willReturnCallback(function ($id, $tid, $bid) use ($orderId, $tenantId, $businessId, $productionOrderData) {
                if ($id === $orderId && $tid === $tenantId && $bid === $businessId) {
                    return $productionOrderData;
                }
                return null;
            });
        $mfgRepo->method('getProductionOrderItems')
            ->willReturn($productionOrderItems);

        // Mock ItemRepository - returns stock entries for components
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->method('getStockEntry')
            ->willReturnCallback(function ($itemId, $bid) use ($components, $businessId) {
                if ($bid !== $businessId) {
                    return null;
                }
                foreach ($components as $component) {
                    if ($component['item_id'] === $itemId) {
                        return ['id' => $itemId, 'quantity' => $component['stock']];
                    }
                }
                // Manufactured item stock (start at 0)
                return ['id' => $itemId, 'quantity' => 0.0];
            });

        // Mock ItemService that tracks adjustStock calls
        $itemService = new class($stockAdjustments) extends ItemService {
            private array $adjustments;

            public function __construct(array &$adjustments) {
                $this->adjustments = &$adjustments;
            }

            public function adjustStock(int $itemId, string $type, float $quantity, ?string $reference, ?string $notes): void {
                $this->adjustments[] = [
                    'item_id'   => $itemId,
                    'type'      => $type,
                    'quantity'  => $quantity,
                    'reference' => $reference,
                ];
            }
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

        // Mock PDO for transaction management
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Build ManufacturingService using reflection to inject mocked dependencies
        $ref = new ReflectionClass(ManufacturingService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $stockValidator = new class($components, $businessId) extends \App\Services\StockValidator {
            private array $components;
            private int $businessId;

            public function __construct(array $components, int $businessId) {
                $this->components = $components;
                $this->businessId = $businessId;
            }

            public function assertSufficientStock(int $itemId, int $businessId, float $quantity): void {}

            public function assertAvailable(int $itemId, float $requestedQty, int $businessId): void {}

            public function checkBatch(array $requirements, int $businessId): array {
                $shortfalls = [];
                foreach ($requirements as $req) {
                    $itemId = (int) $req['item_id'];
                    $needed = (float) $req['required'];
                    $available = 0.0;
                    foreach ($this->components as $component) {
                        if ($component['item_id'] === $itemId) {
                            $available = (float) $component['stock'];
                            break;
                        }
                    }
                    if ($available < $needed) {
                        $itemName = 'Component_' . $itemId;
                        $shortfalls[] = "Insufficient stock for: {$itemName} (need {$needed}, have {$available})";
                    }
                }
                return $shortfalls;
            }
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
     * Build a ManufacturingService with mocked dependencies that tracks stock adjustments.
     *
     * @param array  $components         Component definitions
     * @param int    $yieldQuantity       Recipe yield
     * @param int    $manufacturedItemId  The manufactured item ID
     * @param int    $recipeId            The recipe ID
     * @param array  &$stockAdjustments  Reference to array that captures all adjustStock calls
     * @return ManufacturingService
     */
    private function buildServiceWithTracking(
        array $components,
        int $yieldQuantity,
        int $manufacturedItemId,
        int $recipeId,
        array &$stockAdjustments
    ): ManufacturingService {
        $tenantId = $this->tenantId;
        $businessId = $this->businessId;

        // Build recipe items as returned by ManufacturingRepository::getRecipeItems
        $recipeItems = [];
        foreach ($components as $component) {
            $recipeItems[] = [
                'id'                  => $component['item_id'],
                'recipe_id'           => $recipeId,
                'component_item_id'   => $component['item_id'],
                'quantity'            => $component['quantity'],
                'component_item_name' => 'Component_' . $component['item_id'],
                'unit'                => 'pcs',
                'cost_price'          => $component['cost_price'],
            ];
        }

        // Build recipe data as returned by ManufacturingRepository::getRecipeById
        $recipeData = [
            'id'                     => $recipeId,
            'tenant_id'              => $tenantId,
            'business_id'            => $businessId,
            'manufactured_item_id'   => $manufacturedItemId,
            'yield_quantity'         => $yieldQuantity,
            'notes'                  => null,
            'is_active'              => 1,
            'created_by'             => $this->userId,
            'manufactured_item_name' => 'Manufactured_Item',
            'unit'                   => 'kg',
        ];

        // Mock ManufacturingRepository
        $mfgRepo = $this->createMock(ManufacturingRepository::class);
        $mfgRepo->method('getRecipeById')
            ->willReturnCallback(function ($id, $tid, $bid) use ($recipeId, $tenantId, $businessId, $recipeData) {
                if ($id === $recipeId && $tid === $tenantId && $bid === $businessId) {
                    return $recipeData;
                }
                return null;
            });
        $mfgRepo->method('getRecipeItems')
            ->willReturn($recipeItems);
        $mfgRepo->method('insertProductionOrder')
            ->willReturn(1);
        // insertProductionOrderItems is void - no willReturn needed

        // Mock ItemRepository - returns stock entries for components
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->method('getStockEntry')
            ->willReturnCallback(function ($itemId, $bid) use ($components, $businessId) {
                if ($bid !== $businessId) {
                    return null;
                }
                foreach ($components as $component) {
                    if ($component['item_id'] === $itemId) {
                        return ['id' => $itemId, 'quantity' => $component['stock']];
                    }
                }
                // Manufactured item stock (start at 0)
                return ['id' => $itemId, 'quantity' => 0.0];
            });

        // Mock ItemService that tracks adjustStock calls
        $itemService = new class($stockAdjustments) extends ItemService {
            private array $adjustments;

            public function __construct(array &$adjustments) {
                // Skip parent constructor (no DB needed)
                $this->adjustments = &$adjustments;
            }

            public function adjustStock(int $itemId, string $type, float $quantity, ?string $reference, ?string $notes): void {
                $this->adjustments[] = [
                    'item_id'   => $itemId,
                    'type'      => $type,
                    'quantity'  => $quantity,
                    'reference' => $reference,
                ];
            }
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

        // Mock PDO for transaction management
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Build ManufacturingService using reflection to inject mocked dependencies
        $ref = new ReflectionClass(ManufacturingService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $stockValidator = new class($components, $businessId) extends \App\Services\StockValidator {
            private array $components;
            private int $businessId;

            public function __construct(array $components, int $businessId) {
                $this->components = $components;
                $this->businessId = $businessId;
            }

            public function assertSufficientStock(int $itemId, int $businessId, float $quantity): void {}

            public function assertAvailable(int $itemId, float $requestedQty, int $businessId): void {}

            public function checkBatch(array $requirements, int $businessId): array {
                $shortfalls = [];
                foreach ($requirements as $req) {
                    $itemId = (int) $req['item_id'];
                    $needed = (float) $req['required'];
                    $available = 0.0;
                    foreach ($this->components as $component) {
                        if ($component['item_id'] === $itemId) {
                            $available = (float) $component['stock'];
                            break;
                        }
                    }
                    if ($available < $needed) {
                        $itemName = 'Component_' . $itemId;
                        $shortfalls[] = "Insufficient stock for: {$itemName} (need {$needed}, have {$available})";
                    }
                }
                return $shortfalls;
            }
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
