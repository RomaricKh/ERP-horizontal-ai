<?php

namespace App\Repository;

use PDO;

class TenantOwnerRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Find the first user_role record where the user holds the TENANT_OWNER role.
     * Returns null if the user is not a Tenant Owner.
     */
    public function findByUserId(int $userId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT ur.user_id, ur.tenant_id, ur.role_id
            FROM user_roles ur
            INNER JOIN roles r ON r.id = ur.role_id
            WHERE ur.user_id = :user_id
              AND r.type = 'TENANT_OWNER'
            ORDER BY ur.id ASC
            LIMIT 1
        ");
        $stmt->execute(['user_id' => $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Find all tenant owner records for a specific tenant, including user identity info.
     */
    public function findAllByTenantId(int $tenantId): array
    {
        $stmt = $this->db->prepare("
            SELECT ur.user_id, ur.tenant_id, ur.role_id,
                   u.username, u.first_name, u.last_name, u.email
            FROM user_roles ur
            INNER JOIN roles r ON r.id = ur.role_id
            INNER JOIN users u ON u.id = ur.user_id
            WHERE ur.tenant_id = :tenant_id
              AND r.type = 'TENANT_OWNER'
            ORDER BY ur.id ASC
        ");
        $stmt->execute(['tenant_id' => $tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Check if a user is a tenant owner for a specific tenant.
     */
    public function exists(int $tenantId, int $userId): bool
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
     * Count the number of tenant owners for a given tenant.
     */
    public function countByTenantId(int $tenantId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM user_roles ur
            INNER JOIN roles r ON r.id = ur.role_id
            WHERE ur.tenant_id = :tenant_id
              AND r.type = 'TENANT_OWNER'
        ");
        $stmt->execute(['tenant_id' => $tenantId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Insert a new user_roles record with the TENANT_OWNER role for a tenant.
     * Looks up the role id from the roles table where type = 'TENANT_OWNER'.
     */
    public function insert(int $tenantId, int $userId, int $actingUserId): void
    {
        // Find the TENANT_OWNER role id
        $stmt = $this->db->prepare("
            SELECT id FROM roles WHERE type = 'TENANT_OWNER' LIMIT 1
        ");
        $stmt->execute();
        $roleId = $stmt->fetchColumn();

        if ($roleId === false) {
            throw new \RuntimeException('TENANT_OWNER role not found in roles table');
        }

        $stmt = $this->db->prepare("
            INSERT INTO user_roles (user_id, role_id, business_id, tenant_id, is_active)
            VALUES (:user_id, :role_id, NULL, :tenant_id, 1)
        ");
        $stmt->execute([
            'user_id' => $userId,
            'role_id' => (int) $roleId,
            'tenant_id' => $tenantId,
        ]);
    }

    /**
     * List all tenant owners for a given tenant with display-ready fields.
     * Returns user_id, username, full_name, email, is_active, designated_at.
     */
    public function listOwnersForTenant(int $tenantId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                u.id AS user_id,
                u.username,
                CONCAT(u.first_name, ' ', u.last_name) AS full_name,
                u.email,
                ur.is_active,
                u.created_at AS designated_at
            FROM user_roles ur
            JOIN users u ON u.id = ur.user_id
            JOIN roles r ON r.id = ur.role_id
            WHERE ur.tenant_id = :tenant_id
              AND r.type = 'TENANT_OWNER'
            ORDER BY ur.id ASC
        ");
        $stmt->execute(['tenant_id' => $tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Delete the user_roles record for a tenant owner.
     */
    public function delete(int $tenantId, int $userId): void
    {
        $stmt = $this->db->prepare("
            DELETE ur FROM user_roles ur
            INNER JOIN roles r ON r.id = ur.role_id
            WHERE ur.user_id = :user_id
              AND ur.tenant_id = :tenant_id
              AND r.type = 'TENANT_OWNER'
        ");
        $stmt->execute([
            'user_id' => $userId,
            'tenant_id' => $tenantId,
        ]);
    }
}
