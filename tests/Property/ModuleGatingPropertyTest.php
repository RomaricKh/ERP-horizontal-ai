<?php

namespace Tests\Property;

use App\Repository\PayeeTypeRepository;
use App\Repository\PeopleRepository;
use App\Services\ModuleActivationService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Property 11: Module-gated type filtering excludes inactive modules
 *
 * For any business configuration with a set of active modules, the payee types
 * returned by listing or person queries SHALL include only types where
 * module_code IS NULL or module_code is in the business's active module set.
 * Types with a module_code linked to an inactive module SHALL never appear.
 *
 * **Validates: Requirements 14.1, 14.3**
 */
class ModuleGatingPropertyTest extends TestCase
{
    private const ITERATIONS = 50;

    private PDO $db;
    private int $tenantId;
    private int $businessId;
    private int $businessTypeId;

    /** @var int[] Module IDs we inserted for test */
    private array $testModuleIds = [];

    /** @var int[] Payee type IDs we inserted for test */
    private array $testTypeIds = [];

    /** @var int[] business_type_modules IDs we inserted */
    private array $testBtmIds = [];

    protected function setUp(): void
    {
        $this->db = new PDO(
            'mysql:host=localhost;dbname=erp;charset=utf8mb4',
            'root',
            '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        // Use existing tenant and business from the DB
        $stmt = $this->db->query("SELECT id, tenant_id, business_type_id FROM businesses LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $this->markTestSkipped('No business found in the database for testing.');
        }

        $this->businessId = (int) $row['id'];
        $this->tenantId = (int) $row['tenant_id'];
        $this->businessTypeId = (int) $row['business_type_id'];

        ModuleActivationService::clearCache();
    }

    protected function tearDown(): void
    {
        // Clean up test data in reverse order of creation
        if (!empty($this->testBtmIds)) {
            $placeholders = implode(',', array_fill(0, count($this->testBtmIds), '?'));
            $stmt = $this->db->prepare("DELETE FROM business_type_modules WHERE id IN ({$placeholders})");
            $stmt->execute($this->testBtmIds);
        }

        if (!empty($this->testTypeIds)) {
            $placeholders = implode(',', array_fill(0, count($this->testTypeIds), '?'));
            $stmt = $this->db->prepare("DELETE FROM payee_types WHERE id IN ({$placeholders})");
            $stmt->execute($this->testTypeIds);
        }

        if (!empty($this->testModuleIds)) {
            $placeholders = implode(',', array_fill(0, count($this->testModuleIds), '?'));
            $stmt = $this->db->prepare("DELETE FROM modules WHERE id IN ({$placeholders})");
            $stmt->execute($this->testModuleIds);
        }

        ModuleActivationService::clearCache();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Create a test module in the DB and return its ID.
     */
    private function createTestModule(string $code): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO modules (code, name, icon, route_prefix, permission_prefixes, category, sort_order, created_at)
            VALUES (:code, :name, 'fas fa-test', :route, '[\"test_mod.\"]', 'other', 99, NOW())
        ");
        $stmt->execute([
            'code' => $code,
            'name' => 'Test Module ' . $code,
            'route' => 'test-' . $code,
        ]);
        $id = (int) $this->db->lastInsertId();
        $this->testModuleIds[] = $id;
        return $id;
    }

    /**
     * Create a test payee type with optional module_id.
     */
    private function createTestPayeeType(string $name, ?int $moduleId = null): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO payee_types (tenant_id, business_id, name, is_system, module_id, code, created_at)
            VALUES (NULL, NULL, :name, 1, :module_id, :code, NOW())
        ");
        $code = 'TEST_' . strtoupper(str_replace(' ', '_', $name)) . '_' . mt_rand(1000, 9999);
        $stmt->execute([
            'name' => $name,
            'module_id' => $moduleId,
            'code' => $code,
        ]);
        $id = (int) $this->db->lastInsertId();
        $this->testTypeIds[] = $id;
        return $id;
    }

    /**
     * Activate a module for the business's type.
     */
    private function activateModule(int $moduleId): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO business_type_modules (business_type_id, module_id, created_at)
            VALUES (:bt_id, :mod_id, NOW())
        ");
        $stmt->execute([
            'bt_id' => $this->businessTypeId,
            'mod_id' => $moduleId,
        ]);
        $id = (int) $this->db->lastInsertId();
        $this->testBtmIds[] = $id;
        ModuleActivationService::clearCache();
        return $id;
    }

    /**
     * Deactivate a module by removing the business_type_modules row.
     */
    private function deactivateModule(int $btmId): void
    {
        $stmt = $this->db->prepare("DELETE FROM business_type_modules WHERE id = :id");
        $stmt->execute(['id' => $btmId]);
        // Remove from our tracking array too
        $this->testBtmIds = array_values(array_diff($this->testBtmIds, [$btmId]));
        ModuleActivationService::clearCache();
    }

    /**
     * Get the current list of visible payee type IDs for the business using PayeeTypeRepository.
     *
     * @return int[]
     */
    private function getVisibleTypeIds(): array
    {
        $repo = new PayeeTypeRepository($this->db);
        $types = $repo->findForBusiness($this->tenantId, $this->businessId);
        return array_map(fn($t) => (int) $t['id'], $types);
    }

    /**
     * Generate a random module code for testing.
     */
    private function randomModuleCode(): string
    {
        return 'test_mod_' . mt_rand(100000, 999999);
    }

    /**
     * Generate a random type name for testing.
     */
    private function randomTypeName(): string
    {
        $adjectives = ['Fast', 'Slow', 'Big', 'Small', 'Red', 'Blue', 'Green', 'Old', 'New', 'Dark'];
        $nouns = ['Agent', 'Broker', 'Client', 'Dealer', 'Expert', 'Funder', 'Guide', 'Helper', 'Intern', 'Judge'];
        return $adjectives[array_rand($adjectives)] . ' ' . $nouns[array_rand($nouns)] . ' ' . mt_rand(1, 9999);
    }

    // ── Property Tests ──────────────────────────────────────────────────────

    /**
     * Property 11: Types with module_id = NULL are always visible regardless of
     * which modules are active or inactive.
     *
     * **Validates: Requirements 14.1**
     */
    public function testTypesWithNoModuleAreAlwaysVisible(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Create a type with no module link
            $name = $this->randomTypeName();
            $typeId = $this->createTestPayeeType($name, null);

            ModuleActivationService::clearCache();
            $visibleIds = $this->getVisibleTypeIds();

            $this->assertContains($typeId, $visibleIds, sprintf(
                'Iteration %d: Type "%s" (id=%d) with module_id=NULL should always be visible.',
                $i, $name, $typeId
            ));
        }
    }

    /**
     * Property 11: Types with a module_id that IS active for the business are visible.
     *
     * **Validates: Requirements 14.1**
     */
    public function testTypesWithActiveModuleAreVisible(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Create a unique module and activate it
            $modCode = $this->randomModuleCode();
            $modId = $this->createTestModule($modCode);
            $btmId = $this->activateModule($modId);

            // Create a type linked to this active module
            $name = $this->randomTypeName();
            $typeId = $this->createTestPayeeType($name, $modId);

            ModuleActivationService::clearCache();
            $visibleIds = $this->getVisibleTypeIds();

            $this->assertContains($typeId, $visibleIds, sprintf(
                'Iteration %d: Type "%s" (id=%d) with active module "%s" should be visible.',
                $i, $name, $typeId, $modCode
            ));

            // Cleanup for next iteration to avoid accumulation
            $this->deactivateModule($btmId);
        }
    }

    /**
     * Property 11: Types with a module_id that is NOT active for the business are hidden.
     *
     * **Validates: Requirements 14.1, 14.3**
     */
    public function testTypesWithInactiveModuleAreHidden(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Create a module but do NOT activate it for this business
            $modCode = $this->randomModuleCode();
            $modId = $this->createTestModule($modCode);

            // Create a type linked to this inactive module
            $name = $this->randomTypeName();
            $typeId = $this->createTestPayeeType($name, $modId);

            ModuleActivationService::clearCache();
            $visibleIds = $this->getVisibleTypeIds();

            $this->assertNotContains($typeId, $visibleIds, sprintf(
                'Iteration %d: Type "%s" (id=%d) with inactive module "%s" should NOT be visible.',
                $i, $name, $typeId, $modCode
            ));
        }
    }

    /**
     * Property 11: Toggling a module on/off changes type visibility.
     *
     * When a module is activated, its linked types become visible.
     * When the module is deactivated, those types disappear.
     *
     * **Validates: Requirements 14.1, 14.3**
     */
    public function testTogglingModuleChangesTypeVisibility(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Create a module (initially inactive)
            $modCode = $this->randomModuleCode();
            $modId = $this->createTestModule($modCode);

            // Create a type linked to this module
            $name = $this->randomTypeName();
            $typeId = $this->createTestPayeeType($name, $modId);

            // Step 1: Module inactive → type should be hidden
            ModuleActivationService::clearCache();
            $visibleIds = $this->getVisibleTypeIds();
            $this->assertNotContains($typeId, $visibleIds, sprintf(
                'Iteration %d (step 1): Type "%s" with inactive module should be hidden.',
                $i, $name
            ));

            // Step 2: Activate module → type should become visible
            $btmId = $this->activateModule($modId);
            $visibleIds = $this->getVisibleTypeIds();
            $this->assertContains($typeId, $visibleIds, sprintf(
                'Iteration %d (step 2): Type "%s" with now-active module should be visible.',
                $i, $name
            ));

            // Step 3: Deactivate module → type should be hidden again
            $this->deactivateModule($btmId);
            $visibleIds = $this->getVisibleTypeIds();
            $this->assertNotContains($typeId, $visibleIds, sprintf(
                'Iteration %d (step 3): Type "%s" with deactivated module should be hidden again.',
                $i, $name
            ));
        }
    }

    /**
     * Property 11: The findAllByBusiness query in PeopleRepository also applies
     * module gating — types linked to inactive modules are excluded from
     * the person listing's type labels.
     *
     * This tests that even when a person has an assigned type whose module is
     * inactive, the GROUP_CONCAT'd payee_types does not include that type name.
     *
     * **Validates: Requirements 14.3**
     */
    public function testPeopleListingExcludesInactiveModuleTypes(): void
    {
        // Create a module (initially inactive)
        $modCode = $this->randomModuleCode();
        $modId = $this->createTestModule($modCode);

        // Create a payee type linked to this inactive module (non-system for this test)
        $stmt = $this->db->prepare("
            INSERT INTO payee_types (tenant_id, business_id, name, is_system, module_id, code, created_at)
            VALUES (:tenant_id, NULL, :name, 1, :module_id, :code, NOW())
        ");
        $typeName = 'GatingTest_' . mt_rand(10000, 99999);
        $typeCode = 'GATING_TEST_' . mt_rand(10000, 99999);
        $stmt->execute([
            'tenant_id' => null,
            'name' => $typeName,
            'module_id' => $modId,
            'code' => $typeCode,
        ]);
        $typeId = (int) $this->db->lastInsertId();
        $this->testTypeIds[] = $typeId;

        // Create a test person and payee, assign this type
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                INSERT INTO people (tenant_id, name, phone, created_at)
                VALUES (:tenant_id, :name, :phone, NOW())
            ");
            $personName = 'TestPerson_' . mt_rand(10000, 99999);
            $stmt->execute(['tenant_id' => $this->tenantId, 'name' => $personName, 'phone' => '0000000']);
            $personId = (int) $this->db->lastInsertId();

            $stmt = $this->db->prepare("
                INSERT INTO payees (people_id, business_id, balance, created_at)
                VALUES (:people_id, :business_id, 0, NOW())
            ");
            $stmt->execute(['people_id' => $personId, 'business_id' => $this->businessId]);
            $payeeId = (int) $this->db->lastInsertId();

            // Also assign a regular type (no module) so the person isn't excluded by HAVING
            $stmt = $this->db->prepare("
                INSERT INTO people_types (tenant_id, people_id, payee_type_id, business_id)
                VALUES (:tenant_id, :people_id, :type_id, :business_id)
            ");
            // Assign the module-gated type
            $stmt->execute(['tenant_id' => $this->tenantId, 'people_id' => $personId, 'type_id' => $typeId, 'business_id' => $this->businessId]);
            // Also assign a NULL-module type (type id 1 = Customer which has no module)
            $stmt->execute(['tenant_id' => $this->tenantId, 'people_id' => $personId, 'type_id' => 1, 'business_id' => $this->businessId]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->markTestSkipped('Could not create test person: ' . $e->getMessage());
            return;
        }

        try {
            // Module is inactive — the type name should NOT appear in listing
            ModuleActivationService::clearCache();
            $repo = new PeopleRepository($this->db);
            $people = $repo->findAllByBusiness($this->tenantId, $this->businessId);

            $testPerson = null;
            foreach ($people as $p) {
                if ((int) ($p['payee_id'] ?? 0) === $payeeId) {
                    $testPerson = $p;
                    break;
                }
            }

            $this->assertNotNull($testPerson, 'Test person should appear in listing.');
            $this->assertStringNotContainsString($typeName, $testPerson['payee_types'] ?? '', sprintf(
                'Type "%s" linked to inactive module should not appear in payee_types column.',
                $typeName
            ));

            // Now activate the module — type name SHOULD appear
            $btmId = $this->activateModule($modId);
            $people = $repo->findAllByBusiness($this->tenantId, $this->businessId);

            $testPerson = null;
            foreach ($people as $p) {
                if ((int) ($p['payee_id'] ?? 0) === $payeeId) {
                    $testPerson = $p;
                    break;
                }
            }

            $this->assertNotNull($testPerson, 'Test person should still appear in listing after module activation.');
            $this->assertStringContainsString($typeName, $testPerson['payee_types'] ?? '', sprintf(
                'Type "%s" linked to now-active module should appear in payee_types column.',
                $typeName
            ));

            // Deactivate again — type name should disappear
            $this->deactivateModule($btmId);
            $people = $repo->findAllByBusiness($this->tenantId, $this->businessId);

            $testPerson = null;
            foreach ($people as $p) {
                if ((int) ($p['payee_id'] ?? 0) === $payeeId) {
                    $testPerson = $p;
                    break;
                }
            }

            $this->assertNotNull($testPerson, 'Test person should still appear after module deactivation.');
            $this->assertStringNotContainsString($typeName, $testPerson['payee_types'] ?? '', sprintf(
                'Type "%s" linked to deactivated module should disappear from payee_types column.',
                $typeName
            ));
        } finally {
            // Cleanup test person data
            $this->db->prepare("DELETE FROM people_types WHERE people_id = ?")->execute([$personId]);
            $this->db->prepare("DELETE FROM payees WHERE id = ?")->execute([$payeeId]);
            $this->db->prepare("DELETE FROM people WHERE id = ?")->execute([$personId]);
        }
    }
}
