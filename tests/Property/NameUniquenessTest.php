<?php

namespace Tests\Property;

use App\Repository\RoleRepository;
use App\Services\AuditService;
use App\Services\RoleService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Feature: business-specific-roles, Property 6: Role name uniqueness within business
 *
 * For any business_id and any role name string, at most one CUSTOM or SYSTEM_OWNER role
 * with that name may exist within that business. Attempting to create a duplicate must be
 * rejected with the error "A role with this name already exists in this business".
 *
 * **Validates: Requirements 1.5, 4.3**
 */
class NameUniquenessTest extends TestCase
{
    private const ITERATIONS = 100;

    /**
     * Generate a random valid role name (trimmed length between 2 and 50 characters).
     * Uses printable ASCII characters (non-space) to ensure trimming doesn't reduce length.
     */
    private static function generateValidName(): string
    {
        $length = mt_rand(2, 50);
        $name = '';
        for ($i = 0; $i < $length; $i++) {
            // Use non-space printable ASCII (33-126) so trim doesn't affect length
            $name .= chr(mt_rand(33, 126));
        }
        return $name;
    }

    /**
     * Generate a random business ID.
     */
    private static function generateBusinessId(): int
    {
        return mt_rand(1, 10000);
    }

    /**
     * Generate a random tenant ID.
     */
    private static function generateTenantId(): int
    {
        return mt_rand(1, 10000);
    }

    /**
     * Data provider generating 100 random valid names and business IDs for create uniqueness testing.
     */
    public static function createUniquenessProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $cases["create_iter_{$i}"] = [
                self::generateValidName(),
                self::generateBusinessId(),
                self::generateTenantId(),
            ];
        }
        return $cases;
    }

    /**
     * Data provider generating 100 random valid names and business IDs for update uniqueness testing.
     */
    public static function updateUniquenessProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $cases["update_iter_{$i}"] = [
                self::generateValidName(),
                self::generateBusinessId(),
                mt_rand(2, 10000), // role ID (not 1, to avoid SYSTEM_OWNER protection)
            ];
        }
        return $cases;
    }

    /**
     * Property 6: Role name uniqueness within business — Create rejection
     *
     * For any valid role name and business ID, if a role with the same name already exists
     * in that business (existsByNameAndBusiness returns true), the create call SHALL throw
     * InvalidArgumentException with message "A role with this name already exists in this business".
     *
     * **Validates: Requirements 4.3**
     *
     * @dataProvider createUniquenessProvider
     */
    public function testCreateRejectsDuplicateNameWithinBusiness(string $name, int $businessId, int $tenantId): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        // Mock existsByNameAndBusiness to return true (simulating an existing role with same name)
        $repo->method('existsByNameAndBusiness')->willReturn(true);

        // The repository insert and syncPermissions should NEVER be called when duplicate detected
        $repo->expects($this->never())->method('insertWithType');
        $repo->expects($this->never())->method('syncPermissions');
        $audit->expects($this->never())->method('log');

        $service = $this->buildServiceWithDeps($repo, $audit);

        $exceptionThrown = false;
        $exceptionMessage = '';

        try {
            $service->create($businessId, $tenantId, $name, null, []);
        } catch (InvalidArgumentException $e) {
            $exceptionThrown = true;
            $exceptionMessage = $e->getMessage();
        }

        $this->assertTrue(
            $exceptionThrown,
            sprintf(
                'Expected InvalidArgumentException for duplicate name "%s" in business %d but none was thrown',
                mb_substr($name, 0, 30),
                $businessId
            )
        );

        $this->assertSame(
            'A role with this name already exists in this business',
            $exceptionMessage,
            sprintf(
                'Expected duplicate name error message but got "%s" for name "%s" in business %d',
                $exceptionMessage,
                mb_substr($name, 0, 30),
                $businessId
            )
        );
    }

    /**
     * Property 6: Role name uniqueness within business — Update rejection
     *
     * For any valid role name, business ID, and role ID (not SYSTEM_OWNER), if a role with
     * the same name already exists in that business (existsByNameAndBusiness returns true excluding
     * current ID), the update call SHALL throw InvalidArgumentException with message
     * "A role with this name already exists in this business".
     *
     * **Validates: Requirements 4.3**
     *
     * @dataProvider updateUniquenessProvider
     */
    public function testUpdateRejectsDuplicateNameWithinBusiness(string $name, int $businessId, int $roleId): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        // Mock findByIdAndBusiness to return the role (it belongs to this business)
        $repo->method('findByIdAndBusiness')
            ->with($roleId, $businessId)
            ->willReturn([
                'id' => $roleId,
                'name' => 'Old Name',
                'description' => null,
                'type' => 'CUSTOM',
            ]);

        // Mock findRoleType to return CUSTOM
        $repo->method('findRoleType')
            ->with($roleId)
            ->willReturn('CUSTOM');

        // Mock existsByNameAndBusiness to return true (simulating another role with same name)
        $repo->method('existsByNameAndBusiness')->willReturn(true);

        // The repository updateRole and syncPermissions should NEVER be called when duplicate detected
        $repo->expects($this->never())->method('updateRole');
        $repo->expects($this->never())->method('syncPermissions');
        $audit->expects($this->never())->method('log');

        $service = $this->buildServiceWithDeps($repo, $audit);

        $exceptionThrown = false;
        $exceptionMessage = '';

        try {
            $service->update($roleId, $businessId, $name, null, []);
        } catch (InvalidArgumentException $e) {
            $exceptionThrown = true;
            $exceptionMessage = $e->getMessage();
        }

        $this->assertTrue(
            $exceptionThrown,
            sprintf(
                'Expected InvalidArgumentException for duplicate name "%s" in business %d (role %d) but none was thrown',
                mb_substr($name, 0, 30),
                $businessId,
                $roleId
            )
        );

        $this->assertSame(
            'A role with this name already exists in this business',
            $exceptionMessage,
            sprintf(
                'Expected duplicate name error message but got "%s" for name "%s" in business %d (role %d)',
                $exceptionMessage,
                mb_substr($name, 0, 30),
                $businessId,
                $roleId
            )
        );
    }

    // ── Helper Methods ────────────────────────────────────────────────────

    private function buildServiceWithDeps(RoleRepository $repo, AuditService $audit): RoleService
    {
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
