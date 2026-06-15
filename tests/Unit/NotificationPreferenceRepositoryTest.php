<?php

namespace Tests\Unit;

use App\Repository\NotificationPreferenceRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for NotificationPreferenceRepository.
 *
 * Uses an in-memory SQLite database for getForUser and isTypeEnabled tests,
 * and mock PDO for upsert tests (which use MySQL-specific ON DUPLICATE KEY UPDATE).
 *
 * Validates: Requirements 7.1, 7.3
 */
class NotificationPreferenceRepositoryTest extends TestCase
{
    private PDO $db;
    private NotificationPreferenceRepository $repo;

    private const USER_ID = 1;
    private const BUSINESS_ID = 100;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $this->createSchema();
        $this->repo = new NotificationPreferenceRepository($this->db);
    }

    private function createSchema(): void
    {
        $this->db->exec("
            CREATE TABLE notification_preferences (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                business_id INTEGER NOT NULL,
                type TEXT NOT NULL,
                enabled INTEGER NOT NULL DEFAULT 1,
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                UNIQUE(user_id, business_id, type)
            )
        ");
    }

    /**
     * Helper to insert a preference record directly into the database.
     */
    private function insertPreference(array $overrides = []): int
    {
        $data = array_merge([
            'user_id'     => self::USER_ID,
            'business_id' => self::BUSINESS_ID,
            'type'        => 'SYSTEM_ALERT',
            'enabled'     => 1,
        ], $overrides);

        $stmt = $this->db->prepare("
            INSERT INTO notification_preferences (user_id, business_id, type, enabled)
            VALUES (:user_id, :business_id, :type, :enabled)
        ");
        $stmt->execute($data);
        return (int) $this->db->lastInsertId();
    }

    // ─── getForUser() Tests ─────────────────────────────────────────────────────

    /**
     * Test getForUser returns empty array for a user with no preferences (defaults).
     *
     * Validates: Requirements 7.3
     */
    public function testGetForUserReturnsEmptyArrayForNewUser(): void
    {
        $result = $this->repo->getForUser(self::USER_ID, self::BUSINESS_ID);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test getForUser returns all preference records for a user+business.
     */
    public function testGetForUserReturnsExistingPreferences(): void
    {
        $this->insertPreference(['type' => 'PO_STATUS_CHANGE', 'enabled' => 1]);
        $this->insertPreference(['type' => 'INVOICE_DUE', 'enabled' => 0]);
        $this->insertPreference(['type' => 'STOCK_LOW', 'enabled' => 1]);

        $result = $this->repo->getForUser(self::USER_ID, self::BUSINESS_ID);

        $this->assertCount(3, $result);

        $types = array_column($result, 'type');
        $this->assertContains('PO_STATUS_CHANGE', $types);
        $this->assertContains('INVOICE_DUE', $types);
        $this->assertContains('STOCK_LOW', $types);
    }

    /**
     * Test getForUser does not return preferences from other users or businesses.
     */
    public function testGetForUserIsScopedToUserAndBusiness(): void
    {
        // Preference for our user+business
        $this->insertPreference(['type' => 'SYSTEM_ALERT', 'enabled' => 1]);

        // Preference for a different user
        $this->insertPreference([
            'user_id' => 999,
            'type'    => 'STOCK_LOW',
            'enabled' => 0,
        ]);

        // Preference for a different business
        $this->insertPreference([
            'business_id' => 999,
            'type'        => 'INVOICE_DUE',
            'enabled'     => 0,
        ]);

        $result = $this->repo->getForUser(self::USER_ID, self::BUSINESS_ID);

        $this->assertCount(1, $result);
        $this->assertSame('SYSTEM_ALERT', $result[0]['type']);
    }

    // ─── upsert() Tests ─────────────────────────────────────────────────────────

    /**
     * Test upsert creates a new record when none exists.
     * Uses mock PDO since upsert uses MySQL's ON DUPLICATE KEY UPDATE.
     *
     * Validates: Requirements 7.1
     */
    public function testUpsertCreatesNewRecord(): void
    {
        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->expects($this->once())
            ->method('execute')
            ->with([
                'user_id'     => self::USER_ID,
                'business_id' => self::BUSINESS_ID,
                'type'        => 'PO_STATUS_CHANGE',
                'enabled'     => 1,
            ])
            ->willReturn(true);

        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('INSERT INTO notification_preferences'))
            ->willReturn($mockStmt);

        $repo = new NotificationPreferenceRepository($mockPdo);
        $repo->upsert(self::USER_ID, self::BUSINESS_ID, 'PO_STATUS_CHANGE', true);
    }

    /**
     * Test upsert updates existing record (enabled value changes).
     * Uses mock PDO since upsert uses MySQL's ON DUPLICATE KEY UPDATE.
     *
     * Validates: Requirements 7.1
     */
    public function testUpsertUpdatesExistingRecord(): void
    {
        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->expects($this->once())
            ->method('execute')
            ->with([
                'user_id'     => self::USER_ID,
                'business_id' => self::BUSINESS_ID,
                'type'        => 'INVOICE_DUE',
                'enabled'     => 0,
            ])
            ->willReturn(true);

        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->expects($this->once())
            ->method('prepare')
            ->with($this->logicalAnd(
                $this->stringContains('INSERT INTO notification_preferences'),
                $this->stringContains('ON DUPLICATE KEY UPDATE')
            ))
            ->willReturn($mockStmt);

        $repo = new NotificationPreferenceRepository($mockPdo);
        $repo->upsert(self::USER_ID, self::BUSINESS_ID, 'INVOICE_DUE', false);
    }

    // ─── isTypeEnabled() Tests ──────────────────────────────────────────────────

    /**
     * Test isTypeEnabled returns true when no preference record exists (default enabled).
     *
     * Validates: Requirements 7.3
     */
    public function testIsTypeEnabledReturnsTrueWhenNoRecordExists(): void
    {
        $result = $this->repo->isTypeEnabled(self::USER_ID, self::BUSINESS_ID, 'PO_STATUS_CHANGE');

        $this->assertTrue($result);
    }

    /**
     * Test isTypeEnabled returns true when record exists with enabled=1.
     *
     * Validates: Requirements 7.1
     */
    public function testIsTypeEnabledReturnsTrueWhenEnabled(): void
    {
        $this->insertPreference(['type' => 'STOCK_LOW', 'enabled' => 1]);

        $result = $this->repo->isTypeEnabled(self::USER_ID, self::BUSINESS_ID, 'STOCK_LOW');

        $this->assertTrue($result);
    }

    /**
     * Test isTypeEnabled returns false when record exists with enabled=0.
     *
     * Validates: Requirements 7.1
     */
    public function testIsTypeEnabledReturnsFalseWhenDisabled(): void
    {
        $this->insertPreference(['type' => 'INVOICE_DUE', 'enabled' => 0]);

        $result = $this->repo->isTypeEnabled(self::USER_ID, self::BUSINESS_ID, 'INVOICE_DUE');

        $this->assertFalse($result);
    }

    /**
     * Test isTypeEnabled checks the correct user+business+type combination.
     */
    public function testIsTypeEnabledIsScopedCorrectly(): void
    {
        // Disable STOCK_LOW for a different user
        $this->insertPreference([
            'user_id' => 999,
            'type'    => 'STOCK_LOW',
            'enabled' => 0,
        ]);

        // Our user has no record, so should default to enabled
        $result = $this->repo->isTypeEnabled(self::USER_ID, self::BUSINESS_ID, 'STOCK_LOW');

        $this->assertTrue($result);
    }
}
