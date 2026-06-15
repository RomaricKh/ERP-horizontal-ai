<?php

namespace Tests\Unit;

use App\Repository\ItemRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ItemRepository::getAllForBusiness() with exclude types filtering.
 *
 * Uses an in-memory SQLite database to verify the actual SQL query logic
 * for type exclusion, case-insensitive matching, and graceful handling
 * of non-existent type names.
 *
 * Validates: Requirements 1.1, 1.4, 1.5, 4.1, 4.2
 */
class ItemRepositoryExcludeTypesTest extends TestCase
{
    private PDO $db;
    private ItemRepository $repo;

    private const TENANT_ID = 1;
    private const BUSINESS_ID = 100;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $this->createSchema();
        $this->seedData();

        $this->repo = new ItemRepository($this->db);
    }

    private function createSchema(): void
    {
        $this->db->exec("
            CREATE TABLE item_types (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                business_id INTEGER,
                name TEXT NOT NULL,
                created_at TEXT,
                created_by INTEGER
            )
        ");

        $this->db->exec("
            CREATE TABLE items (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                business_id INTEGER,
                item_type_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                sku TEXT,
                unit TEXT,
                cost_price REAL,
                sale_price REAL,
                notes TEXT,
                is_active INTEGER DEFAULT 1,
                has_expiry INTEGER DEFAULT 0,
                customer_id INTEGER,
                has_cost_change_flag INTEGER DEFAULT 0,
                min_stock REAL,
                markup_percent REAL,
                markup_enabled INTEGER DEFAULT 0,
                created_at TEXT,
                created_by INTEGER,
                updated_at TEXT,
                FOREIGN KEY (item_type_id) REFERENCES item_types(id)
            )
        ");

        $this->db->exec("
            CREATE TABLE stock (
                id INTEGER PRIMARY KEY,
                business_id INTEGER NOT NULL,
                item_id INTEGER NOT NULL,
                quantity REAL DEFAULT 0,
                updated_at TEXT,
                FOREIGN KEY (item_id) REFERENCES items(id)
            )
        ");

        $this->db->exec("
            CREATE TABLE people (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                tenant_id INTEGER
            )
        ");

        $this->db->exec("
            CREATE TABLE item_price_history (
                id INTEGER PRIMARY KEY,
                item_id INTEGER NOT NULL,
                tenant_id INTEGER,
                old_cost REAL,
                new_cost REAL,
                old_sale REAL,
                new_sale REAL,
                changed_at TEXT,
                changed_by INTEGER,
                FOREIGN KEY (item_id) REFERENCES items(id)
            )
        ");
    }

    private function seedData(): void
    {
        // Insert item types
        $stmt = $this->db->prepare("
            INSERT INTO item_types (id, tenant_id, business_id, name) VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([1, self::TENANT_ID, self::BUSINESS_ID, 'Raw Material']);
        $stmt->execute([2, self::TENANT_ID, self::BUSINESS_ID, 'Finished Goods']);
        $stmt->execute([3, self::TENANT_ID, self::BUSINESS_ID, 'Service']);
        $stmt->execute([4, self::TENANT_ID, self::BUSINESS_ID, 'Consumable']);

        // Insert items
        $stmt = $this->db->prepare("
            INSERT INTO items (id, tenant_id, business_id, item_type_id, name, sku, unit, cost_price, sale_price, is_active, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        // Raw Material items
        $stmt->execute([1, self::TENANT_ID, self::BUSINESS_ID, 1, 'Steel Rod', 'RM-001', 'kg', 5.00, 7.00, 1, null]);
        $stmt->execute([2, self::TENANT_ID, self::BUSINESS_ID, 1, 'Copper Wire', 'RM-002', 'meter', 3.00, 4.50, 1, null]);
        // Finished Goods items
        $stmt->execute([3, self::TENANT_ID, self::BUSINESS_ID, 2, 'Widget A', 'FG-001', 'piece', 10.00, 25.00, 1, null]);
        $stmt->execute([4, self::TENANT_ID, self::BUSINESS_ID, 2, 'Widget B', 'FG-002', 'piece', 12.00, 30.00, 1, null]);
        // Service items
        $stmt->execute([5, self::TENANT_ID, self::BUSINESS_ID, 3, 'Installation', 'SV-001', 'hour', 0.00, 50.00, 1, null]);
        // Consumable items
        $stmt->execute([6, self::TENANT_ID, self::BUSINESS_ID, 4, 'Lubricant', 'CN-001', 'liter', 8.00, 12.00, 1, null]);
        // Inactive item (should never appear)
        $stmt->execute([7, self::TENANT_ID, self::BUSINESS_ID, 2, 'Discontinued Widget', 'FG-003', 'piece', 5.00, 10.00, 0, null]);
    }

    /**
     * Helper to extract item IDs from the result set.
     */
    private function extractIds(array $items): array
    {
        $ids = array_map(fn($item) => (int) $item['id'], $items);
        sort($ids);
        return $ids;
    }

    // ─── Tests ──────────────────────────────────────────────────────────────────

    /**
     * Test that items with excluded types are not returned.
     *
     * Validates: Requirements 1.1, 4.1
     */
    public function testExcludedTypeItemsAreNotReturned(): void
    {
        $result = $this->repo->getAllForBusiness(self::TENANT_ID, self::BUSINESS_ID, ['Raw Material']);

        $ids = $this->extractIds($result);

        // Raw Material items (1, 2) should be excluded; others (3, 4, 5, 6) should remain
        $this->assertNotContains(1, $ids);
        $this->assertNotContains(2, $ids);
        $this->assertContains(3, $ids);
        $this->assertContains(4, $ids);
        $this->assertContains(5, $ids);
        $this->assertContains(6, $ids);
        $this->assertCount(4, $result);
    }

    /**
     * Test that multiple types can be excluded at once.
     *
     * Validates: Requirements 1.1
     */
    public function testMultipleTypesExcluded(): void
    {
        $result = $this->repo->getAllForBusiness(self::TENANT_ID, self::BUSINESS_ID, ['Raw Material', 'Service']);

        $ids = $this->extractIds($result);

        // Raw Material (1, 2) and Service (5) excluded; Finished Goods (3, 4) and Consumable (6) remain
        $this->assertEquals([3, 4, 6], $ids);
    }

    /**
     * Test case-insensitive matching: "raw material" excludes "Raw Material".
     *
     * Validates: Requirements 1.4, 4.2
     */
    public function testCaseInsensitiveMatchingLowercase(): void
    {
        $result = $this->repo->getAllForBusiness(self::TENANT_ID, self::BUSINESS_ID, ['raw material']);

        $ids = $this->extractIds($result);

        // Should still exclude Raw Material items despite lowercase input
        $this->assertNotContains(1, $ids);
        $this->assertNotContains(2, $ids);
        $this->assertCount(4, $result);
    }

    /**
     * Test case-insensitive matching: "RAW MATERIAL" excludes "Raw Material".
     *
     * Validates: Requirements 1.4, 4.2
     */
    public function testCaseInsensitiveMatchingUppercase(): void
    {
        $result = $this->repo->getAllForBusiness(self::TENANT_ID, self::BUSINESS_ID, ['RAW MATERIAL']);

        $ids = $this->extractIds($result);

        $this->assertNotContains(1, $ids);
        $this->assertNotContains(2, $ids);
        $this->assertCount(4, $result);
    }

    /**
     * Test case-insensitive matching: mixed case "rAw MaTeRiAl" excludes "Raw Material".
     *
     * Validates: Requirements 1.4, 4.2
     */
    public function testCaseInsensitiveMatchingMixedCase(): void
    {
        $result = $this->repo->getAllForBusiness(self::TENANT_ID, self::BUSINESS_ID, ['rAw MaTeRiAl']);

        $ids = $this->extractIds($result);

        $this->assertNotContains(1, $ids);
        $this->assertNotContains(2, $ids);
        $this->assertCount(4, $result);
    }

    /**
     * Test that non-existent type names are ignored without error.
     *
     * Validates: Requirements 1.5
     */
    public function testNonExistentTypeNameIgnoredWithoutError(): void
    {
        $result = $this->repo->getAllForBusiness(self::TENANT_ID, self::BUSINESS_ID, ['NonExistentType']);

        // All active items should be returned since the type doesn't match anything
        $ids = $this->extractIds($result);
        $this->assertEquals([1, 2, 3, 4, 5, 6], $ids);
    }

    /**
     * Test that a mix of valid and non-existent type names works correctly.
     *
     * Validates: Requirements 1.1, 1.5
     */
    public function testMixOfValidAndNonExistentTypes(): void
    {
        $result = $this->repo->getAllForBusiness(self::TENANT_ID, self::BUSINESS_ID, ['Raw Material', 'FakeType', 'BogusCategory']);

        $ids = $this->extractIds($result);

        // Only Raw Material items should be excluded; non-existent types are ignored
        $this->assertNotContains(1, $ids);
        $this->assertNotContains(2, $ids);
        $this->assertContains(3, $ids);
        $this->assertContains(4, $ids);
        $this->assertContains(5, $ids);
        $this->assertContains(6, $ids);
    }

    /**
     * Test that empty excludeTypes array returns all active items (no filtering).
     *
     * Validates: Requirements 1.5
     */
    public function testEmptyExcludeTypesReturnsAllActiveItems(): void
    {
        $result = $this->repo->getAllForBusiness(self::TENANT_ID, self::BUSINESS_ID, []);

        $ids = $this->extractIds($result);

        // All 6 active items should be returned (item 7 is inactive)
        $this->assertEquals([1, 2, 3, 4, 5, 6], $ids);
    }

    /**
     * Test that whitespace in exclude type values is trimmed before matching.
     *
     * Validates: Requirements 4.1
     */
    public function testWhitespaceInExcludeValuesIsTrimmed(): void
    {
        $result = $this->repo->getAllForBusiness(self::TENANT_ID, self::BUSINESS_ID, ['  Raw Material  ']);

        $ids = $this->extractIds($result);

        // Should still exclude Raw Material items despite whitespace padding
        $this->assertNotContains(1, $ids);
        $this->assertNotContains(2, $ids);
        $this->assertCount(4, $result);
    }

    /**
     * Test that excluding all types results in an empty result set.
     *
     * Validates: Requirements 1.1
     */
    public function testExcludingAllTypesReturnsEmptyResult(): void
    {
        $result = $this->repo->getAllForBusiness(
            self::TENANT_ID,
            self::BUSINESS_ID,
            ['Raw Material', 'Finished Goods', 'Service', 'Consumable']
        );

        $this->assertEmpty($result);
    }

    /**
     * Test that inactive items are never returned regardless of exclusion filter.
     *
     * Validates: Requirements 1.1
     */
    public function testInactiveItemsNeverReturned(): void
    {
        $result = $this->repo->getAllForBusiness(self::TENANT_ID, self::BUSINESS_ID, []);

        $ids = $this->extractIds($result);

        // Item 7 is inactive and should never appear
        $this->assertNotContains(7, $ids);
    }
}
