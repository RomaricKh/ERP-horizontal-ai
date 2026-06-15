<?php

namespace App\Services;

use App\Repository\BusinessTypeRepository;
use App\Repository\BusinessModuleOverrideRepository;
use App\Repository\ModuleRepository;
use PDO;

class BusinessTypeService
{
    private PDO $db;
    private BusinessTypeRepository $businessTypeRepo;
    private BusinessModuleOverrideRepository $overrideRepo;
    private ModuleRepository $moduleRepo;
    private AuditService $audit;

    /**
     * Core modules that cannot be deactivated.
     */
    private const CORE_MODULES = ['auth', 'users', 'businesses'];

    /**
     * Mapping of module codes to their associated database tables.
     * Used by getModuleRecordCount to count records per module.
     */
    private const MODULE_TABLE_MAP = [
        'people'          => ['people'],
        'items'           => ['items'],
        'stock'           => ['stock_movements'],
        'purchase_orders' => ['purchase_orders', 'purchase_order_items'],
        'sales_invoices'  => ['sales_invoices', 'sales_invoice_items'],
        'manufacturing'   => ['manufacturing_recipes', 'manufacturing_orders'],
        'accounts'        => ['accounts'],
        'expenses'        => ['expenses'],
        'payments'        => ['payments'],
        'collections'     => ['collections'],
        'salaries'        => ['salaries'],
        'exchange_rates'  => ['exchange_rates'],
    ];

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->businessTypeRepo = new BusinessTypeRepository($db);
        $this->overrideRepo = new BusinessModuleOverrideRepository($db);
        $this->moduleRepo = new ModuleRepository($db);
        $this->audit = new AuditService($db);
    }

    /**
     * Get all business types with their module codes.
     */
    public function getAllTypes(): array
    {
        $types = $this->businessTypeRepo->getAll();

        foreach ($types as &$type) {
            $type['modules'] = $this->businessTypeRepo->getModuleCodesForType((int) $type['id']);
        }

        return $types;
    }

    /**
     * Validate that a business_type_id exists. Returns the type or null.
     */
    public function getTypeById(int $id): ?array
    {
        return $this->businessTypeRepo->getById($id);
    }

    /**
     * Change a business's type.
     * If force=false and modules with data would be deactivated, returns warning.
     * If force=true, applies change + cleans redundant overrides + audits.
     * Executes in a single transaction.
     *
     * @return array ['success' => bool, 'warning' => ?array, 'message' => ?string]
     */
    public function changeBusinessType(int $businessId, int $newTypeId, bool $force, int $userId): array
    {
        // Validate the new type exists
        $newType = $this->businessTypeRepo->getById($newTypeId);
        if (!$newType) {
            return [
                'success' => false,
                'warning' => null,
                'message' => 'Business type does not exist'
            ];
        }

        // If not forcing, check for affected modules with data
        if (!$force) {
            $affectedModules = $this->getAffectedModulesOnTypeChange($businessId, $newTypeId);
            if (!empty($affectedModules)) {
                return [
                    'success' => false,
                    'warning' => [
                        'warning' => true,
                        'affected_modules' => $affectedModules
                    ],
                    'message' => 'Modules with existing data will be deactivated. Set force=true to confirm.'
                ];
            }
        }

        // Apply the change in a transaction
        $this->db->beginTransaction();

        try {
            // Get the current business type for audit logging
            $stmt = $this->db->prepare("
                SELECT business_type_id FROM businesses WHERE id = :business_id
            ");
            $stmt->execute(['business_id' => $businessId]);
            $currentTypeId = (int) $stmt->fetchColumn();

            // Update the business type
            $stmt = $this->db->prepare("
                UPDATE businesses
                SET business_type_id = :new_type_id, updated_at = NOW()
                WHERE id = :business_id
            ");
            $stmt->execute([
                'new_type_id' => $newTypeId,
                'business_id' => $businessId
            ]);

            // Clean redundant overrides
            $newTypeModules = $this->moduleRepo->getModulesForBusinessType($newTypeId);
            $newTypeModuleIds = array_map(function ($m) {
                return (int) $m['id'];
            }, $newTypeModules);
            $this->overrideRepo->deleteRedundantOverrides($businessId, $newTypeModuleIds);

            // Audit log
            $this->audit->log(
                'BUSINESS_TYPE_CHANGE',
                'businesses',
                $businessId,
                'UPDATE',
                ['business_type_id' => $currentTypeId],
                ['business_type_id' => $newTypeId],
                $userId,
                null,
                $businessId
            );

            $this->db->commit();

            return [
                'success' => true,
                'warning' => null,
                'message' => 'Business type updated successfully'
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return [
                'success' => false,
                'warning' => null,
                'message' => 'Failed to update business type'
            ];
        }
    }

    /**
     * Check which modules would be deactivated and have existing data.
     * Returns array of ['module_code' => string, 'record_count' => int]
     */
    public function getAffectedModulesOnTypeChange(int $businessId, int $newTypeId): array
    {
        // Get current active modules for this business
        $stmt = $this->db->prepare("
            SELECT business_type_id FROM businesses WHERE id = :business_id
        ");
        $stmt->execute(['business_id' => $businessId]);
        $currentTypeId = $stmt->fetchColumn();

        if ($currentTypeId === false) {
            return [];
        }

        // Get current type's module codes
        $currentModuleCodes = $this->businessTypeRepo->getModuleCodesForType((int) $currentTypeId);

        // Get overrides for this business
        $overrides = $this->overrideRepo->getOverridesForBusiness($businessId);

        // Resolve currently active modules: (type_defaults + activate_overrides) - deactivate_overrides
        $activeSet = array_flip($currentModuleCodes);
        foreach ($overrides as $code => $action) {
            if ($action === 'activate') {
                $activeSet[$code] = true;
            } elseif ($action === 'deactivate') {
                unset($activeSet[$code]);
            }
        }
        $currentlyActive = array_keys($activeSet);

        // Get new type's module codes
        $newModuleCodes = $this->businessTypeRepo->getModuleCodesForType($newTypeId);

        // Modules that would be deactivated: currently active but NOT in new type defaults
        // (overrides will be cleaned up, so we consider only new type defaults)
        $wouldBeDeactivated = array_diff($currentlyActive, $newModuleCodes);

        // Filter out core modules (they can never be deactivated)
        $wouldBeDeactivated = array_filter($wouldBeDeactivated, function ($code) {
            return !in_array($code, self::CORE_MODULES, true);
        });

        // Check which of these have data
        $affected = [];
        foreach ($wouldBeDeactivated as $moduleCode) {
            $count = $this->getModuleRecordCount($moduleCode, $businessId);
            if ($count > 0) {
                $affected[] = [
                    'module_code' => $moduleCode,
                    'record_count' => $count
                ];
            }
        }

        return $affected;
    }

    /**
     * Get the record count for a module's tables in a given business.
     * Uses the module's associated tables (mapped by convention).
     */
    public function getModuleRecordCount(string $moduleCode, int $businessId): int
    {
        if (!isset(self::MODULE_TABLE_MAP[$moduleCode])) {
            return 0;
        }

        $tables = self::MODULE_TABLE_MAP[$moduleCode];
        $totalCount = 0;

        foreach ($tables as $table) {
            try {
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) FROM `{$table}` WHERE business_id = :business_id
                ");
                $stmt->execute(['business_id' => $businessId]);
                $totalCount += (int) $stmt->fetchColumn();
            } catch (\Throwable $e) {
                // Table might not exist or not have business_id column — skip
                continue;
            }
        }

        return $totalCount;
    }
}
