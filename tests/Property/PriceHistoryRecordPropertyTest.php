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
 * Feature: po-price-update, Property 3: History record correctness
 *
 * For any PO receipt that causes at least one price change, exactly one
 * `item_prices` record SHALL be inserted per unique item_id that had a price
 * change, containing the correct item_id, business_id, currency, cost_price
 * (new price), sale_price (item's current sale_price), and valid_from (PO date).
 *
 * **Validates: Requirements 2.1, 2.2, 4.3**
 */
class PriceHistoryRecordPropertyTest extends TestCase
{
    private const ITERATIONS = 120;

    /**
     * Data provider generating 120 random PO line/item scenarios.
     *
     * Each case produces:
     * - lines: array of PO lines with item_id and unit_price_bc
     * - items: map of item_id => item data with known cost_price, sale_price, business_id, currency
     * - poDate: random PO date string
     * - expectedInserts: map of item_id => expected insert data (only items that should get a history record)
     * - expectedNoInserts: array of item_ids that should NOT get a history record
     */
    public static function historyRecordProvider(): array
    {
        $currencies = ['USD', 'EUR', 'GBP', 'AED', 'SAR', 'JPY'];
        $cases = [];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $numLines = mt_rand(1, 10);
            $lines = [];
            $items = [];

            for ($j = 0; $j < $numLines; $j++) {
                $itemId = mt_rand(1, 30);
                $scenario = mt_rand(1, 5);

                switch ($scenario) {
                    case 1:
                        // Price differs → should insert history record
                        $currentPrice = round(mt_rand(100, 99999) / 100, 2);
                        $newPrice = round($currentPrice + mt_rand(1, 5000) / 100, 2);
                        $businessId = mt_rand(1, 10);
                        $currency = $currencies[array_rand($currencies)];
                        $salePrice = round(mt_rand(100, 99999) / 100, 2);

                        $lines[] = [
                            'item_id' => $itemId,
                            'unit_price_bc' => $newPrice,
                        ];
                        $items[$itemId] = [
                            'id' => $itemId,
                            'cost_price' => $currentPrice,
                            'sale_price' => $salePrice,
                            'business_id' => $businessId,
                            'currency' => $currency,
                            'tenant_id' => 1,
                        ];
                        break;

                    case 2:
                        // Price matches → should NOT insert history record
                        $price = round(mt_rand(100, 99999) / 100, 2);
                        $businessId = mt_rand(1, 10);
                        $currency = $currencies[array_rand($currencies)];

                        $lines[] = [
                            'item_id' => $itemId,
                            'unit_price_bc' => $price,
                        ];
                        $items[$itemId] = [
                            'id' => $itemId,
                            'cost_price' => $price,
                            'sale_price' => round(mt_rand(100, 99999) / 100, 2),
                            'business_id' => $businessId,
                            'currency' => $currency,
                            'tenant_id' => 1,
                        ];
                        break;

                    case 3:
                        // Item cost_price is NULL → should insert history record
                        $newPrice = round(mt_rand(100, 99999) / 100, 2);
                        $businessId = mt_rand(1, 10);
                        $currency = $currencies[array_rand($currencies)];
                        $salePrice = round(mt_rand(100, 99999) / 100, 2);

                        $lines[] = [
                            'item_id' => $itemId,
                            'unit_price_bc' => $newPrice,
                        ];
                        $items[$itemId] = [
                            'id' => $itemId,
                            'cost_price' => null,
                            'sale_price' => $salePrice,
                            'business_id' => $businessId,
                            'currency' => $currency,
                            'tenant_id' => 1,
                        ];
                        break;

                    case 4:
                        // unit_price_bc is NULL → line skipped, no history record
                        $lines[] = [
                            'item_id' => $itemId,
                            'unit_price_bc' => null,
                        ];
                        if (!isset($items[$itemId])) {
                            $items[$itemId] = [
                                'id' => $itemId,
                                'cost_price' => round(mt_rand(100, 99999) / 100, 2),
                                'sale_price' => round(mt_rand(100, 99999) / 100, 2),
                                'business_id' => mt_rand(1, 10),
                                'currency' => $currencies[array_rand($currencies)],
                                'tenant_id' => 1,
                            ];
                        }
                        break;

                    case 5:
                        // Item not found → line skipped, no history record
                        $newPrice = round(mt_rand(100, 99999) / 100, 2);
                        $lines[] = [
                            'item_id' => $itemId,
                            'unit_price_bc' => $newPrice,
                        ];
                        // Don't add to items map → getById returns null
                        break;
                }
            }

            // Generate a random PO date
            $year = mt_rand(2020, 2025);
            $month = str_pad(mt_rand(1, 12), 2, '0', STR_PAD_LEFT);
            $day = str_pad(mt_rand(1, 28), 2, '0', STR_PAD_LEFT);
            $poDate = "{$year}-{$month}-{$day}";

            // Deduplicate lines (last line wins) to determine expected outcomes
            $deduped = [];
            foreach ($lines as $line) {
                $deduped[$line['item_id']] = $line;
            }

            $expectedInserts = [];
            $expectedNoInserts = [];

            foreach ($deduped as $itemId => $line) {
                // Skip if unit_price_bc is null
                if ($line['unit_price_bc'] === null) {
                    $expectedNoInserts[] = $itemId;
                    continue;
                }

                // Skip if item not found
                if (!isset($items[$itemId])) {
                    $expectedNoInserts[] = $itemId;
                    continue;
                }

                $item = $items[$itemId];
                $newPrice = (float) $line['unit_price_bc'];
                $oldPrice = $item['cost_price'] !== null ? (float) $item['cost_price'] : null;

                if ($oldPrice !== null && $oldPrice == $newPrice) {
                    // Price matches → no history record
                    $expectedNoInserts[] = $itemId;
                } else {
                    // Price differs or is NULL → history record expected
                    $expectedInserts[$itemId] = [
                        'item_id' => (int) $itemId,
                        'business_id' => (int) $item['business_id'],
                        'currency' => !empty($item['currency']) ? $item['currency'] : null,
                        'cost_price' => $newPrice,
                        'sale_price' => $item['sale_price'] ?? null,
                        'valid_from' => $poDate,
                    ];
                }
            }

            $cases["iter_{$i}_lines_{$numLines}"] = [
                $lines,
                $items,
                $poDate,
                $expectedInserts,
                $expectedNoInserts,
            ];
        }

        return $cases;
    }

    /**
     * Property 3: History record correctness
     *
     * For any PO receipt that causes at least one price change, exactly one
     * `item_prices` record SHALL be inserted per unique item_id that had a price
     * change, containing the correct item_id, business_id, currency, cost_price
     * (new price), sale_price (item's current sale_price), and valid_from (PO date).
     *
     * **Validates: Requirements 2.1, 2.2, 4.3**
     *
     * @dataProvider historyRecordProvider
     */
    public function testHistoryRecordCorrectness(
        array $lines,
        array $items,
        string $poDate,
        array $expectedInserts,
        array $expectedNoInserts
    ): void {
        // Set up AuthContext
        AuthContext::set((object) [
            'user_id' => 1,
            'tenant_id' => 1,
            'business_id' => 1,
        ]);

        // Track insert() calls on ItemPriceRepository
        $insertedRecords = [];

        // Stub ItemRepository
        $itemRepo = new class($items) extends ItemRepository {
            private array $items;

            public function __construct(array $items)
            {
                // Skip parent constructor
                $this->items = $items;
            }

            public function getById(int $id, int $tenantId): ?array
            {
                return $this->items[$id] ?? null;
            }

            public function updateCostPrice(int $itemId, int $tenantId, float $costPrice): void
            {
                // no-op for this test
            }
        };

        // Stub ItemPriceRepository that captures insert() calls
        $priceRepo = new class($insertedRecords) extends ItemPriceRepository {
            /** @var array */
            private array $records;

            public function __construct(array &$records)
            {
                // Skip parent constructor
                $this->records = &$records;
            }

            public function closePreviousRecord(int $itemId, int $businessId, string $newValidFrom): void
            {
                // no-op
            }

            public function insert(array $data): int
            {
                $this->records[] = $data;
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

        $updater = $this->buildPriceUpdaterWithStubs($pdo, $itemRepo, $priceRepo, $audit);

        // Execute
        $updater->updatePricesFromPO($lines, $poDate, 100, 'PO-2025-100');

        // Verify: exactly one insert per unique item_id that had a price change
        $insertsByItemId = [];
        foreach ($insertedRecords as $record) {
            $itemId = $record['item_id'];
            if (!isset($insertsByItemId[$itemId])) {
                $insertsByItemId[$itemId] = [];
            }
            $insertsByItemId[$itemId][] = $record;
        }

        // Check that each expected item got exactly one insert
        foreach ($expectedInserts as $itemId => $expectedData) {
            $this->assertArrayHasKey(
                $itemId,
                $insertsByItemId,
                sprintf('Item %d should have a history record inserted but none was found', $itemId)
            );

            $this->assertCount(
                1,
                $insertsByItemId[$itemId],
                sprintf('Item %d should have exactly 1 history record but got %d', $itemId, count($insertsByItemId[$itemId]))
            );

            $actual = $insertsByItemId[$itemId][0];

            // Verify each field
            $this->assertSame(
                $expectedData['item_id'],
                $actual['item_id'],
                sprintf('History record item_id mismatch for item %d', $itemId)
            );

            $this->assertSame(
                $expectedData['business_id'],
                $actual['business_id'],
                sprintf('History record business_id mismatch for item %d: expected %d, got %s', $itemId, $expectedData['business_id'], var_export($actual['business_id'], true))
            );

            $this->assertSame(
                $expectedData['currency'],
                $actual['currency'],
                sprintf('History record currency mismatch for item %d: expected %s, got %s', $itemId, $expectedData['currency'], $actual['currency'] ?? 'NULL')
            );

            $this->assertEqualsWithDelta(
                $expectedData['cost_price'],
                $actual['cost_price'],
                0.001,
                sprintf('History record cost_price mismatch for item %d: expected %.2f, got %.2f', $itemId, $expectedData['cost_price'], $actual['cost_price'])
            );

            $this->assertEquals(
                $expectedData['sale_price'],
                $actual['sale_price'],
                sprintf('History record sale_price mismatch for item %d: expected %s, got %s', $itemId, var_export($expectedData['sale_price'], true), var_export($actual['sale_price'], true))
            );

            $this->assertSame(
                $expectedData['valid_from'],
                $actual['valid_from'],
                sprintf('History record valid_from mismatch for item %d: expected %s, got %s', $itemId, $expectedData['valid_from'], $actual['valid_from'])
            );
        }

        // Verify: zero inserts for items where price matched or line was skipped
        foreach (array_unique($expectedNoInserts) as $itemId) {
            if (!isset($expectedInserts[$itemId])) {
                $this->assertArrayNotHasKey(
                    $itemId,
                    $insertsByItemId,
                    sprintf('Item %d should NOT have a history record but one was inserted', $itemId)
                );
            }
        }

        // Verify: no unexpected inserts occurred
        foreach ($insertsByItemId as $itemId => $records) {
            $this->assertArrayHasKey(
                $itemId,
                $expectedInserts,
                sprintf('Item %d had a history record inserted unexpectedly', $itemId)
            );
        }
    }

    /**
     * Build a PriceUpdater with mocked dependencies injected via reflection.
     */
    private function buildPriceUpdaterWithStubs(
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
