<?php

namespace Tests\Unit;

use App\Repository\DashboardRepository;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DashboardRepository::getMonthlyPayables().
 *
 * Verifies the fix from REQ-002:
 * - Uses po_date (not order_date) for date filtering
 * - Uses status NOT IN ('CLOSED', 'CANCELLED') (not 'PAID')
 * - Returns a float value
 *
 * Validates: Requirements 2.1, 2.2
 */
class DashboardRepositoryPayablesTest extends TestCase
{
    private string $capturedSql = '';

    /**
     * Test that getMonthlyPayables uses po_date for year/month filtering.
     */
    public function testPayablesQueryUsesPoDate(): void
    {
        $repo = $this->buildRepoCapturingSql(1500.50);

        $repo->getMonthlyPayables(1, 2024, 6);

        $this->assertStringContainsString('po_date', $this->capturedSql);
        $this->assertStringNotContainsString('order_date', $this->capturedSql);
    }

    /**
     * Test that getMonthlyPayables filters by CLOSED status (not PAID).
     */
    public function testPayablesQueryUsesClosedStatus(): void
    {
        $repo = $this->buildRepoCapturingSql(0.0);

        $repo->getMonthlyPayables(1, 2024, 6);

        $this->assertStringContainsString('CLOSED', $this->capturedSql);
        $this->assertStringContainsString('CANCELLED', $this->capturedSql);
        $this->assertStringNotContainsString("'PAID'", $this->capturedSql);
    }

    /**
     * Test that getMonthlyPayables returns a float.
     */
    public function testPayablesReturnsFloat(): void
    {
        $repo = $this->buildRepoCapturingSql(2500.75);

        $result = $repo->getMonthlyPayables(1, 2024, 6);

        $this->assertIsFloat($result);
        $this->assertSame(2500.75, $result);
    }

    /**
     * Test that getMonthlyPayables returns 0.0 when no payables exist.
     */
    public function testPayablesReturnsZeroWhenEmpty(): void
    {
        $repo = $this->buildRepoCapturingSql(0);

        $result = $repo->getMonthlyPayables(1, 2024, 1);

        $this->assertIsFloat($result);
        $this->assertSame(0.0, $result);
    }

    // ── Helper Methods ────────────────────────────────────────────────────

    /**
     * Build a DashboardRepository with a mocked PDO that captures the SQL
     * and returns the given value from fetchColumn.
     */
    /**
     * @param float|int $returnValue
     */
    private function buildRepoCapturingSql($returnValue): DashboardRepository
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn($returnValue);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')
            ->willReturnCallback(function (string $sql) use ($stmt) {
                $this->capturedSql = $sql;
                return $stmt;
            });

        return new DashboardRepository($pdo);
    }
}
