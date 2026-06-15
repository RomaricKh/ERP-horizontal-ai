<?php

namespace Tests\Property;

use App\Repository\RoleRepository;
use App\Services\AuditService;
use App\Services\RoleService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Feature: business-specific-roles, Property: Role Data Round-Trip
 *
 * For any valid role name (2–50 chars), optional description (≤255 chars), and set of
 * valid permission IDs, creating or updating a role and then reading it back SHALL return
 * the same name, description, and permission set.
 *
 * **Validates: Requirements 4.1, 4.2, 4.4**
 */
class RoleDataRoundTripTest extends TestCase
{
    private const ITERATIONS = 100;

    /**
     * Generate a random valid role name (2–50 chars, trimmed).
     * Uses alphanumeric + spaces but ensures trimmed length is within bounds.
     */
    private function generateValidRoleName(): string
    {
        $length = mt_rand(2, 50);
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789 _-';
        $name = '';
        for ($i = 0; $i < $length; $i++) {
            $name .= $chars[mt_rand(0, strlen($chars) - 1)];
        }
        // Ensure trimmed length is at least 2
        $trimmed = trim($name);
        if (mb_strlen($trimmed) < 2) {
            $name = 'Ab' . $name;
        }
        // Ensure trimmed length does not exceed 50
        $trimmed = trim($name);
        if (mb_strlen($trimmed) > 50) {
            $name = mb_substr($trimmed, 0, 50);
        }
        return $name;
    }

    /**
     * Generate a random valid description (0–255 chars). Returns null ~20% of the time.
     */
    private function generateValidDescription(): ?string
    {
        if (mt_rand(1, 5) === 1) {
            return null;
        }
        $length = mt_rand(0, 255);
        if ($length === 0) {
            return null;
        }
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789 .,!?-_()';
        $desc = '';
        for ($i = 0; $i < $length; $i++) {
            $desc .= $chars[mt_rand(0, strlen($chars) - 1)];
        }
        return $desc;
    }

    /**
     * Generate a random subset of permission IDs from a given pool.
     */
    private function generatePermissionIdSubset(array $allPermissionIds): array
    {
        if (empty($allPermissionIds)) {
            return [];
        }
        $count = mt_rand(0, count($allPermissionIds));
        $shuffled = $allPermissionIds;
        shuffle($shuffled);
        return array_slice($shuffled, 0, $count);
    }

    /**
     * Generate a pool of available permissions (random IDs and codes).
     */
    private function generatePermissionPool(): array
    {
        $count = mt_rand(3, 20);
        $permissions = [];
        for ($i = 1; $i <= $count; $i++) {
            $modules = ['po', 'expenses', 'roles', 'users', 'finance', 'items', 'invoices'];
            $actions = ['view', 'create', 'edit', 'delete'];
            $module = $modules[mt_rand(0, count($modules) - 1)];
            $action = $actions[mt_rand(0, count($actions) - 1)];
            $permissions[] = [
                'id' => $i,
                'code' => $module . '.' . $action,
                'name' => $module . ' ' . $action,
            ];
        }
        return $permissions;
    }

    /**
     * Property: Role Data Round-Trip — Create then read back
     *
     * For any valid role name, description, and permission ID subset, creating a role
     * and then reading it back via getById SHALL return the same name (trimmed),
     * description, and permission set.
     *
     * **Validates: Requirements 4.1, 4.2**
     */
    public function testCreateThenReadBackReturnsMatchingData(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $businessId = mt_rand(1, 10000);
            $tenantId = mt_rand(1, 10000);
            $name = $this->generateValidRoleName();
            $description = $this->generateValidDescription();
            $allPermissions = $this->generatePermissionPool();
            $allPermissionIds = array_column($allPermissions, 'id');
            $selectedPermissionIds = $this->generatePermissionIdSubset($allPermissionIds);

            $trimmedName = trim($name);
            $newRoleId = mt_rand(2, 10000); // Avoid id=1 (SYSTEM_OWNER)

            // Build the permissions that should be returned for the selected IDs
            $expectedPermissions = array_values(array_filter($allPermissions, function ($p) use ($selectedPermissionIds) {
                return in_array($p['id'], $selectedPermissionIds, true);
            }));

            // Mock repository
            $repo = $this->createMock(RoleRepository::class);
            $audit = $this->createMock(AuditService::class);

            // existsByNameAndBusiness returns false (no duplicate)
            $repo->method('existsByNameAndBusiness')
                ->willReturn(false);

            // findAllPermissions returns our pool (for validation)
            $repo->method('findAllPermissions')
                ->willReturn($allPermissions);

            // insertWithType returns the new role ID
            $repo->expects($this->once())
                ->method('insertWithType')
                ->with($tenantId, $businessId, $trimmedName, $description, 'CUSTOM')
                ->willReturn($newRoleId);

            // syncPermissions is called with the selected IDs
            $repo->expects($this->once())
                ->method('syncPermissions')
                ->with($newRoleId, $selectedPermissionIds);

            // findByIdAndBusiness returns the created role data (for getById)
            $repo->method('findByIdAndBusiness')
                ->with($newRoleId, $businessId)
                ->willReturn([
                    'id' => $newRoleId,
                    'name' => $trimmedName,
                    'description' => $description,
                    'type' => 'CUSTOM',
                ]);

            // findPermissionsByRoleId returns the synced permissions
            $repo->method('findPermissionsByRoleId')
                ->with($newRoleId)
                ->willReturn($expectedPermissions);

            $service = $this->buildServiceWithDeps($repo, $audit);

            // Create the role
            $createdId = $service->create($businessId, $tenantId, $name, $description, $selectedPermissionIds);
            $this->assertSame($newRoleId, $createdId, "Iteration $i: Created role ID should match");

            // Read it back
            $readBack = $service->getById($newRoleId, $businessId);

            // Verify round-trip: name matches (trimmed)
            $this->assertSame(
                $trimmedName,
                $readBack['name'],
                sprintf(
                    'Iteration %d: Role name round-trip failed. Created "%s" (trimmed: "%s"), read back "%s".',
                    $i,
                    $name,
                    $trimmedName,
                    $readBack['name']
                )
            );

            // Verify round-trip: description matches
            $this->assertSame(
                $description,
                $readBack['description'],
                sprintf(
                    'Iteration %d: Role description round-trip failed. Created "%s", read back "%s".',
                    $i,
                    $description ?? 'null',
                    $readBack['description'] ?? 'null'
                )
            );

            // Verify round-trip: permissions match
            $readPermissionIds = array_map('intval', array_column($readBack['permissions'], 'id'));
            $expectedIds = array_map('intval', $selectedPermissionIds);
            sort($readPermissionIds);
            sort($expectedIds);

            $this->assertSame(
                $expectedIds,
                $readPermissionIds,
                sprintf(
                    'Iteration %d: Permission IDs round-trip failed. Created with [%s], read back [%s].',
                    $i,
                    implode(',', $expectedIds),
                    implode(',', $readPermissionIds)
                )
            );
        }
    }

    /**
     * Property: Role Data Round-Trip — Update then read back
     *
     * For any valid role (not SYSTEM_OWNER), updating its name, description, and
     * permissions and then reading it back via getById SHALL return the updated values.
     *
     * **Validates: Requirements 4.1, 4.4**
     */
    public function testUpdateThenReadBackReturnsMatchingData(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $businessId = mt_rand(1, 10000);
            $roleId = mt_rand(2, 10000); // Avoid id=1 (SYSTEM_OWNER)
            $newName = $this->generateValidRoleName();
            $newDescription = $this->generateValidDescription();
            $allPermissions = $this->generatePermissionPool();
            $allPermissionIds = array_column($allPermissions, 'id');
            $newPermissionIds = $this->generatePermissionIdSubset($allPermissionIds);

            $trimmedNewName = trim($newName);

            // Build the permissions that should be returned for the new selected IDs
            $expectedPermissions = array_values(array_filter($allPermissions, function ($p) use ($newPermissionIds) {
                return in_array($p['id'], $newPermissionIds, true);
            }));

            // Mock repository
            $repo = $this->createMock(RoleRepository::class);
            $audit = $this->createMock(AuditService::class);

            // findByIdAndBusiness returns the role (for update verification and getById)
            $oldData = [
                'id' => $roleId,
                'name' => 'OldName',
                'description' => 'Old description',
                'type' => 'CUSTOM',
            ];
            $updatedData = [
                'id' => $roleId,
                'name' => $trimmedNewName,
                'description' => $newDescription,
                'type' => 'CUSTOM',
            ];
            $repo->method('findByIdAndBusiness')
                ->with($roleId, $businessId)
                ->willReturnOnConsecutiveCalls($oldData, $updatedData);

            // findRoleType returns CUSTOM
            $repo->method('findRoleType')
                ->with($roleId)
                ->willReturn('CUSTOM');

            // existsByNameAndBusiness returns false (no duplicate)
            $repo->method('existsByNameAndBusiness')
                ->willReturn(false);

            // findAllPermissions returns our pool (for validation)
            $repo->method('findAllPermissions')
                ->willReturn($allPermissions);

            // findPermissionsByRoleId: first call returns old perms (for audit), second returns new
            $repo->method('findPermissionsByRoleId')
                ->with($roleId)
                ->willReturnOnConsecutiveCalls(
                    [['id' => 999, 'code' => 'old.perm', 'name' => 'old perm']],
                    $expectedPermissions
                );

            // updateRole is called with the new data
            $repo->expects($this->once())
                ->method('updateRole')
                ->with($roleId, $trimmedNewName, $newDescription);

            // syncPermissions is called with the new permission IDs
            $repo->expects($this->once())
                ->method('syncPermissions')
                ->with($roleId, $newPermissionIds);

            $service = $this->buildServiceWithDeps($repo, $audit);

            // Update the role
            $service->update($roleId, $businessId, $newName, $newDescription, $newPermissionIds);

            // Read it back
            $readBack = $service->getById($roleId, $businessId);

            // Verify round-trip: name matches (trimmed)
            $this->assertSame(
                $trimmedNewName,
                $readBack['name'],
                sprintf(
                    'Iteration %d: Updated role name round-trip failed. Updated to "%s" (trimmed: "%s"), read back "%s".',
                    $i,
                    $newName,
                    $trimmedNewName,
                    $readBack['name']
                )
            );

            // Verify round-trip: description matches
            $this->assertSame(
                $newDescription,
                $readBack['description'],
                sprintf(
                    'Iteration %d: Updated role description round-trip failed. Updated to "%s", read back "%s".',
                    $i,
                    $newDescription ?? 'null',
                    $readBack['description'] ?? 'null'
                )
            );

            // Verify round-trip: permissions match
            $readPermissionIds = array_map('intval', array_column($readBack['permissions'], 'id'));
            $expectedIds = array_map('intval', $newPermissionIds);
            sort($readPermissionIds);
            sort($expectedIds);

            $this->assertSame(
                $expectedIds,
                $readPermissionIds,
                sprintf(
                    'Iteration %d: Updated permission IDs round-trip failed. Updated with [%s], read back [%s].',
                    $i,
                    implode(',', $expectedIds),
                    implode(',', $readPermissionIds)
                )
            );
        }
    }

    // ── Helper Methods ────────────────────────────────────────────────────

    private function buildServiceWithDeps(RoleRepository $repo, AuditService $audit): RoleService
    {
        $pdo = $this->createMock(\PDO::class);

        $ref = new ReflectionClass(RoleService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $repoProp = $ref->getProperty('repo');
        $repoProp->setAccessible(true);
        $repoProp->setValue($service, $repo);

        $auditProp = $ref->getProperty('audit');
        $auditProp->setAccessible(true);
        $auditProp->setValue($service, $audit);

        return $service;
    }
}
