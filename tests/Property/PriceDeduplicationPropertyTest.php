<?php

namespace Tests\Property;

use App\Services\PriceUpdater;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Feature: po-price-update, Property 2: Deduplication — last line wins
 *
 * For any array of PO lines where multiple lines reference the same item_id,
 * the `deduplicateLines` function SHALL return a map where each item_id maps
 * to the line data of the last occurrence (by array order, which corresponds
 * to `po_details.id` ASC).
 *
 * **Validates: Requirements 4.2**
 */
class PriceDeduplicationPropertyTest extends TestCase
{
    private const ITERATIONS = 120;

    /**
     * Data provider generating 120 random PO line arrays with varying
     * numbers of duplicate item_ids.
     *
     * Each case: [lines array]
     */
    public static function deduplicationProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Generate between 2 and 20 lines
            $lineCount = mt_rand(2, 20);
            // Use a small pool of item_ids to ensure duplicates
            $itemIdPoolSize = mt_rand(1, max(1, (int) ($lineCount / 2)));
            $itemIdPool = [];
            for ($p = 0; $p < $itemIdPoolSize; $p++) {
                $itemIdPool[] = mt_rand(1, 1000);
            }

            $lines = [];
            for ($j = 0; $j < $lineCount; $j++) {
                $itemId = $itemIdPool[array_rand($itemIdPool)];
                $lines[] = [
                    'id'            => $j + 1, // po_details.id ASC
                    'item_id'       => $itemId,
                    'unit_price_bc' => round(mt_rand(1, 999999) / 100, 2),
                    'unit_price_fc' => round(mt_rand(1, 999999) / 100, 2),
                    'quantity'      => round(mt_rand(1, 10000) / 100, 2),
                ];
            }

            $cases["iter_{$i}_lines_{$lineCount}_items_{$itemIdPoolSize}"] = [$lines];
        }
        return $cases;
    }

    /**
     * Property 2: Deduplication — last line wins
     *
     * The returned map has exactly the number of unique item_ids from the input,
     * and each item_id maps to the LAST occurrence of that item_id in the input array.
     *
     * **Validates: Requirements 4.2**
     *
     * @dataProvider deduplicationProvider
     */
    public function testDeduplicateReturnsLastOccurrencePerItemId(array $lines): void
    {
        // Instantiate PriceUpdater with a mock PDO (deduplicateLines doesn't use DB)
        $pdo = $this->createMock(PDO::class);
        $updater = new PriceUpdater($pdo);

        $result = $updater->deduplicateLines($lines);

        // Compute expected unique item_ids
        $expectedItemIds = array_unique(array_column($lines, 'item_id'));

        // 1. The returned map has exactly the number of unique item_ids
        $this->assertCount(
            count($expectedItemIds),
            $result,
            sprintf(
                'Expected %d unique item_ids in result, got %d. Lines: %d',
                count($expectedItemIds),
                count($result),
                count($lines)
            )
        );

        // 2. All unique item_ids from input are present in output
        foreach ($expectedItemIds as $itemId) {
            $this->assertArrayHasKey(
                $itemId,
                $result,
                sprintf('item_id %d from input is missing in deduplicated output', $itemId)
            );
        }

        // 3. Each item_id maps to the LAST occurrence in the input array
        foreach ($expectedItemIds as $itemId) {
            // Find the last occurrence of this item_id in the input
            $lastLine = null;
            foreach ($lines as $line) {
                if ($line['item_id'] === $itemId) {
                    $lastLine = $line;
                }
            }

            $this->assertSame(
                $lastLine,
                $result[$itemId],
                sprintf(
                    'item_id %d should map to last occurrence (id=%d, price=%.2f) '
                    . 'but got (id=%d, price=%.2f)',
                    $itemId,
                    $lastLine['id'],
                    $lastLine['unit_price_bc'],
                    $result[$itemId]['id'],
                    $result[$itemId]['unit_price_bc']
                )
            );
        }

        // 4. No data corruption — output values are exact copies from input
        foreach ($result as $itemId => $lineData) {
            $this->assertSame($itemId, $lineData['item_id'],
                'Map key must match the line data item_id');
            $this->assertArrayHasKey('id', $lineData, 'Line data must preserve id field');
            $this->assertArrayHasKey('unit_price_bc', $lineData, 'Line data must preserve unit_price_bc');
            $this->assertArrayHasKey('unit_price_fc', $lineData, 'Line data must preserve unit_price_fc');
            $this->assertArrayHasKey('quantity', $lineData, 'Line data must preserve quantity');
        }
    }

    /**
     * Property 2 (edge case): Single line per item — no data loss
     *
     * When all lines have unique item_ids, the output should contain all lines
     * with no data loss.
     *
     * **Validates: Requirements 4.2**
     */
    public function testDeduplicateWithAllUniqueItemIds(): void
    {
        $pdo = $this->createMock(PDO::class);
        $updater = new PriceUpdater($pdo);

        // Generate 50 lines with all unique item_ids
        $lines = [];
        for ($i = 1; $i <= 50; $i++) {
            $lines[] = [
                'id'            => $i,
                'item_id'       => $i * 10, // guaranteed unique
                'unit_price_bc' => round(mt_rand(1, 999999) / 100, 2),
                'unit_price_fc' => round(mt_rand(1, 999999) / 100, 2),
                'quantity'      => round(mt_rand(1, 10000) / 100, 2),
            ];
        }

        $result = $updater->deduplicateLines($lines);

        // All 50 lines should be preserved
        $this->assertCount(50, $result);

        // Each line should be present exactly as-is
        foreach ($lines as $line) {
            $this->assertSame($line, $result[$line['item_id']]);
        }
    }

    /**
     * Property 2 (edge case): Empty input returns empty output
     *
     * **Validates: Requirements 4.2**
     */
    public function testDeduplicateWithEmptyArray(): void
    {
        $pdo = $this->createMock(PDO::class);
        $updater = new PriceUpdater($pdo);

        $result = $updater->deduplicateLines([]);

        $this->assertSame([], $result);
    }
}
