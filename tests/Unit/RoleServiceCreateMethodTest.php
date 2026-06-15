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
 * Unit tests for RoleService::create method.
 *
 * Validates: Requirements 4.1, 4.2, 4.3, 4.7
 */
class RoleServiceCreateMethodTest extends TestCase
{
    /**
     * Test successful role creation with permissions.
     *
     * Validates: Requirements 4.1, 4.7
     */
    public function testCreateSuccessfullyWithPermissions(): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        $repo->method('existsByNameAndBusiness')->willReturn(false);
        $repo->method('findAllPermissions')->willReturn([
            ['id' => 1, 'code' => 'po.view', 'name' => 'po view'],
            ['id' => 2, 'code' => 'po.create', 'name' => 'po create'],
        ]);
        $repo->expects($this->once())
            ->method('insertWithType')
            ->with(1, 5, 'Sales Rep', 'Handles sales', 'CUSTOM')
            ->willReturn(10);
        $repo->expects($this->once())
            ->method('syncPermissions')
            ->with(10, [1, 2]);
        $audit->expects($this->once())
            ->method('log')
            ->with('CREATE', 'roles', 10, 'create', null, $this->anything(), null, 1);

        $service = $this->buildServiceWithDeps($repo, $audit);

        $result = $service->create(5, 1, 'Sales Rep', 'Handles sales', [1, 2]);

        $this->assertSame(10, $result);
    }

    /**
     * Test successful role creation with zero permissions.
     *
     * Validates: Requirements 4.1
     */
    public function testCreateSuccessfullyWithZeroPermissions(): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        $repo->method('existsByNameAndBusiness')->willReturn(false);
        $repo->expects($this->once())
            ->method('insertWithType')
            ->with(1, 5, 'Empty Role', null, 'CUSTOM')
            ->willReturn(11);
        $repo->expects($this->once())
            ->method('syncPermissions')
            ->with(11, []);
        $audit->expects($this->once())->method('log');

        $service = $this->buildServiceWithDeps($repo, $audit);

        $result = $service->create(5, 1, 'Empty Role', null, []);

        $this->assertSame(11, $result);
    }

    /**
     * Test name too short throws exception.
     *
     * Validates: Requirements 4.1
     */
    public function testCreateRejectsNameTooShort(): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        $service = $this->buildServiceWithDeps($repo, $audit);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Role name must be between 2 and 50 characters");

        $service->create(5, 1, 'A', null, []);
    }

    /**
     * Test name too long throws exception.
     *
     * Validates: Requirements 4.1
     */
    public function testCreateRejectsNameTooLong(): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        $service = $this->buildServiceWithDeps($repo, $audit);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Role name must be between 2 and 50 characters");

        $longName = str_repeat('A', 51);
        $service->create(5, 1, $longName, null, []);
    }

    /**
     * Test name with only whitespace is rejected (trimmed length < 2).
     *
     * Validates: Requirements 4.1
     */
    public function testCreateRejectsWhitespaceOnlyName(): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        $service = $this->buildServiceWithDeps($repo, $audit);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Role name must be between 2 and 50 characters");

        $service->create(5, 1, '   ', null, []);
    }

    /**
     * Test description too long throws exception.
     *
     * Validates: Requirements 4.1
     */
    public function testCreateRejectsDescriptionTooLong(): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        $service = $this->buildServiceWithDeps($repo, $audit);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Description must not exceed 255 characters");

        $longDesc = str_repeat('B', 256);
        $service->create(5, 1, 'Valid Name', $longDesc, []);
    }

    /**
     * Test duplicate name throws exception.
     *
     * Validates: Requirements 4.3
     */
    public function testCreateRejectsDuplicateName(): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        $repo->method('existsByNameAndBusiness')->with('Existing Role', 5, null)->willReturn(true);

        $service = $this->buildServiceWithDeps($repo, $audit);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("A role with this name already exists in this business");

        $service->create(5, 1, 'Existing Role', null, []);
    }

    /**
     * Test invalid permission IDs throws exception.
     *
     * Validates: Requirements 4.7
     */
    public function testCreateRejectsInvalidPermissionIds(): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        $repo->method('existsByNameAndBusiness')->willReturn(false);
        $repo->method('findAllPermissions')->willReturn([
            ['id' => 1, 'code' => 'po.view', 'name' => 'po view'],
            ['id' => 2, 'code' => 'po.create', 'name' => 'po create'],
        ]);

        $service = $this->buildServiceWithDeps($repo, $audit);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid permission IDs provided");

        $service->create(5, 1, 'New Role', null, [1, 999]);
    }

    /**
     * Test name is trimmed before validation and storage.
     *
     * Validates: Requirements 4.1
     */
    public function testCreateTrimsNameBeforeValidation(): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        $repo->method('existsByNameAndBusiness')->willReturn(false);
        $repo->expects($this->once())
            ->method('insertWithType')
            ->with(1, 5, 'Trimmed', null, 'CUSTOM')
            ->willReturn(12);
        $repo->expects($this->once())
            ->method('syncPermissions')
            ->with(12, []);
        $audit->expects($this->once())->method('log');

        $service = $this->buildServiceWithDeps($repo, $audit);

        $result = $service->create(5, 1, '  Trimmed  ', null, []);

        $this->assertSame(12, $result);
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
