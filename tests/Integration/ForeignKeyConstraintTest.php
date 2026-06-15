<?php

namespace Tests\Integration;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for foreign key constraints (REQ-022).
 *
 * Verifies that FK constraints prevent deletion of referenced records:
 * - Deleting a person who has payee records is rejected (ON DELETE RESTRICT)
 * - Deleting a sales invoice cascades to si_details (ON DELETE CASCADE)
 * - Deleting a user cascades to user_roles (ON DELETE CASCADE)
 *
 * Validates: Requirements 22.4
 */
class ForeignKeyConstraintTest extends TestCase
{
    private PDO $pdo;

    private ?int $personId = null;
    private ?int $payeeId = null;
    private ?int $invoiceId = null;
    private ?int $detailId = null;

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
        // Clean up in reverse dependency order
        if ($this->detailId !== null) {
            $this->pdo->exec("DELETE FROM si_details WHERE id = {$this->detailId}");
        }
        if ($this->invoiceId !== null) {
            $this->pdo->exec("DELETE FROM sales_invoices WHERE id = {$this->invoiceId}");
        }
        if ($this->payeeId !== null) {
            $this->pdo->exec("DELETE FROM payees WHERE id = {$this->payeeId}");
        }
        if ($this->personId !== null) {
            $this->pdo->exec("DELETE FROM people WHERE id = {$this->personId}");
        }
    }

    /**
     * Test that deleting a person who has payee records is rejected by the FK constraint.
     */
    public function testDeletePersonWithPayeeIsRejected(): void
    {
        // Create a person
        $stmt = $this->pdo->prepare(
            "INSERT INTO people (tenant_id, name, phone, created_at) VALUES (:tenant_id, :name, :phone, NOW())"
        );
        $stmt->execute([
            'tenant_id' => 1,
            'name' => 'FK Test Person ' . uniqid(),
            'phone' => '0000000000',
        ]);
        $this->personId = (int) $this->pdo->lastInsertId();

        // Create a payee referencing this person
        $stmt = $this->pdo->prepare(
            "INSERT INTO payees (business_id, people_id, balance, created_at) VALUES (:business_id, :people_id, 0.00, NOW())"
        );
        $stmt->execute([
            'business_id' => 1,
            'people_id' => $this->personId,
        ]);
        $this->payeeId = (int) $this->pdo->lastInsertId();

        // Attempt to delete the person — should fail with FK constraint violation
        $this->expectException(PDOException::class);
        $this->expectExceptionMessageMatches('/foreign key constraint|a foreign key constraint fails/i');

        $this->pdo->exec("DELETE FROM people WHERE id = {$this->personId}");
    }

    /**
     * Test that the fk_payees_people constraint exists with RESTRICT behavior.
     */
    public function testPayeesPeopleForeignKeyExists(): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = 'erp'
               AND TABLE_NAME = 'payees'
               AND CONSTRAINT_NAME = 'fk_payees_people'"
        );
        $stmt->execute();
        $row = $stmt->fetch();

        $this->assertNotFalse($row, 'FK constraint fk_payees_people must exist');
        $this->assertEquals('RESTRICT', $row['DELETE_RULE'], 'payees → people must use ON DELETE RESTRICT');
    }

    /**
     * Test that si_details → sales_invoices FK uses ON DELETE CASCADE.
     */
    public function testSiDetailsCascadeConstraintExists(): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = 'erp'
               AND TABLE_NAME = 'si_details'
               AND CONSTRAINT_NAME = 'fk_si_details_invoice'"
        );
        $stmt->execute();
        $row = $stmt->fetch();

        $this->assertNotFalse($row, 'FK constraint fk_si_details_invoice must exist');
        $this->assertEquals('CASCADE', $row['DELETE_RULE'], 'si_details → sales_invoices must use ON DELETE CASCADE');
    }

    /**
     * Test that po_details → purchase_orders FK uses ON DELETE CASCADE.
     */
    public function testPoDetailsCascadeConstraintExists(): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = 'erp'
               AND TABLE_NAME = 'po_details'
               AND CONSTRAINT_NAME = 'fk_po_details_po'"
        );
        $stmt->execute();
        $row = $stmt->fetch();

        $this->assertNotFalse($row, 'FK constraint fk_po_details_po must exist');
        $this->assertEquals('CASCADE', $row['DELETE_RULE'], 'po_details → purchase_orders must use ON DELETE CASCADE');
    }

    /**
     * Test that user_roles → users FK uses ON DELETE CASCADE.
     */
    public function testUserRolesCascadeConstraintExists(): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = 'erp'
               AND TABLE_NAME = 'user_roles'
               AND CONSTRAINT_NAME = 'fk_ur_user'"
        );
        $stmt->execute();
        $row = $stmt->fetch();

        $this->assertNotFalse($row, 'FK constraint fk_ur_user must exist');
        $this->assertEquals('CASCADE', $row['DELETE_RULE'], 'user_roles → users must use ON DELETE CASCADE');
    }

    /**
     * Test that the migration file exists.
     */
    public function testMigrationFileExists(): void
    {
        $migrationPath = __DIR__ . '/../../database/migrations/add_foreign_keys.sql';
        $this->assertFileExists($migrationPath, 'Migration file add_foreign_keys.sql must exist');

        $content = file_get_contents($migrationPath);
        $this->assertStringContainsString('CASCADE', $content, 'Migration must contain CASCADE rules');
        $this->assertStringContainsString('RESTRICT', $content, 'Migration must contain RESTRICT rules');
    }
}
