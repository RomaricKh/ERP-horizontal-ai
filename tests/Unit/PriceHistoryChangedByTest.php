<?php

namespace Tests\Unit;

use App\Repository\ItemPriceHistoryRepository;
use App\Services\PriceHistoryService;
use App\Support\AuthContext;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for REQ-029 / Task 29.5: Verify that when a user changes an item price,
 * the price history modal shows the user's name in the "Changed By" column.
 *
 * Validates: Requirements REQ-023, REQ-029
 */
class PriceHistoryChangedByTest extends TestCase
{
    private array $insertedRecords = [];

    protected function setUp(): void
    {
        $this->insertedRecords = [];
    }

    protected function tearDown(): void
    {
        // Reset AuthContext
        $ref = new ReflectionClass(AuthContext::class);
        $prop = $ref->getProperty('user');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    private function setAuthContext(int $userId, int $tenantId, int $businessId): void
    {
        AuthContext::set((object) [
            'user_id'     => $userId,
            'tenant_id'   => $tenantId,
            'business_id' => $businessId,
        ]);
    }

    private function buildService(int $mockUserId = 7, string $mockUserName = 'John Doe'): PriceHistoryService
    {
        $pdo = $this->createMock(PDO::class);

        $repository = $this->createMock(ItemPriceHistoryRepository::class);
        $repository->method('insert')->willReturnCallback(function (array $data) {
            $this->insertedRecords[] = $data;
            return count($this->insertedRecords);
        });
        $repository->method('findByItem')->willReturnCallback(function () use ($mockUserId, $mockUserName) {
            // Simulate what the real repository does: JOIN users to resolve changed_by → name
            $results = [];
            foreach (array_reverse($this->insertedRecords) as $i => $record) {
                $results[] = [
                    'id'              => $i + 1,
                    'item_id'         => $record['item_id'],
                    'tenant_id'       => $record['tenant_id'],
                    'price_type'      => $record['price_type'],
                    'old_price'       => $record['old_price'],
                    'new_price'       => $record['new_price'],
                    'effective_date'  => $record['effective_date'],
                    'source_type'     => $record['source_type'],
                    'source_id'       => $record['source_id'],
                    'changed_by'      => $record['changed_by'],
                    'created_at'      => date('Y-m-d H:i:s'),
                    // This simulates the LEFT JOIN users u ON u.id = iph.changed_by
                    'changed_by_name' => ($record['changed_by'] === $mockUserId) ? $mockUserName : null,
                ];
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

    /**
     * Test: When a logged-in user changes an item's cost price, the price history
     * records their user ID and the history query resolves it to their full name.
     */
    public function testPriceChangeRecordsAuthenticatedUserAndResolvesName(): void
    {
        // Set up auth context: user ID 7, "John Doe"
        $this->setAuthContext(7, 1, 1);

        $service = $this->buildService(7, 'John Doe');

        // User changes cost price from 10.00 to 15.00
        $service->recordManualEdit(1, 1, 'COST', 10.00, 15.00);

        // Verify the insert captured the user ID
        $this->assertCount(1, $this->insertedRecords);
        $this->assertSame(7, $this->insertedRecords[0]['changed_by']);

        // Now fetch history (simulates opening the price history modal)
        $history = $service->getHistory(1, 1);

        $this->assertCount(1, $history);
        $record = $history[0];

        // Verify the "Changed By" column shows the user's name
        $this->assertSame('John Doe', $record['changed_by_name']);
        $this->assertSame(7, $record['changed_by']);
        $this->assertSame('COST', $record['price_type']);
        $this->assertSame(10.00, $record['old_price']);
        $this->assertSame(15.00, $record['new_price']);
    }

    /**
     * Test: When a user changes the sale price, the history also shows their name.
     */
    public function testSalePriceChangeShowsUserName(): void
    {
        $this->setAuthContext(7, 1, 1);

        $service = $this->buildService(7, 'Jane Smith');

        $service->recordManualEdit(2, 1, 'SALE', 50.00, 60.00);

        $history = $service->getHistory(2, 1);

        $this->assertCount(1, $history);
        // The mock resolves user 7 to "Jane Smith" for this test
        $this->assertSame('Jane Smith', $history[0]['changed_by_name']);
        $this->assertSame('SALE', $history[0]['price_type']);
    }

    /**
     * Test: When no auth context is available (e.g., system/cron), changed_by is null
     * and changed_by_name is also null.
     */
    public function testPriceChangeWithoutAuthContextShowsNullUser(): void
    {
        // Don't set AuthContext — simulates a system process
        $service = $this->buildService(7, 'John Doe');

        $service->recordManualEdit(1, 1, 'COST', 20.00, 25.00);

        // changed_by should be null since no auth context
        $this->assertNull($this->insertedRecords[0]['changed_by']);

        $history = $service->getHistory(1, 1);
        // With null changed_by, the JOIN won't match, so name is null
        $this->assertNull($history[0]['changed_by_name']);
    }

    /**
     * Test: Multiple price changes by the same user all show their name.
     */
    public function testMultiplePriceChangesByUserAllShowName(): void
    {
        $this->setAuthContext(7, 1, 1);

        $service = $this->buildService(7, 'John Doe');

        // Two consecutive price changes
        $service->recordManualEdit(1, 1, 'COST', 10.00, 12.00);
        $service->recordManualEdit(1, 1, 'COST', 12.00, 14.00);

        $history = $service->getHistory(1, 1);

        $this->assertCount(2, $history);
        // Both records should show the user's name
        $this->assertSame('John Doe', $history[0]['changed_by_name']);
        $this->assertSame('John Doe', $history[1]['changed_by_name']);
    }
}
