<?php

namespace Tests\Property;

use App\Config\Database;
use App\Controllers\Tenant\TenantCoreController;
use App\Support\AuthContext;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Property 4: Single tenant fetch ownership guard
 *
 * For any tenant ID and user ID, viewTenant() SHALL return the tenant record
 * if and only if the user owns the tenant; otherwise it SHALL return HTTP 403.
 *
 * This is a database integration test that creates real tenants, users, and
 * ownership relationships, then verifies the ownership guard behavior.
 *
 * **Validates: Requirements 8.2, 8.3**
 */
class TenantFetchOwnershipPropertyTest extends TestCase
{
    private const ITERATIONS = 50;

    private PDO $db;

    /** @var int[] Tenant IDs created during the test */
    private array $testTenantIds = [];

    /** @var int[] User IDs created during the test */
    private array $testUserIds = [];

    /** @var int[] Role IDs created during the test */
    private array $testRoleIds = [];

    /** @var int[] User role IDs created during the test */
    private array $testUserRoleIds = [];

    protected function setUp(): void
    {
        $this->db = new PDO(
            'mysql:host=localhost;dbname=erp;charset=utf8mb4',
            'root',
            '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        // Initialize the Database singleton so the controller can use Database::getConnection()
        Database::connect([
            'host'    => 'localhost',
            'dbname'  => 'erp',
            'user'    => 'root',
            'pass'    => '',
            'charset' => 'utf8mb4',
        ]);
    }

    protected function tearDown(): void
    {
        // Clean up in reverse dependency order
        if (!empty($this->testUserRoleIds)) {
            $placeholders = implode(',', array_fill(0, count($this->testUserRoleIds), '?'));
            $stmt = $this->db->prepare("DELETE FROM user_roles WHERE id IN ({$placeholders})");
            $stmt->execute($this->testUserRoleIds);
        }

        if (!empty($this->testRoleIds)) {
            $placeholders = implode(',', array_fill(0, count($this->testRoleIds), '?'));
            $stmt = $this->db->prepare("DELETE FROM roles WHERE id IN ({$placeholders})");
            $stmt->execute($this->testRoleIds);
        }

        if (!empty($this->testUserIds)) {
            $placeholders = implode(',', array_fill(0, count($this->testUserIds), '?'));
            $stmt = $this->db->prepare("DELETE FROM users WHERE id IN ({$placeholders})");
            $stmt->execute($this->testUserIds);
        }

        if (!empty($this->testTenantIds)) {
            $placeholders = implode(',', array_fill(0, count($this->testTenantIds), '?'));
            $stmt = $this->db->prepare("DELETE FROM tenants WHERE id IN ({$placeholders})");
            $stmt->execute($this->testTenantIds);
        }

        $this->testUserRoleIds = [];
        $this->testRoleIds = [];
        $this->testUserIds = [];
        $this->testTenantIds = [];
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Create a test tenant and return its ID.
     */
    private function createTestTenant(): int
    {
        $name = 'TestTenant_Fetch_' . mt_rand(100000, 999999) . '_' . time();
        $stmt = $this->db->prepare("
            INSERT INTO tenants (name, is_active, created_at)
            VALUES (:name, 1, NOW())
        ");
        $stmt->execute(['name' => $name]);
        $id = (int) $this->db->lastInsertId();
        $this->testTenantIds[] = $id;
        return $id;
    }

    /**
     * Create a test user and return its ID.
     */
    private function createTestUser(): int
    {
        $suffix = mt_rand(100000, 999999) . '_' . microtime(true);
        $stmt = $this->db->prepare("
            INSERT INTO users (username, password_hash, first_name, last_name, email, is_active, created_at)
            VALUES (:username, :password_hash, 'Test', 'User', :email, 1, NOW())
        ");
        $stmt->execute([
            'username'      => 'testuser_fetch_' . $suffix,
            'password_hash' => password_hash('TestPass123', PASSWORD_BCRYPT),
            'email'         => 'testfetch_' . $suffix . '@example.com',
        ]);
        $id = (int) $this->db->lastInsertId();
        $this->testUserIds[] = $id;
        return $id;
    }

    /**
     * Create a TENANT_OWNER role for the given tenant and return the role ID.
     */
    private function createTenantOwnerRole(int $tenantId): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO roles (name, type, created_at)
            VALUES (:name, 'TENANT_OWNER', NOW())
        ");
        $stmt->execute(['name' => 'TenantOwner_Test_' . $tenantId . '_' . mt_rand(1000, 9999)]);
        $id = (int) $this->db->lastInsertId();
        $this->testRoleIds[] = $id;
        return $id;
    }

    /**
     * Assign a user as TENANT_OWNER of a tenant via user_roles.
     */
    private function assignOwnership(int $userId, int $tenantId, int $roleId): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO user_roles (user_id, role_id, business_id, tenant_id, is_active)
            VALUES (:user_id, :role_id, NULL, :tenant_id, 1)
        ");
        $stmt->execute([
            'user_id'   => $userId,
            'role_id'   => $roleId,
            'tenant_id' => $tenantId,
        ]);
        $this->testUserRoleIds[] = (int) $this->db->lastInsertId();
    }

    /**
     * Set AuthContext to simulate a specific user.
     */
    private function setAuthContextForUser(int $userId): void
    {
        AuthContext::set((object) [
            'user_id'         => $userId,
            'tenant_id'       => mt_rand(1, 100000),
            'business_id'     => mt_rand(1, 100000),
            'is_tenant_owner' => true,
            'permissions'     => [],
        ]);
    }

    /**
     * Invoke the controller's viewTenant method and capture the response.
     *
     * @return array{status: int, body: array}
     */
    private function invokeViewTenant(int $tenantId): array
    {
        http_response_code(200);
        ob_start();

        $controller = new TenantCoreController();
        $controller->viewTenant($tenantId);

        $output = ob_get_clean();
        $status = http_response_code();
        $body = json_decode($output, true) ?? [];

        return ['status' => $status, 'body' => $body];
    }

    // ── Property Tests ──────────────────────────────────────────────────────

    /**
     * Property 4: viewTenant() returns tenant data when user owns the tenant.
     *
     * For any tenant owned by a user, viewTenant() SHALL return the tenant
     * record with HTTP 200 and success=true.
     *
     * **Validates: Requirements 8.2**
     */
    public function testViewTenantReturnsDataForOwner(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Create a tenant and a user
            $tenantId = $this->createTestTenant();
            $userId = $this->createTestUser();

            // Create TENANT_OWNER role and assign ownership
            $roleId = $this->createTenantOwnerRole($tenantId);
            $this->assignOwnership($userId, $tenantId, $roleId);

            // Set auth context to the owner user
            $this->setAuthContextForUser($userId);

            // Attempt to view the tenant
            $response = $this->invokeViewTenant($tenantId);

            // Assert: 200 response
            $this->assertSame(200, $response['status'], sprintf(
                'Iteration %d: viewTenant(%d) should return 200 when user %d owns the tenant. Got status %d. Body: %s',
                $i, $tenantId, $userId, $response['status'], json_encode($response['body'])
            ));

            // Assert: success is true
            $this->assertTrue($response['body']['success'] ?? false, sprintf(
                'Iteration %d: Response success should be true for tenant owner.',
                $i
            ));

            // Assert: tenant data is present
            $this->assertNotNull($response['body']['data']['tenant'] ?? null, sprintf(
                'Iteration %d: Response data.tenant should not be null for tenant owner.',
                $i
            ));

            // Assert: tenant ID matches
            $this->assertEquals($tenantId, (int) $response['body']['data']['tenant']['id'], sprintf(
                'Iteration %d: Returned tenant ID should match requested ID %d.',
                $i, $tenantId
            ));
        }
    }

    /**
     * Property 4: viewTenant() returns 403 when user does NOT own the tenant.
     *
     * For any tenant NOT owned by the authenticated user, viewTenant() SHALL
     * return HTTP 403.
     *
     * **Validates: Requirements 8.3**
     */
    public function testViewTenantRejects403ForNonOwner(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Create a tenant and TWO users
            $tenantId = $this->createTestTenant();
            $ownerUserId = $this->createTestUser();
            $nonOwnerUserId = $this->createTestUser();

            // Assign ownership only to the owner user
            $roleId = $this->createTenantOwnerRole($tenantId);
            $this->assignOwnership($ownerUserId, $tenantId, $roleId);

            // Set auth context to the NON-owner user
            $this->setAuthContextForUser($nonOwnerUserId);

            // Attempt to view the tenant
            $response = $this->invokeViewTenant($tenantId);

            // Assert: 403 response
            $this->assertSame(403, $response['status'], sprintf(
                'Iteration %d: viewTenant(%d) should return 403 when user %d does NOT own the tenant '
                . '(owner is user %d). Got status %d. Body: %s',
                $i, $tenantId, $nonOwnerUserId, $ownerUserId, $response['status'],
                json_encode($response['body'])
            ));

            // Assert: success is false
            $this->assertFalse($response['body']['success'] ?? true, sprintf(
                'Iteration %d: Response success should be false for non-owner.',
                $i
            ));

            // Assert: no tenant data is exposed
            $this->assertNull($response['body']['data'], sprintf(
                'Iteration %d: Response data should be null (no tenant data exposed) for non-owner.',
                $i
            ));

            // Assert: message indicates access denial
            $this->assertStringContainsString('Access denied', $response['body']['message'] ?? '', sprintf(
                'Iteration %d: Response message should indicate access denial for non-owner.',
                $i
            ));
        }
    }

    /**
     * Property 4: viewTenant() with randomized ownership scenarios.
     *
     * For any randomly generated user/tenant combination where ownership is
     * toggled, the response correctly reflects ownership status.
     *
     * This test creates multiple tenants and users, assigns ownership randomly,
     * and verifies that viewTenant returns the correct response for each pairing.
     *
     * **Validates: Requirements 8.2, 8.3**
     */
    public function testViewTenantOwnershipBidirectional(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Create a tenant and a user
            $tenantId = $this->createTestTenant();
            $userId = $this->createTestUser();

            // Randomly decide whether this user owns the tenant
            $isOwner = (bool) mt_rand(0, 1);

            if ($isOwner) {
                $roleId = $this->createTenantOwnerRole($tenantId);
                $this->assignOwnership($userId, $tenantId, $roleId);
            }

            // Set auth context to the user
            $this->setAuthContextForUser($userId);

            // Attempt to view the tenant
            $response = $this->invokeViewTenant($tenantId);

            if ($isOwner) {
                // Owner should get 200 with tenant data
                $this->assertSame(200, $response['status'], sprintf(
                    'Iteration %d (owner=true): viewTenant(%d) should return 200 for owner user %d. Got %d.',
                    $i, $tenantId, $userId, $response['status']
                ));
                $this->assertTrue($response['body']['success'] ?? false, sprintf(
                    'Iteration %d (owner=true): Response success should be true.',
                    $i
                ));
                $this->assertNotNull($response['body']['data']['tenant'] ?? null, sprintf(
                    'Iteration %d (owner=true): Tenant data should be present.',
                    $i
                ));
                $this->assertEquals($tenantId, (int) $response['body']['data']['tenant']['id'], sprintf(
                    'Iteration %d (owner=true): Tenant ID should match.',
                    $i
                ));
            } else {
                // Non-owner should get 403
                $this->assertSame(403, $response['status'], sprintf(
                    'Iteration %d (owner=false): viewTenant(%d) should return 403 for non-owner user %d. Got %d.',
                    $i, $tenantId, $userId, $response['status']
                ));
                $this->assertFalse($response['body']['success'] ?? true, sprintf(
                    'Iteration %d (owner=false): Response success should be false.',
                    $i
                ));
                $this->assertNull($response['body']['data'], sprintf(
                    'Iteration %d (owner=false): No tenant data should be exposed.',
                    $i
                ));
            }
        }
    }
}
