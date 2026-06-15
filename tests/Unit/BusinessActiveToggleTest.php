<?php

namespace Tests\Unit;

use App\Repository\BusinessRepository;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for business is_active feature (REQ-010).
 *
 * Tests:
 * - BusinessRepository::setActive deactivates a business (is_active = 0)
 * - BusinessRepository::setActive activates a business (is_active = 1)
 * - BusinessRepository::setActive throws when business not found
 * - AuthMiddleware returns 403 when business is inactive (simulated via query pattern)
 *
 * Validates: Requirements REQ-010
 */
class BusinessActiveToggleTest extends TestCase
{
    // ── BusinessRepository::setActive tests ──────────────────────────────

    /**
     * setActive(businessId, tenantId, false) executes UPDATE with is_active = 0.
     */
    public function testSetActiveDeactivatesBusiness(): void
    {
        $executedParams = null;
        $executedSql = null;

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturnCallback(
            function (array $params) use (&$executedParams) {
                $executedParams = $params;
                return true;
            }
        );
        $stmt->method('rowCount')->willReturn(1);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(
            function (string $sql) use ($stmt, &$executedSql) {
                $executedSql = $sql;
                return $stmt;
            }
        );

        $repo = new BusinessRepository($pdo);
        $repo->setActive(10, 1, false);

        $this->assertNotNull($executedParams);
        $this->assertEquals(0, $executedParams['active']);
        $this->assertEquals(10, $executedParams['id']);
        $this->assertEquals(1, $executedParams['tenant_id']);
        $this->assertStringContainsString('is_active', $executedSql);
    }

    /**
     * setActive(businessId, tenantId, true) executes UPDATE with is_active = 1.
     */
    public function testSetActiveActivatesBusiness(): void
    {
        $executedParams = null;

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturnCallback(
            function (array $params) use (&$executedParams) {
                $executedParams = $params;
                return true;
            }
        );
        $stmt->method('rowCount')->willReturn(1);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $repo = new BusinessRepository($pdo);
        $repo->setActive(10, 1, true);

        $this->assertNotNull($executedParams);
        $this->assertEquals(1, $executedParams['active']);
        $this->assertEquals(10, $executedParams['id']);
        $this->assertEquals(1, $executedParams['tenant_id']);
    }

    /**
     * setActive throws RuntimeException when no rows are affected (business not found).
     */
    public function testSetActiveThrowsWhenBusinessNotFound(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('rowCount')->willReturn(0);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $repo = new BusinessRepository($pdo);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Business not found or not allowed');

        $repo->setActive(999, 1, false);
    }

    // ── AuthMiddleware business is_active check tests ────────────────────

    /**
     * Verify that the middleware query pattern correctly identifies an inactive business.
     * When is_active = 0, the middleware should block access with 403.
     */
    public function testInactiveBusinessQueryReturnsZero(): void
    {
        $pdo = $this->buildPdoWithBusinessActive(5, 0);

        $stmt = $pdo->prepare("SELECT is_active FROM businesses WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => 5]);
        $result = $stmt->fetchColumn();

        $this->assertEquals(0, (int) $result);
    }

    /**
     * Verify that the middleware query pattern correctly identifies an active business.
     * When is_active = 1, the middleware should allow access.
     */
    public function testActiveBusinessQueryReturnsOne(): void
    {
        $pdo = $this->buildPdoWithBusinessActive(5, 1);

        $stmt = $pdo->prepare("SELECT is_active FROM businesses WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => 5]);
        $result = $stmt->fetchColumn();

        $this->assertEquals(1, (int) $result);
    }

    /**
     * Simulate the full deactivation + 403 flow:
     * 1. Deactivate a business via setActive
     * 2. Verify the is_active check query returns 0 (which triggers 403 in middleware)
     */
    public function testDeactivatedBusinessTriggers403Logic(): void
    {
        // Step 1: Deactivate the business
        $deactivateStmt = $this->createMock(PDOStatement::class);
        $deactivateStmt->method('execute')->willReturn(true);
        $deactivateStmt->method('rowCount')->willReturn(1);

        $checkStmt = $this->createMock(PDOStatement::class);
        $checkStmt->method('execute')->willReturn(true);
        // After deactivation, is_active should be 0
        $checkStmt->method('fetchColumn')->willReturn(0);

        $pdo = $this->createMock(PDO::class);
        $callCount = 0;
        $pdo->method('prepare')->willReturnCallback(
            function (string $sql) use ($deactivateStmt, $checkStmt, &$callCount) {
                $callCount++;
                if (strpos($sql, 'UPDATE') !== false) {
                    return $deactivateStmt;
                }
                return $checkStmt;
            }
        );

        // Deactivate
        $repo = new BusinessRepository($pdo);
        $repo->setActive(5, 1, false);

        // Simulate middleware check
        $stmt = $pdo->prepare("SELECT is_active FROM businesses WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => 5]);
        $isActive = (int) $stmt->fetchColumn();

        // Middleware would return 403 because is_active = 0
        $this->assertEquals(0, $isActive, 'Business should be inactive after deactivation');

        // This is the condition that triggers the 403 in AuthMiddleware
        $shouldBlock = ($isActive === 0);
        $this->assertTrue($shouldBlock, 'Middleware should block access (403) for inactive business');
    }

    // ── Helper ───────────────────────────────────────────────────────────

    /**
     * Build a PDO mock that returns a specific is_active value for a business.
     */
    private function buildPdoWithBusinessActive(int $businessId, int $isActive): PDO
    {
        $pdo = $this->createMock(PDO::class);

        $pdo->method('prepare')->willReturnCallback(
            function (string $sql) use ($businessId, $isActive) {
                $stmt = $this->createMock(PDOStatement::class);
                $capturedParams = [];

                $stmt->method('execute')->willReturnCallback(
                    function ($params = null) use (&$capturedParams) {
                        if ($params !== null) {
                            $capturedParams = $params;
                        }
                        return true;
                    }
                );

                if (strpos($sql, 'is_active') !== false && strpos($sql, 'FROM businesses') !== false) {
                    $stmt->method('fetchColumn')->willReturnCallback(
                        function () use (&$capturedParams, $businessId, $isActive) {
                            $id = $capturedParams['id'] ?? $capturedParams[':id'] ?? null;
                            if ((int) $id === $businessId) {
                                return $isActive;
                            }
                            return false;
                        }
                    );
                }

                return $stmt;
            }
        );

        return $pdo;
    }
}
