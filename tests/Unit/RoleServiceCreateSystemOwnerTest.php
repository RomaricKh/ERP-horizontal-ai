<?php

namespace Tests\Unit;

use App\Repository\RoleRepository;
use App\Services\RoleService;
use RuntimeException;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for RoleService::createSystemOwnerRole method.
 *
 * Validates: Requirements 2.4, 2.5, 3.1, 3.4
 */
class RoleServiceCreateSystemOwnerTest extends TestCase
{
    /**
     * Test createSystemOwnerRole succeeds when no SYSTEM_OWNER exists for the business.
     *
     * Validates: Requirements 2.4, 3.1, 3.4
     */
    public function testCreateSystemOwnerRoleSucceeds(): void
    {
        $repo = $this->createMock(RoleRepository::class);

        $repo->method('countSystemOwnerRoles')
            ->with(10)
            ->willReturn(0);

        $repo->expects($this->once())
            ->method('insertWithType')
            ->with(1, 10, 'Business Owner', null, 'SYSTEM_OWNER')
            ->willReturn(42);

        $service = $this->buildServiceWithRepo($repo);

        $result = $service->createSystemOwnerRole(10, 1);

        $this->assertSame(42, $result);
    }

    /**
     * Test createSystemOwnerRole throws RuntimeException when SYSTEM_OWNER already exists.
     *
     * Validates: Requirements 2.5
     */
    public function testCreateSystemOwnerRoleThrowsWhenAlreadyExists(): void
    {
        $repo = $this->createMock(RoleRepository::class);

        $repo->method('countSystemOwnerRoles')
            ->with(10)
            ->willReturn(1);

        $repo->expects($this->never())
            ->method('insertWithType');

        $service = $this->buildServiceWithRepo($repo);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("A SYSTEM_OWNER role already exists for this business");

        $service->createSystemOwnerRole(10, 1);
    }

    // ── Helper Methods ────────────────────────────────────────────────────

    private function buildServiceWithRepo(RoleRepository $repo): RoleService
    {
        $ref = new ReflectionClass(RoleService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $prop = $ref->getProperty('repo');
        $prop->setAccessible(true);
        $prop->setValue($service, $repo);

        return $service;
    }
}
