<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Repository\SystemTypeRepository;
use App\Repository\ItemTypeRepository;
use App\Repository\PayeeTypeRepository;
use App\Services\ModuleActivationService;
use PDO;

/**
 * Tests for REQ-031: System-defined types with module visibility gating.
 *
 * Task 31.12: Disable Manufacturing module → verify RAW_MATERIAL and MANUFACTURED
 *             item types are not shown; re-enable → verify they reappear.
 *
 * Task 31.13: Create a person with type SUPPLIER only → verify they cannot be
 *             selected as a customer; add CUSTOMER type → verify they appear.
 */
class SystemTypeModuleGatingTest extends TestCase {
    private PDO $db;
    private SystemTypeRepository $systemTypeRepo;
    private ItemTypeRepository $itemTypeRepo;
    private PayeeTypeRepository $payeeTypeRepo;

    protected function setUp(): void {
        $this->db = new PDO(
            'mysql:host=localhost;dbname=erp;charset=utf8mb4',
            'root',
            '',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );

        $this->systemTypeRepo = new SystemTypeRepository($this->db);
        $this->itemTypeRepo = new ItemTypeRepository($this->db);
        $this->payeeTypeRepo = new PayeeTypeRepository($this->db);
    }

    // ── Task 31.12: Module gating for item types ─────────────────────────────

    /**
     * Test that RAW_MATERIAL and MANUFACTURED item types are hidden when
     * the Manufacturing module is not active for the business.
     */
    public function testManufacturingTypesHiddenWhenModuleInactive(): void {
        // Get the business type for business 1
        $stmt = $this->db->prepare("SELECT business_type_id FROM businesses WHERE id = 1");
        $stmt->execute();
        $businessTypeId = (int) $stmt->fetchColumn();

        // Get the manufacturing module ID
        $stmt = $this->db->prepare("SELECT id FROM modules WHERE code = 'manufacturing'");
        $stmt->execute();
        $mfgModuleId = $stmt->fetchColumn();

        if (!$mfgModuleId) {
            $this->markTestSkipped('Manufacturing module not found in modules table');
        }

        // Check if manufacturing module is currently active for this business type
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM business_type_modules WHERE business_type_id = ? AND module_id = ?"
        );
        $stmt->execute([$businessTypeId, $mfgModuleId]);
        $wasActive = (int) $stmt->fetchColumn() > 0;

        // Ensure manufacturing module is REMOVED from the business type
        if ($wasActive) {
            $this->db->prepare(
                "DELETE FROM business_type_modules WHERE business_type_id = ? AND module_id = ?"
            )->execute([$businessTypeId, $mfgModuleId]);
        }

        // Clear the module activation cache
        ModuleActivationService::clearCache();

        // Fetch item types for business 1 — should NOT include RAW_MATERIAL or MANUFACTURED
        $types = $this->itemTypeRepo->findForBusiness(1, 1);
        $codes = array_column($types, 'code');

        $this->assertNotContains('RAW_MATERIAL', $codes,
            'RAW_MATERIAL should be hidden when manufacturing module is inactive');
        $this->assertNotContains('MANUFACTURED', $codes,
            'MANUFACTURED should be hidden when manufacturing module is inactive');

        // Types without module_code should still be visible
        $this->assertContains('TRADING', $codes, 'TRADING should always be visible');
        $this->assertContains('CONSUMABLE', $codes, 'CONSUMABLE should always be visible');
        $this->assertContains('FINISHED_GOOD', $codes, 'FINISHED_GOOD should always be visible');

        // Now RE-ENABLE manufacturing module
        $this->db->prepare(
            "INSERT INTO business_type_modules (business_type_id, module_id, created_at) VALUES (?, ?, NOW())"
        )->execute([$businessTypeId, $mfgModuleId]);

        // Clear cache again
        ModuleActivationService::clearCache();

        // Fetch item types again — should now include RAW_MATERIAL and MANUFACTURED
        $typesAfter = $this->itemTypeRepo->findForBusiness(1, 1);
        $codesAfter = array_column($typesAfter, 'code');

        $this->assertContains('RAW_MATERIAL', $codesAfter,
            'RAW_MATERIAL should reappear when manufacturing module is re-enabled');
        $this->assertContains('MANUFACTURED', $codesAfter,
            'MANUFACTURED should reappear when manufacturing module is re-enabled');

        // Cleanup: restore original state
        if (!$wasActive) {
            $this->db->prepare(
                "DELETE FROM business_type_modules WHERE business_type_id = ? AND module_id = ?"
            )->execute([$businessTypeId, $mfgModuleId]);
        }
    }

    /**
     * Test that system types without module_code are always visible regardless
     * of module activation state.
     */
    public function testSystemTypesWithoutModuleCodeAlwaysVisible(): void {
        ModuleActivationService::clearCache();

        $types = $this->itemTypeRepo->findForBusiness(1, 1);
        $codes = array_column($types, 'code');

        // These have module_code = NULL, so they should always be visible
        $this->assertContains('TRADING', $codes);
        $this->assertContains('CONSUMABLE', $codes);
        $this->assertContains('FINISHED_GOOD', $codes);
    }

    /**
     * Test that system types cannot be edited.
     */
    public function testSystemTypeCannotBeEdited(): void {
        // Find a system type
        $stmt = $this->db->prepare(
            "SELECT id FROM item_types WHERE is_system = 1 AND tenant_id = 1 LIMIT 1"
        );
        $stmt->execute();
        $systemTypeId = (int) $stmt->fetchColumn();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot modify a system-defined item type');

        $this->itemTypeRepo->update($systemTypeId, 1, 'Renamed System Type');
    }

    /**
     * Test that system types cannot be deleted.
     */
    public function testSystemTypeCannotBeDeleted(): void {
        $stmt = $this->db->prepare(
            "SELECT id FROM item_types WHERE is_system = 1 AND tenant_id = 1 LIMIT 1"
        );
        $stmt->execute();
        $systemTypeId = (int) $stmt->fetchColumn();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot delete a system-defined item type');

        $this->itemTypeRepo->delete($systemTypeId, 1);
    }

    // ── Task 31.13: People type filtering ────────────────────────────────────

    /**
     * Test that a person with only SUPPLIER type cannot be found as a customer,
     * and adding CUSTOMER type makes them appear in customer queries.
     */
    public function testPersonTypeFilteringForInvoiceAndPO(): void {
        // Create a test person
        $this->db->prepare(
            "INSERT INTO people (tenant_id, name, phone, created_at) VALUES (1, 'Test Supplier Only', '999-TEST-31', NOW())"
        )->execute();
        $personId = (int) $this->db->lastInsertId();

        // Create a payee record for this person in business 1
        $this->db->prepare(
            "INSERT INTO payees (people_id, business_id, balance, created_at) VALUES (?, 1, 0, NOW())"
        )->execute([$personId]);
        $payeeId = (int) $this->db->lastInsertId();

        // Get the SUPPLIER type ID
        $supplierType = $this->payeeTypeRepo->findByCode('SUPPLIER', 1);
        $this->assertNotNull($supplierType, 'SUPPLIER type should exist');

        // Get the CUSTOMER type ID
        $customerType = $this->payeeTypeRepo->findByCode('CUSTOMER', 1);
        $this->assertNotNull($customerType, 'CUSTOMER type should exist');

        // Assign ONLY SUPPLIER type to this person
        $this->payeeTypeRepo->setTypesForPerson($personId, [(int) $supplierType['id']]);

        // Verify person has SUPPLIER type
        $this->assertTrue(
            $this->payeeTypeRepo->personHasType($personId, 'SUPPLIER', 1),
            'Person should have SUPPLIER type'
        );

        // Verify person does NOT have CUSTOMER type
        $this->assertFalse(
            $this->payeeTypeRepo->personHasType($personId, 'CUSTOMER', 1),
            'Person should NOT have CUSTOMER type'
        );

        // Find people by CUSTOMER type — our test person should NOT be in the list
        $customers = $this->payeeTypeRepo->findPeopleByType((int) $customerType['id'], 1);
        $customerIds = array_map('intval', array_column($customers, 'id'));
        $this->assertNotContains($personId, $customerIds,
            'Supplier-only person should not appear in customer list');

        // Find people by SUPPLIER type — our test person SHOULD be in the list
        $suppliers = $this->payeeTypeRepo->findPeopleByType((int) $supplierType['id'], 1);
        $supplierIds = array_map('intval', array_column($suppliers, 'id'));
        $this->assertContains($personId, $supplierIds,
            'Person with SUPPLIER type should appear in supplier list');

        // Now ADD CUSTOMER type to this person
        $this->payeeTypeRepo->setTypesForPerson($personId, [
            (int) $supplierType['id'],
            (int) $customerType['id'],
        ]);

        // Verify person now has CUSTOMER type
        $this->assertTrue(
            $this->payeeTypeRepo->personHasType($personId, 'CUSTOMER', 1),
            'Person should now have CUSTOMER type'
        );

        // Find people by CUSTOMER type — our test person SHOULD now be in the list
        $customersAfter = $this->payeeTypeRepo->findPeopleByType((int) $customerType['id'], 1);
        $customerIdsAfter = array_map('intval', array_column($customersAfter, 'id'));
        $this->assertContains($personId, $customerIdsAfter,
            'Person with CUSTOMER type should now appear in customer list');

        // Cleanup
        $this->db->prepare("DELETE FROM people_types WHERE people_id = ?")->execute([$personId]);
        $this->db->prepare("DELETE FROM payees WHERE id = ?")->execute([$payeeId]);
        $this->db->prepare("DELETE FROM people WHERE id = ?")->execute([$personId]);
    }

    /**
     * Test that the people_types junction correctly supports multiple types per person.
     */
    public function testMultipleTypesPerPerson(): void {
        // Create a test person
        $this->db->prepare(
            "INSERT INTO people (tenant_id, name, phone, created_at) VALUES (1, 'Multi-Type Person', '999-MULTI-31', NOW())"
        )->execute();
        $personId = (int) $this->db->lastInsertId();

        // Get all type IDs
        $types = $this->payeeTypeRepo->findForBusiness(1, 1);
        $typeIds = array_column($types, 'id');

        // Assign multiple types
        $this->payeeTypeRepo->setTypesForPerson($personId, $typeIds);

        // Verify all types are assigned
        $assignedTypes = $this->payeeTypeRepo->getTypesForPerson($personId);
        $this->assertCount(count($typeIds), $assignedTypes,
            'Person should have all assigned types');

        // Cleanup
        $this->db->prepare("DELETE FROM people_types WHERE people_id = ?")->execute([$personId]);
        $this->db->prepare("DELETE FROM people WHERE id = ?")->execute([$personId]);
    }
}
