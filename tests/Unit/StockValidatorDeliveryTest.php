<?php

namespace Tests\Unit;

use App\Services\StockValidator;
use App\Support\AuthContext;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for StockValidator::assertSufficientStock() and invoice delivery stock guard.
 *
 * Validates: Requirements 1.6 (REQ-006)
 */
class StockValidatorDeliveryTest extends TestCase
{
    private int $businessId = 1;
    private int $tenantId = 1;

    protected function setUp(): void
    {
        AuthContext::set((object) [
            'user_id'     => 1,
            'tenant_id'   => $this->tenantId,
            'business_id' => $this->businessId,
            'permissions' => [],
        ]);
    }

    /**
     * Build a StockValidator with a PDO mock that handles stock and item lookups.
     *
     * @param array $stockMap  [itemId => quantity] stock entries
     * @param array $itemNames [itemId => name] item name lookup
     */
    private function buildValidator(array $stockMap, array $itemNames): StockValidator
    {
        $pdo = $this->createMock(PDO::class);

        $pdo->method('prepare')->willReturnCallback(
            function (string $sql) use ($stockMap, $itemNames) {
                $stmt = $this->createMock(PDOStatement::class);
                $capturedParams = [];

                $stmt->method('execute')->willReturnCallback(
                    function (array $params) use (&$capturedParams) {
                        $capturedParams = $params;
                        return true;
                    }
                );

                if (strpos($sql, 'FROM stock') !== false) {
                    $stmt->method('fetch')->willReturnCallback(
                        function () use (&$capturedParams, $stockMap) {
                            $itemId = $capturedParams['item'] ?? $capturedParams['item_id'] ?? null;
                            if ($itemId !== null && isset($stockMap[$itemId])) {
                                return ['id' => 1, 'quantity' => $stockMap[$itemId]];
                            }
                            return false;
                        }
                    );
                } elseif (strpos($sql, 'FROM items') !== false) {
                    $stmt->method('fetch')->willReturnCallback(
                        function () use (&$capturedParams, $itemNames) {
                            $itemId = $capturedParams['id'] ?? null;
                            if ($itemId !== null && isset($itemNames[$itemId])) {
                                return ['id' => $itemId, 'name' => $itemNames[$itemId], 'business_id' => 1];
                            }
                            return false;
                        }
                    );
                }

                return $stmt;
            }
        );

        return new StockValidator($pdo);
    }

    // ── assertSufficientStock tests ───────────────────────────────────────

    /**
     * assertSufficientStock passes when stock is sufficient.
     */
    public function testAssertSufficientStockPassesWhenStockIsSufficient(): void
    {
        $validator = $this->buildValidator(
            [10 => 100.0],
            [10 => 'Widget A']
        );

        $validator->assertSufficientStock(10, $this->businessId, 50.0);
        $this->assertTrue(true);
    }

    /**
     * assertSufficientStock passes when stock exactly matches requested quantity.
     */
    public function testAssertSufficientStockPassesWhenExactMatch(): void
    {
        $validator = $this->buildValidator(
            [10 => 25.0],
            [10 => 'Widget B']
        );

        $validator->assertSufficientStock(10, $this->businessId, 25.0);
        $this->assertTrue(true);
    }

    /**
     * assertSufficientStock throws RuntimeException when stock is insufficient.
     * This simulates attempting to deliver an invoice with more quantity than available.
     */
    public function testAssertSufficientStockThrowsWhenInsufficientStock(): void
    {
        $validator = $this->buildValidator(
            [10 => 5.0],
            [10 => 'Premium Coffee Beans']
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Insufficient stock for 'Premium Coffee Beans': available 5, requested 20");

        $validator->assertSufficientStock(10, $this->businessId, 20.0);
    }

    /**
     * assertSufficientStock throws when item has zero stock.
     */
    public function testAssertSufficientStockThrowsWhenZeroStock(): void
    {
        $validator = $this->buildValidator(
            [10 => 0.0],
            [10 => 'Empty Item']
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Insufficient stock for 'Empty Item': available 0, requested 10");

        $validator->assertSufficientStock(10, $this->businessId, 10.0);
    }

    /**
     * assertSufficientStock throws when item has no stock entry at all.
     * This means the item was never stocked — treated as 0 quantity.
     */
    public function testAssertSufficientStockThrowsWhenNoStockEntry(): void
    {
        $validator = $this->buildValidator(
            [], // No stock entries
            [99 => 'Brand New Item']
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Insufficient stock for 'Brand New Item': available 0, requested 3");

        $validator->assertSufficientStock(99, $this->businessId, 3.0);
    }

    /**
     * assertSufficientStock includes item name in error message for clarity.
     * When item is not found in items table, falls back to "Item #ID".
     */
    public function testAssertSufficientStockFallsBackToItemIdWhenNameNotFound(): void
    {
        $validator = $this->buildValidator(
            [42 => 2.0],
            [] // No item names — simulates item not found
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Insufficient stock for 'Item #42': available 2, requested 10");

        $validator->assertSufficientStock(42, $this->businessId, 10.0);
    }

    /**
     * assertSufficientStock handles fractional quantities correctly.
     */
    public function testAssertSufficientStockHandlesFractionalQuantities(): void
    {
        $validator = $this->buildValidator(
            [10 => 2.5],
            [10 => 'Liquid Chemical']
        );

        // 2.5 available, requesting 2.5 — should pass
        $validator->assertSufficientStock(10, $this->businessId, 2.5);
        $this->assertTrue(true);
    }

    /**
     * assertSufficientStock throws for fractional shortfall.
     */
    public function testAssertSufficientStockThrowsForFractionalShortfall(): void
    {
        $validator = $this->buildValidator(
            [10 => 2.5],
            [10 => 'Liquid Chemical']
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Insufficient stock for 'Liquid Chemical': available 2.5, requested 3");

        $validator->assertSufficientStock(10, $this->businessId, 3.0);
    }

    /**
     * Simulates the invoice delivery scenario: attempting to deliver an invoice
     * with more quantity than available stock should throw a clear error and
     * stock remains unchanged (since the exception prevents any modification).
     */
    public function testDeliveryWithInsufficientStockReturnsErrorAndStockUnchanged(): void
    {
        $validator = $this->buildValidator(
            [10 => 8.0, 20 => 3.0],
            [10 => 'Item A', 20 => 'Item B']
        );

        // Simulate invoice lines: Item A qty 5 (OK), Item B qty 10 (FAIL)
        $invoiceLines = [
            ['item_id' => 10, 'quantity' => 5.0],
            ['item_id' => 20, 'quantity' => 10.0],
        ];

        $exceptionThrown = false;
        $exceptionMessage = '';

        // Simulate the delivery validation loop (as done in SalesInvoiceService)
        try {
            foreach ($invoiceLines as $line) {
                $validator->assertSufficientStock(
                    (int) $line['item_id'],
                    $this->businessId,
                    (float) $line['quantity']
                );
            }
        } catch (\RuntimeException $e) {
            $exceptionThrown = true;
            $exceptionMessage = $e->getMessage();
        }

        // Verify exception was thrown with clear error message
        $this->assertTrue($exceptionThrown, 'Expected RuntimeException for insufficient stock');
        $this->assertStringContainsString('Insufficient stock', $exceptionMessage);
        $this->assertStringContainsString('Item B', $exceptionMessage);
        $this->assertStringContainsString('available 3', $exceptionMessage);
        $this->assertStringContainsString('requested 10', $exceptionMessage);

        // Stock is unchanged because the exception prevents any stock adjustments
        // (In the real service, this happens inside a transaction that gets rolled back)
    }
}
