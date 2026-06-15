<?php
namespace App\Repository;

use PDO;

/**
 * Repository for asset_categories with module-gated queries.
 * System types cannot be edited or deleted.
 */
class AssetCategoryRepository {
    private PDO $db;
    private SystemTypeRepository $systemTypeRepo;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->systemTypeRepo = new SystemTypeRepository($db);
    }

    /**
     * Get all asset categories for a business, filtered by active modules.
     */
    public function findForBusiness(int $tenantId, int $businessId): array {
        return $this->systemTypeRepo->findForBusiness('asset_categories', $businessId, $tenantId);
    }

    /**
     * Find a single category by ID.
     */
    public function findById(int $id, int $tenantId): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM asset_categories
            WHERE id = :id AND tenant_id = :tenant
            LIMIT 1
        ");
        $stmt->execute(['id' => $id, 'tenant' => $tenantId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Find an asset category by code.
     */
    public function findByCode(string $code, int $tenantId): ?array {
        return $this->systemTypeRepo->findByCode('asset_categories', $code, $tenantId);
    }

    /**
     * Create a new user-defined asset category.
     */
    public function create(int $tenantId, ?int $businessId, string $name, ?string $code = null): int {
        $stmt = $this->db->prepare("
            INSERT INTO asset_categories (tenant_id, business_id, code, name, is_system, created_at)
            VALUES (:tenant_id, :business_id, :code, :name, 0, NOW())
        ");
        $stmt->execute([
            'tenant_id'   => $tenantId,
            'business_id' => $businessId,
            'code'        => $code,
            'name'        => $name,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Update an asset category. Rejects edits on system types.
     */
    public function update(int $id, int $tenantId, string $name): void {
        if ($this->systemTypeRepo->isSystemType('asset_categories', $id)) {
            throw new \RuntimeException('Cannot modify a system-defined asset category');
        }

        $stmt = $this->db->prepare("
            UPDATE asset_categories SET name = :name, updated_at = NOW()
            WHERE id = :id AND tenant_id = :tenant
        ");
        $stmt->execute(['id' => $id, 'tenant' => $tenantId, 'name' => $name]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Asset category not found or not allowed');
        }
    }

    /**
     * Delete an asset category. Rejects deletes on system types.
     */
    public function delete(int $id, int $tenantId): void {
        if ($this->systemTypeRepo->isSystemType('asset_categories', $id)) {
            throw new \RuntimeException('Cannot delete a system-defined asset category');
        }

        $stmt = $this->db->prepare(
            "DELETE FROM asset_categories WHERE id = :id AND tenant_id = :tenant"
        );
        $stmt->execute(['id' => $id, 'tenant' => $tenantId]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Asset category not found or not allowed');
        }
    }

    /**
     * Count assets referencing a category.
     */
    public function countAssetsForCategory(int $categoryId): int {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM assets WHERE category_id = :category_id"
        );
        $stmt->execute(['category_id' => $categoryId]);
        return (int) $stmt->fetchColumn();
    }
}
