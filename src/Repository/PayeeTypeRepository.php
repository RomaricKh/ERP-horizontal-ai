<?php
namespace App\Repository;

use PDO;

/**
 * Repository for payee_types with module-gated queries.
 * Provides read-only access to the people_types junction table.
 * Write operations to people_types are owned by PeopleRepository::setPayeeTypes().
 */
class PayeeTypeRepository {
    private PDO $db;
    private SystemTypeRepository $systemTypeRepo;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->systemTypeRepo = new SystemTypeRepository($db);
    }

    /**
     * Get all payee types for a business, filtered by active modules.
     */
    public function findForBusiness(int $tenantId, int $businessId): array {
        return $this->systemTypeRepo->findForBusiness('payee_types', $businessId, $tenantId);
    }

    /**
     * Legacy method — alias for findForBusiness.
     */
    public function findAllForBusiness(int $tenantId, int $businessId): array {
        return $this->findForBusiness($tenantId, $businessId);
    }

    /**
     * Find a payee type by ID.
     */
    public function findById(int $id, int $tenantId): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM payee_types
            WHERE id = :id AND tenant_id = :tenant_id
            LIMIT 1
        ");
        $stmt->execute(['id' => $id, 'tenant_id' => $tenantId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Find a payee type by its system code.
     */
    public function findByCode(string $code, int $tenantId): ?array {
        return $this->systemTypeRepo->findByCode('payee_types', $code, $tenantId);
    }

    /**
     * Create a new user-defined payee type.
     */
    public function create(int $tenantId, int $businessId, string $name, int $createdBy): int {
        $stmt = $this->db->prepare("
            INSERT INTO payee_types (tenant_id, business_id, name, is_system, created_at)
            VALUES (:tenant_id, :business_id, :name, 0, NOW())
        ");
        $stmt->execute([
            'tenant_id'   => $tenantId,
            'business_id' => $businessId,
            'name'        => $name,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Update a payee type. Rejects edits on system types.
     */
    public function update(int $id, int $tenantId, string $name): void {
        if ($this->systemTypeRepo->isSystemType('payee_types', $id)) {
            throw new \RuntimeException('Cannot modify a system-defined payee type');
        }

        $stmt = $this->db->prepare("
            UPDATE payee_types SET name = :name, updated_at = NOW()
            WHERE id = :id AND tenant_id = :tenant_id
        ");
        $stmt->execute(['id' => $id, 'tenant_id' => $tenantId, 'name' => $name]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Payee type not found or not allowed');
        }
    }

    /**
     * Delete a payee type. Rejects deletes on system types.
     */
    public function delete(int $id, int $tenantId): void {
        if ($this->systemTypeRepo->isSystemType('payee_types', $id)) {
            throw new \RuntimeException('Cannot delete a system-defined payee type');
        }

        $stmt = $this->db->prepare(
            "DELETE FROM payee_types WHERE id = :id AND tenant_id = :tenant_id"
        );
        $stmt->execute(['id' => $id, 'tenant_id' => $tenantId]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Payee type not found or not allowed');
        }
    }

    /**
     * Count usage of a payee type in the people_types junction.
     */
    public function countUsage(int $id): int {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM people_types WHERE payee_type_id = ?"
        );
        $stmt->execute([$id]);
        return (int) $stmt->fetchColumn();
    }

    // ── People Types Junction (Read-Only) ──────────────────────────────────

    /**
     * Get all type IDs assigned to a person.
     *
     * @param int $peopleId The people.id
     * @return int[] Array of payee_type_id values
     */
    public function getTypesForPerson(int $peopleId): array {
        $stmt = $this->db->prepare(
            "SELECT payee_type_id FROM people_types WHERE people_id = :people_id"
        );
        $stmt->execute(['people_id' => $peopleId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Find all people who have a specific payee type assigned.
     * Used for filtering people by type (e.g., get all CUSTOMER people).
     *
     * @param int $typeId The payee_type_id to filter by
     * @param int $tenantId Tenant scope
     * @return array Array of people rows
     */
    public function findPeopleByType(int $typeId, int $tenantId): array {
        $stmt = $this->db->prepare("
            SELECT p.id, p.name, p.phone, p.email
            FROM people p
            JOIN people_types pt ON pt.people_id = p.id
            WHERE pt.payee_type_id = :type_id
              AND p.tenant_id = :tenant_id
            ORDER BY p.name
        ");
        $stmt->execute(['type_id' => $typeId, 'tenant_id' => $tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find all people who have a specific payee type code assigned.
     * Convenience method that resolves code to ID first.
     *
     * @param string $code The payee type code (e.g., 'CUSTOMER', 'SUPPLIER')
     * @param int $tenantId Tenant scope
     * @param int $businessId Business scope (for module gating)
     * @return array Array of people rows
     */
    public function findPeopleByTypeCode(string $code, int $tenantId, int $businessId): array {
        $type = $this->findByCode($code, $tenantId);
        if (!$type) {
            return [];
        }
        return $this->findPeopleByType((int) $type['id'], $tenantId);
    }

    /**
     * Check if a person has a specific type code assigned.
     *
     * @param int $peopleId The people.id
     * @param string $code The payee type code
     * @param int $tenantId Tenant scope
     * @return bool
     */
    public function personHasType(int $peopleId, string $code, int $tenantId): bool {
        $type = $this->findByCode($code, $tenantId);
        if (!$type) {
            return false;
        }

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM people_types WHERE people_id = :people_id AND payee_type_id = :type_id"
        );
        $stmt->execute(['people_id' => $peopleId, 'type_id' => $type['id']]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
