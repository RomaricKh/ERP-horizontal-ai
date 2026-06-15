<?php

namespace Tests\Unit;

use App\Repository\AuditRepository;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AuditRepository::findForBusiness().
 *
 * Verifies the fix from REQ-003:
 * - Uses CONCAT(u.first_name, ' ', u.last_name) AS user_name
 * - Does NOT select u.name as the user_name column
 *
 * Validates: Requirements 3.1, 3.2
 */
class AuditRepositoryUserNameTest extends TestCase
{
    private string $capturedSql = '';

    /**
     * Test that findForBusiness uses CONCAT(u.first_name, ' ', u.last_name) for user_name.
     */
    public function testQueryUsesConcatFirstLastName(): void
    {
        $repo = $this->buildRepoCapturingSql();

        $repo->findForBusiness(1, [], 10, 0);

        $this->assertStringContainsString(
            "CONCAT(u.first_name, ' ', u.last_name) AS user_name",
            $this->capturedSql
        );
    }

    /**
     * Test that findForBusiness does NOT use u.name as a selected column.
     * Note: u.name should not appear as "u.name AS user_name" in the SELECT.
     */
    public function testQueryDoesNotSelectUName(): void
    {
        $repo = $this->buildRepoCapturingSql();

        $repo->findForBusiness(1, [], 10, 0);

        $this->assertStringNotContainsString('u.name', $this->capturedSql);
    }

    /**
     * Test that findForBusiness still selects u.email AS user_email.
     */
    public function testQuerySelectsUserEmail(): void
    {
        $repo = $this->buildRepoCapturingSql();

        $repo->findForBusiness(1, [], 10, 0);

        $this->assertStringContainsString('u.email AS user_email', $this->capturedSql);
    }

    // ── Helper Methods ────────────────────────────────────────────────────

    /**
     * Build an AuditRepository with a mocked PDO that captures the data SQL
     * and returns empty results.
     */
    private function buildRepoCapturingSql(): AuditRepository
    {
        $countStmt = $this->createMock(PDOStatement::class);
        $countStmt->method('execute')->willReturn(true);
        $countStmt->method('fetchColumn')->willReturn(0);

        $dataStmt = $this->createMock(PDOStatement::class);
        $dataStmt->method('execute')->willReturn(true);
        $dataStmt->method('bindValue')->willReturn(true);
        $dataStmt->method('fetchAll')->willReturn([]);

        $callCount = 0;
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')
            ->willReturnCallback(function (string $sql) use ($countStmt, $dataStmt, &$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    // First call is the COUNT query
                    return $countStmt;
                }
                // Second call is the data query — capture it
                $this->capturedSql = $sql;
                return $dataStmt;
            });

        return new AuditRepository($pdo);
    }
}
