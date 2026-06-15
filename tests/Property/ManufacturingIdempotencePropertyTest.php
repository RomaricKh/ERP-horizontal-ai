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
 * Feature: manufacturing-module, Property 12: Double reversal prevention
 *
 * For any production order already in REVERSED status, a subsequent reversal
 * request SHALL be rejected and no stock adjustments SHALL occur.
 *
 * **Validates: Requirements 10.6**
 */
class ManufacturingIdempotencePropertyTest extends TestCase
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
     * Data provider generating 100 random production order scenarios that have
     * already been reversed (status = 'REVERSED').
     *
     * Each case: [components, yieldQuantity, productionQuantity, orderId]
     * - components: array of ['item_id' => int, 'quantity' => float, 'cost_price' => float]
     * - yieldQuantity: int > 0
     * - productionQuantity: float > 0
     * - orderId: random order ID
     */
    public static function doubleReversalProvider(): array
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

            // Random order ID
            $orderId = mt_rand(1, 10000);

            for ($j = 0; $j < $numComponents; $j++) {
                $itemId = 100 + $j;
                // Recipe quantity per batch: between 0.5 and 50.0
                $recipeQty = round(mt_rand(50, 5000) / 100, 2);
                // Cost price: between 1.00 and 500.00
                $costPrice = round(mt_rand(100, 50000) / 100, 2);

                $components[] = [
                    'item_id'    => $itemId,
                    'quantity'   => $recipeQty,
                    'cost_price' => $costPrice,
                ];
            }

            $cases["double_reversal_iter_{$i}_order_{$orderId}_components_{$numComponents}"] = [
                $components,
                $yieldQuantity,
                $productionQuantity,
                $orderId,
            ];
        }
        return $cases;
    }

    /**
     * Property 12: Double reversal prevention
     *
     * For any production order already in REVERSED status, a subsequent reversal
     * request SHALL be rejected with an InvalidArgumentException containing the
     * message "Production order has already been reversed", and no stock
     * adjustments SHALL occur.
     *
     * **Validates: Requirements 10.6**
     *
     * @dataProvider doubleReversalProvider
     */
    public function testDoubleReversalIsPrevented(
        array $components,
        int $yieldQuantity,
        float $productionQuantity,
        int $orderId
    ): void {
        $manufacturedItemId = 999;
        $recipeId = 1;

        // Track all stock adjustments made by ItemService::adjustStock
        $stockAdjustments = [];

        // Build the ManufacturingService with a production order already in REVERSED status
        $service = $this->buildServiceWithReversedOrder(
            $components,
            $yieldQuantity,
            $manufacturedItemId,
            $recipeId,
            $orderId,
            $productionQuantity,
            $stockAdjustments
        );

        // Attempt a second reversal — should throw InvalidArgumentException
        $exceptionThrown = false;
        $exceptionMessage = '';

        try {
            $service->reverseProductionOrder($orderId);
        } catch (\InvalidArgumentException $e) {
            $exceptionThrown = true;
            $exceptionMessage = $e->getMessage();
        }

        // Assert that the exception was thrown
        $this->assertTrue(
            $exceptionThrown,
            "Expected InvalidArgumentException when attempting to reverse an already-reversed production order (order #{$orderId})"
        );

        // Assert the correct error message
        $this->assertSame(
            'Production order has already been reversed',
            $exceptionMessage,
            "Exception message should indicate the order has already been reversed"
        );

        // Assert no stock adjustments were made during the second reversal attempt
        $this->assertEmpty(
            $stockAdjustments,
            sprintf(
                'No stock adjustments should occur when reversing an already-reversed order. Got %d adjustment(s).',
                count($stockAdjustments)
            )
        );
    }

    /**
     * Build a ManufacturingService with mocked dependencies where the production
     * order is already in REVERSED status.
     *
     * @param array  $components          Component definitions
     * @param int    $yieldQuantity        Recipe yield
     * @param int    $manufacturedItemId   The manufactured item ID
     * @param int    $recipeId             The recipe ID
     * @param int    $orderId              The production order ID
     * @param float  $productionQuantity   The production quantity
     * @param array  &$stockAdjustments   Reference to array that captures all adjustStock calls
     * @return ManufacturingService
     */
    private function buildServiceWithReversedOrder(
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

        // Build production order data with status REVERSED
        $productionOrderData = [
            'id'                      => $orderId,
            'tenant_id'               => $tenantId,
            'business_id'             => $businessId,
            'recipe_id'               => $recipeId,
            'manufactured_item_id'    => $manufacturedItemId,
            'production_quantity'     => $productionQuantity,
            'manufacturing_cost'      => 0.0,
            'unit_manufacturing_cost' => 0.0,
            'status'                  => 'REVERSED',
            'production_date'         => date('Y-m-d H:i:s'),
            'created_by'              => $this->userId,
        ];

        // Build production order items (these should NOT be accessed for a rejected reversal)
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
        $mfgRepo->method('getProductionOrderById')
            ->willReturnCallback(function ($id, $tid, $bid) use ($orderId, $tenantId, $businessId, $productionOrderData) {
                if ($id === $orderId && $tid === $tenantId && $bid === $businessId) {
                    return $productionOrderData;
                }
                return null;
            });
        $mfgRepo->method('getProductionOrderItems')
            ->willReturn($productionOrderItems);

        // Mock ItemRepository
        $itemRepo = $this->createMock(ItemRepository::class);

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
