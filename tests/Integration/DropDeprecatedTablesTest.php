<?php

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for dropping deprecated tables (REQ-027).
 *
 * Verifies:
 * - The migration file exists and contains the expected DROP statements
 * - The deprecated tables no longer exist in the database
 * - The application can still query related tables without errors
 *
 * Validates: Requirements 27.4
 */
class DropDeprecatedTablesTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO(
            'mysql:host=localhost;dbname=erp;charset=utf8mb4',
            'root',
            '',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }

    /**
     * Test that the migration file exists and contains the expected DROP TABLE statements.
     */
    public function testMigrationFileExists(): void
    {
        $migrationPath = __DIR__ . '/../../database/migrations/drop_deprecated_tables.sql';
        $this->assertFileExists($migrationPath, 'Migration file drop_deprecated_tables.sql must exist');

        $content = file_get_contents($migrationPath);
        $this->assertStringContainsString('DROP TABLE IF EXISTS `notification_roles`', $content);
        $this->assertStringContainsString('DROP TABLE IF EXISTS `business_payees_types`', $content);
        $this->assertStringContainsString('DROP TABLE IF EXISTS `business_module_overrides`', $content);
        $this->assertStringContainsString('DROP TABLE IF EXISTS `user_notifications`', $content);
    }

    /**
     * Test that notification_roles table no longer exists.
     */
    public function testNotificationRolesTableDropped(): void
    {
        $exists = $this->tableExists('notification_roles');
        $this->assertFalse($exists, 'Table notification_roles should have been dropped');
    }

    /**
     * Test that business_payees_types table no longer exists.
     */
    public function testBusinessPayeesTypesTableDropped(): void
    {
        $exists = $this->tableExists('business_payees_types');
        $this->assertFalse($exists, 'Table business_payees_types should have been dropped');
    }

    /**
     * Test that business_module_overrides table no longer exists.
     */
    public function testBusinessModuleOverridesTableDropped(): void
    {
        $exists = $this->tableExists('business_module_overrides');
        $this->assertFalse($exists, 'Table business_module_overrides should have been dropped');
    }

    /**
     * Test that user_notifications table no longer exists.
     */
    public function testUserNotificationsTableDropped(): void
    {
        $exists = $this->tableExists('user_notifications');
        $this->assertFalse($exists, 'Table user_notifications should have been dropped');
    }

    /**
     * Test that the notifications table still works (not affected by user_notifications drop).
     */
    public function testNotificationsTableStillAccessible(): void
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) AS cnt FROM notifications");
        $row = $stmt->fetch();
        $this->assertIsArray($row);
        $this->assertArrayHasKey('cnt', $row);
    }

    /**
     * Test that the business_types table still works (not affected by business_module_overrides drop).
     */
    public function testBusinessTypesTableStillAccessible(): void
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) AS cnt FROM business_types");
        $row = $stmt->fetch();
        $this->assertIsArray($row);
        $this->assertArrayHasKey('cnt', $row);
    }

    /**
     * Test that the roles table still works (not affected by notification_roles drop).
     */
    public function testRolesTableStillAccessible(): void
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) AS cnt FROM roles");
        $row = $stmt->fetch();
        $this->assertIsArray($row);
        $this->assertArrayHasKey('cnt', $row);
    }

    /**
     * Test that the migration is idempotent (running DROP IF EXISTS again doesn't error).
     */
    public function testMigrationIsIdempotent(): void
    {
        // Running the DROP TABLE IF EXISTS statements again should not throw
        $statements = [
            "DROP TABLE IF EXISTS `notification_roles`",
            "DROP TABLE IF EXISTS `business_payees_types`",
            "DROP TABLE IF EXISTS `business_module_overrides`",
            "DROP TABLE IF EXISTS `user_notifications`",
        ];

        foreach ($statements as $sql) {
            $this->pdo->exec($sql);
        }

        // If we get here without exception, the migration is idempotent
        $this->assertTrue(true);
    }

    /**
     * Helper: check if a table exists in the erp database.
     */
    private function tableExists(string $tableName): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS cnt FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = 'erp' AND TABLE_NAME = :table_name"
        );
        $stmt->execute(['table_name' => $tableName]);
        $row = $stmt->fetch();
        return (int) $row['cnt'] > 0;
    }
}
