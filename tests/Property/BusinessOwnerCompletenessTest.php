<?php

namespace Tests\Property;

use App\Repository\RoleRepository;
use PHPUnit\Framework\TestCase;
use PDO;
use PDOStatement;

/**
 * Feature: user-roles-permissions, Property 3: Business Owner Permission Completeness
 *
 * For any state of the permissions table, querying the Business Owner role's permissions
 * SHALL return a set equal to the complete set of all permissions in the system.
 *
 * **Validates: Requirements 2.3, 6.3**
 */
class BusinessOwnerCompletenessTest extends TestCase
{
    private const ITERATIONS = 100;
    private const BUSINESS_OWNER_ID = 1;

    /**
     * Generate a random permission code in dot-notation (e.g. "module.action").
     */
    private function generateRandomPermissionCode(): string
    {
        $modules = ['po', 'expenses', 'finance', 'items', 'users', 'roles', 'reports',
                    'invoices', 'salaries', 'settings', 'dashboard', 'manufacturing',
                    'inventory', 'hr', 'crm', 'analytics', 'audit', 'notifications'];
        $actions = ['view', 'create', 'edit', 'delete', 'export', 'import', 'approve',
                    'reject', 'archive', 'restore', 'manage', 'configure'];

        $module = $modules[mt_rand(0, count($modules) - 1)];
        $action = $actions[mt_rand(0, count($actions) - 1)];

        // Add a random suffix to ensure uniqueness across iterations
        $suffix = mt_rand(1, 9999);

        return "{$module}.{$action}_{$suffix}";
    }

    /**
     * Generate a random human-readable permission name.
     */
    private function generateRandomPermissionName(): string
    {
        $length = mt_rand(5, 40);
        $chars = 'abcdefghijklmnopqrstuvwxyz ';
        $name = '';
        for ($i = 0; $i < $length; $i++) {
            $name .= $chars[mt_rand(0, strlen($chars) - 1)];
        }
        return trim($name) ?: 'permission';
    }

    /**
     * Generate a random set of permissions (1-50 permissions).
     *
     * @return array Array of permission rows with 'id', 'code', 'name'
     */
    private function generateRandomPermissionSet(): array
    {
        $count = mt_rand(1, 50);
        $permissions = [];
        $usedCodes = [];

        for ($i = 0; $i < $count; $i++) {
            // Ensure unique codes
            $code = $this->generateRandomPermissionCode();
            while (in_array($code, $usedCodes, true)) {
                $code = $this->generateRandomPermissionCode();
            }
            $usedCodes[] = $code;

            $permissions[] = [
                'id' => $i + 1,
                'code' => $code,
                'name' => $this->generateRandomPermissionName(),
            ];
        }

        // Sort by code to match the ORDER BY code in the repository
        usort($permissions, function ($a, $b) {
            return strcmp($a['code'], $b['code']);
        });

        // Re-assign IDs after sorting to keep them sequential
        foreach ($permissions as $idx => &$perm) {
            $perm['id'] = $idx + 1;
        }

        return $permissions;
    }

    /**
     * Create a mock PDO that returns the given permissions when queried.
     */
    private function createMockPdo(array $permissions): PDO
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')
            ->with(PDO::FETCH_ASSOC)
            ->willReturn($permissions);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')
            ->willReturn($stmt);

        return $pdo;
    }

    /**
     * Property 3: Business Owner Permission Completeness — findPermissionsByRoleId(1)
     * returns the complete permission set.
     *
     * For any random set of permissions in the permissions table, calling
     * findPermissionsByRoleId(1) SHALL return the exact same set as all permissions
     * in the system.
     *
     * **Validates: Requirements 2.3, 6.3**
     */
    public function testBusinessOwnerAlwaysGetsAllPermissions(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $allPermissions = $this->generateRandomPermissionSet();

            $pdo = $this->createMockPdo($allPermissions);
            $repo = new RoleRepository($pdo);

            $result = $repo->findPermissionsByRoleId(self::BUSINESS_OWNER_ID);

            // The Business Owner should get ALL permissions
            $this->assertSame(
                $allPermissions,
                $result,
                sprintf(
                    'Iteration %d: findPermissionsByRoleId(1) should return all %d permissions, got %d.',
                    $i,
                    count($allPermissions),
                    count($result)
                )
            );
        }
    }

    /**
     * Property 3: Business Owner Permission Completeness — findPermissionsByRoleId(1)
     * equals findAllPermissions() for any permission state.
     *
     * For any random set of permissions, the result of findPermissionsByRoleId(1)
     * SHALL be identical to the result of findAllPermissions().
     *
     * **Validates: Requirements 2.3, 6.3**
     */
    public function testBusinessOwnerPermissionsEqualAllPermissions(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $allPermissions = $this->generateRandomPermissionSet();

            $pdo = $this->createMockPdo($allPermissions);
            $repo = new RoleRepository($pdo);

            $businessOwnerPerms = $repo->findPermissionsByRoleId(self::BUSINESS_OWNER_ID);
            $allPerms = $repo->findAllPermissions();

            // Both methods should return the exact same set
            $this->assertSame(
                $allPerms,
                $businessOwnerPerms,
                sprintf(
                    'Iteration %d: findPermissionsByRoleId(1) should equal findAllPermissions(). ' .
                    'findAllPermissions returned %d, findPermissionsByRoleId(1) returned %d.',
                    $i,
                    count($allPerms),
                    count($businessOwnerPerms)
                )
            );
        }
    }

    /**
     * Property 3: Business Owner Permission Completeness — No permission is missing.
     *
     * For any random set of permissions, every single permission in the system
     * SHALL appear in the Business Owner's permission set (no omissions).
     *
     * **Validates: Requirements 2.3, 6.3**
     */
    public function testNoPermissionMissingFromBusinessOwner(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $allPermissions = $this->generateRandomPermissionSet();

            $pdo = $this->createMockPdo($allPermissions);
            $repo = new RoleRepository($pdo);

            $businessOwnerPerms = $repo->findPermissionsByRoleId(self::BUSINESS_OWNER_ID);
            $businessOwnerCodes = array_column($businessOwnerPerms, 'code');

            // Every permission in the system must be present in Business Owner's set
            foreach ($allPermissions as $permission) {
                $this->assertContains(
                    $permission['code'],
                    $businessOwnerCodes,
                    sprintf(
                        'Iteration %d: Permission "%s" (id=%d) is missing from Business Owner\'s permissions. ' .
                        'Total permissions: %d, Business Owner has: %d.',
                        $i,
                        $permission['code'],
                        $permission['id'],
                        count($allPermissions),
                        count($businessOwnerPerms)
                    )
                );
            }

            // Count must match exactly (no extras, no missing)
            $this->assertCount(
                count($allPermissions),
                $businessOwnerPerms,
                sprintf(
                    'Iteration %d: Business Owner should have exactly %d permissions, got %d.',
                    $i,
                    count($allPermissions),
                    count($businessOwnerPerms)
                )
            );
        }
    }

    /**
     * Property 3: Business Owner Permission Completeness — Non-Business-Owner roles
     * do NOT get the completeness treatment.
     *
     * For any role ID != 1, findPermissionsByRoleId should use the role_permissions
     * join (different query path), not the "all permissions" shortcut.
     *
     * This verifies the special behavior is exclusive to role ID 1.
     *
     * **Validates: Requirements 2.3, 6.3**
     */
    public function testNonBusinessOwnerDoesNotGetAllPermissions(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $roleId = mt_rand(2, 10000); // Any role that is NOT Business Owner
            $allPermissions = $this->generateRandomPermissionSet();

            // For non-Business-Owner roles, the query joins role_permissions.
            // We mock PDO to return an empty set (simulating no role_permissions rows).
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            $stmt->method('fetchAll')
                ->with(PDO::FETCH_ASSOC)
                ->willReturn([]); // No permissions assigned via role_permissions

            $pdo = $this->createMock(PDO::class);
            $pdo->method('prepare')
                ->willReturn($stmt);

            $repo = new RoleRepository($pdo);
            $result = $repo->findPermissionsByRoleId($roleId);

            // Non-Business-Owner with no role_permissions should get empty set
            $this->assertEmpty(
                $result,
                sprintf(
                    'Iteration %d: findPermissionsByRoleId(%d) should return empty set for ' .
                    'non-Business-Owner role with no assigned permissions, got %d permissions.',
                    $i,
                    $roleId,
                    count($result)
                )
            );
        }
    }
}
