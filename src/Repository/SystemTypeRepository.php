<?php
namespace App\Repository;

use App\Services\ModuleActivationService;
use PDO;

/**
 * Generic repository for system-type classification tables.
 * Provides module-gated queries that filter system types by active modules.
 */
class SystemTypeRepository {
    private PDO $db;
    private ModuleActivationService $moduleService;

    /** @var string[] Allowed table names to prevent SQL injection */
    private const ALLOWED_TABLES = [
        'item_types',
        'payee_types',
        'expense_categories',
        'service_categories',
        'asset_categories',
    ];

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->moduleService = new ModuleActivationService($db);
    }

    /**
     * Find all types/categories for a business, filtered by active modules.
     *
     * Rules:
     * - System types with module_code = NULL are always returned
     * - System types with module_code set are only returned if that module is active
     * - Tenant/business-defined types (is_system = 0) are always returned for their scope
     *
     * @param string $table The classification table name
     * @param int $businessId The business ID (used for module gating and scope)
     * @param int $tenantId The tenant ID for scope filtering
     * @return array
     */
    public function findForBusiness(string $table, int $businessId, int $tenantId): array {
        $this->assertValidTable($table);

        // Get active module codes for this business
        $activeModules = $this->moduleService->getActiveModules($businessId);

        // Query all types visible to this tenant/business:
        // - System types (is_system=1) are global (tenant_id is NULL)
        // - User-defined types are scoped to tenant + business
        // JOIN modules to resolve module_id → code for filtering
        $sql = "SELECT t.*, m.code AS module_code FROM `{$table}` t
                LEFT JOIN modules m ON m.id = t.module_id
                WHERE (t.tenant_id = :tenant_id AND (t.business_id = :business_id OR t.business_id IS NULL))
                   OR t.is_system = 1
                ORDER BY t.is_system DESC, t.name ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'tenant_id'   => $tenantId,
            'business_id' => $businessId,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Filter: remove system types whose module is not active for this business
        return array_values(array_filter($rows, function ($row) use ($activeModules) {
            // Non-system types are always visible
            if (empty($row['is_system'])) {
                return true;
            }
            // System types with no module_id are always visible
            if (empty($row['module_id'])) {
                return true;
            }
            // System types with module_id are only visible if that module is active
            $moduleCode = $row['module_code'] ?? null;
            return $moduleCode && in_array($moduleCode, $activeModules, true);
        }));
    }

    /**
     * Check if a given ID in a table is a system type.
     * Used as a guard to prevent edits/deletes on system types.
     *
     * @param string $table The classification table name
     * @param int $id The row ID to check
     * @return bool True if the row is a system type
     */
    public function isSystemType(string $table, int $id): bool {
        $this->assertValidTable($table);

        $stmt = $this->db->prepare(
            "SELECT is_system FROM `{$table}` WHERE id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetchColumn();

        return $result !== false && (int) $result === 1;
    }

    /**
     * Get a type/category by its code.
     *
     * @param string $table The classification table name
     * @param string $code The system type code
     * @param int $tenantId The tenant ID
     * @return array|null
     */
    public function findByCode(string $table, string $code, int $tenantId): ?array {
        $this->assertValidTable($table);

        $stmt = $this->db->prepare(
            "SELECT * FROM `{$table}` WHERE code = :code AND tenant_id = :tenant_id LIMIT 1"
        );
        $stmt->execute(['code' => $code, 'tenant_id' => $tenantId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Validate that the table name is in the allowed list.
     *
     * @param string $table
     * @throws \InvalidArgumentException
     */
    private function assertValidTable(string $table): void {
        if (!in_array($table, self::ALLOWED_TABLES, true)) {
            throw new \InvalidArgumentException("Invalid classification table: {$table}");
        }
    }
}
