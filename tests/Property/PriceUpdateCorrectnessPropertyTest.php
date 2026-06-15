<?php

namespace Tests\Property;

use App\Repository\ItemPriceRepository;
use App\Repository\ItemRepository;
use App\Services\AuditService;
use App\Services\PriceUpdater;
use App\Support\AuthContext;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Feature: po-price-update, Property 1: Price update correctness
 *
 * For any set of PO lines and corresponding items, after processing by PriceUpdater,
 * each item's cost_price SHALL equal the PO line's unit_price_bc if it was different
 * from (or NULL compared to) the original cost_price, and SHALL remain unchanged if
 * the PO line price equaled the original cost_price.
 *
 * **Validates: Requirements 1.2, 1.3, 1.4**
 */
class PriceUpdateCorrectnessPropertyTest extends TestCase
{
    private const ITERATIONS = 120;

    /**
     * Data provider generating 120 random PO line/item scenarios.
     *
     * Each case: [lines, items, expectedUpdates]
     * - lines: array of PO lines with item_id and unit_price_bc
     * - items: map of item_id => ['cost_price' => float|null, ...]
     * - expectedUpdates: map of item_id => expected new cost_price (only items that should be updated)
     * - expectedSkips: array of item_ids that should NOT be updated
     */
    public static function priceUpdateProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $numLines = mt_rand(1, 8);
            $lines = [];
            $items = [];
            $expectedUpdates = [];
            $expectedSkips = [];

            for ($j = 0; $j < $numLines; $j++) {
                $itemId = mt_rand(1, 50);
                $scenario = mt_rand(1, 5);

                switch ($scenario) {
                    case 1:
                        // Price differs from current cost_price → should update
                        $currentPrice = round(mt_rand(100, 99999) / 100, 2);
                        // Ensure new price is different
                        $newPrice = round($currentPrice + mt_rand(1, 1000) / 100, 2);
                        $lines[] = [
                            'item_id' => $itemId,
                            'unit_price_bc' => $newPrice,
                        ];
                        $items[$itemId] = [
                            'id' => $itemId,
                            'cost_price' => $currentPrice,
                            'sale_price' => round(mt_rand(100, 99999) / 100, 2),
                            'business_id' => 1,
                            'currency' => 'USD',
                            'tenant_id' => 1,
                        ];
                        $expectedUpdates[$itemId] = $newPrice;
                        break;

                    case 2:
                        // Price equals current cost_price → should skip
                        $price = round(mt_rand(100, 99999) / 100, 2);
                        $lines[] = [
                            'item_id' => $itemId,
                            'unit_price_bc' => $price,
                        ];
                        $items[$itemId] = [
                            'id' => $itemId,
                            'cost_price' => $price,
                            'sale_price' => round(mt_rand(100, 99999) / 100, 2),
                            'business_id' => 1,
                            'currency' => 'USD',
                            'tenant_id' => 1,
                        ];
                        $expectedSkips[] = $itemId;
                        // Remove from updates if previously added by a different line
                        unset($expectedUpdates[$itemId]);
                        break;

                    case 3:
                        // Item's cost_price is NULL → should update
                        $newPrice = round(mt_rand(100, 99999) / 100, 2);
                        $lines[] = [
                            'item_id' => $itemId,
                            'unit_price_bc' => $newPrice,
                        ];
                        $items[$itemId] = [
                            'id' => $itemId,
                            'cost_price' => null,
                            'sale_price' => round(mt_rand(100, 99999) / 100, 2),
                            'business_id' => 1,
                            'currency' => 'USD',
                            'tenant_id' => 1,
                        ];
                        $expectedUpdates[$itemId] = $newPrice;
                        break;

                    case 4:
                        // unit_price_bc is NULL → should skip (line skipped entirely)
                        $lines[] = [
                            'item_id' => $itemId,
                            'unit_price_bc' => null,
                        ];
                        // Item may or may not exist, doesn't matter since line is skipped
                        if (!isset($items[$itemId])) {
                            $items[$itemId] = [
                                'id' => $itemId,
                                'cost_price' => round(mt_rand(100, 99999) / 100, 2),
                                'sale_price' => round(mt_rand(100, 99999) / 100, 2),
                                'business_id' => 1,
                                'currency' => 'USD',
                                'tenant_id' => 1,
                            ];
                        }
                        $expectedSkips[] = $itemId;
                        unset($expectedUpdates[$itemId]);
                        break;

                    case 5:
                        // Item not found → should skip
                        $newPrice = round(mt_rand(100, 99999) / 100, 2);
                        $lines[] = [
                            'item_id' => $itemId,
                            'unit_price_bc' => $newPrice,
                        ];
                        // Don't add to items map → getById returns null
                        if (!isset($items[$itemId])) {
                            $expectedSkips[] = $itemId;
                        }
                        break;
                }
            }

            // Because of deduplication (last line wins), recalculate expected outcomes
            // based on the deduplicated lines
            $deduped = [];
            foreach ($lines as $line) {
                $deduped[$line['item_id']] = $line;
            }

            $finalExpectedUpdates = [];
            $finalExpectedSkips = [];

            foreach ($deduped as $itemId => $line) {
                // Skip if unit_price_bc is null
                if ($line['unit_price_bc'] === null) {
                    $finalExpectedSkips[] = $itemId;
                    continue;
                }

                // Skip if item not found
                if (!isset($items[$itemId])) {
                    $finalExpectedSkips[] = $itemId;
                    continue;
                }

                $item = $items[$itemId];
                $newPrice = (float) $line['unit_price_bc'];
                $oldPrice = $item['cost_price'] !== null ? (float) $item['cost_price'] : null;

                if ($oldPrice !== null && $oldPrice == $newPrice) {
                    // Price matches → skip
                    $finalExpectedSkips[] = $itemId;
                } else {
                    // Price differs or is NULL → update
                    $finalExpectedUpdates[$itemId] = $newPrice;
                }
            }

            $cases["iter_{$i}_lines_{$numLines}"] = [
                $lines,
                $items,
                $finalExpectedUpdates,
                $finalExpectedSkips,
            ];
        }
        return $cases;
    }

    /**
     * Property 1: Price update correctness
     *
     * After processing by PriceUpdater, each item's cost_price SHALL equal the
     * PO line's unit_price_bc if it was different from (or NULL compared to) the
     * original cost_price, and SHALL remain unchanged if the PO line price equaled
     * the original cost_price.
     *
     * **Validates: Requirements 1.2, 1.3, 1.4**
     *
     * @dataProvider priceUpdateProvider
     */
    public function testPriceUpdateCorrectness(
        array $lines,
        array $items,
        array $expectedUpdates,
        array $expectedSkips
    ): void {
        // Set up AuthContext
        AuthContext::set((object) [
            'user_id' => 1,
            'tenant_id' => 1,
            'business_id' => 1,
        ]);

        // Track updateCostPrice calls
        $costPriceUpdates = [];

        // Stub ItemRepository
        $itemRepo = new class($items, $costPriceUpdates) extends ItemRepository {
            private array $items;
            private array $updates;

            public function __construct(array $items, array &$updates)
            {
                // Skip parent constructor
                $this->items = $items;
                $this->updates = &$updates;
            }

            public function getById(int $id, int $tenantId): ?array
            {
                return $this->items[$id] ?? null;
            }

            public function updateCostPrice(int $itemId, int $tenantId, float $costPrice): void
            {
                $this->updates[$itemId] = $costPrice;
            }
        };

        // Stub ItemPriceRepository
        $priceRepo = new class extends ItemPriceRepository {
            public function __construct()
            {
                // Skip parent constructor
            }

            public function closePreviousRecord(int $itemId, int $businessId, string $newValidFrom): void
            {
                // no-op
            }

            public function insert(array $data): int
            {
                return mt_rand(1, 10000);
            }
        };

        // Stub AuditService
        $audit = new class extends AuditService {
            public function __construct()
            {
                // Skip parent constructor
            }

            public function log(string $eventType, string $table, $recordId,
                                string $action, ?array $oldValues = null, ?array $newValues = null,
                                ?int $userId = null, ?int $tenantId = null, ?int $businessId = null, bool $force = false, bool $tenantScope = false): void
            {
                // no-op
            }
        };

        // Build PriceUpdater with injected stubs via reflection
        // The PDO mock must handle prepare() calls from ManufacturingService::recalculateAffectedRecipes
        // which is triggered by PriceUpdater when a cost_price changes.
        $stmtMock = $this->createMock(\PDOStatement::class);
        $stmtMock->method('execute')->willReturn(true);
        $stmtMock->method('fetchAll')->willReturn([]);
        $stmtMock->method('fetchColumn')->willReturn(false);
        $stmtMock->method('fetch')->willReturn(false);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmtMock);

        $updater = $this->buildPriceUpdaterWithMocks($pdo, $itemRepo, $priceRepo, $audit);

        // Execute
        $updater->updatePricesFromPO($lines, '2025-01-15', 100, 'PO-2025-100');

        // Verify: all expected updates were made with correct prices
        foreach ($expectedUpdates as $itemId => $expectedPrice) {
            $this->assertArrayHasKey(
                $itemId,
                $costPriceUpdates,
                sprintf(
                    'Item %d should have been updated (old: %s, new: %.2f)',
                    $itemId,
                    $items[$itemId]['cost_price'] ?? 'NULL',
                    $expectedPrice
                )
            );
            $this->assertEqualsWithDelta(
                $expectedPrice,
                $costPriceUpdates[$itemId],
                0.001,
                sprintf(
                    'Item %d cost_price should be %.2f but got %.2f',
                    $itemId,
                    $expectedPrice,
                    $costPriceUpdates[$itemId]
                )
            );
        }

        // Verify: skipped items were NOT updated
        foreach ($finalSkipIds = array_unique($expectedSkips) as $itemId) {
            // Only assert skip if the item was not also expected to be updated
            // (can happen if same item_id appears in both due to data generation)
            if (!isset($expectedUpdates[$itemId])) {
                $this->assertArrayNotHasKey(
                    $itemId,
                    $costPriceUpdates,
                    sprintf(
                        'Item %d should NOT have been updated but updateCostPrice was called',
                        $itemId
                    )
                );
            }
        }

        // Verify: no unexpected updates occurred
        foreach ($costPriceUpdates as $itemId => $price) {
            $this->assertArrayHasKey(
                $itemId,
                $expectedUpdates,
                sprintf(
                    'Item %d was updated unexpectedly with price %.2f',
                    $itemId,
                    $price
                )
            );
        }
    }

    /**
     * Build a PriceUpdater with mocked dependencies injected via reflection.
     */
    private function buildPriceUpdaterWithMocks(
        PDO $pdo,
        ItemRepository $itemRepo,
        ItemPriceRepository $priceRepo,
        AuditService $audit
    ): PriceUpdater {
        $ref = new ReflectionClass(PriceUpdater::class);
        $updater = $ref->newInstanceWithoutConstructor();

        $props = [
            'db' => $pdo,
            'itemRepo' => $itemRepo,
            'priceRepo' => $priceRepo,
            'audit' => $audit,
        ];

        foreach ($props as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($updater, $value);
        }

        return $updater;
    }
}
