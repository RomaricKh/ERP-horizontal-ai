<?php
namespace App\Repository;

use PDO;

/**
 * Repository for item_types with module-gated queries.
 * System types with a module_code are only returned if that module is active.
 * Edits and deletes on system types are rejected.
 */
class ItemTypeRepository {
    private PDO $db;
    private SystemTypeRepository $systemTypeRepo;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->systemTypeRepo = new SystemTypeRepository($db);
    }

    /**
     * Get all item types for a business, filtered by active modules.
     * System types with module_code set are only shown if that module is active.
     */
    public function findForBusiness(int $tenantId, int $businessId): array {
        return $this->systemTypeRepo->findForBusiness('item_types', $businessId, $tenantId);
    }

    /**
     * Get a single item type by ID.
     */
    public function findById(int $id, int $tenantId): ?array {
        $stmt = $this->db->prepare(
            "SELECT * FROM item_types WHERE id = :id AND (tenant_id = :tenant OR tenant_id IS NULL) LIMIT 1"
        );
        $stmt->execute(['id' => $id, 'tenant' => $tenantId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Find an item type by its system code.
     */
    public function findByCode(string $code, int $tenantId): ?array {
        return $this->systemTypeRepo->findByCode('item_types', $code, $tenantId);
    }

    /**
     * Create a new user-defined item type.
     */
    public function create(int $tenantId, int $businessId, string $name, int $createdBy): int {
        $stmt = $this->db->prepare("
            INSERT INTO item_types (tenant_id, business_id, name, is_system, created_at, created_by)
            VALUES (:tenant, :business, :name, 0, NOW(), :created_by)
        ");
        $stmt->execute([
            'tenant'     => $tenantId,
            'business'   => $businessId,
            'name'       => $name,
            'created_by' => $createdBy,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Update an item type. Rejects edits on system types.
     *
     * @throws \RuntimeException If the type is a system type or not found
     */
    public function update(int $id, int $tenantId, string $name): void {
        if ($this->systemTypeRepo->isSystemType('item_types', $id)) {
            throw new \RuntimeException('Cannot modify a system-defined item type');
        }

        $stmt = $this->db->prepare(
            "UPDATE item_types SET name = :name, updated_at = NOW()
             WHERE id = :id AND tenant_id = :tenant"
        );
        $stmt->execute(['id' => $id, 'tenant' => $tenantId, 'name' => $name]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Item type not found or not allowed');
        }
    }

    /**
     * Delete an item type. Rejects deletes on system types.
     *
     * @throws \RuntimeException If the type is a system type or not found
     */
    public function delete(int $id, int $tenantId): void {
        if ($this->systemTypeRepo->isSystemType('item_types', $id)) {
            throw new \RuntimeException('Cannot delete a system-defined item type');
        }

        $stmt = $this->db->prepare(
            "DELETE FROM item_types WHERE id = :id AND tenant_id = :tenant"
        );
        $stmt->execute(['id' => $id, 'tenant' => $tenantId]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Item type not found or not allowed');
        }
    }

    /**
     * Count items using a given type.
     */
    public function countItemsForType(int $typeId): int {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM items WHERE item_type_id = ? AND is_active = 1"
        );
        $stmt->execute([$typeId]);
        return (int) $stmt->fetchColumn();
    }
}
