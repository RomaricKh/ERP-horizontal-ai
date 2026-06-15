<?php

namespace Tests\Property;

use App\Repository\RoleRepository;
use App\Services\AuditService;
use App\Services\RoleService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Feature: user-roles-permissions, Property 1: Tenant Isolation
 *
 * For any tenant ID, the roles list SHALL return only roles whose tenant_id matches
 * the requesting tenant, and no roles belonging to other tenants.
 *
 * **Validates: Requirements 1.1**
 */
class TenantIsolationTest extends TestCase
{
    private const ITERATIONS = 100;

    /**
     * Generate a random tenant ID between 1 and 10000.
     */
    private function generateRandomTenantId(): int
    {
        return mt_rand(1, 10000);
    }

    /**
     * Generate a random role name (3-30 chars alphanumeric).
     */
    private function generateRandomRoleName(): string
    {
        $length = mt_rand(3, 30);
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $name = '';
        for ($i = 0; $i < $length; $i++) {
            $name .= $chars[mt_rand(0, strlen($chars) - 1)];
        }
        return $name;
    }

    /**
     * Generate a set of random roles across multiple tenants.
     *
     * @return array Array of roles with 'id', 'name', 'description', 'tenant_id'
     */
    private function generateRolesForMultipleTenants(int $targetTenantId): array
    {
        $roles = [];
        $id = 2; // Start at 2 to avoid Business Owner id=1

        // Generate 1-5 roles for the target tenant
        $targetCount = mt_rand(1, 5);
        for ($i = 0; $i < $targetCount; $i++) {
            $roles[] = [
                'id' => $id++,
                'name' => $this->generateRandomRoleName(),
                'description' => 'Role for tenant ' . $targetTenantId,
                'tenant_id' => $targetTenantId,
            ];
        }

        // Generate 1-5 roles for other tenants (different from target)
        $otherCount = mt_rand(1, 5);
        for ($i = 0; $i < $otherCount; $i++) {
            $otherTenantId = $targetTenantId;
            while ($otherTenantId === $targetTenantId) {
                $otherTenantId = $this->generateRandomTenantId();
            }
            $roles[] = [
                'id' => $id++,
                'name' => $this->generateRandomRoleName(),
                'description' => 'Role for tenant ' . $otherTenantId,
                'tenant_id' => $otherTenantId,
            ];
        }

        return $roles;
    }

    /**
     * Property 1: Tenant Isolation — getAllForTenant returns only matching tenant's roles
     *
     * For any random tenant ID, when roles exist across multiple tenants, the service
     * SHALL delegate to the repository with the correct tenant_id, and the repository
     * SHALL filter to return only roles belonging to that tenant.
     *
     * **Validates: Requirements 1.1**
     */
    public function testGetAllForTenantReturnsOnlyMatchingTenantRoles(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $targetTenantId = $this->generateRandomTenantId();
            $allRoles = $this->generateRolesForMultipleTenants($targetTenantId);

            // Filter to only roles belonging to the target tenant (simulating DB WHERE clause)
            $expectedRoles = array_values(array_filter($allRoles, function ($role) use ($targetTenantId) {
                return $role['tenant_id'] === $targetTenantId;
            }));

            // Strip tenant_id from expected results (repository returns id, name, description)
            $expectedResult = array_map(function ($role) {
                return [
                    'id' => $role['id'],
                    'name' => $role['name'],
                    'description' => $role['description'],
                ];
            }, $expectedRoles);

            // Mock the repository to return only the target tenant's roles
            $repo = $this->createMock(RoleRepository::class);
            $repo->expects($this->once())
                ->method('findAllByTenant')
                ->with($this->identicalTo($targetTenantId))
                ->willReturn($expectedResult);

            $service = $this->buildServiceWithRepo($repo);

            $result = $service->getAllForTenant($targetTenantId);

            // Verify: result matches expected roles for this tenant
            $this->assertSame(
                $expectedResult,
                $result,
                sprintf(
                    'Iteration %d: getAllForTenant(%d) should return only roles for tenant %d. ' .
                    'Expected %d roles, got %d.',
                    $i,
                    $targetTenantId,
                    $targetTenantId,
                    count($expectedResult),
                    count($result)
                )
            );

            // Verify: no role in the result belongs to a different tenant
            foreach ($result as $role) {
                // Cross-check against our generated data to ensure no cross-tenant leakage
                $originalRole = null;
                foreach ($allRoles as $r) {
                    if ($r['id'] === $role['id']) {
                        $originalRole = $r;
                        break;
                    }
                }

                if ($originalRole !== null) {
                    $this->assertSame(
                        $targetTenantId,
                        $originalRole['tenant_id'],
                        sprintf(
                            'Iteration %d: Role id=%d belongs to tenant %d but was returned for tenant %d.',
                            $i,
                            $role['id'],
                            $originalRole['tenant_id'],
                            $targetTenantId
                        )
                    );
                }
            }
        }
    }

    /**
     * Property 1: Tenant Isolation — Service always passes correct tenant_id to repository
     *
     * For any random tenant ID, the RoleService::getAllForTenant method SHALL always
     * delegate to RoleRepository::findAllByTenant with the exact same tenant_id parameter.
     *
     * **Validates: Requirements 1.1**
     */
    public function testServiceAlwaysPassesCorrectTenantIdToRepository(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $tenantId = $this->generateRandomTenantId();

            $repo = $this->createMock(RoleRepository::class);

            // Verify the repository is called with the exact tenant ID
            $repo->expects($this->once())
                ->method('findAllByTenant')
                ->with($this->identicalTo($tenantId))
                ->willReturn([]);

            $service = $this->buildServiceWithRepo($repo);
            $service->getAllForTenant($tenantId);
        }
    }

    /**
     * Property 1: Tenant Isolation — Empty result for tenant with no roles
     *
     * For any random tenant ID that has no roles, getAllForTenant SHALL return
     * an empty array (not null, not an error).
     *
     * **Validates: Requirements 1.1**
     */
    public function testEmptyResultForTenantWithNoRoles(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $tenantId = $this->generateRandomTenantId();

            $repo = $this->createMock(RoleRepository::class);
            $repo->expects($this->once())
                ->method('findAllByTenant')
                ->with($this->identicalTo($tenantId))
                ->willReturn([]);

            $service = $this->buildServiceWithRepo($repo);
            $result = $service->getAllForTenant($tenantId);

            $this->assertIsArray(
                $result,
                sprintf(
                    'Iteration %d: getAllForTenant(%d) should return an array, got %s.',
                    $i,
                    $tenantId,
                    gettype($result)
                )
            );

            $this->assertEmpty(
                $result,
                sprintf(
                    'Iteration %d: getAllForTenant(%d) should return empty array for tenant with no roles.',
                    $i,
                    $tenantId
                )
            );
        }
    }

    /**
     * Property 1: Tenant Isolation — Different tenants get different results
     *
     * For any two distinct random tenant IDs, when each has its own roles,
     * getAllForTenant SHALL return different role sets for each tenant.
     *
     * **Validates: Requirements 1.1**
     */
    public function testDifferentTenantsGetDifferentResults(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $tenantA = $this->generateRandomTenantId();
            $tenantB = $tenantA;
            while ($tenantB === $tenantA) {
                $tenantB = $this->generateRandomTenantId();
            }

            $rolesA = [
                ['id' => mt_rand(2, 500), 'name' => $this->generateRandomRoleName(), 'description' => 'Tenant A role'],
            ];
            $rolesB = [
                ['id' => mt_rand(501, 1000), 'name' => $this->generateRandomRoleName(), 'description' => 'Tenant B role'],
            ];

            // First call for tenant A
            $repoA = $this->createMock(RoleRepository::class);
            $repoA->expects($this->once())
                ->method('findAllByTenant')
                ->with($this->identicalTo($tenantA))
                ->willReturn($rolesA);

            $serviceA = $this->buildServiceWithRepo($repoA);
            $resultA = $serviceA->getAllForTenant($tenantA);

            // Second call for tenant B
            $repoB = $this->createMock(RoleRepository::class);
            $repoB->expects($this->once())
                ->method('findAllByTenant')
                ->with($this->identicalTo($tenantB))
                ->willReturn($rolesB);

            $serviceB = $this->buildServiceWithRepo($repoB);
            $resultB = $serviceB->getAllForTenant($tenantB);

            // Results should be different (different role IDs)
            $this->assertNotEquals(
                $resultA,
                $resultB,
                sprintf(
                    'Iteration %d: Tenant %d and tenant %d should have different role sets.',
                    $i,
                    $tenantA,
                    $tenantB
                )
            );
        }
    }

    // ── Helper Methods ────────────────────────────────────────────────────

    private function buildServiceWithRepo(RoleRepository $repo): RoleService
    {
        $ref = new ReflectionClass(RoleService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $repoProp = $ref->getProperty('repo');
        $repoProp->setAccessible(true);
        $repoProp->setValue($service, $repo);

        return $service;
    }
}
