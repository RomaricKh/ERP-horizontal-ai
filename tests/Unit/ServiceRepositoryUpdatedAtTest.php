<?php

namespace Tests\Unit;

use App\Repository\ServiceRepository;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests verifying that ServiceRepository includes updated_at in
 * INSERT and UPDATE statements.
 *
 * Validates: Requirements REQ-009
 */
class ServiceRepositoryUpdatedAtTest extends TestCase
{
    /**
     * Test: INSERT statement includes updated_at = NOW().
     */
    public function testCreateIncludesUpdatedAt(): void
    {
        $capturedSql = null;

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('lastInsertId')->willReturn('1');
        $pdo->method('prepare')->willReturnCallback(
            function (string $sql) use (&$capturedSql, $stmt) {
                $capturedSql = $sql;
                return $stmt;
            }
        );

        $repo = new ServiceRepository($pdo);
        $repo->create([
            'tenant_id'   => 1,
            'business_id' => 1,
            'category_id' => null,
            'name'        => 'Test Service',
            'description' => 'A test',
            'unit'        => 'hour',
            'rate'        => 50.00,
            'created_by'  => 1,
        ]);

        $this->assertNotNull($capturedSql, 'Expected a SQL statement to be prepared');
        $this->assertStringContainsString('updated_at', $capturedSql, 'INSERT should include updated_at column');
        $this->assertStringContainsString('NOW()', $capturedSql, 'INSERT should set updated_at to NOW()');
    }

    /**
     * Test: UPDATE statement includes updated_at = NOW().
     */
    public function testUpdateIncludesUpdatedAt(): void
    {
        $capturedSql = null;

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(
            function (string $sql) use (&$capturedSql, $stmt) {
                $capturedSql = $sql;
                return $stmt;
            }
        );

        $repo = new ServiceRepository($pdo);
        $repo->update(1, ['name' => 'Updated Service']);

        $this->assertNotNull($capturedSql, 'Expected a SQL statement to be prepared');
        $this->assertStringContainsString('updated_at', $capturedSql, 'UPDATE should include updated_at');
        $this->assertStringContainsString('NOW()', $capturedSql, 'UPDATE should set updated_at to NOW()');
    }

    /**
     * Test: UPDATE with no allowed fields still does not execute (early return).
     */
    public function testUpdateWithNoAllowedFieldsDoesNotExecute(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('prepare');

        $repo = new ServiceRepository($pdo);
        $repo->update(1, ['invalid_field' => 'value']);
    }
}
