<?php
namespace App\Repository;

use PDO;

class BusinessModuleOverrideRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Get all overrides for a business as [module_code => action] map.
     */
    public function getOverridesForBusiness(int $businessId): array {
        $stmt = $this->db->prepare("
            SELECT m.code, bmo.action
            FROM business_module_overrides bmo
            INNER JOIN modules m ON m.id = bmo.module_id
            WHERE bmo.business_id = :business_id
        ");
        $stmt->execute(['business_id' => $businessId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $map = [];
        foreach ($rows as $row) {
            $map[$row['code']] = $row['action'];
        }
        return $map;
    }

    /**
     * Upsert an override (replace if exists for same business+module).
     * Uses INSERT ... ON DUPLICATE KEY UPDATE for atomic upsert.
     */
    public function upsert(int $businessId, int $moduleId, string $action, int $createdBy): void {
        $stmt = $this->db->prepare("
            INSERT INTO business_module_overrides (business_id, module_id, action, created_by, created_at)
            VALUES (:business_id, :module_id, :action, :created_by, NOW())
            ON DUPLICATE KEY UPDATE
                action = VALUES(action),
                created_by = VALUES(created_by),
                created_at = NOW()
        ");
        $stmt->execute([
            'business_id' => $businessId,
            'module_id'   => $moduleId,
            'action'      => $action,
            'created_by'  => $createdBy
        ]);
    }

    /**
     * Delete an override by business_id and module_id.
     */
    public function delete(int $businessId, int $moduleId): void {
        $stmt = $this->db->prepare("
            DELETE FROM business_module_overrides
            WHERE business_id = :business_id AND module_id = :module_id
        ");
        $stmt->execute([
            'business_id' => $businessId,
            'module_id'   => $moduleId
        ]);
    }

    /**
     * Delete overrides that are redundant given a set of type-default module IDs.
     * Removes:
     *   - activate overrides for modules that ARE in the type defaults (redundant activation)
     *   - deactivate overrides for modules that are NOT in the type defaults (redundant deactivation)
     *
     * @param int   $businessId    The business ID
     * @param array $typeModuleIds Array of module IDs that are in the new type defaults
     * @return int  Number of deleted rows
     */
    public function deleteRedundantOverrides(int $businessId, array $typeModuleIds): int {
        if (empty($typeModuleIds)) {
            // If no type defaults, only deactivate overrides for non-default modules are redundant.
            // Since typeModuleIds is empty, ALL modules are "not in type defaults",
            // so all deactivate overrides are redundant.
            $stmt = $this->db->prepare("
                DELETE FROM business_module_overrides
                WHERE business_id = :business_id AND action = 'deactivate'
            ");
            $stmt->execute(['business_id' => $businessId]);
            return $stmt->rowCount();
        }

        // Build placeholders for the IN clause
        $placeholders = [];
        $params = ['business_id' => $businessId];
        foreach ($typeModuleIds as $i => $moduleId) {
            $key = "module_id_{$i}";
            $placeholders[] = ":{$key}";
            $params[$key] = $moduleId;
        }
        $inClause = implode(', ', $placeholders);

        // Delete activate overrides for modules IN type defaults (redundant: already active by default)
        // AND deactivate overrides for modules NOT IN type defaults (redundant: already inactive by default)
        $stmt = $this->db->prepare("
            DELETE FROM business_module_overrides
            WHERE business_id = :business_id
              AND (
                (action = 'activate' AND module_id IN ({$inClause}))
                OR
                (action = 'deactivate' AND module_id NOT IN ({$inClause}))
              )
        ");
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Check if an override exists for a business+module combination.
     */
    public function exists(int $businessId, int $moduleId): bool {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM business_module_overrides
            WHERE business_id = :business_id AND module_id = :module_id
        ");
        $stmt->execute([
            'business_id' => $businessId,
            'module_id'   => $moduleId
        ]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
