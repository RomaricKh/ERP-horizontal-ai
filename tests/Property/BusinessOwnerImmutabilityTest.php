<?php

namespace Tests\Property;

use App\Repository\RoleRepository;
use App\Services\AuditService;
use App\Services\RoleService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * Feature: business-specific-roles, Property 3: SYSTEM_OWNER Immutability
 *
 * For any update or delete request targeting a role with type SYSTEM_OWNER,
 * the Role_Service must reject the operation and return the appropriate error message,
 * leaving the role unchanged.
 *
 * **Validates: Requirements 2.2, 2.3**
 */
class BusinessOwnerImmutabilityTest extends TestCase
{
    private const ITERATIONS = 100;
    private const SYSTEM_OWNER_ROLE_ID = 1;

    /**
     * Generate a random string of given length using printable ASCII characters.
     */
    private function randomString(int $length): string
    {
        $chars = '';
        for ($i = 0; $i < $length; $i++) {
            $chars .= chr(mt_rand(32, 126));
        }
        return $chars;
    }

    /**
     * Data provider generating 100 random update payloads targeting SYSTEM_OWNER role.
     */
    public static function updatePayloadProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $businessId = mt_rand(1, 10000);
            // Generate random name (1-100 chars)
            $nameLength = mt_rand(1, 100);
            $name = '';
            for ($j = 0; $j < $nameLength; $j++) {
                $name .= chr(mt_rand(32, 126));
            }
            // Generate random description (0-500 chars)
            $descLength = mt_rand(0, 500);
            $description = $descLength > 0 ? '' : null;
            if ($descLength > 0) {
                for ($j = 0; $j < $descLength; $j++) {
                    $description .= chr(mt_rand(32, 126));
                }
            }
            // Generate random permission IDs (0-20 IDs)
            $permCount = mt_rand(0, 20);
            $permissionIds = [];
            for ($j = 0; $j < $permCount; $j++) {
                $permissionIds[] = mt_rand(1, 1000);
            }

            $cases["update_iter_{$i}"] = [
                $businessId,
                $name,
                $description,
                $permissionIds,
            ];
        }
        return $cases;
    }

    /**
     * Data provider generating 100 random delete payloads targeting SYSTEM_OWNER role.
     */
    public static function deletePayloadProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $businessId = mt_rand(1, 10000);
            $cases["delete_iter_{$i}"] = [$businessId];
        }
        return $cases;
    }

    /**
     * Property 3: SYSTEM_OWNER Immutability — Update rejection
     *
     * For any random update payload (business ID, name, description, permission IDs),
     * attempting to update the SYSTEM_OWNER role SHALL throw a RuntimeException
     * with message "Cannot modify the system owner role".
     *
     * **Validates: Requirements 2.2**
     *
     * @dataProvider updatePayloadProvider
     */
    public function testUpdateRejectsAnyPayloadForBusinessOwner(
        int $businessId,
        string $name,
        ?string $description,
        array $permissionIds
    ): void {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        // The role exists in the business (findByIdAndBusiness returns it)
        $repo->method('findByIdAndBusiness')
            ->with(self::SYSTEM_OWNER_ROLE_ID, $businessId)
            ->willReturn([
                'id' => self::SYSTEM_OWNER_ROLE_ID,
                'name' => 'Business Owner',
                'description' => 'Full access',
                'type' => 'SYSTEM_OWNER',
            ]);

        // findRoleType returns SYSTEM_OWNER
        $repo->method('findRoleType')
            ->with(self::SYSTEM_OWNER_ROLE_ID)
            ->willReturn('SYSTEM_OWNER');

        // The repository and audit service should NEVER be called for SYSTEM_OWNER updates
        $repo->expects($this->never())->method('updateRole');
        $repo->expects($this->never())->method('syncPermissions');
        $audit->expects($this->never())->method('log');

        $service = $this->buildServiceWithDeps($repo, $audit);

        $exceptionThrown = false;
        $exceptionMessage = '';

        try {
            $service->update(
                self::SYSTEM_OWNER_ROLE_ID,
                $businessId,
                $name,
                $description,
                $permissionIds
            );
        } catch (RuntimeException $e) {
            $exceptionThrown = true;
            $exceptionMessage = $e->getMessage();
        } catch (\Exception $e) {
            $this->fail(sprintf(
                'Expected RuntimeException but got %s: "%s" for payload [business=%d, name="%s"]',
                get_class($e),
                $e->getMessage(),
                $businessId,
                mb_substr($name, 0, 20)
            ));
        }

        $this->assertTrue(
            $exceptionThrown,
            sprintf(
                'Expected RuntimeException for SYSTEM_OWNER update but none was thrown. ' .
                'Payload: [business=%d, name="%s"]',
                $businessId,
                mb_substr($name, 0, 20)
            )
        );

        $this->assertSame(
            'Cannot modify the system owner role',
            $exceptionMessage,
            sprintf(
                'Expected exact error message "Cannot modify the system owner role" but got "%s"',
                $exceptionMessage
            )
        );
    }

    /**
     * Property 3: SYSTEM_OWNER Immutability — Delete rejection
     *
     * For any random business ID, attempting to delete the SYSTEM_OWNER role
     * SHALL throw a RuntimeException with message "Cannot delete the system owner role".
     *
     * **Validates: Requirements 2.3**
     *
     * @dataProvider deletePayloadProvider
     */
    public function testDeleteRejectsAnyPayloadForBusinessOwner(int $businessId): void
    {
        $repo = $this->createMock(RoleRepository::class);
        $audit = $this->createMock(AuditService::class);

        // The role exists in the business (findByIdAndBusiness returns it)
        $repo->method('findByIdAndBusiness')
            ->with(self::SYSTEM_OWNER_ROLE_ID, $businessId)
            ->willReturn([
                'id' => self::SYSTEM_OWNER_ROLE_ID,
                'name' => 'Business Owner',
                'description' => 'Full access',
                'type' => 'SYSTEM_OWNER',
            ]);

        // findRoleType returns SYSTEM_OWNER
        $repo->method('findRoleType')
            ->with(self::SYSTEM_OWNER_ROLE_ID)
            ->willReturn('SYSTEM_OWNER');

        // The repository and audit service should NEVER be called for SYSTEM_OWNER deletion
        $repo->expects($this->never())->method('deleteRole');
        $repo->expects($this->never())->method('countUsersWithRoleInBusiness');
        $audit->expects($this->never())->method('log');

        $service = $this->buildServiceWithDeps($repo, $audit);

        $exceptionThrown = false;
        $exceptionMessage = '';

        try {
            $service->delete(self::SYSTEM_OWNER_ROLE_ID, $businessId);
        } catch (RuntimeException $e) {
            $exceptionThrown = true;
            $exceptionMessage = $e->getMessage();
        } catch (\Exception $e) {
            $this->fail(sprintf(
                'Expected RuntimeException but got %s: "%s" for delete with business=%d',
                get_class($e),
                $e->getMessage(),
                $businessId
            ));
        }

        $this->assertTrue(
            $exceptionThrown,
            sprintf(
                'Expected RuntimeException for SYSTEM_OWNER delete but none was thrown. Business=%d',
                $businessId
            )
        );

        $this->assertSame(
            'Cannot delete the system owner role',
            $exceptionMessage,
            sprintf(
                'Expected exact error message "Cannot delete the system owner role" but got "%s"',
                $exceptionMessage
            )
        );
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
