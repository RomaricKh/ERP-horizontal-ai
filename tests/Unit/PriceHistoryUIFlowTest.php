<?php

namespace Tests\Unit;

use App\Repository\ItemPriceHistoryRepository;
use App\Services\PriceHistoryService;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for the Price History UI flow (Task 23.5).
 *
 * Verifies that when an item's cost price is changed, the price history
 * records the change with old and new values and the user's identity.
 */
class PriceHistoryUIFlowTest extends TestCase
{
    private array $insertedRecords = [];

    private function buildService(?int $mockUserId = null): PriceHistoryService
    {
        $pdo = $this->createMock(PDO::class);

        $repository = $this->createMock(ItemPriceHistoryRepository::class);
        $repository->method('insert')->willReturnCallback(function (array $data) {
            $this->insertedRecords[] = $data;
            return count($this->insertedRecords);
        });
        $repository->method('findByItem')->willReturnCallback(function () {
            // Return the inserted records in reverse order (newest first) with user name
            $results = [];
            foreach (array_reverse($this->insertedRecords) as $record) {
                $results[] = array_merge($record, [
                    'id' => count($results) + 1,
                    'created_at' => date('Y-m-d H:i:s'),
                    'changed_by_name' => $record['changed_by'] !== null ? 'Test User' : null,
                ]);
            }
            return $results;
        });

        $ref = new ReflectionClass(PriceHistoryService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $dbProp = $ref->getProperty('db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($service, $pdo);

        $repoProp = $ref->getProperty('repository');
        $repoProp->setAccessible(true);
        $repoProp->setValue($service, $repository);

        return $service;
    }

    protected function setUp(): void
    {
        $this->insertedRecords = [];
    }

    /**
     * Test: changing an item's cost price records old and new values.
     *
     * Simulates the flow: user edits item cost price from 10.00 to 15.00.
     * Verifies the history record contains both old and new values.
     */
    public function testCostPriceChangeRecordsOldAndNewValues(): void
    {
        $service = $this->buildService();

        // Simulate a manual cost price edit: old=10.00, new=15.00
        $service->recordManualEdit(1, 10, 'COST', 10.00, 15.00);

        $this->assertCount(1, $this->insertedRecords);

        $record = $this->insertedRecords[0];
        $this->assertSame(1, $record['item_id']);
        $this->assertSame(10, $record['tenant_id']);
        $this->assertSame('COST', $record['price_type']);
        $this->assertSame(10.00, $record['old_price']);
        $this->assertSame(15.00, $record['new_price']);
        $this->assertSame('MANUAL_EDIT', $record['source_type']);
        $this->assertSame(date('Y-m-d'), $record['effective_date']);
    }

    /**
     * Test: changing an item's sale price records old and new values.
     */
    public function testSalePriceChangeRecordsOldAndNewValues(): void
    {
        $service = $this->buildService();

        $service->recordManualEdit(2, 10, 'SALE', 50.00, 55.00);

        $this->assertCount(1, $this->insertedRecords);

        $record = $this->insertedRecords[0];
        $this->assertSame(2, $record['item_id']);
        $this->assertSame('SALE', $record['price_type']);
        $this->assertSame(50.00, $record['old_price']);
        $this->assertSame(55.00, $record['new_price']);
    }

    /**
     * Test: price history includes changed_by field for user tracking.
     *
     * When AuthContext is not available (unit test), changed_by should be null.
     * The repository insert call must include the changed_by key.
     */
    public function testPriceChangeIncludesChangedByField(): void
    {
        $service = $this->buildService();

        $service->recordManualEdit(1, 10, 'COST', 20.00, 25.00);

        $record = $this->insertedRecords[0];
        // changed_by key must exist in the record (even if null when no auth context)
        $this->assertArrayHasKey('changed_by', $record);
    }

    /**
     * Test: getHistory returns records with changed_by_name resolved.
     *
     * Verifies the API response shape includes the user name for display.
     */
    public function testGetHistoryReturnsChangedByName(): void
    {
        $service = $this->buildService();

        // Insert a record first
        $service->recordManualEdit(1, 10, 'COST', 10.00, 15.00);

        // Fetch history
        $history = $service->getHistory(1, 10);

        $this->assertNotEmpty($history);
        $firstRecord = $history[0];

        // Verify the record has the expected fields for UI display
        $this->assertArrayHasKey('price_type', $firstRecord);
        $this->assertArrayHasKey('old_price', $firstRecord);
        $this->assertArrayHasKey('new_price', $firstRecord);
        $this->assertArrayHasKey('effective_date', $firstRecord);
        $this->assertArrayHasKey('source_type', $firstRecord);
        $this->assertArrayHasKey('changed_by_name', $firstRecord);
    }

    /**
     * Test: PO-triggered cost change records old and new values with PO reference.
     */
    public function testPOCostChangeRecordsWithReference(): void
    {
        $service = $this->buildService();

        $service->recordFromPO(3, 10, 8.00, 9.50, 42, '2024-06-15');

        $this->assertCount(1, $this->insertedRecords);

        $record = $this->insertedRecords[0];
        $this->assertSame(3, $record['item_id']);
        $this->assertSame('COST', $record['price_type']);
        $this->assertSame(8.00, $record['old_price']);
        $this->assertSame(9.50, $record['new_price']);
        $this->assertSame('PURCHASE_ORDER', $record['source_type']);
        $this->assertSame(42, $record['source_id']);
        $this->assertSame('2024-06-15', $record['effective_date']);
        $this->assertArrayHasKey('changed_by', $record);
    }

    /**
     * Test: no record is created when PO price hasn't changed.
     */
    public function testPOSamePriceDoesNotCreateRecord(): void
    {
        $service = $this->buildService();

        $service->recordFromPO(3, 10, 10.00, 10.00, 42, '2024-06-15');

        $this->assertCount(0, $this->insertedRecords);
    }

    /**
     * Test: multiple price changes create multiple history records.
     *
     * Simulates: cost changes from 10→15, then 15→20.
     * Both changes should be recorded separately.
     */
    public function testMultiplePriceChangesCreateMultipleRecords(): void
    {
        $service = $this->buildService();

        $service->recordManualEdit(1, 10, 'COST', 10.00, 15.00);
        $service->recordManualEdit(1, 10, 'COST', 15.00, 20.00);

        $this->assertCount(2, $this->insertedRecords);

        // First change
        $this->assertSame(10.00, $this->insertedRecords[0]['old_price']);
        $this->assertSame(15.00, $this->insertedRecords[0]['new_price']);

        // Second change
        $this->assertSame(15.00, $this->insertedRecords[1]['old_price']);
        $this->assertSame(20.00, $this->insertedRecords[1]['new_price']);
    }
}
