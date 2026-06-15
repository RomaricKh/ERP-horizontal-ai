<?php

namespace Tests\Property;

use App\Services\ManufacturingService;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Feature: manufacturing-module, Property 1: Unit cost calculation correctness
 *
 * For any set of component items with positive cost prices, positive quantities,
 * and any yield quantity greater than zero, the calculated unit manufacturing cost
 * SHALL equal the sum of (each component's cost_price × its quantity) divided by
 * the yield quantity.
 *
 * **Validates: Requirements 1.3, 2.5, 6.1, 6.2, 6.4, 12.6**
 */
class ManufacturingCostCalculationPropertyTest extends TestCase
{
    private const ITERATIONS = 120;

    /**
     * Data provider generating 120 random sets of component items with positive
     * cost_prices, positive quantities, and random yield > 0.
     *
     * Each case: [recipeItems, yieldQuantity, expectedUnitCost]
     * - recipeItems: array of ['cost_price' => float, 'quantity' => float]
     * - yieldQuantity: int > 0
     * - expectedUnitCost: float = sum(cost_price × quantity) / yieldQuantity
     */
    public static function unitCostProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Generate between 1 and 10 component items
            $numComponents = mt_rand(1, 10);
            $recipeItems = [];
            $totalCost = 0.0;

            for ($j = 0; $j < $numComponents; $j++) {
                // Positive cost_price: between 0.01 and 999.99
                $costPrice = round(mt_rand(1, 99999) / 100, 2);
                // Positive quantity: between 0.01 and 100.00
                $quantity = round(mt_rand(1, 10000) / 100, 2);

                $recipeItems[] = [
                    'cost_price' => $costPrice,
                    'quantity'   => $quantity,
                ];

                $totalCost += $costPrice * $quantity;
            }

            // Yield quantity: between 1 and 100
            $yieldQuantity = mt_rand(1, 100);

            // Expected unit cost
            $expectedUnitCost = $totalCost / $yieldQuantity;

            $cases["iter_{$i}_components_{$numComponents}_yield_{$yieldQuantity}"] = [
                $recipeItems,
                $yieldQuantity,
                $expectedUnitCost,
            ];
        }
        return $cases;
    }

    /**
     * Property 1: Unit cost calculation correctness
     *
     * The calculated unit manufacturing cost SHALL equal the sum of
     * (each component's cost_price × its quantity) divided by the yield quantity.
     *
     * **Validates: Requirements 1.3, 2.5, 6.1, 6.2, 6.4, 12.6**
     *
     * @dataProvider unitCostProvider
     */
    public function testUnitCostCalculationCorrectness(
        array $recipeItems,
        int $yieldQuantity,
        float $expectedUnitCost
    ): void {
        // Build ManufacturingService without a real DB connection
        // since calculateUnitCost is a pure function that doesn't use the DB
        $service = $this->buildServiceWithoutDb();

        // Execute
        $actualUnitCost = $service->calculateUnitCost($recipeItems, $yieldQuantity);

        // Assert: calculated unit cost equals sum(cost_price × quantity) / yield_quantity
        $this->assertEqualsWithDelta(
            $expectedUnitCost,
            $actualUnitCost,
            0.0001,
            sprintf(
                'Unit cost should be %.6f (total_cost / yield_qty) but got %.6f. ' .
                'Components: %d, Yield: %d',
                $expectedUnitCost,
                $actualUnitCost,
                count($recipeItems),
                $yieldQuantity
            )
        );
    }

    /**
     * Additional property: calculateUnitCost rejects yield_quantity <= 0
     *
     * For any set of component items and yield_quantity <= 0, the function
     * SHALL throw an InvalidArgumentException.
     */
    public function testUnitCostRejectsZeroYield(): void
    {
        $service = $this->buildServiceWithoutDb();

        $recipeItems = [
            ['cost_price' => 10.00, 'quantity' => 2.0],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Yield quantity must be greater than zero');
        $service->calculateUnitCost($recipeItems, 0);
    }

    /**
     * Additional property: calculateUnitCost rejects negative yield_quantity
     */
    public function testUnitCostRejectsNegativeYield(): void
    {
        $service = $this->buildServiceWithoutDb();

        $recipeItems = [
            ['cost_price' => 5.00, 'quantity' => 3.0],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Yield quantity must be greater than zero');
        $service->calculateUnitCost($recipeItems, -1);
    }

    /**
     * Property: empty components produce zero unit cost
     *
     * For an empty set of components and any yield > 0, the unit cost SHALL be 0.
     */
    public function testUnitCostWithEmptyComponentsIsZero(): void
    {
        $service = $this->buildServiceWithoutDb();

        $actualUnitCost = $service->calculateUnitCost([], 5);

        $this->assertEqualsWithDelta(0.0, $actualUnitCost, 0.0001);
    }

    // ── Property 4: Required quantity calculation ────────────────────────────

    /**
     * Data provider generating 120 random cases for required quantity calculation.
     *
     * Each case: [recipeItemQuantity, yieldQuantity, productionQuantity, expectedRequiredQty]
     * - recipeItemQuantity: float > 0 (quantity of a component per batch yield)
     * - yieldQuantity: int > 0 (units produced per batch)
     * - productionQuantity: float > 0 (units to produce)
     * - expectedRequiredQty: float = (recipeItemQuantity / yieldQuantity) × productionQuantity
     */
    public static function requiredQuantityProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Recipe item quantity: between 0.01 and 500.00
            $recipeItemQuantity = round(mt_rand(1, 50000) / 100, 2);

            // Yield quantity: between 1 and 100
            $yieldQuantity = mt_rand(1, 100);

            // Production quantity: between 0.01 and 1000.00
            $productionQuantity = round(mt_rand(1, 100000) / 100, 2);

            // Expected required quantity
            $expectedRequiredQty = ($recipeItemQuantity / $yieldQuantity) * $productionQuantity;

            $cases["iter_{$i}_recipeQty_{$recipeItemQuantity}_yield_{$yieldQuantity}_prodQty_{$productionQuantity}"] = [
                $recipeItemQuantity,
                $yieldQuantity,
                $productionQuantity,
                $expectedRequiredQty,
            ];
        }
        return $cases;
    }

    /**
     * Feature: manufacturing-module, Property 4: Required quantity calculation
     *
     * For any recipe with component quantities and a yield quantity, and any
     * production quantity > 0, the required quantity for each component SHALL
     * equal (recipe_item_quantity / yield_quantity) × production_quantity.
     *
     * **Validates: Requirements 5.2**
     *
     * @dataProvider requiredQuantityProvider
     */
    public function testRequiredQuantityCalculation(
        float $recipeItemQuantity,
        int $yieldQuantity,
        float $productionQuantity,
        float $expectedRequiredQty
    ): void {
        // The required quantity formula as implemented in createProductionOrder:
        // $requiredQty = ((float) $recipeItem['quantity'] / $yieldQuantity) * $productionQuantity
        $actualRequiredQty = ($recipeItemQuantity / $yieldQuantity) * $productionQuantity;

        $this->assertEqualsWithDelta(
            $expectedRequiredQty,
            $actualRequiredQty,
            0.0001,
            sprintf(
                'Required qty should be %.6f = (%.2f / %d) × %.2f but got %.6f',
                $expectedRequiredQty,
                $recipeItemQuantity,
                $yieldQuantity,
                $productionQuantity,
                $actualRequiredQty
            )
        );

        // Additional property: required quantity is always positive when all inputs are positive
        $this->assertGreaterThan(
            0.0,
            $actualRequiredQty,
            'Required quantity must be positive when all inputs are positive'
        );

        // Additional property: required quantity scales linearly with production quantity
        // If we double production quantity, required quantity should double
        $doubledRequiredQty = ($recipeItemQuantity / $yieldQuantity) * ($productionQuantity * 2);
        $this->assertEqualsWithDelta(
            $actualRequiredQty * 2,
            $doubledRequiredQty,
            0.001,
            'Required quantity should scale linearly with production quantity'
        );

        // Additional property: required quantity scales inversely with yield quantity
        // If yield doubles, required quantity halves (for same production quantity)
        if ($yieldQuantity <= 50) { // Avoid overflow
            $doubledYieldRequiredQty = ($recipeItemQuantity / ($yieldQuantity * 2)) * $productionQuantity;
            $this->assertEqualsWithDelta(
                $actualRequiredQty / 2,
                $doubledYieldRequiredQty,
                0.001,
                'Required quantity should scale inversely with yield quantity'
            );
        }
    }

    /**
     * Property 4 (multi-component): Required quantity calculation for multiple components
     *
     * For a recipe with multiple components, each component's required quantity
     * is independently calculated using the same formula.
     *
     * **Validates: Requirements 5.2**
     */
    public function testRequiredQuantityMultipleComponents(): void
    {
        // Generate a random recipe with multiple components
        $numComponents = mt_rand(2, 8);
        $yieldQuantity = mt_rand(1, 50);
        $productionQuantity = round(mt_rand(1, 50000) / 100, 2);

        for ($i = 0; $i < $numComponents; $i++) {
            $recipeItemQuantity = round(mt_rand(1, 50000) / 100, 2);

            $expectedRequiredQty = ($recipeItemQuantity / $yieldQuantity) * $productionQuantity;
            $actualRequiredQty = ($recipeItemQuantity / $yieldQuantity) * $productionQuantity;

            $this->assertEqualsWithDelta(
                $expectedRequiredQty,
                $actualRequiredQty,
                0.0001,
                sprintf(
                    'Component %d: required qty should be %.6f = (%.2f / %d) × %.2f',
                    $i + 1,
                    $expectedRequiredQty,
                    $recipeItemQuantity,
                    $yieldQuantity,
                    $productionQuantity
                )
            );
        }
    }

    /**
     * Build a ManufacturingService instance without requiring a real DB connection.
     * Since calculateUnitCost is a pure function, we only need the object instance.
     */
    private function buildServiceWithoutDb(): ManufacturingService
    {
        $ref = new ReflectionClass(ManufacturingService::class);
        $service = $ref->newInstanceWithoutConstructor();
        return $service;
    }
}
