<?php

namespace Tests\Unit;

use App\Repository\ManufacturingRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests verifying that item markup and cost-change flag behavior
 * works correctly after merging item_auto_markup and item_cost_flags
 * into the items table directly.
 *
 * Validates: REQ-028
 */
class ItemMarkupMergeTest extends TestCase
{
    private PDO $db;
    private ManufacturingRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $this->createSchema();
        $this->seedData();

        $this->repo = new ManufacturingRepository($this->db);
    }

    private function createSchema(): void
    {
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
                updated_at TEXT
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

        $this->db->exec("
            CREATE TABLE recipes (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                business_id INTEGER NOT NULL,
                manufactured_item_id INTEGER NOT NULL,
                yield_quantity REAL DEFAULT 1,
                notes TEXT,
                is_active INTEGER DEFAULT 1,
                created_by INTEGER,
                created_at TEXT,
                updated_at TEXT,
                FOREIGN KEY (manufactured_item_id) REFERENCES items(id)
            )
        ");
    }

    private function seedData(): void
    {
        // Item with markup enabled
        $this->db->exec("
            INSERT INTO items (id, tenant_id, business_id, item_type_id, name, sku, unit, cost_price, sale_price, is_active, has_cost_change_flag, markup_percent, markup_enabled)
            VALUES (1, 1, 100, 2, 'Widget A', 'FG-001', 'piece', 20.00, 30.00, 1, 0, 25.00, 1)
        ");

        // Item without markup
        $this->db->exec("
            INSERT INTO items (id, tenant_id, business_id, item_type_id, name, sku, unit, cost_price, sale_price, is_active, has_cost_change_flag, markup_percent, markup_enabled)
            VALUES (2, 1, 100, 2, 'Widget B', 'FG-002', 'piece', 15.00, 22.50, 1, 0, NULL, 0)
        ");

        // Item with cost change flag set
        $this->db->exec("
            INSERT INTO items (id, tenant_id, business_id, item_type_id, name, sku, unit, cost_price, sale_price, is_active, has_cost_change_flag, markup_percent, markup_enabled)
            VALUES (3, 1, 100, 2, 'Widget C', 'FG-003', 'piece', 30.00, 45.00, 1, 1, 50.00, 1)
        ");

        // Price history for item 3 (cost change flag is set)
        $this->db->exec("
            INSERT INTO item_price_history (id, item_id, tenant_id, old_cost, new_cost, changed_at, changed_by)
            VALUES (1, 3, 1, 25.00, 30.00, '2025-01-15 10:00:00', 1)
        ");

        // Recipe for item 1
        $this->db->exec("
            INSERT INTO recipes (id, tenant_id, business_id, manufactured_item_id, yield_quantity, is_active)
            VALUES (1, 1, 100, 1, 2, 1)
        ");
    }

    // ─── Auto-Markup Tests ──────────────────────────────────────────────────────

    /**
     * Test: getAutoMarkupConfig returns markup data for items with markup_enabled = 1.
     */
    public function testGetAutoMarkupConfigReturnsDataForEnabledItem(): void
    {
        $config = $this->repo->getAutoMarkupConfig(1);

        $this->assertNotNull($config);
        $this->assertEquals(25.00, (float) $config['markup_percent']);
        $this->assertEquals(1, (int) $config['is_enabled']);
    }

    /**
     * Test: getAutoMarkupConfig returns null for items with markup_enabled = 0.
     */
    public function testGetAutoMarkupConfigReturnsNullForDisabledItem(): void
    {
        $config = $this->repo->getAutoMarkupConfig(2);

        $this->assertNull($config);
    }

    /**
     * Test: setAutoMarkupConfig updates the items table directly.
     */
    public function testSetAutoMarkupConfigUpdatesItemsTable(): void
    {
        // Item 2 has no markup — enable it
        $this->repo->setAutoMarkupConfig(2, 30.00);

        // Verify the items table was updated
        $stmt = $this->db->prepare("SELECT markup_percent, markup_enabled FROM items WHERE id = 2");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals(30.00, (float) $row['markup_percent']);
        $this->assertEquals(1, (int) $row['markup_enabled']);
    }

    /**
     * Test: setAutoMarkupConfig updates existing markup percentage.
     */
    public function testSetAutoMarkupConfigUpdatesExistingMarkup(): void
    {
        // Item 1 has 25% markup — change to 40%
        $this->repo->setAutoMarkupConfig(1, 40.00);

        $stmt = $this->db->prepare("SELECT markup_percent, markup_enabled FROM items WHERE id = 1");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals(40.00, (float) $row['markup_percent']);
        $this->assertEquals(1, (int) $row['markup_enabled']);
    }

    /**
     * Test: Recipe query returns markup_percent when markup is enabled.
     */
    public function testRecipeQueryReturnsMarkupPercentWhenEnabled(): void
    {
        $recipe = $this->repo->getRecipeById(1, 1, 100);

        $this->assertNotNull($recipe);
        $this->assertEquals(25.00, (float) $recipe['markup_percent']);
    }

    // ─── Cost Change Flag Tests ─────────────────────────────────────────────────

    /**
     * Test: setCostChangeFlag sets has_cost_change_flag = 1 on the items table.
     */
    public function testSetCostChangeFlagSetsItemFlag(): void
    {
        // Item 1 starts with has_cost_change_flag = 0
        $this->repo->setCostChangeFlag(1, 20.00, 25.00);

        $stmt = $this->db->prepare("SELECT has_cost_change_flag FROM items WHERE id = 1");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals(1, (int) $row['has_cost_change_flag']);
    }

    /**
     * Test: dismissCostChangeFlag sets has_cost_change_flag = 0 on the items table.
     */
    public function testDismissCostChangeFlagClearsItemFlag(): void
    {
        // Item 3 starts with has_cost_change_flag = 1
        $this->repo->dismissCostChangeFlag(3);

        $stmt = $this->db->prepare("SELECT has_cost_change_flag FROM items WHERE id = 3");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals(0, (int) $row['has_cost_change_flag']);
    }

    /**
     * Test: getCostChangeFlag returns data when flag is set and price history exists.
     */
    public function testGetCostChangeFlagReturnsDataWhenFlagSet(): void
    {
        $flag = $this->repo->getCostChangeFlag(3);

        $this->assertNotNull($flag);
        $this->assertEquals(3, (int) $flag['item_id']);
        $this->assertEquals(25.00, (float) $flag['old_cost_price']);
        $this->assertEquals(30.00, (float) $flag['new_cost_price']);
        $this->assertEquals(0, (int) $flag['is_dismissed']);
    }

    /**
     * Test: getCostChangeFlag returns null when flag is not set.
     */
    public function testGetCostChangeFlagReturnsNullWhenFlagNotSet(): void
    {
        $flag = $this->repo->getCostChangeFlag(1);

        $this->assertNull($flag);
    }

    /**
     * Test: After dismissing, getCostChangeFlag returns null.
     */
    public function testGetCostChangeFlagReturnsNullAfterDismissal(): void
    {
        $this->repo->dismissCostChangeFlag(3);
        $flag = $this->repo->getCostChangeFlag(3);

        $this->assertNull($flag);
    }

    /**
     * Test: Markup calculation applies correctly (simulates what ManufacturingService does).
     * When cost changes and auto-markup is enabled, sale_price = new_cost * (1 + markup_percent/100).
     */
    public function testMarkupCalculationAppliesCorrectly(): void
    {
        $itemId = 1;
        $newCost = 24.00;

        // Get markup config
        $config = $this->repo->getAutoMarkupConfig($itemId);
        $this->assertNotNull($config);

        $markupPercent = (float) $config['markup_percent'];
        $expectedSalePrice = $newCost * (1 + $markupPercent / 100);

        // Verify the calculation: 24.00 * 1.25 = 30.00
        $this->assertEqualsWithDelta(30.00, $expectedSalePrice, 0.01);

        // Simulate what ManufacturingService does: update sale_price
        $stmt = $this->db->prepare("UPDATE items SET cost_price = :cost, sale_price = :sale WHERE id = :id");
        $stmt->execute(['cost' => $newCost, 'sale' => $expectedSalePrice, 'id' => $itemId]);

        // Verify
        $stmt = $this->db->prepare("SELECT cost_price, sale_price FROM items WHERE id = :id");
        $stmt->execute(['id' => $itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertEqualsWithDelta(24.00, (float) $row['cost_price'], 0.01);
        $this->assertEqualsWithDelta(30.00, (float) $row['sale_price'], 0.01);
    }
}
