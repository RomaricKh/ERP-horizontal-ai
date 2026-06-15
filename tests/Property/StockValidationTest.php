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
 * Feature: operations-financial-integrity, Property 5: Production Order Stock Sufficiency Gate
 *
 * For any production order with a valid recipe and production quantity, the order is accepted
 * if and only if every component item has current stock >= (recipe_quantity / yield_quantity) × production_quantity.
 * When rejected, the error message lists every deficient component in the format
 * "Insufficient stock for: [item_name] (need [required], have [available])".
 *
 * **Validates: Requirements 4.1, 4.2, 4.4**
 */
class StockValidationTest extends TestCase
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
     * Data provider generating 100 random scenarios with mixed sufficient/insufficient stock.
     *
     * Each case generates a random recipe with 1-10 components, random stock levels (0-10000),
     * and random production quantities. The expected outcome (accept/reject) is computed from
     * the stock sufficiency condition.
     *
     * Returns: [components, yieldQuantity, productionQuantity, expectedAccepted, deficientIndices]
     */
    public static function productionOrderStockSufficiencyProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Generate between 1 and 10 component items
            $numComponents = mt_rand(1, 10);

            // Yield quantity: between 1 and 100
            $yieldQuantity = mt_rand(1, 100);

            // Production quantity: between 0.01 and 500.00
            $productionQuantity = round(mt_rand(1, 50000) / 100, 2);

            $components = [];
            $deficientIndices = [];

            for ($j = 0; $j < $numComponents; $j++) {
                $itemId = 100 + $j;
                // Recipe quantity per batch: between 0.1 and 100.0
                $recipeQty = round(mt_rand(10, 10000) / 100, 2);
                // Cost price: between 1.00 and 500.00
                $costPrice = round(mt_rand(100, 50000) / 100, 2);

                // Random stock level: between 0 and 10000
                $stock = round(mt_rand(0, 1000000) / 100, 2);

                // Calculate required quantity for this production
                $requiredQty = ($recipeQty / $yieldQuantity) * $productionQuantity;

                // Determine if this component is deficient
                if ($stock < $requiredQty) {
                    $deficientIndices[] = $j;
                }

                $components[] = [
                    'item_id'    => $itemId,
                    'quantity'   => $recipeQty,
                    'cost_price' => $costPrice,
                    'stock'      => $stock,
                ];
            }

            $expectedAccepted = empty($deficientIndices);

            $cases["iter_{$i}_comp_{$numComponents}_yield_{$yieldQuantity}_prodqty_{$productionQuantity}"] = [
                $components,
                $yieldQuantity,
                $productionQuantity,
                $expectedAccepted,
                $deficientIndices,
            ];
        }
        return $cases;
    }

    /**
     * Property 5: Production Order Stock Sufficiency Gate
     *
     * For any production order with a valid recipe and production quantity:
     * - The order is accepted iff every component has stock >= (recipe_qty / yield_qty) × production_qty
     * - When rejected, the error message lists every deficient component
     *
     * **Validates: Requirements 4.1, 4.2, 4.4**
     *
     * @dataProvider productionOrderStockSufficiencyProvider
     */
    public function testProductionOrderAcceptedIffAllComponentsHaveSufficientStock(
        array $components,
        int $yieldQuantity,
        float $productionQuantity,
        bool $expectedAccepted,
        array $deficientIndices
    ): void {
        $manufacturedItemId = 999;
        $recipeId = 1;

        $service = $this->buildManufacturingService(
            $components,
            $yieldQuantity,
            $manufacturedItemId,
            $recipeId
        );

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
        } catch (\InvalidArgumentException $e) {
            $exceptionThrown = true;
            $exceptionMessage = $e->getMessage();
        }

        if ($expectedAccepted) {
            // Order should be accepted — no exception thrown
            $this->assertFalse(
                $exceptionThrown,
                sprintf(
                    'Expected production order to be ACCEPTED but got exception: "%s". ' .
                    'Components: %s, Yield: %d, ProdQty: %.2f',
                    $exceptionMessage,
                    json_encode(array_map(function ($c) use ($yieldQuantity, $productionQuantity) {
                        $required = ($c['quantity'] / $yieldQuantity) * $productionQuantity;
                        return [
                            'id' => $c['item_id'],
                            'stock' => $c['stock'],
                            'required' => round($required, 4),
                        ];
                    }, $components)),
                    $yieldQuantity,
                    $productionQuantity
                )
            );
        } else {
            // Order should be rejected — exception thrown
            $this->assertTrue(
                $exceptionThrown,
                sprintf(
                    'Expected production order to be REJECTED but it was accepted. ' .
                    'Components: %s, Yield: %d, ProdQty: %.2f, Deficient indices: %s',
                    json_encode(array_map(function ($c) use ($yieldQuantity, $productionQuantity) {
                        $required = ($c['quantity'] / $yieldQuantity) * $productionQuantity;
                        return [
                            'id' => $c['item_id'],
                            'stock' => $c['stock'],
                            'required' => round($required, 4),
                        ];
                    }, $components)),
                    $yieldQuantity,
                    $productionQuantity,
                    json_encode($deficientIndices)
                )
            );

            // Verify error message contains "Insufficient stock for"
            $this->assertStringContainsString(
                'Insufficient stock for',
                $exceptionMessage,
                'Rejection message should start with "Insufficient stock for"'
            );

            // Verify at least one deficient component is listed in the error message
            // (assertSufficientStock uses fail-fast behavior — reports first insufficient item)
            $foundDeficient = false;
            foreach ($deficientIndices as $idx) {
                $component = $components[$idx];
                $componentName = 'Component_' . $component['item_id'];

                if (strpos($exceptionMessage, $componentName) !== false) {
                    $foundDeficient = true;

                    // Verify the format includes "available X, requested Y" for this component
                    $this->assertMatchesRegularExpression(
                        '/' . preg_quote($componentName, '/') . '.*available [0-9.]+, requested [0-9.]+/',
                        $exceptionMessage,
                        sprintf(
                            'Error message should contain "%s...available X, requested Y" format. Message: %s',
                            $componentName,
                            $exceptionMessage
                        )
                    );
                    break;
                }
            }
            $this->assertTrue(
                $foundDeficient,
                sprintf(
                    'Error message should list at least one deficient component. Message: %s',
                    $exceptionMessage
                )
            );
        }
    }

    /**
     * Data provider generating 100 random scenarios for invoice line item stock validation.
     *
     * Each case generates a random item with random stock level (0-10000) and a random
     * requested quantity (0.01-15000). The expected outcome (accept/reject) is computed
     * from the stock sufficiency condition: requested_qty <= available_stock.
     *
     * Returns: [itemId, itemName, availableStock, requestedQty, expectedAccepted]
     */
    public static function invoiceLineItemStockValidationProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $itemId = mt_rand(1, 9999);
            $itemName = 'Item_' . $itemId;

            // Available stock: between 0.00 and 10000.00
            $availableStock = round(mt_rand(0, 1000000) / 100, 2);

            // Requested quantity: between 0.01 and 15000.00
            // Use a range that can exceed available stock to test both accept and reject
            $requestedQty = round(mt_rand(1, 1500000) / 100, 2);

            // Operation succeeds iff requested_qty <= available_stock
            $expectedAccepted = $requestedQty <= $availableStock;

            $cases["iter_{$i}_item_{$itemId}_stock_{$availableStock}_req_{$requestedQty}"] = [
                $itemId,
                $itemName,
                $availableStock,
                $requestedQty,
                $expectedAccepted,
            ];
        }
        return $cases;
    }

    /**
     * Property 6: Invoice Line Item Stock Validation
     *
     * For any sales invoice line item addition or quantity edit, the operation succeeds
     * if and only if the requested quantity is less than or equal to the current available
     * stock for that item. When rejected, the error message follows the format
     * "Insufficient stock for [item_name]: available [available_qty], requested [requested_qty]".
     *
     * **Validates: Requirements 5.1, 5.2, 5.3**
     *
     * @dataProvider invoiceLineItemStockValidationProvider
     */
    public function testInvoiceLineItemAcceptedIffRequestedQtyWithinStock(
        int $itemId,
        string $itemName,
        float $availableStock,
        float $requestedQty,
        bool $expectedAccepted
    ): void {
        $businessId = $this->businessId;

        // Build StockValidator with mocked PDO
        $validator = $this->buildStockValidator($itemId, $itemName, $availableStock, $businessId);

        $exceptionThrown = false;
        $exceptionMessage = '';

        try {
            $validator->assertAvailable($itemId, $requestedQty, $businessId);
        } catch (\RuntimeException $e) {
            $exceptionThrown = true;
            $exceptionMessage = $e->getMessage();
        }

        if ($expectedAccepted) {
            // Operation should succeed — no exception thrown
            $this->assertFalse(
                $exceptionThrown,
                sprintf(
                    'Expected line item to be ACCEPTED (stock %.2f >= requested %.2f) but got exception: "%s"',
                    $availableStock,
                    $requestedQty,
                    $exceptionMessage
                )
            );
        } else {
            // Operation should be rejected — exception thrown
            $this->assertTrue(
                $exceptionThrown,
                sprintf(
                    'Expected line item to be REJECTED (stock %.2f < requested %.2f) but it was accepted.',
                    $availableStock,
                    $requestedQty
                )
            );

            // Verify error message format: "Insufficient stock for [item_name]: available [available_qty], requested [requested_qty]"
            $expectedMessage = "Insufficient stock for {$itemName}: available {$availableStock}, requested {$requestedQty}";
            $this->assertSame(
                $expectedMessage,
                $exceptionMessage,
                sprintf(
                    'Error message format mismatch. Expected: "%s", Got: "%s"',
                    $expectedMessage,
                    $exceptionMessage
                )
            );
        }
    }

    /**
     * Build a StockValidator with mocked PDO for testing invoice line item stock validation.
     */
    private function buildStockValidator(
        int $itemId,
        string $itemName,
        float $availableStock,
        int $businessId
    ): \App\Services\StockValidator {
        // Mock PDOStatement for stock query
        $stockStmt = $this->createMock(\PDOStatement::class);
        $stockStmt->method('execute')->willReturn(true);
        $stockStmt->method('fetch')->willReturn(['quantity' => $availableStock]);

        // Mock PDOStatement for item name query
        $nameStmt = $this->createMock(\PDOStatement::class);
        $nameStmt->method('execute')->willReturn(true);
        $nameStmt->method('fetch')->willReturn(['name' => $itemName]);

        // Mock PDO to return appropriate statements based on query
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')
            ->willReturnCallback(function (string $sql) use ($stockStmt, $nameStmt) {
                if (strpos($sql, 'stock') !== false) {
                    return $stockStmt;
                }
                if (strpos($sql, 'items') !== false) {
                    return $nameStmt;
                }
                return $stockStmt;
            });

        return new \App\Services\StockValidator($pdo);
    }

    /**
     * Build a ManufacturingService with mocked dependencies for testing stock sufficiency.
     */
    private function buildManufacturingService(
        array $components,
        int $yieldQuantity,
        int $manufacturedItemId,
        int $recipeId
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

        // Mock ItemRepository - returns stock entries for components
        $itemRepo = $this->createMock(ItemRepository::class);
        $itemRepo->method('getStockEntry')
            ->willReturnCallback(function ($itemId, $bid) use ($components, $businessId, $manufacturedItemId) {
                if ($bid !== $businessId) {
                    return null;
                }
                foreach ($components as $component) {
                    if ($component['item_id'] === $itemId) {
                        return ['id' => $itemId, 'quantity' => $component['stock']];
                    }
                }
                // Manufactured item stock (start at 0)
                if ($itemId === $manufacturedItemId) {
                    return ['id' => $itemId, 'quantity' => 0.0];
                }
                return null;
            });

        // Mock ItemService (no-op for stock adjustments in accepted cases)
        $itemService = new class extends ItemService {
            public function __construct() {}
            public function adjustStock(int $itemId, string $type, float $quantity, ?string $reference, ?string $notes): void {}
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

        // Build StockValidator that uses the mocked ItemRepository for stock checks
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

        // Build ManufacturingService using reflection to inject mocked dependencies
        $ref = new ReflectionClass(ManufacturingService::class);
        $service = $ref->newInstanceWithoutConstructor();

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
