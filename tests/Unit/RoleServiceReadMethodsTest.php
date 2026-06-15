<?php

namespace Tests\Unit;

use App\Repository\RoleRepository;
use App\Services\RoleService;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for RoleService read methods (getById, getAllPermissions).
 *
 * Validates: Requirements 6.1, 6.2, 6.3
 */
class RoleServiceReadMethodsTest extends TestCase
{
    /**
     * Test getById returns role with permissions when role exists in the business.
     *
     * Validates: Requirements 6.2
     */
    public function testGetByIdReturnsRoleWithPermissions(): void
    {
        $role = ['id' => 5, 'name' => 'Accountant', 'description' => 'Finance access'];
        $permissions = [
            ['id' => 3, 'code' => 'expenses.view', 'name' => 'expenses view'],
            ['id' => 4, 'code' => 'expenses.create', 'name' => 'expenses create'],
        ];

        $repo = $this->createMock(RoleRepository::class);
        $repo->method('findByIdAndBusiness')->with(5, 10)->willReturn($role);
        $repo->method('findPermissionsByRoleId')->with(5)->willReturn($permissions);

        $service = $this->buildServiceWithRepo($repo);

        $result = $service->getById(5, 10);

        $this->assertNotNull($result);
        $this->assertSame('Accountant', $result['name']);
        $this->assertSame('Finance access', $result['description']);
        $this->assertArrayHasKey('permissions', $result);
        $this->assertCount(2, $result['permissions']);
        $this->assertSame('expenses.view', $result['permissions'][0]['code']);
    }

    /**
     * Test getById returns null when role does not exist in the business.
     *
     * Validates: Requirements 6.2, 6.3
     */
    public function testGetByIdReturnsNullWhenNotFound(): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $repo->method('findByIdAndBusiness')->with(999, 10)->willReturn(null);

        $service = $this->buildServiceWithRepo($repo);

        $result = $service->getById(999, 10);

        $this->assertNull($result);
    }

    /**
     * Test getById returns null when role belongs to different business.
     *
     * Validates: Requirements 6.2, 6.3
     */
    public function testGetByIdReturnsNullForDifferentBusiness(): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $repo->method('findByIdAndBusiness')->with(5, 20)->willReturn(null);

        $service = $this->buildServiceWithRepo($repo);

        $result = $service->getById(5, 20);

        $this->assertNull($result);
    }

    /**
     * Test getById for Business Owner (SYSTEM_OWNER) returns all permissions.
     *
     * Validates: Requirements 8.2, 8.3
     */
    public function testGetByIdBusinessOwnerReturnsAllPermissions(): void
    {
        $role = ['id' => 1, 'name' => 'Business Owner', 'description' => 'Full access'];
        $allPermissions = [
            ['id' => 1, 'code' => 'expenses.view', 'name' => 'expenses view'],
            ['id' => 2, 'code' => 'expenses.create', 'name' => 'expenses create'],
            ['id' => 3, 'code' => 'roles.view', 'name' => 'roles view'],
            ['id' => 4, 'code' => 'roles.create', 'name' => 'roles create'],
        ];

        $repo = $this->createMock(RoleRepository::class);
        $repo->method('findByIdAndBusiness')->with(1, 10)->willReturn($role);
        $repo->method('findPermissionsByRoleId')->with(1)->willReturn($allPermissions);

        $service = $this->buildServiceWithRepo($repo);

        $result = $service->getById(1, 10);

        $this->assertNotNull($result);
        $this->assertSame('Business Owner', $result['name']);
        $this->assertCount(4, $result['permissions']);
    }

    /**
     * Test getAllPermissions returns all permissions from repository.
     */
    public function testGetAllPermissionsReturnsAllPermissions(): void
    {
        $permissions = [
            ['id' => 1, 'code' => 'expenses.view', 'name' => 'expenses view'],
            ['id' => 2, 'code' => 'expenses.create', 'name' => 'expenses create'],
            ['id' => 3, 'code' => 'roles.view', 'name' => 'roles view'],
        ];

        $repo = $this->createMock(RoleRepository::class);
        $repo->method('findAllPermissions')->willReturn($permissions);

        $service = $this->buildServiceWithRepo($repo);

        $result = $service->getAllPermissions();

        $this->assertCount(3, $result);
        $this->assertSame('expenses.view', $result[0]['code']);
        $this->assertSame('roles.view', $result[2]['code']);
    }

    /**
     * Test getAllPermissions returns empty array when no permissions exist.
     */
    public function testGetAllPermissionsReturnsEmptyArrayWhenNone(): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $repo->method('findAllPermissions')->willReturn([]);

        $service = $this->buildServiceWithRepo($repo);

        $result = $service->getAllPermissions();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // ── Helper Methods ────────────────────────────────────────────────────

    private function buildServiceWithRepo(RoleRepository $repo): RoleService
    {
        $pdo = $this->createMock(PDO::class);

        $ref = new ReflectionClass(RoleService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $prop = $ref->getProperty('repo');
        $prop->setAccessible(true);
        $prop->setValue($service, $repo);

        return $service;
    }
}
