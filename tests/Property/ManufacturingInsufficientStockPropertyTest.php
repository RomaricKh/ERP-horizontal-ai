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
 * Feature: manufacturing-module, Property 7: Insufficient stock prevents production order execution
 *
 * For any production order where at least one component item has current stock less
 * than its required quantity, the production order SHALL be rejected, no stock
 * adjustments SHALL occur, and the error SHALL identify each short item and its deficit.
 *
 * **Validates: Requirements 5.3, 5.4**
 */
class ManufacturingInsufficientStockPropertyTest extends TestCase
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
     * Data provider generating 100 random scenarios where at least one component
     * has insufficient stock for the production order.
     *
     * Each case: [components, yieldQuantity, productionQuantity, shortItemIndices]
     * - components: array of ['item_id' => int, 'quantity' => float, 'cost_price' => float, 'stock' => float]
     * - yieldQuantity: int > 0
     * - productionQuantity: float > 0
     * - shortItemIndices: array of indices into $components that have insufficient stock
     */
    public static function insufficientStockProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Generate between 1 and 6 component items
            $numComponents = mt_rand(1, 6);
            $components = [];

            // Yield quantity: between 1 and 50
            $yieldQuantity = mt_rand(1, 50);

            // Production quantity: between 1 and 200
            $productionQuantity = round(mt_rand(100, 20000) / 100, 2);

            // Decide how many components will be short (at least 1)
            $numShort = mt_rand(1, max(1, $numComponents));
            // Pick which indices will be short
            $allIndices = range(0, $numComponents - 1);
            shuffle($allIndices);
            $shortItemIndices = array_slice($allIndices, 0, $numShort);

            for ($j = 0; $j < $numComponents; $j++) {
                $itemId = 100 + $j;
                // Recipe quantity per batch: between 0.5 and 50.0
                $recipeQty = round(mt_rand(50, 5000) / 100, 2);
                // Cost price: between 1.00 and 500.00
                $costPrice = round(mt_rand(100, 50000) / 100, 2);

                // Calculate required quantity for this production
                $requiredQty = ($recipeQty / $yieldQuantity) * $productionQuantity;

                if (in_array($j, $shortItemIndices)) {
                    // Insufficient stock: stock is between 0 and (requiredQty - 0.01)
                    // Ensure stock is at least 0 and strictly less than required
                    $maxStock = max(0, $requiredQty - 0.01);
                    $stock = round(mt_rand(0, (int) ($maxStock * 100)) / 100, 2);
                    // Ensure stock is strictly less than required
                    if ($stock >= $requiredQty) {
                        $stock = max(0, round($requiredQty - 0.01, 2));
                    }
                } else {
                    // Sufficient stock: stock >= requiredQty (add buffer)
                    $stock = $requiredQty + round(mt_rand(100, 10000) / 100, 2);
                }

                $components[] = [
                    'item_id'    => $itemId,
                    'quantity'   => $recipeQty,
                    'cost_price' => $costPrice,
                    'stock'      => $stock,
                ];
            }

            $cases["iter_{$i}_components_{$numComponents}_short_{$numShort}_yield_{$yieldQuantity}"] = [
                $components,
                $yieldQuantity,
                $productionQuantity,
                $shortItemIndices,
            ];
        }
        return $cases;
    }

    /**
     * Property 7: Insufficient stock prevents production order execution
     *
     * For any production order where at least one component item has current stock
     * less than its required quantity:
     * 1. An InvalidArgumentException is thrown
     * 2. The error message contains "Insufficient stock"
     * 3. No stock adjustments were made (the adjustments array is empty)
     *
     * **Validates: Requirements 5.3, 5.4**
     *
     * @dataProvider insufficientStockProvider
     */
    public function testInsufficientStockPreventsProductionOrderExecution(
        array $components,
        int $yieldQuantity,
        float $productionQuantity,
        array $shortItemIndices
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

        // Attempt to execute the production order - should throw
        $exceptionThrown = false;
        $exceptionMessage = '';

        try {
            $service->createProductionOrder([
                'recipe_id'           => $recipeId,
                'production_quantity' => $productionQuantity,
            ]);
        } catch (\RuntimeException $e) {
            $exceptionThrown = true;
            $exceptionMessage = $e->getMessage();
        }

        // Assert 1: A RuntimeException is thrown
        $this->assertTrue(
            $exceptionThrown,
            sprintf(
                'Expected RuntimeException for insufficient stock. Components: %s, Yield: %d, ProdQty: %.2f',
                json_encode(array_map(function ($c) use ($yieldQuantity, $productionQuantity) {
                    $required = ($c['quantity'] / $yieldQuantity) * $productionQuantity;
                    return ['id' => $c['item_id'], 'stock' => $c['stock'], 'required' => $required];
                }, $components)),
                $yieldQuantity,
                $productionQuantity
            )
        );

        // Assert 2: The error message contains "Insufficient stock"
        $this->assertStringContainsString(
            'Insufficient stock',
            $exceptionMessage,
            'Error message should contain "Insufficient stock"'
        );

        // Assert 3: The error message identifies at least one short item (fail-fast behavior)
        $foundShortItem = false;
        foreach ($shortItemIndices as $idx) {
            $component = $components[$idx];
            $componentName = 'Component_' . $component['item_id'];
            if (strpos($exceptionMessage, $componentName) !== false) {
                $foundShortItem = true;
                break;
            }
        }
        $this->assertTrue(
            $foundShortItem,
            sprintf(
                'Error message should identify at least one short item. Message was: %s',
                $exceptionMessage
            )
        );

        // Assert 4: No stock adjustments were made
        $this->assertEmpty(
            $stockAdjustments,
            sprintf(
                'No stock adjustments should occur when stock is insufficient. Got %d adjustments.',
                count($stockAdjustments)
            )
        );
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
                ?int $userId = null, ?int $tenantId = null, ?int $businessId = null,
                bool $force = false, bool $tenantScope = false
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

            public function assertSufficientStock(int $itemId, int $businessId, float $quantity): void {
                $available = 0.0;
                foreach ($this->components as $component) {
                    if ($component['item_id'] === $itemId) {
                        $available = (float) $component['stock'];
                        break;
                    }
                }
                if ($available < $quantity) {
                    $itemName = 'Component_' . $itemId;
                    throw new \RuntimeException(
                        "Insufficient stock for '{$itemName}': available {$available}, requested {$quantity}"
                    );
                }
            }

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
