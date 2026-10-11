<?php

namespace Tests\Property;

use App\Repository\BusinessRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Property 6: Active business filtering
 *
 * For any user with business assignments where some businesses have is_active = 0,
 * calling getAllForCurrentUser() SHALL return only businesses where is_active = 1.
 *
 * **Validates: Requirements 12.1, 12.2**
 */
class ActiveBusinessFilterPropertyTest extends TestCase
{
    private const ITERATIONS = 50;

    private PDO $db;
    private int $tenantId;
    private int $roleId;

    /** @var int[] Business IDs created during the test */
    private array $testBusinessIds = [];

    /** @var int[] User IDs created during the test */
    private array $testUserIds = [];

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

        // Use existing tenant
        $stmt = $this->db->query("SELECT id FROM tenants LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $this->markTestSkipped('No tenant found in the database for testing.');
        }
        $this->tenantId = (int) $row['id'];

        // Use an existing role
        $stmt = $this->db->query("SELECT id FROM roles LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $this->markTestSkipped('No role found in the database for testing.');
        }
        $this->roleId = (int) $row['id'];
    }

    protected function tearDown(): void
    {
        // Clean up in reverse order of creation
        if (!empty($this->testUserRoleIds)) {
            $placeholders = implode(',', array_fill(0, count($this->testUserRoleIds), '?'));
            $stmt = $this->db->prepare("DELETE FROM user_roles WHERE id IN ({$placeholders})");
            $stmt->execute($this->testUserRoleIds);
        }

        if (!empty($this->testBusinessIds)) {
            $placeholders = implode(',', array_fill(0, count($this->testBusinessIds), '?'));
            $stmt = $this->db->prepare("DELETE FROM businesses WHERE id IN ({$placeholders})");
            $stmt->execute($this->testBusinessIds);
        }

        if (!empty($this->testUserIds)) {
            $placeholders = implode(',', array_fill(0, count($this->testUserIds), '?'));
            $stmt = $this->db->prepare("DELETE FROM users WHERE id IN ({$placeholders})");
            $stmt->execute($this->testUserIds);
        }

        $this->testUserRoleIds = [];
        $this->testBusinessIds = [];
        $this->testUserIds = [];
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Create a test user and return their ID.
     */
    private function createTestUser(): int
    {
        $username = 'test_abf_' . mt_rand(100000, 999999) . '_' . time();
        $email = $username . '@test.local';

        $stmt = $this->db->prepare("
            INSERT INTO users (username, password_hash, first_name, last_name, email, is_active, created_at)
            VALUES (:username, :password_hash, 'Test', 'User', :email, 1, NOW())
        ");
        $stmt->execute([
            'username' => $username,
            'password_hash' => password_hash('testpass123', PASSWORD_DEFAULT),
            'email' => $email,
        ]);
        $id = (int) $this->db->lastInsertId();
        $this->testUserIds[] = $id;
        return $id;
    }

    /**
     * Create a test business with a given is_active state.
     */
    private function createTestBusiness(bool $isActive): int
    {
        $name = 'TestBiz_' . ($isActive ? 'Active' : 'Inactive') . '_' . mt_rand(100000, 999999);

        $stmt = $this->db->prepare("
            INSERT INTO businesses (tenant_id, name, is_active, currency, business_type_id, created_at)
            VALUES (:tenant_id, :name, :is_active, 'USD', 1, NOW())
        ");
        $stmt->execute([
            'tenant_id' => $this->tenantId,
            'name' => $name,
            'is_active' => $isActive ? 1 : 0,
        ]);
        $id = (int) $this->db->lastInsertId();
        $this->testBusinessIds[] = $id;
        return $id;
    }

    /**
     * Link a user to a business via user_roles.
     */
    private function assignUserToBusiness(int $userId, int $businessId): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO user_roles (user_id, role_id, business_id, tenant_id, is_active)
            VALUES (:user_id, :role_id, :business_id, :tenant_id, 1)
        ");
        $stmt->execute([
            'user_id' => $userId,
            'role_id' => $this->roleId,
            'business_id' => $businessId,
            'tenant_id' => $this->tenantId,
        ]);
        $this->testUserRoleIds[] = (int) $this->db->lastInsertId();
    }

    // ── Property Tests ──────────────────────────────────────────────────────

    /**
     * Property 6: For any mix of active/inactive businesses assigned to a user,
     * getAllForCurrentUser() returns ONLY active businesses.
     *
     * **Validates: Requirements 12.1, 12.2**
     */
    public function testOnlyActiveBusinessesReturned(): void
    {
        $repo = new BusinessRepository($this->db);

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Create a fresh user for each iteration
            $userId = $this->createTestUser();

            // Generate a random mix of active and inactive businesses (1-5 each)
            $activeCount = mt_rand(1, 5);
            $inactiveCount = mt_rand(1, 5);

            $activeIds = [];
            $inactiveIds = [];

            for ($a = 0; $a < $activeCount; $a++) {
                $bizId = $this->createTestBusiness(true);
                $this->assignUserToBusiness($userId, $bizId);
                $activeIds[] = $bizId;
            }

            for ($n = 0; $n < $inactiveCount; $n++) {
                $bizId = $this->createTestBusiness(false);
                $this->assignUserToBusiness($userId, $bizId);
                $inactiveIds[] = $bizId;
            }

            // Call the repository method
            $result = $repo->getAllForCurrentUser($userId);
            $returnedIds = array_map(fn($b) => (int) $b['id'], $result);

            // All active businesses should be in the result
            foreach ($activeIds as $activeId) {
                $this->assertContains($activeId, $returnedIds, sprintf(
                    'Iteration %d: Active business id=%d should be returned by getAllForCurrentUser().',
                    $i, $activeId
                ));
            }

            // No inactive businesses should be in the result
            foreach ($inactiveIds as $inactiveId) {
                $this->assertNotContains($inactiveId, $returnedIds, sprintf(
                    'Iteration %d: Inactive business id=%d should NOT be returned by getAllForCurrentUser().',
                    $i, $inactiveId
                ));
            }
        }
    }

    /**
     * Property 6: When ALL businesses assigned to a user are inactive,
     * getAllForCurrentUser() returns an empty array.
     *
     * **Validates: Requirements 12.1, 12.2**
     */
    public function testAllInactiveReturnsEmpty(): void
    {
        $repo = new BusinessRepository($this->db);

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $userId = $this->createTestUser();

            // Create only inactive businesses
            $inactiveCount = mt_rand(1, 5);
            for ($n = 0; $n < $inactiveCount; $n++) {
                $bizId = $this->createTestBusiness(false);
                $this->assignUserToBusiness($userId, $bizId);
            }

            $result = $repo->getAllForCurrentUser($userId);

            // Filter result to only our test businesses (avoid interference from shared DB state)
            $testBizResult = array_filter($result, fn($b) => in_array((int) $b['id'], $this->testBusinessIds));

            $this->assertEmpty($testBizResult, sprintf(
                'Iteration %d: getAllForCurrentUser() should return no inactive businesses. Got %d.',
                $i, count($testBizResult)
            ));
        }
    }

    /**
     * Property 6: When ALL businesses assigned to a user are active,
     * getAllForCurrentUser() returns all of them.
     *
     * **Validates: Requirements 12.1, 12.2**
     */
    public function testAllActiveReturnsAll(): void
    {
        $repo = new BusinessRepository($this->db);

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $userId = $this->createTestUser();

            // Create only active businesses
            $activeCount = mt_rand(1, 5);
            $activeIds = [];
            for ($a = 0; $a < $activeCount; $a++) {
                $bizId = $this->createTestBusiness(true);
                $this->assignUserToBusiness($userId, $bizId);
                $activeIds[] = $bizId;
            }

            $result = $repo->getAllForCurrentUser($userId);
            $returnedIds = array_map(fn($b) => (int) $b['id'], $result);

            foreach ($activeIds as $activeId) {
                $this->assertContains($activeId, $returnedIds, sprintf(
                    'Iteration %d: Active business id=%d should be returned when all are active.',
                    $i, $activeId
                ));
            }
        }
    }
}
