<?php

namespace Tests\Property;

use App\Services\SetupGuard;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Feature: first-installation-flow, Property 1: Setup Guard Correctness
 *
 * For any non-negative integer count returned by the tenants table, the
 * SetupGuard SHALL return true if and only if the count equals zero.
 *
 * The data provider generates 100+ random non-negative integers (mixed
 * across the boundary at zero). For each value we wire a mocked PDO whose
 * `query('SELECT COUNT(*) FROM tenants')` call returns a PDOStatement whose
 * `fetchColumn()` yields that integer. The guard MUST return:
 *   - true  iff count === 0
 *   - false iff count >= 1
 *
 * **Validates: Requirements 1.1, 1.2**
 */
class SetupGuardPropertyTest extends TestCase
{
    private const ITERATIONS = 120;

    /**
     * Data provider generating random non-negative integer tenant counts.
     *
     * Half of the cases force `count = 0` (fresh-install boundary) and the
     * other half pick a random positive integer in [1, 1_000_000]. Mixing
     * both sides of the boundary ensures we exercise the if/else branch
     * roughly evenly across 120 iterations.
     *
     * Each case yields: [int $tenantCount]
     */
    public static function tenantCountProvider(): array
    {
        $cases = [];

        // Always include the explicit boundary cases
        $cases['boundary_zero_explicit'] = [0];
        $cases['boundary_one_explicit'] = [1];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // 50/50 split between zero and a random positive count so both
            // branches of the property are covered with roughly equal weight.
            if (mt_rand(0, 1) === 0) {
                $count = 0;
                $label = "zero_iter_{$i}";
            } else {
                $count = mt_rand(1, 1_000_000);
                $label = "positive_{$count}_iter_{$i}";
            }

            $cases[$label] = [$count];
        }

        return $cases;
    }

    /**
     * Property 1: Setup Guard Correctness
     *
     * For any non-negative integer count returned by the tenants table,
     * `SetupGuard::isFreshInstall()` SHALL return true if and only if the
     * count equals zero.
     *
     * **Validates: Requirements 1.1, 1.2**
     *
     * @dataProvider tenantCountProvider
     */
    public function testIsFreshInstallReturnsTrueIffTenantCountIsZero(int $tenantCount): void
    {
        $this->assertGreaterThanOrEqual(
            0,
            $tenantCount,
            'Generator invariant: tenant count must be a non-negative integer'
        );

        $pdo = $this->createMockPdoForTenantCount($tenantCount);
        $guard = new SetupGuard($pdo);

        $result = $guard->isFreshInstall();

        $expected = ($tenantCount === 0);

        $this->assertSame(
            $expected,
            $result,
            sprintf(
                'SetupGuard::isFreshInstall() returned %s for tenant count %d; '
                . 'expected %s (true iff count === 0).',
                var_export($result, true),
                $tenantCount,
                var_export($expected, true)
            )
        );
    }

    /**
     * Build a mocked PDO whose `query()` returns a PDOStatement that yields
     * the provided count from `fetchColumn()`.
     *
     * The mock is intentionally permissive about which SQL is passed in so
     * that this property test stays focused on the count -> boolean mapping
     * rather than the exact SQL string (which is exercised by other tests).
     */
    private function createMockPdoForTenantCount(int $tenantCount): PDO
    {
        $stmt = $this->createMock(PDOStatement::class);
        // PDO::query returns a PDOStatement on which `fetchColumn()` yields
        // the COUNT(*) result. Returning the int directly mirrors what the
        // real driver returns for a numeric column.
        $stmt->method('fetchColumn')->willReturn($tenantCount);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturn($stmt);

        return $pdo;
    }
}
