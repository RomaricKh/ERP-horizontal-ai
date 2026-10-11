<?php

namespace Tests\Property;

use App\Services\PeopleService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Property 6: Cross-tenant payee type assignment is rejected
 *
 * For any set of payee_type_ids provided during create or update, if any ID does not exist
 * in payee_types or belongs to a different tenant (where is_system = 0 and tenant_id does
 * not match the authenticated tenant), the PeopleService SHALL throw an
 * InvalidArgumentException identifying the invalid type IDs.
 *
 * **Validates: Requirements 8.1, 8.2**
 */
class PayeeTypeOwnershipPropertyTest extends TestCase
{
    private const ITERATIONS = 100;

    /**
     * Create a PeopleService instance with a mocked PDO injected via reflection.
     */
    private function buildServiceWithMockedDb(\PDO $pdo): PeopleService
    {
        $ref = new ReflectionClass(PeopleService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $dbProp = $ref->getProperty('db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($service, $pdo);

        return $service;
    }

    /**
     * Invoke the private validatePayeeTypeOwnership method via reflection.
     */
    private function invokeValidatePayeeTypeOwnership(PeopleService $service, array $typeIds, int $tenantId): void
    {
        $ref = new ReflectionClass(PeopleService::class);
        $method = $ref->getMethod('validatePayeeTypeOwnership');
        $method->setAccessible(true);
        $method->invoke($service, $typeIds, $tenantId);
    }

    /**
     * Create a mock PDO that returns specified valid IDs from the ownership query.
     *
     * @param array $validIds IDs that the database query "finds" as valid
     */
    private function createMockedPdo(array $validIds): \PDO
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')
            ->with(\PDO::FETCH_COLUMN)
            ->willReturn($validIds);

        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        return $pdo;
    }

    // ── Generators ─────────────────────────────────────────────────────────

    /**
     * Generate a random positive integer (type ID range).
     */
    private function randomTypeId(): int
    {
        return mt_rand(1, 99999);
    }

    /**
     * Generate a random tenant ID.
     */
    private function randomTenantId(): int
    {
        return mt_rand(1, 9999);
    }

    /**
     * Generate a random set of type IDs (1 to 10 unique IDs).
     */
    private function randomTypeIds(int $min = 1, int $max = 10): array
    {
        $count = mt_rand($min, $max);
        $ids = [];
        while (count($ids) < $count) {
            $id = $this->randomTypeId();
            if (!in_array($id, $ids)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    // ── Property Tests ─────────────────────────────────────────────────────

    /**
     * Property 6: IDs that don't exist in the database are always rejected.
     *
     * For any set of type IDs where the database returns no matching rows (simulating
     * nonexistent IDs), validatePayeeTypeOwnership SHALL throw InvalidArgumentException
     * listing all the invalid IDs.
     *
     * **Validates: Requirements 8.1, 8.2**
     */
    public function testNonExistentTypeIdsAreRejected(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $typeIds = $this->randomTypeIds();
            $tenantId = $this->randomTenantId();

            // Database returns empty: none of the IDs exist
            $pdo = $this->createMockedPdo([]);
            $service = $this->buildServiceWithMockedDb($pdo);

            try {
                $this->invokeValidatePayeeTypeOwnership($service, $typeIds, $tenantId);
                $this->fail(sprintf(
                    'Iteration %d: validatePayeeTypeOwnership([%s], %d) should throw for nonexistent IDs.',
                    $i, implode(', ', $typeIds), $tenantId
                ));
            } catch (InvalidArgumentException $e) {
                // Verify the exception message contains 'Invalid payee type IDs:'
                $this->assertStringContainsString('Invalid payee type IDs:', $e->getMessage());
                // Verify all invalid IDs are mentioned in the message
                foreach ($typeIds as $id) {
                    $this->assertStringContainsString((string)(int)$id, $e->getMessage(),
                        "ID {$id} should appear in exception message.");
                }
            }
        }
    }

    /**
     * Property 6: IDs belonging to a different tenant (not system types) are rejected.
     *
     * For any set of type IDs where some belong to a different tenant (the DB query
     * with is_system=1 OR tenant_id=? does not match them), validatePayeeTypeOwnership
     * SHALL throw InvalidArgumentException identifying only the cross-tenant IDs.
     *
     * **Validates: Requirements 8.1, 8.2**
     */
    public function testCrossTenantTypeIdsAreRejected(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $tenantId = $this->randomTenantId();

            // Generate some valid IDs (belong to tenant or are system types)
            $validIds = $this->randomTypeIds(1, 5);
            // Generate some cross-tenant IDs (not returned by query)
            $invalidIds = $this->randomTypeIds(1, 5);
            // Ensure no overlap
            $invalidIds = array_values(array_diff($invalidIds, $validIds));
            if (empty($invalidIds)) {
                $invalidIds = [max($validIds) + mt_rand(1, 100)];
            }

            $allIds = array_merge($validIds, $invalidIds);
            shuffle($allIds);

            // The DB query returns only the valid IDs (tenant-owned + system)
            $pdo = $this->createMockedPdo($validIds);
            $service = $this->buildServiceWithMockedDb($pdo);

            try {
                $this->invokeValidatePayeeTypeOwnership($service, $allIds, $tenantId);
                $this->fail(sprintf(
                    'Iteration %d: should throw for cross-tenant IDs [%s] (valid=[%s]).',
                    $i, implode(', ', $invalidIds), implode(', ', $validIds)
                ));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Invalid payee type IDs:', $e->getMessage());
                // All invalid IDs should be mentioned
                foreach ($invalidIds as $id) {
                    $this->assertStringContainsString((string)(int)$id, $e->getMessage(),
                        "Cross-tenant ID {$id} should be in exception message.");
                }
                // Valid IDs should NOT be in the exception message's ID list
                // Parse the IDs from the message after the prefix
                $msgParts = explode('Invalid payee type IDs: ', $e->getMessage());
                $reportedIds = array_map('intval', explode(', ', $msgParts[1] ?? ''));
                foreach ($validIds as $id) {
                    $this->assertNotContains((int)$id, $reportedIds,
                        "Valid ID {$id} should NOT appear in invalid IDs list.");
                }
            }
        }
    }

    /**
     * Property 6: System types (is_system=1) are always accepted regardless of tenant.
     *
     * For any set of type IDs that are system types (the DB query returns them because
     * is_system=1 matches), validatePayeeTypeOwnership SHALL NOT throw.
     *
     * **Validates: Requirements 8.1**
     */
    public function testSystemTypesAcceptedRegardlessOfTenant(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $typeIds = $this->randomTypeIds();
            $tenantId = $this->randomTenantId();

            // Database returns all IDs as valid (simulating they are system types)
            $pdo = $this->createMockedPdo(array_map('intval', $typeIds));
            $service = $this->buildServiceWithMockedDb($pdo);

            // Should NOT throw
            $this->invokeValidatePayeeTypeOwnership($service, $typeIds, $tenantId);
            $this->assertTrue(true, "System types should pass ownership validation.");
        }
    }

    /**
     * Property 6: Tenant-owned types (matching tenant_id) are accepted.
     *
     * For any set of type IDs belonging to the authenticated tenant (the DB query returns
     * them because tenant_id matches), validatePayeeTypeOwnership SHALL NOT throw.
     *
     * **Validates: Requirements 8.1**
     */
    public function testTenantOwnedTypesAccepted(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $typeIds = $this->randomTypeIds();
            $tenantId = $this->randomTenantId();

            // Database returns all IDs (simulating they belong to the correct tenant)
            $pdo = $this->createMockedPdo(array_map('intval', $typeIds));
            $service = $this->buildServiceWithMockedDb($pdo);

            // Should NOT throw
            $this->invokeValidatePayeeTypeOwnership($service, $typeIds, $tenantId);
            $this->assertTrue(true, "Tenant-owned types should pass ownership validation.");
        }
    }

    /**
     * Property 6: Empty type ID array short-circuits without DB query.
     *
     * For an empty typeIds array, validatePayeeTypeOwnership SHALL return immediately
     * without throwing and without executing any database query.
     *
     * **Validates: Requirements 8.1**
     */
    public function testEmptyTypeIdsPassesWithoutDbQuery(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $tenantId = $this->randomTenantId();

            $pdo = $this->createMock(\PDO::class);
            // prepare() should NEVER be called for empty arrays
            $pdo->expects($this->never())->method('prepare');

            $service = $this->buildServiceWithMockedDb($pdo);

            // Should NOT throw and should not hit the DB
            $this->invokeValidatePayeeTypeOwnership($service, [], $tenantId);
            $this->assertTrue(true, "Empty type IDs should skip DB validation.");
        }
    }

    /**
     * Property 6: Mixed valid and invalid IDs — only invalid ones appear in error.
     *
     * For any combination where some IDs are valid (system or tenant-owned) and some are
     * invalid (nonexistent or cross-tenant), the exception message SHALL list exactly
     * the invalid IDs — no more, no less.
     *
     * **Validates: Requirements 8.1, 8.2**
     */
    public function testMixedIdsReportsExactlyInvalidOnes(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $tenantId = $this->randomTenantId();

            // Create distinct sets for valid and invalid IDs
            $numValid = mt_rand(1, 5);
            $numInvalid = mt_rand(1, 5);

            $validIds = [];
            while (count($validIds) < $numValid) {
                $id = mt_rand(1, 50000);
                if (!in_array($id, $validIds)) {
                    $validIds[] = $id;
                }
            }

            $invalidIds = [];
            while (count($invalidIds) < $numInvalid) {
                $id = mt_rand(50001, 99999);
                if (!in_array($id, $invalidIds)) {
                    $invalidIds[] = $id;
                }
            }

            $allIds = array_merge($validIds, $invalidIds);
            shuffle($allIds);

            // DB only returns the valid ones
            $pdo = $this->createMockedPdo($validIds);
            $service = $this->buildServiceWithMockedDb($pdo);

            try {
                $this->invokeValidatePayeeTypeOwnership($service, $allIds, $tenantId);
                $this->fail(sprintf(
                    'Iteration %d: should throw for invalid IDs [%s].',
                    $i, implode(', ', $invalidIds)
                ));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Invalid payee type IDs:', $e->getMessage());

                // Parse reported IDs from the exception message
                $msgParts = explode('Invalid payee type IDs: ', $e->getMessage());
                $this->assertCount(2, $msgParts, 'Exception message format should match expected pattern.');
                $reportedIds = array_map('intval', explode(', ', $msgParts[1]));
                sort($reportedIds);

                $expectedInvalid = array_map('intval', $invalidIds);
                sort($expectedInvalid);

                $this->assertEquals($expectedInvalid, $reportedIds, sprintf(
                    'Iteration %d: reported IDs [%s] should match expected invalid [%s].',
                    $i, implode(', ', $reportedIds), implode(', ', $expectedInvalid)
                ));
            }
        }
    }

    /**
     * Property 6: Single invalid ID among many valid ones is still caught.
     *
     * For any set where exactly one ID is invalid (not returned by DB), the validation
     * SHALL throw identifying that single ID.
     *
     * **Validates: Requirements 8.2**
     */
    public function testSingleInvalidIdAmongValidOnesIsCaught(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $tenantId = $this->randomTenantId();

            // Generate several valid IDs
            $validIds = $this->randomTypeIds(2, 8);
            // Add exactly one invalid ID
            $invalidId = max($validIds) + mt_rand(1, 1000);

            $allIds = array_merge($validIds, [$invalidId]);
            shuffle($allIds);

            // DB returns only the valid ones
            $pdo = $this->createMockedPdo($validIds);
            $service = $this->buildServiceWithMockedDb($pdo);

            try {
                $this->invokeValidatePayeeTypeOwnership($service, $allIds, $tenantId);
                $this->fail(sprintf(
                    'Iteration %d: should throw for single invalid ID %d.',
                    $i, $invalidId
                ));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Invalid payee type IDs:', $e->getMessage());
                $this->assertStringContainsString((string)$invalidId, $e->getMessage());
            }
        }
    }
}
