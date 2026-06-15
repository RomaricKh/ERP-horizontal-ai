<?php

namespace Tests\Property;

use App\Repository\RoleRepository;
use App\Services\AuditService;
use App\Services\RoleService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Feature: business-specific-roles, Property 10: Deletion blocked when users assigned
 *
 * For any CUSTOM role that has one or more users assigned via user_roles, deletion must
 * be rejected with a message indicating the count of assigned users.
 *
 * **Validates: Requirements 4.5**
 */
class InUseDeletionProtectionTest extends TestCase
{
    private const ITERATIONS = 100;

    /**
     * Generate a random role ID (not 1, to avoid SYSTEM_OWNER protection).
     */
    private function generateRoleId(): int
    {
        return mt_rand(2, 10000);
    }

    /**
     * Generate a random business ID.
     */
    private function generateBusinessId(): int
    {
        return mt_rand(1, 10000);
    }

    /**
     * Generate a random user count (1-100) representing users assigned to the role.
     */
    private function generateUserCount(): int
    {
        return mt_rand(1, 100);
    }

    /**
     * Property 10: Deletion blocked when users assigned — Rejection with correct user count
     *
     * For any random role ID (not 1) assigned to N random users (N > 0),
     * attempting to delete that role SHALL throw an InvalidArgumentException
     * with message "Cannot delete role: assigned to {N} user(s)".
     *
     * **Validates: Requirements 4.5**
     */
    public function testDeletionRejectedWhenRoleIsInUse(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $roleId = $this->generateRoleId();
            $businessId = $this->generateBusinessId();
            $userCount = $this->generateUserCount();

            $repo = $this->createMock(RoleRepository::class);
            $audit = $this->createMock(AuditService::class);

            // Mock findByIdAndBusiness to return the role (it belongs to this business)
            $repo->method('findByIdAndBusiness')
                ->with($roleId, $businessId)
                ->willReturn([
                    'id' => $roleId,
                    'name' => 'Test Role',
                    'description' => null,
                    'type' => 'CUSTOM',
                ]);

            // Mock findRoleType to return CUSTOM
            $repo->method('findRoleType')
                ->with($roleId)
                ->willReturn('CUSTOM');

            // Mock countUsersWithRoleInBusiness to return the generated user count (> 0)
            $repo->method('countUsersWithRoleInBusiness')
                ->with($roleId, $businessId)
                ->willReturn($userCount);

            // deleteRole should NEVER be called when the role is in use
            $repo->expects($this->never())
                ->method('deleteRole');

            // Audit log should NEVER be called when deletion is rejected
            $audit->expects($this->never())
                ->method('log');

            $service = $this->buildServiceWithDeps($repo, $audit);

            $exceptionThrown = false;
            $exceptionMessage = '';

            try {
                $service->delete($roleId, $businessId);
            } catch (InvalidArgumentException $e) {
                $exceptionThrown = true;
                $exceptionMessage = $e->getMessage();
            } catch (\Exception $e) {
                $this->fail(sprintf(
                    'Iteration %d: Expected InvalidArgumentException but got %s: "%s" ' .
                    'for role ID %d with %d user(s) assigned.',
                    $i,
                    get_class($e),
                    $e->getMessage(),
                    $roleId,
                    $userCount
                ));
            }

            $this->assertTrue(
                $exceptionThrown,
                sprintf(
                    'Iteration %d: Expected InvalidArgumentException for in-use role deletion ' .
                    'but none was thrown. Role ID=%d, Business=%d, Users=%d',
                    $i,
                    $roleId,
                    $businessId,
                    $userCount
                )
            );

            $expectedMessage = "Cannot delete role: assigned to {$userCount} user(s)";
            $this->assertSame(
                $expectedMessage,
                $exceptionMessage,
                sprintf(
                    'Iteration %d: Expected error message "%s" but got "%s". ' .
                    'Role ID=%d, Users=%d',
                    $i,
                    $expectedMessage,
                    $exceptionMessage,
                    $roleId,
                    $userCount
                )
            );
        }
    }

    /**
     * Property 10: Deletion blocked when users assigned — Role is preserved (not deleted)
     *
     * For any random role ID (not 1) assigned to N random users (N > 0),
     * after the failed deletion attempt, the role SHALL still exist (deleteRole
     * is never invoked, preserving the role in the database).
     *
     * **Validates: Requirements 4.5**
     */
    public function testRoleIsPreservedWhenDeletionIsRejected(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $roleId = $this->generateRoleId();
            $businessId = $this->generateBusinessId();
            $userCount = $this->generateUserCount();

            $repo = $this->createMock(RoleRepository::class);
            $audit = $this->createMock(AuditService::class);

            // Mock findByIdAndBusiness to return the role (it belongs to this business)
            $repo->method('findByIdAndBusiness')
                ->with($roleId, $businessId)
                ->willReturn([
                    'id' => $roleId,
                    'name' => 'Test Role',
                    'description' => null,
                    'type' => 'CUSTOM',
                ]);

            // Mock findRoleType to return CUSTOM
            $repo->method('findRoleType')
                ->with($roleId)
                ->willReturn('CUSTOM');

            // Mock countUsersWithRoleInBusiness to return the generated user count (> 0)
            $repo->method('countUsersWithRoleInBusiness')
                ->with($roleId, $businessId)
                ->willReturn($userCount);

            // deleteRole should NEVER be called — the role must be preserved
            $repo->expects($this->never())
                ->method('deleteRole');

            $service = $this->buildServiceWithDeps($repo, $audit);

            try {
                $service->delete($roleId, $businessId);
            } catch (InvalidArgumentException $e) {
                // Expected — role is in use, deletion rejected
            }

            // If we reach here without the mock expectation failing,
            // it confirms deleteRole was never called and the role is preserved.
            $this->assertTrue(
                true,
                sprintf(
                    'Iteration %d: deleteRole should never be called for in-use role. ' .
                    'Role ID=%d, Users=%d',
                    $i,
                    $roleId,
                    $userCount
                )
            );
        }
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
