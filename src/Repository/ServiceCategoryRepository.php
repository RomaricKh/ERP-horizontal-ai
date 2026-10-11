<?php
namespace App\Repository;

use PDO;

/**
 * Repository for service_categories with module-gated queries.
 * System types cannot be edited or deleted.
 */
class ServiceCategoryRepository {
    private PDO $db;
    private SystemTypeRepository $systemTypeRepo;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->systemTypeRepo = new SystemTypeRepository($db);
    }

    /**
     * Get all service categories for a business, filtered by active modules.
     */
    public function findForBusiness(int $tenantId, int $businessId): array {
        return $this->systemTypeRepo->findForBusiness('service_categories', $businessId, $tenantId);
    }

    /**
     * Find a single category by ID, scoped by tenant and business.
     * System categories (is_system = 1, business_id IS NULL) remain visible to all businesses.
     */
    public function findById(int $id, int $tenantId, int $businessId): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM service_categories
            WHERE id = :id AND tenant_id = :tenant
              AND (business_id = :business OR (is_system = 1 AND business_id IS NULL))
            LIMIT 1
        ");
        $stmt->execute(['id' => $id, 'tenant' => $tenantId, 'business' => $businessId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Create a new user-defined service category.
     */
    public function create(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO service_categories (tenant_id, business_id, name, is_system, created_by, created_at)
            VALUES (:tenant_id, :business_id, :name, 0, :created_by, NOW())
        ");
        $stmt->execute([
            'tenant_id'   => $data['tenant_id'],
            'business_id' => $data['business_id'],
            'name'        => $data['name'],
            'created_by'  => $data['created_by'],
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Update a service category. Rejects edits on system types.
     */
    public function update(int $id, int $tenantId, int $businessId, string $name): void {
        if ($this->systemTypeRepo->isSystemType('service_categories', $id)) {
            throw new \RuntimeException('Cannot modify a system-defined service category');
        }

        $stmt = $this->db->prepare("
            UPDATE service_categories SET name = :name
            WHERE id = :id AND tenant_id = :tenant AND business_id = :business
        ");
        $stmt->execute(['id' => $id, 'tenant' => $tenantId, 'business' => $businessId, 'name' => $name]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Service category not found or not allowed');
        }
    }

    /**
     * Delete a service category. Rejects deletes on system types.
     */
    public function delete(int $id, int $tenantId, int $businessId): void {
        if ($this->systemTypeRepo->isSystemType('service_categories', $id)) {
            throw new \RuntimeException('Cannot delete a system-defined service category');
        }

        $stmt = $this->db->prepare(
            "DELETE FROM service_categories WHERE id = :id AND tenant_id = :tenant AND business_id = :business"
        );
        $stmt->execute(['id' => $id, 'tenant' => $tenantId, 'business' => $businessId]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Service category not found or not allowed');
        }
    }

    /**
     * Count services referencing a category.
     */
    public function countServicesForCategory(int $categoryId, int $businessId, int $tenantId): int {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM services
            WHERE category_id = :category_id
              AND business_id = :business
              AND tenant_id = :tenant
        ");
        $stmt->execute([
            'category_id' => $categoryId,
            'business'    => $businessId,
            'tenant'      => $tenantId,
        ]);
        return (int) $stmt->fetchColumn();
    }
}
