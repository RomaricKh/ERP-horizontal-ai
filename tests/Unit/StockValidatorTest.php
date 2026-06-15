<?php

namespace Tests\Unit;

use App\Services\StockValidator;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for StockValidator service.
 *
 * Tests assertAvailable and checkBatch methods with various stock scenarios.
 *
 * Validates: Requirements 4.1, 4.2, 4.4, 5.1, 5.2, 5.3
 */
class StockValidatorTest extends TestCase
{
    private int $businessId = 1;

    /**
     * Build a StockValidator with a PDO mock that properly handles
     * sequential prepare/execute/fetch calls.
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
                            $itemId = $capturedParams['item_id'] ?? null;
                            if ($itemId !== null && isset($stockMap[$itemId])) {
                                return ['quantity' => $stockMap[$itemId]];
                            }
                            return false;
                        }
                    );
                } elseif (strpos($sql, 'FROM items') !== false) {
                    $stmt->method('fetch')->willReturnCallback(
                        function () use (&$capturedParams, $itemNames) {
                            $itemId = $capturedParams['id'] ?? null;
                            if ($itemId !== null && isset($itemNames[$itemId])) {
                                return ['name' => $itemNames[$itemId]];
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

    // ── assertAvailable tests ─────────────────────────────────────────────

    /**
     * assertAvailable passes when stock exactly matches requested quantity.
     */
    public function testAssertAvailableWithExactStockMatch(): void
    {
        $validator = $this->buildValidator(
            [10 => 50.0],
            [10 => 'Widget']
        );

        // Should not throw
        $validator->assertAvailable(10, 50.0, $this->businessId);
        $this->assertTrue(true); // If we get here, no exception was thrown
    }

    /**
     * assertAvailable passes when stock exceeds requested quantity.
     */
    public function testAssertAvailableWithSufficientStock(): void
    {
        $validator = $this->buildValidator(
            [10 => 100.0],
            [10 => 'Widget']
        );

        $validator->assertAvailable(10, 50.0, $this->businessId);
        $this->assertTrue(true);
    }

    /**
     * assertAvailable throws RuntimeException with correct message when stock is insufficient.
     */
    public function testAssertAvailableThrowsWhenInsufficientStock(): void
    {
        $validator = $this->buildValidator(
            [10 => 30.0],
            [10 => 'Sugar']
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Insufficient stock for Sugar: available 30, requested 50');

        $validator->assertAvailable(10, 50.0, $this->businessId);
    }

    /**
     * assertAvailable throws when item has zero stock.
     */
    public function testAssertAvailableThrowsWhenZeroStock(): void
    {
        $validator = $this->buildValidator(
            [10 => 0.0],
            [10 => 'Flour']
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Insufficient stock for Flour: available 0, requested 5');

        $validator->assertAvailable(10, 5.0, $this->businessId);
    }

    /**
     * assertAvailable throws when item has no stock entry (not in stock table).
     */
    public function testAssertAvailableThrowsWhenNoStockEntry(): void
    {
        $validator = $this->buildValidator(
            [], // No stock entries
            [10 => 'New Item']
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Insufficient stock for New Item: available 0, requested 10');

        $validator->assertAvailable(10, 10.0, $this->businessId);
    }

    /**
     * assertAvailable passes when requested quantity is zero.
     */
    public function testAssertAvailablePassesWhenRequestedZero(): void
    {
        $validator = $this->buildValidator(
            [10 => 0.0],
            [10 => 'Widget']
        );

        $validator->assertAvailable(10, 0.0, $this->businessId);
        $this->assertTrue(true);
    }

    // ── checkBatch tests ──────────────────────────────────────────────────

    /**
     * checkBatch returns empty array when all items have sufficient stock.
     */
    public function testCheckBatchReturnsEmptyWhenAllSufficient(): void
    {
        $validator = $this->buildValidator(
            [10 => 100.0, 20 => 50.0, 30 => 200.0],
            [10 => 'Item A', 20 => 'Item B', 30 => 'Item C']
        );

        $requirements = [
            ['item_id' => 10, 'required' => 50.0],
            ['item_id' => 20, 'required' => 50.0],
            ['item_id' => 30, 'required' => 100.0],
        ];

        $result = $validator->checkBatch($requirements, $this->businessId);
        $this->assertEmpty($result);
    }

    /**
     * checkBatch returns shortfall messages for all deficient items.
     */
    public function testCheckBatchReturnsShortfallsForDeficientItems(): void
    {
        $validator = $this->buildValidator(
            [10 => 5.0, 20 => 2.0, 30 => 100.0],
            [10 => 'Sugar', 20 => 'Flour', 30 => 'Water']
        );

        $requirements = [
            ['item_id' => 10, 'required' => 20.0],
            ['item_id' => 20, 'required' => 10.0],
            ['item_id' => 30, 'required' => 50.0],
        ];

        $result = $validator->checkBatch($requirements, $this->businessId);

        $this->assertCount(2, $result);
        $this->assertStringContainsString('Insufficient stock for: Sugar (need 20, have 5)', $result[0]);
        $this->assertStringContainsString('Insufficient stock for: Flour (need 10, have 2)', $result[1]);
    }

    /**
     * checkBatch returns empty array when requirements array is empty.
     */
    public function testCheckBatchReturnsEmptyForEmptyRequirements(): void
    {
        $validator = $this->buildValidator([], []);

        $result = $validator->checkBatch([], $this->businessId);
        $this->assertEmpty($result);
    }

    /**
     * checkBatch handles items with no stock entry (treats as zero).
     */
    public function testCheckBatchHandlesNoStockEntry(): void
    {
        $validator = $this->buildValidator(
            [], // No stock entries at all
            [10 => 'Missing Item']
        );

        $requirements = [
            ['item_id' => 10, 'required' => 5.0],
        ];

        $result = $validator->checkBatch($requirements, $this->businessId);

        $this->assertCount(1, $result);
        $this->assertStringContainsString('Insufficient stock for: Missing Item (need 5, have 0)', $result[0]);
    }

    /**
     * checkBatch passes when required quantity is zero for all items.
     */
    public function testCheckBatchPassesWhenAllRequiredZero(): void
    {
        $validator = $this->buildValidator(
            [10 => 0.0],
            [10 => 'Widget']
        );

        $requirements = [
            ['item_id' => 10, 'required' => 0.0],
        ];

        $result = $validator->checkBatch($requirements, $this->businessId);
        $this->assertEmpty($result);
    }
}
