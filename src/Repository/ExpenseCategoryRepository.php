<?php
namespace App\Repository;

use PDO;

/**
 * Repository for expense_categories with module-gated queries.
 * System types cannot be edited or deleted.
 */
class ExpenseCategoryRepository {
    private PDO $db;
    private SystemTypeRepository $systemTypeRepo;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->systemTypeRepo = new SystemTypeRepository($db);
    }

    /**
     * Get all expense categories for a business, filtered by active modules.
     */
    public function findForBusiness(int $tenantId, int $businessId): array {
        return $this->systemTypeRepo->findForBusiness('expense_categories', $businessId, $tenantId);
    }

    /**
     * Find a single category by ID.
     */
    public function findById(int $id, int $tenantId): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM expense_categories
            WHERE id = :id AND tenant_id = :tenant
            LIMIT 1
        ");
        $stmt->execute(['id' => $id, 'tenant' => $tenantId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Create a new user-defined expense category.
     */
    public function create(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO expense_categories
              (tenant_id, business_id, name, is_system, created_by, created_at, updated_at)
            VALUES
              (:tenant_id, :business_id, :name, 0, :created_by, NOW(), NOW())
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
     * Update an expense category. Rejects edits on system types.
     */
    public function update(int $id, int $tenantId, array $data): void {
        if ($this->systemTypeRepo->isSystemType('expense_categories', $id)) {
            throw new \RuntimeException('Cannot modify a system-defined expense category');
        }

        $stmt = $this->db->prepare("
            UPDATE expense_categories
            SET name = :name, is_fixed = :is_fixed, updated_at = NOW()
            WHERE id = :id AND tenant_id = :tenant
        ");
        $stmt->execute([
            'name'     => $data['name'],
            'is_fixed' => $data['is_fixed'] ? 1 : 0,
            'id'       => $id,
            'tenant'   => $tenantId,
        ]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Category not found or not allowed');
        }
    }

    /**
     * Delete an expense category. Rejects deletes on system types.
     */
    public function delete(int $id, int $tenantId): void {
        if ($this->systemTypeRepo->isSystemType('expense_categories', $id)) {
            throw new \RuntimeException('Cannot delete a system-defined expense category');
        }

        $stmt = $this->db->prepare(
            "DELETE FROM expense_categories WHERE id = :id AND tenant_id = :tenant"
        );
        $stmt->execute(['id' => $id, 'tenant' => $tenantId]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Category not found or not allowed');
        }
    }

    /**
     * Count expenses referencing a category.
     */
    public function countExpensesForCategory(int $categoryId): int {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM expenses WHERE expense_category_id = :category_id"
        );
        $stmt->execute(['category_id' => $categoryId]);
        return (int) $stmt->fetchColumn();
    }
}
