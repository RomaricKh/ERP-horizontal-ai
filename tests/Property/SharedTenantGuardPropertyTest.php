<?php

namespace Tests\Property;

use App\Support\AuthContext;
use PHPUnit\Framework\TestCase;

/**
 * Feature: tenant-admin-review-fixes, Property 7: Shared tenant guard rejection
 *
 * For any request context where AuthContext::isTenantOwner() returns false,
 * the shared tenant guard SHALL output HTTP 403 with the JSON body:
 * {"success": false, "data": null, "message": "Access denied: Tenant Owner privileges required"}
 * and return false.
 *
 * The data provider generates 100+ random user contexts where is_tenant_owner
 * is falsy (false, null, 0, empty string, or missing entirely). For each context
 * we invoke the guard closure and verify:
 *   - The closure returns false
 *   - The echoed JSON matches the expected error body exactly
 *
 * **Validates: Requirements 13.4**
 */
class SharedTenantGuardPropertyTest extends TestCase
{
    private const ITERATIONS = 120;

    /**
     * The expected JSON response body when the guard rejects.
     */
    private const EXPECTED_JSON = [
        'success' => false,
        'data'    => null,
        'message' => 'Access denied: Tenant Owner privileges required',
    ];

    /**
     * Data provider generating random user contexts where isTenantOwner() returns false.
     *
     * Generates contexts with various falsy values for is_tenant_owner:
     * - false (boolean)
     * - 0 (integer)
     * - null
     * - '' (empty string)
     * - property missing entirely
     *
     * Each case also includes random user_id, tenant_id, and business_id to ensure
     * the guard does not depend on these values.
     *
     * @return array
     */
    public static function nonOwnerContextProvider(): array
    {
        $cases = [];

        // Falsy variants for is_tenant_owner
        $falsyValues = [false, 0, null, ''];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $userId = mt_rand(1, 100_000);
            $tenantId = mt_rand(1, 10_000);
            $businessId = mt_rand(1, 50_000);

            // Decide whether to include the property with a falsy value or omit it entirely
            $variant = mt_rand(0, count($falsyValues)); // extra index = "omit property"

            if ($variant < count($falsyValues)) {
                $falsyValue = $falsyValues[$variant];
                $context = (object) [
                    'user_id'         => $userId,
                    'tenant_id'       => $tenantId,
                    'business_id'     => $businessId,
                    'is_tenant_owner' => $falsyValue,
                    'permissions'     => [],
                ];
                $label = sprintf(
                    'user_%d_tenant_%d_falsy_%s_iter_%d',
                    $userId,
                    $tenantId,
                    var_export($falsyValue, true),
                    $i
                );
            } else {
                // Omit is_tenant_owner property entirely
                $context = (object) [
                    'user_id'     => $userId,
                    'tenant_id'   => $tenantId,
                    'business_id' => $businessId,
                    'permissions' => [],
                ];
                $label = sprintf('user_%d_tenant_%d_omitted_iter_%d', $userId, $tenantId, $i);
            }

            $cases[$label] = [$context];
        }

        return $cases;
    }

    /**
     * Property 7: Shared tenant guard rejection
     *
     * For any request context where AuthContext::isTenantOwner() returns false,
     * the shared tenant guard SHALL:
     *   1. Return false (guard rejects the request)
     *   2. Echo JSON body matching the expected error response exactly
     *
     * We use output buffering to capture the echoed JSON since the guard uses
     * echo json_encode(...) to send the response body.
     *
     * **Validates: Requirements 13.4**
     *
     * @dataProvider nonOwnerContextProvider
     */
    public function testGuardRejectsNonTenantOwnerWithCorrectResponse(object $context): void
    {
        // Arrange: Set AuthContext to the non-owner context
        AuthContext::set($context);

        // Verify precondition: isTenantOwner() should return false for this context
        $this->assertFalse(
            AuthContext::isTenantOwner(),
            sprintf(
                'Generator invariant: AuthContext::isTenantOwner() must return false. '
                . 'Context: %s',
                json_encode($context)
            )
        );

        // Load the guard closure fresh each time
        $guard = require __DIR__ . '/../../public/api/routes/_guards.php';

        // Act: Invoke the guard with output buffering to capture echoed JSON
        ob_start();
        $result = $guard();
        $output = ob_get_clean();

        // Assert: The guard must return false
        $this->assertFalse(
            $result,
            sprintf(
                'Guard should return false for non-tenant-owner context. '
                . 'Context: %s',
                json_encode($context)
            )
        );

        // Assert: The echoed output must be valid JSON matching the expected body
        $decoded = json_decode($output, true);
        $this->assertNotNull(
            $decoded,
            sprintf(
                'Guard output must be valid JSON. Got: "%s"',
                $output
            )
        );

        $this->assertSame(
            self::EXPECTED_JSON,
            $decoded,
            sprintf(
                'Guard JSON body mismatch. Expected: %s, Got: %s',
                json_encode(self::EXPECTED_JSON),
                $output
            )
        );
    }

    /**
     * Additional verification: For a tenant owner context (is_tenant_owner = true),
     * the guard SHALL return true and produce no output.
     *
     * This is a sanity check — not the primary property under test, but ensures
     * the guard has correct bidirectional behavior.
     */
    public function testGuardAcceptsTenantOwner(): void
    {
        // Arrange: Set AuthContext to a tenant owner context
        AuthContext::set((object) [
            'user_id'         => 1,
            'tenant_id'       => 1,
            'business_id'     => 1,
            'is_tenant_owner' => true,
            'permissions'     => [],
        ]);

        $guard = require __DIR__ . '/../../public/api/routes/_guards.php';

        // Act
        ob_start();
        $result = $guard();
        $output = ob_get_clean();

        // Assert: guard returns true and produces no output
        $this->assertTrue($result, 'Guard should return true for tenant owner');
        $this->assertEmpty($output, 'Guard should produce no output for tenant owner');
    }
}
