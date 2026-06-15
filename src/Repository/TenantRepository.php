<?php

namespace App\Repository;

use PDO;

interface TenantRepositoryInterface
{
    public function findAllByOwnerUserId(int $userId): array;
    public function findById(int $id): ?array;
    public function create(string $name, ?string $description): int;
    public function update(int $id, string $name, ?string $description): void;
    public function userOwnsTenant(int $userId, int $tenantId): bool;
}

class TenantRepository implements TenantRepositoryInterface
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Find all tenants where the given user holds the TENANT_OWNER role.
     * Uses user_roles.tenant_id to identify which tenants the user owns.
     */
    public function findAllByOwnerUserId(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                t.id,
                t.name,
                t.description,
                t.is_active,
                t.created_at,
                (SELECT COUNT(*) FROM businesses b WHERE b.tenant_id = t.id) AS business_count
            FROM tenants t
            INNER JOIN user_roles ur ON ur.tenant_id = t.id AND ur.user_id = :user_id
            INNER JOIN roles r ON r.id = ur.role_id AND r.type = 'TENANT_OWNER'
            ORDER BY t.id ASC
        ");
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find a tenant by its ID.
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, name, description, is_active
            FROM tenants
            WHERE id = :id
        ");
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Create a new tenant with the given name and optional description.
     * Returns the ID of the newly created tenant.
     */
    public function create(string $name, ?string $description): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO tenants (name, description)
            VALUES (:name, :description)
        ");
        $stmt->execute([
            'name' => $name,
            'description' => $description,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Update an existing tenant's name and description.
     */
    public function update(int $id, string $name, ?string $description): void
    {
        $stmt = $this->db->prepare("
            UPDATE tenants SET name = :name, description = :description
            WHERE id = :id
        ");
        $stmt->execute([
            'id' => $id,
            'name' => $name,
            'description' => $description,
        ]);
    }

    /**
     * Check if a user holds the TENANT_OWNER role for the given tenant.
     */
    public function userOwnsTenant(int $userId, int $tenantId): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM user_roles ur
            INNER JOIN roles r ON r.id = ur.role_id
            WHERE ur.user_id = :user_id
              AND ur.tenant_id = :tenant_id
              AND r.type = 'TENANT_OWNER'
        ");
        $stmt->execute([
            'user_id' => $userId,
            'tenant_id' => $tenantId,
        ]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Set the is_active flag on a tenant.
     */
    public function setActive(int $id, bool $active): void
    {
        $stmt = $this->db->prepare("
            UPDATE tenants SET is_active = :active WHERE id = :id
        ");
        $stmt->execute([
            'id' => $id,
            'active' => $active ? 1 : 0,
        ]);
    }
}
