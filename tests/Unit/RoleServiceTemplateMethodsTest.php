<?php

namespace Tests\Unit;

use App\Repository\RoleRepository;
use App\Services\AuditService;
use App\Services\RoleService;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for RoleService template CRUD methods:
 * createTemplate, updateTemplate, deleteTemplate.
 *
 * Validates: Requirements 5.1, 5.2, 5.3
 */
class RoleServiceTemplateMethodsTest extends TestCase
{
    // ─── createTemplate tests ─────────────────────────────────────────────

    /**
     * Test successful template creation with permissions.
     *
     * Validates: Requirements 5.1, 5.2
     */
    public function testCreateTemplateSuccessfully(): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        $repo->method('existsTemplateByNameAndTenant')->willReturn(false);
        $repo->method('findAllPermissions')->willReturn([
            ['id' => 1, 'code' => 'po.view', 'name' => 'po view'],
            ['id' => 2, 'code' => 'po.create', 'name' => 'po create'],
        ]);
        $repo->expects($this->once())
            ->method('insertWithType')
            ->with(10, null, 'Template Role', 'A template', 'TEMPLATE')
            ->willReturn(42);
        $repo->expects($this->once())
            ->method('syncPermissions')
            ->with(42, [1, 2]);
        $audit->expects($this->once())
            ->method('log')
            ->with('CREATE', 'roles', 42, 'create', null, $this->anything(), null, 10);

        $service = $this->buildServiceWithDeps($repo, $audit);

        $result = $service->createTemplate(10, 'Template Role', 'A template', [1, 2]);

        $this->assertSame(42, $result);
    }

    /**
     * Test createTemplate rejects name too short.
     *
     * Validates: Requirements 5.2
     */
    public function testCreateTemplateRejectsNameTooShort(): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        $service = $this->buildServiceWithDeps($repo, $audit);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Role name must be between 2 and 50 characters");

        $service->createTemplate(10, 'A', null, []);
    }

    /**
     * Test createTemplate rejects name too long.
     *
     * Validates: Requirements 5.2
     */
    public function testCreateTemplateRejectsNameTooLong(): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        $service = $this->buildServiceWithDeps($repo, $audit);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Role name must be between 2 and 50 characters");

        $service->createTemplate(10, str_repeat('X', 51), null, []);
    }

    /**
     * Test createTemplate rejects description too long.
     *
     * Validates: Requirements 5.2
     */
    public function testCreateTemplateRejectsDescriptionTooLong(): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        $service = $this->buildServiceWithDeps($repo, $audit);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Description must not exceed 255 characters");

        $service->createTemplate(10, 'Valid Name', str_repeat('D', 256), []);
    }

    /**
     * Test createTemplate rejects duplicate template name within tenant.
     *
     * Validates: Requirements 5.3
     */
    public function testCreateTemplateRejectsDuplicateName(): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        $repo->method('existsTemplateByNameAndTenant')->with('Existing', 10, null)->willReturn(true);

        $service = $this->buildServiceWithDeps($repo, $audit);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("A template role with this name already exists");

        $service->createTemplate(10, 'Existing', null, []);
    }

    /**
     * Test createTemplate rejects invalid permission IDs.
     *
     * Validates: Requirements 5.2
     */
    public function testCreateTemplateRejectsInvalidPermissionIds(): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        $repo->method('existsTemplateByNameAndTenant')->willReturn(false);
        $repo->method('findAllPermissions')->willReturn([
            ['id' => 1, 'code' => 'po.view', 'name' => 'po view'],
        ]);

        $service = $this->buildServiceWithDeps($repo, $audit);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid permission IDs provided");

        $service->createTemplate(10, 'Valid Name', null, [1, 999]);
    }

    /**
     * Test createTemplate trims name before validation.
     *
     * Validates: Requirements 5.2
     */
    public function testCreateTemplateTrimsName(): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        $repo->method('existsTemplateByNameAndTenant')->willReturn(false);
        $repo->expects($this->once())
            ->method('insertWithType')
            ->with(10, null, 'Trimmed', null, 'TEMPLATE')
            ->willReturn(1);
        $repo->expects($this->once())
            ->method('syncPermissions')
            ->with(1, []);
        $audit->expects($this->once())->method('log');

        $service = $this->buildServiceWithDeps($repo, $audit);

        $result = $service->createTemplate(10, '  Trimmed  ', null, []);

        $this->assertSame(1, $result);
    }

    // ─── updateTemplate tests ─────────────────────────────────────────────

    /**
     * Test successful template update.
     *
     * Validates: Requirements 5.1, 5.2
     */
    public function testUpdateTemplateSuccessfully(): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        $repo->method('findByIdAndTenant')->willReturn([
            'id' => 5, 'name' => 'Old Template', 'description' => 'Old desc'
        ]);
        $repo->method('findRoleType')->willReturn('TEMPLATE');
        $repo->method('existsTemplateByNameAndTenant')->willReturn(false);
        $repo->method('findAllPermissions')->willReturn([
            ['id' => 1, 'code' => 'po.view', 'name' => 'po view'],
            ['id' => 2, 'code' => 'po.create', 'name' => 'po create'],
        ]);
        $repo->method('findPermissionsByRoleId')->willReturn([
            ['id' => 1, 'code' => 'po.view', 'name' => 'po view'],
        ]);

        $repo->expects($this->once())
            ->method('updateRole')
            ->with(5, 'New Template', 'New desc');
        $repo->expects($this->once())
            ->method('syncPermissions')
            ->with(5, [1, 2]);
        $audit->expects($this->once())
            ->method('log')
            ->with('UPDATE', 'roles', 5, 'update', $this->anything(), $this->anything(), null, 10);

        $service = $this->buildServiceWithDeps($repo, $audit);

        $service->updateTemplate(5, 10, 'New Template', 'New desc', [1, 2]);

        $this->assertTrue(true);
    }

    /**
     * Test updateTemplate throws when role not found.
     *
     * Validates: Requirements 5.1
     */
    public function testUpdateTemplateThrowsWhenNotFound(): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        $repo->method('findByIdAndTenant')->willReturn(null);

        $service = $this->buildServiceWithDeps($repo, $audit);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Role not found");

        $service->updateTemplate(999, 10, 'Name', null, []);
    }

    /**
     * Test updateTemplate throws when role is not TEMPLATE type.
     *
     * Validates: Requirements 5.1
     */
    public function testUpdateTemplateThrowsWhenNotTemplateType(): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        $repo->method('findByIdAndTenant')->willReturn([
            'id' => 5, 'name' => 'Custom Role', 'description' => null
        ]);
        $repo->method('findRoleType')->willReturn('CUSTOM');

        $service = $this->buildServiceWithDeps($repo, $audit);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Only template roles can be modified/deleted");

        $service->updateTemplate(5, 10, 'Name', null, []);
    }

    /**
     * Test updateTemplate rejects duplicate template name.
     *
     * Validates: Requirements 5.3
     */
    public function testUpdateTemplateRejectsDuplicateName(): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        $repo->method('findByIdAndTenant')->willReturn([
            'id' => 5, 'name' => 'Old Name', 'description' => null
        ]);
        $repo->method('findRoleType')->willReturn('TEMPLATE');
        $repo->method('existsTemplateByNameAndTenant')->with('Duplicate', 10, 5)->willReturn(true);

        $service = $this->buildServiceWithDeps($repo, $audit);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("A template role with this name already exists");

        $service->updateTemplate(5, 10, 'Duplicate', null, []);
    }

    /**
     * Test updateTemplate rejects name too short.
     *
     * Validates: Requirements 5.2
     */
    public function testUpdateTemplateRejectsNameTooShort(): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        $repo->method('findByIdAndTenant')->willReturn([
            'id' => 5, 'name' => 'Old', 'description' => null
        ]);
        $repo->method('findRoleType')->willReturn('TEMPLATE');

        $service = $this->buildServiceWithDeps($repo, $audit);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Role name must be between 2 and 50 characters");

        $service->updateTemplate(5, 10, 'X', null, []);
    }

    /**
     * Test updateTemplate rejects invalid permission IDs.
     *
     * Validates: Requirements 5.2
     */
    public function testUpdateTemplateRejectsInvalidPermissionIds(): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        $repo->method('findByIdAndTenant')->willReturn([
            'id' => 5, 'name' => 'Template', 'description' => null
        ]);
        $repo->method('findRoleType')->willReturn('TEMPLATE');
        $repo->method('existsTemplateByNameAndTenant')->willReturn(false);
        $repo->method('findAllPermissions')->willReturn([
            ['id' => 1, 'code' => 'po.view', 'name' => 'po view'],
        ]);

        $service = $this->buildServiceWithDeps($repo, $audit);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid permission IDs provided");

        $service->updateTemplate(5, 10, 'Template', null, [1, 888]);
    }

    // ─── deleteTemplate tests ─────────────────────────────────────────────

    /**
     * Test successful template deletion.
     *
     * Validates: Requirements 5.1
     */
    public function testDeleteTemplateSuccessfully(): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        $repo->method('findByIdAndTenant')->willReturn([
            'id' => 5, 'name' => 'Template', 'description' => 'Desc'
        ]);
        $repo->method('findRoleType')->willReturn('TEMPLATE');

        $repo->expects($this->once())
            ->method('deleteRole')
            ->with(5);
        $audit->expects($this->once())
            ->method('log')
            ->with(
                'DELETE',
                'roles',
                5,
                'delete',
                ['name' => 'Template', 'description' => 'Desc'],
                null,
                null,
                10
            );

        $service = $this->buildServiceWithDeps($repo, $audit);

        $service->deleteTemplate(5, 10);

        $this->assertTrue(true);
    }

    /**
     * Test deleteTemplate throws when role not found.
     *
     * Validates: Requirements 5.1
     */
    public function testDeleteTemplateThrowsWhenNotFound(): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        $repo->method('findByIdAndTenant')->willReturn(null);

        $service = $this->buildServiceWithDeps($repo, $audit);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Role not found");

        $service->deleteTemplate(999, 10);
    }

    /**
     * Test deleteTemplate throws when role is not TEMPLATE type.
     *
     * Validates: Requirements 5.1
     */
    public function testDeleteTemplateThrowsWhenNotTemplateType(): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        $repo->method('findByIdAndTenant')->willReturn([
            'id' => 5, 'name' => 'Custom Role', 'description' => null
        ]);
        $repo->method('findRoleType')->willReturn('CUSTOM');

        $service = $this->buildServiceWithDeps($repo, $audit);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Only template roles can be modified/deleted");

        $service->deleteTemplate(5, 10);
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
