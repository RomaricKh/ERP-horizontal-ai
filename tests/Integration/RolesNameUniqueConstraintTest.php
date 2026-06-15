<?php

namespace Tests\Integration;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for the roles.name unique constraint fix (REQ-005).
 *
 * Verifies:
 * 1. Two roles with the same name in DIFFERENT businesses can coexist
 * 2. Two roles with the same name in the SAME business are rejected
 * 3. The migration file exists and contains the correct SQL
 *
 * Validates: Requirements 5.1, 5.2, 5.3 (REQ-005)
 */
class RolesNameUniqueConstraintTest extends TestCase
{
    private PDO $pdo;

    /** @var int[] IDs of roles created during tests (for cleanup) */
    private array $createdRoleIds = [];

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

    protected function tearDown(): void
    {
        if (!empty($this->createdRoleIds)) {
            $ids = implode(',', $this->createdRoleIds);
            $this->pdo->exec("DELETE FROM roles WHERE id IN ({$ids})");
        }
    }

    /**
     * Test that two roles with the same name in different businesses both succeed.
     */
    public function testSameNameDifferentBusinessesSucceeds(): void
    {
        $roleName = 'TestRole_' . uniqid();

        // Insert role in business 1
        $stmt = $this->pdo->prepare(
            "INSERT INTO roles (name, business_id, type, created_at) VALUES (:name, :biz, 'CUSTOM', NOW())"
        );
        $stmt->execute(['name' => $roleName, 'biz' => 1]);
        $id1 = (int) $this->pdo->lastInsertId();
        $this->createdRoleIds[] = $id1;

        // Insert role with same name in business 2
        $stmt->execute(['name' => $roleName, 'biz' => 2]);
        $id2 = (int) $this->pdo->lastInsertId();
        $this->createdRoleIds[] = $id2;

        // Verify both exist
        $check = $this->pdo->prepare("SELECT id, business_id FROM roles WHERE name = :name ORDER BY business_id");
        $check->execute(['name' => $roleName]);
        $rows = $check->fetchAll();

        $this->assertCount(2, $rows, 'Both roles with the same name in different businesses should exist');
        $this->assertEquals(1, $rows[0]['business_id']);
        $this->assertEquals(2, $rows[1]['business_id']);
    }

    /**
     * Test that two roles with the same name in the same business are rejected.
     */
    public function testSameNameSameBusinessIsRejected(): void
    {
        $roleName = 'TestRole_' . uniqid();

        // Insert first role in business 1
        $stmt = $this->pdo->prepare(
            "INSERT INTO roles (name, business_id, type, created_at) VALUES (:name, :biz, 'CUSTOM', NOW())"
        );
        $stmt->execute(['name' => $roleName, 'biz' => 1]);
        $id1 = (int) $this->pdo->lastInsertId();
        $this->createdRoleIds[] = $id1;

        // Attempt to insert duplicate in same business — should fail
        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/Duplicate entry/i');

        $stmt->execute(['name' => $roleName, 'biz' => 1]);
    }

    /**
     * Test that the migration file exists and contains the expected SQL.
     */
    public function testMigrationFileExistsWithCorrectContent(): void
    {
        $migrationPath = __DIR__ . '/../../database/migrations/fix_roles_name_unique.sql';

        $this->assertFileExists($migrationPath, 'Migration file fix_roles_name_unique.sql must exist');

        $content = file_get_contents($migrationPath);

        // Should drop the global unique index
        $this->assertStringContainsString('DROP INDEX', $content, 'Migration must drop the global unique index');
        $this->assertStringContainsString('uq_roles_tenant_name', $content, 'Migration must reference the correct index name');
    }

    /**
     * Test that the global unique index on name alone no longer exists.
     */
    public function testGlobalUniqueIndexOnNameDoesNotExist(): void
    {
        $stmt = $this->pdo->query("SHOW INDEX FROM roles WHERE Key_name = 'uq_roles_tenant_name'");
        $rows = $stmt->fetchAll();

        $this->assertEmpty($rows, 'The global unique index uq_roles_tenant_name should not exist after migration');
    }

    /**
     * Test that the composite unique index on (business_id, name) exists.
     */
    public function testCompositeUniqueIndexExists(): void
    {
        $stmt = $this->pdo->query("SHOW INDEX FROM roles WHERE Key_name = 'uq_roles_business_name'");
        $rows = $stmt->fetchAll();

        $this->assertNotEmpty($rows, 'Composite unique index uq_roles_business_name must exist');

        // Verify it covers both business_id and name columns
        $columns = array_column($rows, 'Column_name');
        $this->assertContains('business_id', $columns);
        $this->assertContains('name', $columns);
    }
}
