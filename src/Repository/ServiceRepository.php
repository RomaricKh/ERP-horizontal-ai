<?php
namespace App\Repository;

use PDO;

class ServiceRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    // ── SERVICES ──────────────────────────────────────────────────────────────

    /**
     * Get all services for a business, optionally including inactive ones.
     * LEFT JOINs service_categories to include category name.
     */
    public function findAllForBusiness(int $businessId, int $tenantId, bool $includeInactive = false): array {
        $sql = "
            SELECT s.id, s.tenant_id, s.business_id, s.category_id,
                   s.name, s.description, s.unit, s.rate, s.is_active,
                   s.created_by, s.created_at,
                   sc.name AS category_name
            FROM services s
            LEFT JOIN service_categories sc ON sc.id = s.category_id
            WHERE s.tenant_id = :tenant
              AND s.business_id = :business
        ";

        if (!$includeInactive) {
            $sql .= " AND s.is_active = 1";
        }

        $sql .= " ORDER BY s.name";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['tenant' => $tenantId, 'business' => $businessId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find a single service by ID, scoped by business and tenant.
     */
    public function findById(int $id, int $businessId, int $tenantId): ?array {
        $stmt = $this->db->prepare("
            SELECT s.id, s.tenant_id, s.business_id, s.category_id,
                   s.name, s.description, s.unit, s.rate, s.is_active,
                   s.created_by, s.created_at,
                   sc.name AS category_name
            FROM services s
            LEFT JOIN service_categories sc ON sc.id = s.category_id
            WHERE s.id = :id
              AND s.tenant_id = :tenant
              AND s.business_id = :business
            LIMIT 1
        ");
        $stmt->execute(['id' => $id, 'tenant' => $tenantId, 'business' => $businessId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Insert a new service and return the new ID.
     */
    public function create(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO services
              (tenant_id, business_id, category_id, name, description, unit, rate, is_active, created_by, created_at, updated_at)
            VALUES
              (:tenant_id, :business_id, :category_id, :name, :description, :unit, :rate, 1, :created_by, NOW(), NOW())
        ");
        $stmt->execute([
            'tenant_id'   => $data['tenant_id'],
            'business_id' => $data['business_id'],
            'category_id' => $data['category_id'] ?? null,
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'unit'        => $data['unit'],
            'rate'        => $data['rate'],
            'created_by'  => $data['created_by'],
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Update only the provided fields for a service.
     */
    public function update(int $id, array $data): void {
        $allowed = ['name', 'description', 'unit', 'rate', 'category_id', 'is_active'];
        $fields  = [];
        $params  = ['id' => $id];

        foreach ($data as $key => $value) {
            if (in_array($key, $allowed, true)) {
                $fields[]    = "`$key` = :$key";
                $params[$key] = $value;
            }
        }

        if (empty($fields)) {
            return;
        }

        $fields[] = "`updated_at` = NOW()";

        $sql = "UPDATE services SET " . implode(', ', $fields) . " WHERE id = :id";
        $this->db->prepare($sql)->execute($params);
    }

    /**
     * Soft-delete a service by setting is_active = 0.
     */
    public function deactivate(int $id, int $businessId, int $tenantId): void {
        $stmt = $this->db->prepare("
            UPDATE services SET is_active = 0
            WHERE id = :id
              AND business_id = :business
              AND tenant_id = :tenant
        ");
        $stmt->execute(['id' => $id, 'business' => $businessId, 'tenant' => $tenantId]);
    }

    /**
     * Count services referencing a given category (used for deletion guard).
     */
    public function countByCategory(int $categoryId, int $businessId, int $tenantId): int {
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

    // ── SERVICE CATEGORIES ────────────────────────────────────────────────────

    /**
     * Get all service categories for a business.
     */
    public function findAllCategories(int $businessId, int $tenantId): array {
        $stmt = $this->db->prepare("
            SELECT id, tenant_id, business_id, name, created_by, created_at
            FROM service_categories
            WHERE tenant_id = :tenant
              AND business_id = :business
            ORDER BY name
        ");
        $stmt->execute(['tenant' => $tenantId, 'business' => $businessId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find a single category by ID, scoped by business and tenant.
     */
    public function findCategoryById(int $id, int $businessId, int $tenantId): ?array {
        $stmt = $this->db->prepare("
            SELECT id, tenant_id, business_id, name, created_by, created_at
            FROM service_categories
            WHERE id = :id
              AND tenant_id = :tenant
              AND business_id = :business
            LIMIT 1
        ");
        $stmt->execute(['id' => $id, 'tenant' => $tenantId, 'business' => $businessId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Insert a new service category and return the new ID.
     */
    public function createCategory(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO service_categories (tenant_id, business_id, name, created_by, created_at)
            VALUES (:tenant_id, :business_id, :name, :created_by, NOW())
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
     * Update a service category's name.
     */
    public function updateCategory(int $id, array $data): void {
        $allowed = ['name'];
        $fields  = [];
        $params  = ['id' => $id];

        foreach ($data as $key => $value) {
            if (in_array($key, $allowed, true)) {
                $fields[]    = "`$key` = :$key";
                $params[$key] = $value;
            }
        }

        if (empty($fields)) {
            return;
        }

        $sql = "UPDATE service_categories SET " . implode(', ', $fields) . " WHERE id = :id";
        $this->db->prepare($sql)->execute($params);
    }

    /**
     * Delete a service category by ID, scoped by business and tenant.
     */
    public function deleteCategory(int $id, int $businessId, int $tenantId): void {
        $stmt = $this->db->prepare("
            DELETE FROM service_categories
            WHERE id = :id
              AND business_id = :business
              AND tenant_id = :tenant
        ");
        $stmt->execute(['id' => $id, 'business' => $businessId, 'tenant' => $tenantId]);
    }
}
