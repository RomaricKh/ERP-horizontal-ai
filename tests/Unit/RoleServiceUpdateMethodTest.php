<?php

namespace Tests\Unit;

use App\Repository\RoleRepository;
use App\Services\AuditService;
use App\Services\RoleService;
use InvalidArgumentException;
use RuntimeException;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for RoleService::update method.
 *
 * Validates: Requirements 2.2, 4.1, 4.3, 4.4, 4.7
 */
class RoleServiceUpdateMethodTest extends TestCase
{
    /**
     * Test successful role update with permissions.
     *
     * Validates: Requirements 4.4
     */
    public function testUpdateSuccessfullyWithPermissions(): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        $repo->method('findByIdAndBusiness')->with(5, 10)->willReturn([
            'id' => 5, 'name' => 'Old Name', 'description' => 'Old desc'
        ]);
        $repo->method('findRoleType')->with(5)->willReturn('CUSTOM');
        $repo->method('existsByNameAndBusiness')->willReturn(false);
        $repo->method('findAllPermissions')->willReturn([
            ['id' => 1, 'code' => 'po.view', 'name' => 'po view'],
            ['id' => 2, 'code' => 'po.create', 'name' => 'po create'],
        ]);
        $repo->method('findPermissionsByRoleId')->willReturn([
            ['id' => 1, 'code' => 'po.view', 'name' => 'po view'],
        ]);

        $repo->expects($this->once())
            ->method('updateRole')
            ->with(5, 'Updated Role', 'New description');
        $repo->expects($this->once())
            ->method('syncPermissions')
            ->with(5, [1, 2]);
        $audit->expects($this->once())
            ->method('log')
            ->with('UPDATE', 'roles', 5, 'update', $this->anything(), $this->anything(), null, null);

        $service = $this->buildServiceWithDeps($repo, $audit);

        $service->update(5, 10, 'Updated Role', 'New description', [1, 2]);

        // No exception means success; void return
        $this->assertTrue(true);
    }

    /**
     * Test SYSTEM_OWNER role protection rejects update.
     *
     * Validates: Requirements 2.2
     */
    public function testUpdateRejectsSystemOwnerRole(): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        $repo->method('findByIdAndBusiness')->with(1, 10)->willReturn([
            'id' => 1, 'name' => 'Business Owner', 'description' => 'Full access'
        ]);
        $repo->method('findRoleType')->with(1)->willReturn('SYSTEM_OWNER');

        $service = $this->buildServiceWithDeps($repo, $audit);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Cannot modify the system owner role");

        $service->update(1, 10, 'Hacked Name', null, []);
    }

    /**
     * Test name too short throws exception.
     *
     * Validates: Requirements 4.1
     */
    public function testUpdateRejectsNameTooShort(): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        $repo->method('findByIdAndBusiness')->with(5, 10)->willReturn([
            'id' => 5, 'name' => 'Old Name', 'description' => null
        ]);
        $repo->method('findRoleType')->with(5)->willReturn('CUSTOM');

        $service = $this->buildServiceWithDeps($repo, $audit);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Role name must be between 2 and 50 characters");

        $service->update(5, 10, 'A', null, []);
    }

    /**
     * Test name too long throws exception.
     *
     * Validates: Requirements 4.1
     */
    public function testUpdateRejectsNameTooLong(): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        $repo->method('findByIdAndBusiness')->with(5, 10)->willReturn([
            'id' => 5, 'name' => 'Old Name', 'description' => null
        ]);
        $repo->method('findRoleType')->with(5)->willReturn('CUSTOM');

        $service = $this->buildServiceWithDeps($repo, $audit);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Role name must be between 2 and 50 characters");

        $longName = str_repeat('A', 51);
        $service->update(5, 10, $longName, null, []);
    }

    /**
     * Test description too long throws exception.
     *
     * Validates: Requirements 4.1
     */
    public function testUpdateRejectsDescriptionTooLong(): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        $repo->method('findByIdAndBusiness')->with(5, 10)->willReturn([
            'id' => 5, 'name' => 'Old Name', 'description' => null
        ]);
        $repo->method('findRoleType')->with(5)->willReturn('CUSTOM');

        $service = $this->buildServiceWithDeps($repo, $audit);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Description must not exceed 255 characters");

        $longDesc = str_repeat('B', 256);
        $service->update(5, 10, 'Valid Name', $longDesc, []);
    }

    /**
     * Test duplicate name (excluding current ID) throws exception.
     *
     * Validates: Requirements 4.3
     */
    public function testUpdateRejectsDuplicateName(): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        $repo->method('findByIdAndBusiness')->with(5, 10)->willReturn([
            'id' => 5, 'name' => 'Old Name', 'description' => null
        ]);
        $repo->method('findRoleType')->with(5)->willReturn('CUSTOM');
        $repo->method('existsByNameAndBusiness')->with('Existing Role', 10, 5)->willReturn(true);

        $service = $this->buildServiceWithDeps($repo, $audit);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("A role with this name already exists in this business");

        $service->update(5, 10, 'Existing Role', null, []);
    }

    /**
     * Test invalid permission IDs throws exception.
     *
     * Validates: Requirements 4.7
     */
    public function testUpdateRejectsInvalidPermissionIds(): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        $repo->method('findByIdAndBusiness')->with(5, 10)->willReturn([
            'id' => 5, 'name' => 'Old Name', 'description' => null
        ]);
        $repo->method('findRoleType')->with(5)->willReturn('CUSTOM');
        $repo->method('existsByNameAndBusiness')->willReturn(false);
        $repo->method('findAllPermissions')->willReturn([
            ['id' => 1, 'code' => 'po.view', 'name' => 'po view'],
            ['id' => 2, 'code' => 'po.create', 'name' => 'po create'],
        ]);

        $service = $this->buildServiceWithDeps($repo, $audit);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid permission IDs provided");

        $service->update(5, 10, 'Valid Name', null, [1, 999]);
    }

    /**
     * Test name is trimmed before validation and storage.
     *
     * Validates: Requirements 4.1
     */
    public function testUpdateTrimsNameBeforeValidation(): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        $repo->method('findByIdAndBusiness')->with(5, 10)->willReturn([
            'id' => 5, 'name' => 'Old Name', 'description' => null
        ]);
        $repo->method('findRoleType')->with(5)->willReturn('CUSTOM');
        $repo->method('existsByNameAndBusiness')->willReturn(false);
        $repo->method('findPermissionsByRoleId')->willReturn([]);

        $repo->expects($this->once())
            ->method('updateRole')
            ->with(5, 'Trimmed', null);
        $repo->expects($this->once())
            ->method('syncPermissions')
            ->with(5, []);
        $audit->expects($this->once())->method('log');

        $service = $this->buildServiceWithDeps($repo, $audit);

        $service->update(5, 10, '  Trimmed  ', null, []);

        $this->assertTrue(true);
    }

    /**
     * Test update with zero permissions succeeds.
     *
     * Validates: Requirements 4.4
     */
    public function testUpdateSuccessfullyWithZeroPermissions(): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        $repo->method('findByIdAndBusiness')->with(5, 10)->willReturn([
            'id' => 5, 'name' => 'Old Name', 'description' => 'Old desc'
        ]);
        $repo->method('findRoleType')->with(5)->willReturn('CUSTOM');
        $repo->method('existsByNameAndBusiness')->willReturn(false);
        $repo->method('findPermissionsByRoleId')->willReturn([
            ['id' => 1, 'code' => 'po.view', 'name' => 'po view'],
        ]);

        $repo->expects($this->once())
            ->method('updateRole')
            ->with(5, 'Updated', null);
        $repo->expects($this->once())
            ->method('syncPermissions')
            ->with(5, []);
        $audit->expects($this->once())->method('log');

        $service = $this->buildServiceWithDeps($repo, $audit);

        $service->update(5, 10, 'Updated', null, []);

        $this->assertTrue(true);
    }

    /**
     * Test audit log captures old and new data correctly.
     */
    public function testUpdateLogsOldAndNewData(): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        $repo->method('findByIdAndBusiness')->with(5, 10)->willReturn([
            'id' => 5, 'name' => 'Old Name', 'description' => 'Old desc'
        ]);
        $repo->method('findRoleType')->with(5)->willReturn('CUSTOM');
        $repo->method('existsByNameAndBusiness')->willReturn(false);
        $repo->method('findPermissionsByRoleId')->willReturn([
            ['id' => 1, 'code' => 'po.view', 'name' => 'po view'],
        ]);
        $repo->method('findAllPermissions')->willReturn([
            ['id' => 1, 'code' => 'po.view', 'name' => 'po view'],
            ['id' => 2, 'code' => 'po.create', 'name' => 'po create'],
        ]);

        $expectedOldData = [
            'name' => 'Old Name',
            'description' => 'Old desc',
            'permission_ids' => [1],
        ];
        $expectedNewData = [
            'name' => 'New Name',
            'description' => 'New desc',
            'permission_ids' => [1, 2],
        ];

        $audit->expects($this->once())
            ->method('log')
            ->with(
                'UPDATE',
                'roles',
                5,
                'update',
                $expectedOldData,
                $expectedNewData,
                null,
                null
            );

        $service = $this->buildServiceWithDeps($repo, $audit);

        $service->update(5, 10, 'New Name', 'New desc', [1, 2]);
    }

    /**
     * Test update returns "Role not found" when role doesn't belong to business.
     *
     * Validates: Requirements 6.2, 6.3
     */
    public function testUpdateReturnsRoleNotFoundForDifferentBusiness(): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        $repo->method('findByIdAndBusiness')->with(5, 99)->willReturn(null);

        $service = $this->buildServiceWithDeps($repo, $audit);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Role not found");

        $service->update(5, 99, 'Name', null, []);
    }

    // ── Helper Methods ────────────────────────────────────────────────────

    private function buildServiceWithDeps(RoleRepository $repo, AuditService $audit): RoleService
    {
        $pdo = $this->createMock(PDO::class);

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
