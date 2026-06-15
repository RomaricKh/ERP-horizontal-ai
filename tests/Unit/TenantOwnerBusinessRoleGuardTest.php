<?php

namespace Tests\Unit;

use App\Repository\UserRepository;
use App\Services\UserService;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the tenant owner business role guard.
 *
 * Tests:
 * - isTenantOwner returns true when user has TENANT_OWNER role in the tenant
 * - isTenantOwner returns false when user does not have TENANT_OWNER role
 * - assignRoleForTenantOwner throws InvalidArgumentException with correct message
 *   when attempting to assign a business-level role to a tenant owner
 * - assignRoleForTenantOwner succeeds for non-tenant-owner users
 *
 * Validates: REQ-026, Correctness Property 10
 */
class TenantOwnerBusinessRoleGuardTest extends TestCase
{
    // ── UserRepository::isTenantOwner tests ──────────────────────────────

    /**
     * isTenantOwner returns true when the user has a TENANT_OWNER role in the tenant.
     */
    public function testIsTenantOwnerReturnsTrueWhenOwner(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn(1);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $repo = new UserRepository($pdo);
        $result = $repo->isTenantOwner(5, 1);

        $this->assertTrue($result);
    }

    /**
     * isTenantOwner returns false when the user does not have a TENANT_OWNER role.
     */
    public function testIsTenantOwnerReturnsFalseWhenNotOwner(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn(0);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $repo = new UserRepository($pdo);
        $result = $repo->isTenantOwner(5, 1);

        $this->assertFalse($result);
    }

    // ── UserService::assignRoleForTenantOwner guard tests ────────────────

    /**
     * assignRoleForTenantOwner throws InvalidArgumentException with the correct
     * message when attempting to assign a business-level role to a tenant owner.
     * This simulates the API returning 422.
     */
    public function testAssignRoleRejectsTenantOwnerWithBusinessRole(): void
    {
        $queryLog = [];

        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('rollBack')->willReturn(true);

        $pdo->method('prepare')->willReturnCallback(
            function (string $sql) use (&$queryLog) {
                $stmt = $this->createMock(PDOStatement::class);
                $capturedParams = [];

                $stmt->method('execute')->willReturnCallback(
                    function ($params = null) use (&$capturedParams, &$queryLog, $sql) {
                        if ($params !== null) {
                            $capturedParams = $params;
                        }
                        $queryLog[] = ['sql' => $sql, 'params' => $params];
                        return true;
                    }
                );

                // "SELECT id FROM users WHERE id = :user_id" — user exists
                if (stripos($sql, 'SELECT id FROM users') !== false) {
                    $stmt->method('fetch')->willReturn(['id' => 10]);
                }

                // isTenantOwner query — return count > 0 (user IS a tenant owner)
                if (stripos($sql, 'TENANT_OWNER') !== false && stripos($sql, 'COUNT') !== false) {
                    $stmt->method('fetchColumn')->willReturn(1);
                }

                return $stmt;
            }
        );

        $this->setAuthContext(1, 1, 1);

        $service = new UserService($pdo);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tenant owners cannot be assigned business-level roles within their own tenant');

        // Attempt to assign a business role (businessId=5) to user 10 who is a tenant owner
        $service->assignRoleForTenantOwner(10, 5, 3, 1);
    }

    /**
     * assignRoleForTenantOwner succeeds for a user who is NOT a tenant owner.
     * Verifies the guard does not block legitimate assignments.
     */
    public function testAssignRoleSucceedsForNonTenantOwner(): void
    {
        $queryLog = [];

        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('rollBack')->willReturn(true);

        $pdo->method('prepare')->willReturnCallback(
            function (string $sql) use (&$queryLog) {
                $stmt = $this->createMock(PDOStatement::class);
                $capturedParams = [];

                $stmt->method('execute')->willReturnCallback(
                    function ($params = null) use (&$capturedParams, &$queryLog, $sql) {
                        if ($params !== null) {
                            $capturedParams = $params;
                        }
                        $queryLog[] = ['sql' => $sql, 'params' => $params];
                        return true;
                    }
                );

                // "SELECT id FROM users WHERE id = :user_id" — user exists
                if (stripos($sql, 'SELECT id FROM users') !== false && stripos($sql, 'LIMIT') !== false) {
                    $stmt->method('fetch')->willReturn(['id' => 10]);
                }

                // isTenantOwner query — return 0 (user is NOT a tenant owner)
                if (stripos($sql, 'TENANT_OWNER') !== false && stripos($sql, 'COUNT') !== false) {
                    $stmt->method('fetchColumn')->willReturn(0);
                }

                // Business belongs to tenant
                if (stripos($sql, 'SELECT id FROM businesses') !== false) {
                    $stmt->method('fetch')->willReturn(['id' => 5]);
                }

                // Role belongs to business
                if (stripos($sql, 'SELECT id FROM roles') !== false) {
                    $stmt->method('fetch')->willReturn(['id' => 3]);
                }

                // getUserBusinessAccessInfo — no existing access
                if (stripos($sql, 'user_roles') !== false && stripos($sql, 'is_active') !== false
                    && stripos($sql, 'TENANT_OWNER') === false && stripos($sql, 'INSERT') === false) {
                    $stmt->method('fetch')->willReturn(false);
                }

                // lastInsertId for assignRole
                $stmt->method('fetchColumn')->willReturn(0);

                return $stmt;
            }
        );

        $this->setAuthContext(1, 1, 1);

        $service = new UserService($pdo);

        // Should NOT throw — user is not a tenant owner
        $service->assignRoleForTenantOwner(10, 5, 3, 1);

        // If we reach here without exception, the test passes
        $this->assertTrue(true);
    }

    // ── Helper to set AuthContext ────────────────────────────────────────

    private function setAuthContext(int $userId, int $tenantId, ?int $businessId): void
    {
        \App\Support\AuthContext::set((object) [
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'business_id' => $businessId,
            'role_id' => 1,
            'permissions' => ['users.manage', 'tenant.manage'],
            'is_tenant_owner' => true,
        ]);
    }
}
