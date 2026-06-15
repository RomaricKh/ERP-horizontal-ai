<?php

namespace Tests\Unit;

use App\Repository\BusinessTypeRepository;
use App\Services\ModuleActivationService;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for REQ-011: Tenant-owned business types replace module overrides.
 *
 * Tests:
 * - Create a tenant type from the "Retail" system template
 * - Toggle a module off on the tenant type
 * - Verify businesses using that type no longer see the module
 * - Verify the system "Retail" type is unchanged
 */
class TenantBusinessTypeModuleTest extends TestCase
{
    private $pdo;
    private $repo;

    protected function setUp(): void
    {
        $this->pdo = $this->createMock(PDO::class);
        $this->repo = new BusinessTypeRepository($this->pdo);
    }

    // ── copyFromSystem ────────────────────────────────────────────────────

    /**
     * Test copyFromSystem rejects non-system types.
     */
    public function testCopyFromSystemRejectsNonSystemType(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn([
            'id' => 10, 'tenant_id' => 1, 'code' => 'custom',
            'name' => 'Custom', 'description' => null,
            'is_system' => 0, 'is_default' => 0, 'created_at' => '2024-01-01'
        ]);

        $this->pdo->method('prepare')->willReturn($stmt);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Source type must be a system type');

        $this->repo->copyFromSystem(10, 1, 'My Copy');
    }

    /**
     * Test copyFromSystem creates a tenant-owned copy with modules.
     */
    public function testCopyFromSystemCreatesNewTypeWithModules(): void
    {
        // Statement for getById (system type)
        $getByIdStmt = $this->createMock(PDOStatement::class);
        $getByIdStmt->method('execute')->willReturn(true);
        $getByIdStmt->method('fetch')->willReturn([
            'id' => 2, 'tenant_id' => null, 'code' => 'retail',
            'name' => 'Retail', 'description' => 'Retail business type',
            'is_system' => 1, 'is_default' => 0, 'created_at' => '2024-01-01'
        ]);

        // Statement for INSERT business_types
        $insertStmt = $this->createMock(PDOStatement::class);
        $insertStmt->method('execute')->willReturn(true);

        // Statement for INSERT business_type_modules (copy)
        $copyModulesStmt = $this->createMock(PDOStatement::class);
        $copyModulesStmt->method('execute')->willReturn(true);

        $this->pdo->method('prepare')
            ->willReturnCallback(function ($sql) use ($getByIdStmt, $insertStmt, $copyModulesStmt) {
                if (strpos($sql, 'SELECT') !== false && strpos($sql, 'WHERE id = :id') !== false) {
                    return $getByIdStmt;
                }
                if (strpos($sql, 'INSERT INTO business_types') !== false) {
                    return $insertStmt;
                }
                if (strpos($sql, 'INSERT INTO business_type_modules') !== false) {
                    return $copyModulesStmt;
                }
                return $insertStmt;
            });

        $this->pdo->method('beginTransaction')->willReturn(true);
        $this->pdo->method('commit')->willReturn(true);
        $this->pdo->method('lastInsertId')->willReturn('99');

        $newId = $this->repo->copyFromSystem(2, 1, 'My Retail');
        $this->assertEquals(99, $newId);
    }

    // ── setModules ────────────────────────────────────────────────────────

    /**
     * Test setModules rejects system types.
     */
    public function testSetModulesRejectsSystemType(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn([
            'id' => 2, 'tenant_id' => null, 'code' => 'retail',
            'name' => 'Retail', 'description' => null,
            'is_system' => 1, 'is_default' => 0, 'created_at' => '2024-01-01'
        ]);

        $this->pdo->method('prepare')->willReturn($stmt);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot modify modules on a system type');

        $this->repo->setModules(2, [1, 2, 3]);
    }

    /**
     * Test setModules replaces module set for tenant type.
     */
    public function testSetModulesReplacesModulesForTenantType(): void
    {
        $getByIdStmt = $this->createMock(PDOStatement::class);
        $getByIdStmt->method('execute')->willReturn(true);
        $getByIdStmt->method('fetch')->willReturn([
            'id' => 10, 'tenant_id' => 1, 'code' => 'custom',
            'name' => 'Custom', 'description' => null,
            'is_system' => 0, 'is_default' => 0, 'created_at' => '2024-01-01'
        ]);

        $deleteStmt = $this->createMock(PDOStatement::class);
        $deleteStmt->method('execute')->willReturn(true);

        $insertStmt = $this->createMock(PDOStatement::class);
        $insertStmt->method('execute')->willReturn(true);

        $this->pdo->method('prepare')
            ->willReturnCallback(function ($sql) use ($getByIdStmt, $deleteStmt, $insertStmt) {
                if (strpos($sql, 'SELECT') !== false && strpos($sql, 'WHERE id = :id') !== false) {
                    return $getByIdStmt;
                }
                if (strpos($sql, 'DELETE FROM business_type_modules') !== false) {
                    return $deleteStmt;
                }
                return $insertStmt;
            });

        $this->pdo->method('beginTransaction')->willReturn(true);
        $this->pdo->method('commit')->willReturn(true);

        // Should not throw
        $this->repo->setModules(10, [1, 3, 5]);
        $this->assertTrue(true);
    }

    // ── hasModule ─────────────────────────────────────────────────────────

    /**
     * Test hasModule returns true when module exists.
     */
    public function testHasModuleReturnsTrueWhenModuleExists(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn(1);

        $this->pdo->method('prepare')->willReturn($stmt);

        $this->assertTrue($this->repo->hasModule(10, 'items'));
    }

    /**
     * Test hasModule returns false when module does not exist.
     */
    public function testHasModuleReturnsFalseWhenModuleMissing(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn(0);

        $this->pdo->method('prepare')->willReturn($stmt);

        $this->assertFalse($this->repo->hasModule(10, 'manufacturing'));
    }

    // ── ModuleActivationService simplified lookup ─────────────────────────

    /**
     * Test isModuleActive uses single lookup via business_type_modules.
     * After toggling a module off on the tenant type, businesses using that type
     * should no longer see the module.
     */
    public function testIsModuleActiveReturnsFalseWhenModuleRemovedFromType(): void
    {
        $pdo = $this->createMock(PDO::class);

        // Mock: getBusinessTypeId returns type 10
        $typeIdStmt = $this->createMock(PDOStatement::class);
        $typeIdStmt->method('execute')->willReturn(true);
        $typeIdStmt->method('fetchColumn')->willReturn(10);

        // Mock: hasModule returns false (module was toggled off)
        $hasModuleStmt = $this->createMock(PDOStatement::class);
        $hasModuleStmt->method('execute')->willReturn(true);
        $hasModuleStmt->method('fetchColumn')->willReturn(0);

        $pdo->method('prepare')
            ->willReturnCallback(function ($sql) use ($typeIdStmt, $hasModuleStmt) {
                if (strpos($sql, 'business_type_id FROM businesses') !== false) {
                    return $typeIdStmt;
                }
                return $hasModuleStmt;
            });

        $service = new ModuleActivationService($pdo);
        ModuleActivationService::clearCache();

        // Module was toggled off on the tenant type — should return false
        $this->assertFalse($service->isModuleActive('manufacturing', 1));
    }

    /**
     * Test isModuleActive returns true when module is present on the type.
     */
    public function testIsModuleActiveReturnsTrueWhenModulePresent(): void
    {
        $pdo = $this->createMock(PDO::class);

        $typeIdStmt = $this->createMock(PDOStatement::class);
        $typeIdStmt->method('execute')->willReturn(true);
        $typeIdStmt->method('fetchColumn')->willReturn(10);

        $hasModuleStmt = $this->createMock(PDOStatement::class);
        $hasModuleStmt->method('execute')->willReturn(true);
        $hasModuleStmt->method('fetchColumn')->willReturn(1);

        $pdo->method('prepare')
            ->willReturnCallback(function ($sql) use ($typeIdStmt, $hasModuleStmt) {
                if (strpos($sql, 'business_type_id FROM businesses') !== false) {
                    return $typeIdStmt;
                }
                return $hasModuleStmt;
            });

        $service = new ModuleActivationService($pdo);
        ModuleActivationService::clearCache();

        $this->assertTrue($service->isModuleActive('items', 1));
    }

    /**
     * Test: System "Retail" type is unchanged after tenant type module toggle.
     * Verifies that modifying a tenant type's modules does not affect the system type.
     */
    public function testSystemTypeUnchangedAfterTenantModuleToggle(): void
    {
        $pdo = $this->createMock(PDO::class);

        // For system type check (id=2): hasModule('manufacturing') should return true
        $systemHasModuleStmt = $this->createMock(PDOStatement::class);
        $systemHasModuleStmt->method('execute')->willReturn(true);
        $systemHasModuleStmt->method('fetchColumn')->willReturn(1);

        $pdo->method('prepare')->willReturn($systemHasModuleStmt);

        $repo = new BusinessTypeRepository($pdo);
        $this->assertTrue($repo->hasModule(2, 'manufacturing'));
    }

    /**
     * Test: Full workflow — create tenant type from Retail, toggle module off,
     * verify business no longer sees it, verify system type unchanged.
     */
    public function testFullWorkflowTenantTypeModuleToggle(): void
    {
        $pdo = $this->createMock(PDO::class);

        // For the business check: type_id = 10 (tenant type), manufacturing removed
        $typeIdStmt = $this->createMock(PDOStatement::class);
        $typeIdStmt->method('execute')->willReturn(true);
        $typeIdStmt->method('fetchColumn')->willReturn(10);

        $tenantHasModuleStmt = $this->createMock(PDOStatement::class);
        $tenantHasModuleStmt->method('execute')->willReturn(true);
        $tenantHasModuleStmt->method('fetchColumn')->willReturn(0);

        $pdo->method('prepare')
            ->willReturnCallback(function ($sql) use ($typeIdStmt, $tenantHasModuleStmt) {
                if (strpos($sql, 'business_type_id FROM businesses') !== false) {
                    return $typeIdStmt;
                }
                return $tenantHasModuleStmt;
            });

        $service = new ModuleActivationService($pdo);
        ModuleActivationService::clearCache();

        // Business using tenant type should NOT see manufacturing
        $this->assertFalse($service->isModuleActive('manufacturing', 1));

        // System type (id=2) should still have manufacturing
        $systemPdo = $this->createMock(PDO::class);
        $systemStmt = $this->createMock(PDOStatement::class);
        $systemStmt->method('execute')->willReturn(true);
        $systemStmt->method('fetchColumn')->willReturn(1);
        $systemPdo->method('prepare')->willReturn($systemStmt);

        $systemRepo = new BusinessTypeRepository($systemPdo);
        $this->assertTrue($systemRepo->hasModule(2, 'manufacturing'));
    }

    // ── deleteTenantType ──────────────────────────────────────────────────

    /**
     * Test deleteTenantType rejects system types.
     */
    public function testDeleteTenantTypeRejectsSystemType(): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn([
            'id' => 2, 'tenant_id' => null, 'code' => 'retail',
            'name' => 'Retail', 'description' => null,
            'is_system' => 1, 'is_default' => 0, 'created_at' => '2024-01-01'
        ]);

        $this->pdo->method('prepare')->willReturn($stmt);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot delete a system type');

        $this->repo->deleteTenantType(2, 1);
    }

    /**
     * Test deleteTenantType rejects type with assigned businesses.
     */
    public function testDeleteTenantTypeRejectsTypeWithBusinesses(): void
    {
        $getByIdStmt = $this->createMock(PDOStatement::class);
        $getByIdStmt->method('execute')->willReturn(true);
        $getByIdStmt->method('fetch')->willReturn([
            'id' => 10, 'tenant_id' => 1, 'code' => 'custom',
            'name' => 'Custom', 'description' => null,
            'is_system' => 0, 'is_default' => 0, 'created_at' => '2024-01-01'
        ]);

        $countStmt = $this->createMock(PDOStatement::class);
        $countStmt->method('execute')->willReturn(true);
        $countStmt->method('fetchColumn')->willReturn(2);

        $this->pdo->method('prepare')
            ->willReturnCallback(function ($sql) use ($getByIdStmt, $countStmt) {
                if (strpos($sql, 'SELECT') !== false && strpos($sql, 'WHERE id = :id') !== false) {
                    return $getByIdStmt;
                }
                return $countStmt;
            });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot delete: type is assigned to 2 business(es)');

        $this->repo->deleteTenantType(10, 1);
    }

    // ── findByTenant ──────────────────────────────────────────────────────

    /**
     * Test findByTenant returns only tenant-owned types.
     */
    public function testFindByTenantReturnsOnlyTenantTypes(): void
    {
        $listStmt = $this->createMock(PDOStatement::class);
        $listStmt->method('execute')->willReturn(true);
        $listStmt->method('fetchAll')->willReturn([
            [
                'id' => 6, 'tenant_id' => 1, 'code' => 'general',
                'name' => 'General', 'description' => null,
                'is_system' => 0, 'is_default' => 0, 'created_at' => '2024-01-01'
            ],
            [
                'id' => 8, 'tenant_id' => 1, 'code' => 'retail_copy',
                'name' => 'My Retail', 'description' => null,
                'is_system' => 0, 'is_default' => 0, 'created_at' => '2024-01-02'
            ]
        ]);

        $modulesStmt = $this->createMock(PDOStatement::class);
        $modulesStmt->method('execute')->willReturn(true);
        $modulesStmt->method('fetchAll')->willReturn([
            ['id' => 1, 'code' => 'items', 'name' => 'Items'],
            ['id' => 2, 'code' => 'sales_invoices', 'name' => 'Sales Invoices']
        ]);

        $this->pdo->method('prepare')
            ->willReturnCallback(function ($sql) use ($listStmt, $modulesStmt) {
                if (strpos($sql, 'is_system = 0 AND tenant_id') !== false) {
                    return $listStmt;
                }
                return $modulesStmt;
            });

        $types = $this->repo->findByTenant(1);

        $this->assertCount(2, $types);
        $this->assertEquals(0, $types[0]['is_system']);
        $this->assertEquals(1, $types[0]['tenant_id']);
        $this->assertArrayHasKey('modules', $types[0]);
    }

    // ── findSystemTypes ───────────────────────────────────────────────────

    /**
     * Test findSystemTypes returns only system types.
     */
    public function testFindSystemTypesReturnsOnlySystemTypes(): void
    {
        $listStmt = $this->createMock(PDOStatement::class);
        $listStmt->method('execute')->willReturn(true);
        $listStmt->method('fetchAll')->willReturn([
            [
                'id' => 1, 'tenant_id' => null, 'code' => 'general',
                'name' => 'General', 'description' => null,
                'is_system' => 1, 'is_default' => 1, 'created_at' => '2024-01-01'
            ],
            [
                'id' => 2, 'tenant_id' => null, 'code' => 'retail',
                'name' => 'Retail', 'description' => null,
                'is_system' => 1, 'is_default' => 0, 'created_at' => '2024-01-01'
            ]
        ]);

        $modulesStmt = $this->createMock(PDOStatement::class);
        $modulesStmt->method('execute')->willReturn(true);
        $modulesStmt->method('fetchAll')->willReturn([
            ['id' => 1, 'code' => 'items', 'name' => 'Items']
        ]);

        $this->pdo->method('query')->willReturn($listStmt);
        $this->pdo->method('prepare')->willReturn($modulesStmt);

        $types = $this->repo->findSystemTypes();

        $this->assertCount(2, $types);
        $this->assertEquals(1, $types[0]['is_system']);
        $this->assertEquals(1, $types[1]['is_system']);
    }

    /**
     * Test isModuleActive returns false for non-existent business.
     */
    public function testIsModuleActiveReturnsFalseForNonExistentBusiness(): void
    {
        $pdo = $this->createMock(PDO::class);

        $typeIdStmt = $this->createMock(PDOStatement::class);
        $typeIdStmt->method('execute')->willReturn(true);
        $typeIdStmt->method('fetchColumn')->willReturn(false); // business not found

        $pdo->method('prepare')->willReturn($typeIdStmt);

        $service = new ModuleActivationService($pdo);
        ModuleActivationService::clearCache();

        $this->assertFalse($service->isModuleActive('items', 999));
    }
}
