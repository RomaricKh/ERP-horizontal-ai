<?php

namespace Tests\Property;

use App\Config\Database;
use App\Controllers\Tenant\TenantBusinessController;
use App\Services\BusinessService;
use App\Support\AuthContext;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Property 2: Tenant scoping rejects cross-tenant business access
 *
 * For any business ID and tenant ID pairing where the business's tenant_id does not
 * equal the authenticated tenant, both viewBusiness() and updateBusiness() SHALL
 * return HTTP 403 without exposing or modifying business data.
 *
 * This is a database integration test that creates real businesses owned by one tenant,
 * then sets AuthContext to a different tenant and verifies 403 rejection.
 *
 * **Validates: Requirements 2.1, 2.2, 2.3, 2.4**
 */
class TenantScopingPropertyTest extends TestCase
{
    private const ITERATIONS = 50;

    private PDO $db;

    /** @var int[] Tenant IDs created during the test */
    private array $testTenantIds = [];

    /** @var int[] Business IDs created during the test */
    private array $testBusinessIds = [];

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
        // Clean up test businesses
        if (!empty($this->testBusinessIds)) {
            $placeholders = implode(',', array_fill(0, count($this->testBusinessIds), '?'));
            $stmt = $this->db->prepare("DELETE FROM businesses WHERE id IN ({$placeholders})");
            $stmt->execute($this->testBusinessIds);
        }

        // Clean up test tenants
        if (!empty($this->testTenantIds)) {
            $placeholders = implode(',', array_fill(0, count($this->testTenantIds), '?'));
            $stmt = $this->db->prepare("DELETE FROM tenants WHERE id IN ({$placeholders})");
            $stmt->execute($this->testTenantIds);
        }

        $this->testBusinessIds = [];
        $this->testTenantIds = [];
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Create a test tenant and return its ID.
     */
    private function createTestTenant(): int
    {
        $name = 'TestTenant_Scoping_' . mt_rand(100000, 999999) . '_' . time();
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
     * Create a test business owned by the given tenant.
     */
    private function createTestBusiness(int $tenantId): int
    {
        $name = 'TestBiz_Scoping_' . mt_rand(100000, 999999);
        $stmt = $this->db->prepare("
            INSERT INTO businesses (tenant_id, name, is_active, currency, business_type_id, created_at)
            VALUES (:tenant_id, :name, 1, 'USD', 1, NOW())
        ");
        $stmt->execute([
            'tenant_id' => $tenantId,
            'name' => $name,
        ]);
        $id = (int) $this->db->lastInsertId();
        $this->testBusinessIds[] = $id;
        return $id;
    }

    /**
     * Set AuthContext to simulate a user in the given tenant.
     */
    private function setAuthContextForTenant(int $tenantId): void
    {
        AuthContext::set((object) [
            'user_id'         => mt_rand(1, 100000),
            'tenant_id'       => $tenantId,
            'business_id'     => mt_rand(1, 100000),
            'is_tenant_owner' => true,
            'permissions'     => [],
        ]);
    }

    /**
     * Invoke the controller's viewBusiness or updateBusiness method and capture the response.
     * Uses output buffering to catch the JSON output and http_response_code() for the status.
     *
     * @return array{status: int, body: array}
     */
    private function invokeControllerMethod(string $method, int $businessId): array
    {
        // Reset response code before each call
        http_response_code(200);

        ob_start();

        $controller = new TenantBusinessController();
        $controller->$method($businessId);

        $output = ob_get_clean();
        $status = http_response_code();

        $body = json_decode($output, true) ?? [];

        return ['status' => $status, 'body' => $body];
    }

    // ── Property Tests ──────────────────────────────────────────────────────

    /**
     * Property 2: viewBusiness() rejects cross-tenant access with 403
     *
     * For any business owned by tenant A, when the authenticated context is tenant B
     * (where B != A), viewBusiness() SHALL return HTTP 403.
     *
     * **Validates: Requirements 2.1, 2.2**
     */
    public function testViewBusinessRejectsCrossTenantAccess(): void
    {
        // Create two tenants
        $ownerTenantId = $this->createTestTenant();
        $attackerTenantId = $this->createTestTenant();

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Create a business owned by the owner tenant
            $businessId = $this->createTestBusiness($ownerTenantId);

            // Set auth context to the attacker tenant
            $this->setAuthContextForTenant($attackerTenantId);

            // Attempt to view the business
            $response = $this->invokeControllerMethod('viewBusiness', $businessId);

            // Assert: 403 response
            $this->assertSame(403, $response['status'], sprintf(
                'Iteration %d: viewBusiness(%d) should return 403 when business belongs to tenant %d '
                . 'but authenticated as tenant %d. Got status %d. Body: %s',
                $i, $businessId, $ownerTenantId, $attackerTenantId, $response['status'],
                json_encode($response['body'])
            ));

            // Assert: response indicates access denied
            $this->assertFalse($response['body']['success'] ?? true, sprintf(
                'Iteration %d: Response success should be false for cross-tenant viewBusiness.',
                $i
            ));

            // Assert: no business data is exposed
            $this->assertNull($response['body']['data'], sprintf(
                'Iteration %d: Response data should be null (no business data exposed) for cross-tenant viewBusiness.',
                $i
            ));

            // Assert: message indicates access denial
            $this->assertStringContainsString('Access denied', $response['body']['message'] ?? '', sprintf(
                'Iteration %d: Response message should indicate access denial.',
                $i
            ));
        }
    }

    /**
     * Property 2: updateBusiness() rejects cross-tenant access with 403
     *
     * For any business owned by tenant A, when the authenticated context is tenant B
     * (where B != A), updateBusiness() SHALL return HTTP 403 without modifying the business.
     *
     * **Validates: Requirements 2.3, 2.4**
     */
    public function testUpdateBusinessRejectsCrossTenantAccess(): void
    {
        // Create two tenants
        $ownerTenantId = $this->createTestTenant();
        $attackerTenantId = $this->createTestTenant();

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Create a business owned by the owner tenant
            $businessId = $this->createTestBusiness($ownerTenantId);

            // Record original business name for later verification
            $stmt = $this->db->prepare("SELECT name FROM businesses WHERE id = ?");
            $stmt->execute([$businessId]);
            $originalName = $stmt->fetchColumn();

            // Set auth context to the attacker tenant
            $this->setAuthContextForTenant($attackerTenantId);

            // Attempt to update the business — guard rejects before input() is called
            $response = $this->invokeControllerMethod('updateBusiness', $businessId);

            // Assert: 403 response
            $this->assertSame(403, $response['status'], sprintf(
                'Iteration %d: updateBusiness(%d) should return 403 when business belongs to tenant %d '
                . 'but authenticated as tenant %d. Got status %d. Body: %s',
                $i, $businessId, $ownerTenantId, $attackerTenantId, $response['status'],
                json_encode($response['body'])
            ));

            // Assert: response indicates access denied
            $this->assertFalse($response['body']['success'] ?? true, sprintf(
                'Iteration %d: Response success should be false for cross-tenant updateBusiness.',
                $i
            ));

            // Assert: no data exposed
            $this->assertNull($response['body']['data'], sprintf(
                'Iteration %d: Response data should be null for cross-tenant updateBusiness.',
                $i
            ));

            // Assert: business data was NOT modified
            $stmt = $this->db->prepare("SELECT name FROM businesses WHERE id = ?");
            $stmt->execute([$businessId]);
            $currentName = $stmt->fetchColumn();

            $this->assertSame($originalName, $currentName, sprintf(
                'Iteration %d: Business name should not be modified by cross-tenant updateBusiness(). '
                . 'Original: "%s", Current: "%s".',
                $i, $originalName, $currentName
            ));
        }
    }

    /**
     * Property 2: Tenant scoping with randomized tenant pairings
     *
     * For any randomly generated pair of distinct tenants, a business owned by one
     * SHALL never be accessible (view) by the other.
     *
     * This test creates fresh tenant pairs each iteration to ensure the property
     * holds regardless of the specific tenant IDs involved.
     *
     * **Validates: Requirements 2.1, 2.2**
     */
    public function testViewBusinessRejectsWithRandomTenantPairings(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Create a fresh pair of distinct tenants each iteration
            $ownerTenantId = $this->createTestTenant();
            $attackerTenantId = $this->createTestTenant();

            // Create a business owned by the owner tenant
            $businessId = $this->createTestBusiness($ownerTenantId);

            // Set auth context to the attacker tenant
            $this->setAuthContextForTenant($attackerTenantId);

            // Attempt to view the business
            $response = $this->invokeControllerMethod('viewBusiness', $businessId);

            // Assert: 403 response
            $this->assertSame(403, $response['status'], sprintf(
                'Iteration %d: viewBusiness(%d) should return 403 for any cross-tenant pair '
                . '(owner=%d, attacker=%d). Got status %d.',
                $i, $businessId, $ownerTenantId, $attackerTenantId, $response['status']
            ));

            // Assert: no business data is exposed
            $this->assertNull($response['body']['data'], sprintf(
                'Iteration %d: No business data should be exposed in cross-tenant access.',
                $i
            ));
        }
    }
}
