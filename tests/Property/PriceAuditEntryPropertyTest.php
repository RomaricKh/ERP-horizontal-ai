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
 * Feature: po-price-update, Property 5: Audit entry correctness
 *
 * For any PO receipt, an audit entry with action "PRICE_UPDATE" SHALL be created
 * for each item whose cost_price changed (containing old price, new price, po_id,
 * and po_number), and zero audit entries SHALL be created when no prices change.
 *
 * **Validates: Requirements 5.1, 5.2, 5.3**
 */
class PriceAuditEntryPropertyTest extends TestCase
{
    private const ITERATIONS = 120;

    /**
     * Data provider generating 120 random PO line/item scenarios.
     *
     * Each case produces:
     * - lines: array of PO lines with item_id and unit_price_bc
     * - items: map of item_id => item data with known cost_price
     * - poId: random PO ID
     * - poNumber: random PO number string
     * - expectedAuditEntries: array of expected audit log calls (one per changed item)
     */
    public static function auditEntryProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $numLines = mt_rand(1, 10);
            $lines = [];
            $items = [];
            $poId = mt_rand(1, 9999);
            $poNumber = 'PO-' . mt_rand(2020, 2025) . '-' . mt_rand(100, 9999);
            $userId = mt_rand(1, 100);
            $tenantId = mt_rand(1, 50);
            $businessId = mt_rand(1, 30);

            for ($j = 0; $j < $numLines; $j++) {
                $itemId = mt_rand(1, 20);
                $scenario = mt_rand(1, 5);

                switch ($scenario) {
                    case 1:
                        // Price differs → should generate audit entry
                        $currentPrice = round(mt_rand(100, 99999) / 100, 2);
                        $newPrice = round($currentPrice + mt_rand(1, 1000) / 100, 2);
                        $lines[] = [
                            'item_id' => $itemId,
                            'unit_price_bc' => $newPrice,
                        ];
                        $items[$itemId] = [
                            'id' => $itemId,
                            'cost_price' => $currentPrice,
                            'sale_price' => round(mt_rand(100, 99999) / 100, 2),
                            'business_id' => $businessId,
                            'currency' => 'USD',
                            'tenant_id' => $tenantId,
                        ];
                        break;

                    case 2:
                        // Price equals current → no audit entry
                        $price = round(mt_rand(100, 99999) / 100, 2);
                        $lines[] = [
                            'item_id' => $itemId,
                            'unit_price_bc' => $price,
                        ];
                        $items[$itemId] = [
                            'id' => $itemId,
                            'cost_price' => $price,
                            'sale_price' => round(mt_rand(100, 99999) / 100, 2),
                            'business_id' => $businessId,
                            'currency' => 'USD',
                            'tenant_id' => $tenantId,
                        ];
                        break;

                    case 3:
                        // Item cost_price is NULL → should generate audit entry
                        $newPrice = round(mt_rand(100, 99999) / 100, 2);
                        $lines[] = [
                            'item_id' => $itemId,
                            'unit_price_bc' => $newPrice,
                        ];
                        $items[$itemId] = [
                            'id' => $itemId,
                            'cost_price' => null,
                            'sale_price' => round(mt_rand(100, 99999) / 100, 2),
                            'business_id' => $businessId,
                            'currency' => 'USD',
                            'tenant_id' => $tenantId,
                        ];
                        break;

                    case 4:
                        // unit_price_bc is NULL → skip, no audit entry
                        $lines[] = [
                            'item_id' => $itemId,
                            'unit_price_bc' => null,
                        ];
                        if (!isset($items[$itemId])) {
                            $items[$itemId] = [
                                'id' => $itemId,
                                'cost_price' => round(mt_rand(100, 99999) / 100, 2),
                                'sale_price' => round(mt_rand(100, 99999) / 100, 2),
                                'business_id' => $businessId,
                                'currency' => 'USD',
                                'tenant_id' => $tenantId,
                            ];
                        }
                        break;

                    case 5:
                        // Item not found → skip, no audit entry
                        $lines[] = [
                            'item_id' => $itemId,
                            'unit_price_bc' => round(mt_rand(100, 99999) / 100, 2),
                        ];
                        // Don't add to items map
                        break;
                }
            }

            // Deduplicate (last line wins) and compute expected audit entries
            $deduped = [];
            foreach ($lines as $line) {
                $deduped[$line['item_id']] = $line;
            }

            $expectedAuditEntries = [];
            foreach ($deduped as $itemId => $line) {
                if ($line['unit_price_bc'] === null) {
                    continue;
                }
                if (!isset($items[$itemId])) {
                    continue;
                }

                $item = $items[$itemId];
                $newPrice = (float) $line['unit_price_bc'];
                $oldPrice = $item['cost_price'] !== null ? (float) $item['cost_price'] : null;

                if ($oldPrice !== null && $oldPrice == $newPrice) {
                    continue;
                }

                $expectedAuditEntries[] = [
                    'eventType' => 'ITEMS',
                    'table' => 'items',
                    'recordId' => $itemId,
                    'action' => 'PRICE_UPDATE',
                    'oldValues' => ['cost_price' => $oldPrice],
                    'newValues' => ['cost_price' => $newPrice, 'po_id' => $poId, 'po_number' => $poNumber],
                    'userId' => $userId,
                    'tenantId' => $tenantId,
                    'businessId' => (int) $item['business_id'],
                ];
            }

            $cases["iter_{$i}_lines_{$numLines}"] = [
                $lines,
                $items,
                $poId,
                $poNumber,
                $userId,
                $tenantId,
                $businessId,
                $expectedAuditEntries,
            ];
        }
        return $cases;
    }

    /**
     * Property 5: Audit entry correctness
     *
     * For any PO receipt, an audit entry with action "PRICE_UPDATE" SHALL be created
     * for each item whose cost_price changed (containing old price, new price, po_id,
     * and po_number), and zero audit entries SHALL be created when no prices change.
     *
     * **Validates: Requirements 5.1, 5.2, 5.3**
     *
     * @dataProvider auditEntryProvider
     */
    public function testAuditEntryCorrectness(
        array $lines,
        array $items,
        int $poId,
        string $poNumber,
        int $userId,
        int $tenantId,
        int $businessId,
        array $expectedAuditEntries
    ): void {
        // Set up AuthContext
        AuthContext::set((object) [
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'business_id' => $businessId,
        ]);

        // Track audit log calls
        $auditCalls = [];

        // Stub ItemRepository
        $itemRepo = new class($items) extends ItemRepository {
            private array $items;

            public function __construct(array $items)
            {
                $this->items = $items;
            }

            public function getById(int $id, int $tenantId): ?array
            {
                return $this->items[$id] ?? null;
            }

            public function updateCostPrice(int $itemId, int $tenantId, float $costPrice): void
            {
                // no-op
            }
        };

        // Stub ItemPriceRepository
        $priceRepo = new class extends ItemPriceRepository {
            public function __construct()
            {
            }

            public function closePreviousRecord(int $itemId, int $businessId, string $newValidFrom): void
            {
            }

            public function insert(array $data): int
            {
                return mt_rand(1, 10000);
            }
        };

        // Stub AuditService that records all log() calls
        $audit = new class($auditCalls) extends AuditService {
            /** @var array */
            private array $calls;

            public function __construct(array &$calls)
            {
                $this->calls = &$calls;
            }

            public function log(string $eventType, string $table, $recordId,
                                string $action, ?array $oldValues = null, ?array $newValues = null,
                                ?int $userId = null, ?int $tenantId = null, ?int $businessId = null, bool $force = false, bool $tenantScope = false): void
            {
                $this->calls[] = [
                    'eventType' => $eventType,
                    'table' => $table,
                    'recordId' => $recordId,
                    'action' => $action,
                    'oldValues' => $oldValues,
                    'newValues' => $newValues,
                    'userId' => $userId,
                    'tenantId' => $tenantId,
                    'businessId' => $businessId,
                ];
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
        $updater->updatePricesFromPO($lines, '2025-01-15', $poId, $poNumber);

        // Verify: correct number of audit entries
        $this->assertCount(
            count($expectedAuditEntries),
            $auditCalls,
            sprintf(
                'Expected %d audit entries but got %d',
                count($expectedAuditEntries),
                count($auditCalls)
            )
        );

        // Verify: zero audit entries when no prices change
        if (count($expectedAuditEntries) === 0) {
            $this->assertEmpty($auditCalls, 'No audit entries should be created when no prices change');
            return;
        }

        // Verify each audit entry has correct content
        // Build a lookup by recordId for easier matching
        $auditByRecordId = [];
        foreach ($auditCalls as $call) {
            $auditByRecordId[$call['recordId']] = $call;
        }

        foreach ($expectedAuditEntries as $expected) {
            $itemId = $expected['recordId'];

            $this->assertArrayHasKey(
                $itemId,
                $auditByRecordId,
                sprintf('Expected audit entry for item %d but none was created', $itemId)
            );

            $actual = $auditByRecordId[$itemId];

            // Verify eventType
            $this->assertSame(
                'ITEMS',
                $actual['eventType'],
                sprintf('Audit entry for item %d should have eventType "ITEMS"', $itemId)
            );

            // Verify table
            $this->assertSame(
                'items',
                $actual['table'],
                sprintf('Audit entry for item %d should have table "items"', $itemId)
            );

            // Verify action
            $this->assertSame(
                'PRICE_UPDATE',
                $actual['action'],
                sprintf('Audit entry for item %d should have action "PRICE_UPDATE"', $itemId)
            );

            // Verify oldValues contains old cost_price
            $this->assertSame(
                $expected['oldValues'],
                $actual['oldValues'],
                sprintf(
                    'Audit entry for item %d oldValues mismatch: expected %s, got %s',
                    $itemId,
                    json_encode($expected['oldValues']),
                    json_encode($actual['oldValues'])
                )
            );

            // Verify newValues contains new cost_price, po_id, and po_number
            $this->assertSame(
                $expected['newValues'],
                $actual['newValues'],
                sprintf(
                    'Audit entry for item %d newValues mismatch: expected %s, got %s',
                    $itemId,
                    json_encode($expected['newValues']),
                    json_encode($actual['newValues'])
                )
            );

            // Verify userId
            $this->assertSame(
                $expected['userId'],
                $actual['userId'],
                sprintf('Audit entry for item %d should have userId %d', $itemId, $expected['userId'])
            );

            // Verify tenantId
            $this->assertSame(
                $expected['tenantId'],
                $actual['tenantId'],
                sprintf('Audit entry for item %d should have tenantId %d', $itemId, $expected['tenantId'])
            );

            // Verify businessId
            $this->assertSame(
                $expected['businessId'],
                $actual['businessId'],
                sprintf('Audit entry for item %d should have businessId %d', $itemId, $expected['businessId'])
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
