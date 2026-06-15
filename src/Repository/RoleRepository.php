<?php
namespace App\Repository;

use PDO;

class RoleRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function findAllByTenant(int $tenantId): array {
        $stmt = $this->db->prepare("
            SELECT r.id, r.name, r.description
            FROM roles r
            INNER JOIN businesses b ON b.id = r.business_id
            WHERE b.tenant_id = :tenant_id
            ORDER BY r.name
        ");
        $stmt->execute(['tenant_id' => $tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findAllByBusiness(int $businessId): array {
        $stmt = $this->db->prepare("
            SELECT id, name, description, type
            FROM roles
            WHERE business_id = :business_id
            ORDER BY name
        ");
        $stmt->execute(['business_id' => $businessId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find all roles for a business, verifying the business belongs to the given tenant.
     * Returns roles only if the business's tenant_id matches.
     */
    public function findAllByBusinessAndTenant(int $businessId, int $tenantId): array {
        $stmt = $this->db->prepare("
            SELECT r.id, r.name, r.description, r.type
            FROM roles r
            INNER JOIN businesses b ON b.id = r.business_id
            WHERE r.business_id = :business_id AND b.tenant_id = :tenant_id
            ORDER BY r.name
        ");
        $stmt->execute(['business_id' => $businessId, 'tenant_id' => $tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findTemplatesByTenant(int $tenantId): array {
        $stmt = $this->db->prepare("
            SELECT r.id, r.name, r.description, r.type
            FROM roles r
            INNER JOIN user_roles ur ON ur.role_id = r.id
            WHERE r.type = 'TEMPLATE' AND ur.tenant_id = :tenant_id
            GROUP BY r.id
            ORDER BY r.name
        ");
        $stmt->execute(['tenant_id' => $tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findByIdAndBusiness(int $id, int $businessId): ?array {
        $stmt = $this->db->prepare("
            SELECT id, name, description, type
            FROM roles
            WHERE id = :id AND business_id = :business_id
        ");
        $stmt->execute(['id' => $id, 'business_id' => $businessId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare("
            SELECT r.id, r.name, r.description, r.type, r.business_id,
                   b.tenant_id
            FROM roles r
            LEFT JOIN businesses b ON b.id = r.business_id
            WHERE r.id = :id
        ");
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findByIdAndTenant(int $id, int $tenantId): ?array {
        $stmt = $this->db->prepare("
            SELECT r.id, r.name, r.description
            FROM roles r
            INNER JOIN businesses b ON b.id = r.business_id
            WHERE r.id = :id AND b.tenant_id = :tenant_id
        ");
        $stmt->execute(['id' => $id, 'tenant_id' => $tenantId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findAllPermissions(): array {
        $stmt = $this->db->prepare("SELECT id, code, name FROM permissions ORDER BY code");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findPermissionsByRoleId(int $roleId): array {
        $type = $this->findRoleType($roleId);

        if ($type === 'SYSTEM_OWNER') {
            // SYSTEM_OWNER always has ALL permissions
            $stmt = $this->db->prepare("SELECT id, code, name FROM permissions ORDER BY code");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // CUSTOM/TEMPLATE roles: join through role_permissions
        $stmt = $this->db->prepare("
            SELECT p.id, p.code, p.name
            FROM role_permissions rp
            JOIN permissions p ON p.id = rp.permission_id
            WHERE rp.role_id = :role_id
            ORDER BY p.code
        ");
        $stmt->execute(['role_id' => $roleId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function existsByNameAndTenant(string $name, int $tenantId, ?int $excludeId = null): bool {
        $sql = "SELECT COUNT(*) FROM roles r INNER JOIN businesses b ON b.id = r.business_id WHERE r.name = :name AND b.tenant_id = :tenant_id";
        $params = ['name' => $name, 'tenant_id' => $tenantId];

        if ($excludeId !== null) {
            $sql .= " AND r.id != :exclude_id";
            $params['exclude_id'] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function existsByNameAndBusiness(string $name, int $businessId, ?int $excludeId = null): bool {
        $sql = "SELECT COUNT(*) FROM roles WHERE name = :name AND business_id = :business_id";
        $params = ['name' => $name, 'business_id' => $businessId];

        if ($excludeId !== null) {
            $sql .= " AND id != :exclude_id";
            $params['exclude_id'] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function existsTemplateByNameAndTenant(string $name, int $tenantId, ?int $excludeId = null): bool {
        $sql = "SELECT COUNT(*) FROM roles r INNER JOIN user_roles ur ON ur.role_id = r.id WHERE r.name = :name AND ur.tenant_id = :tenant_id AND r.type = 'TEMPLATE'";
        $params = ['name' => $name, 'tenant_id' => $tenantId];

        if ($excludeId !== null) {
            $sql .= " AND r.id != :exclude_id";
            $params['exclude_id'] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function countUsersWithRole(int $roleId): int {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM user_roles WHERE role_id = :role_id");
        $stmt->execute(['role_id' => $roleId]);
        return (int) $stmt->fetchColumn();
    }

    public function countUsersWithRoleInBusiness(int $roleId, int $businessId): int {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM user_roles
            WHERE role_id = :role_id AND business_id = :business_id
        ");
        $stmt->execute(['role_id' => $roleId, 'business_id' => $businessId]);
        return (int) $stmt->fetchColumn();
    }

    public function insert(int $tenantId, string $name, ?string $description): int {
        $stmt = $this->db->prepare("
            INSERT INTO roles (name, description)
            VALUES (:name, :description)
        ");
        $stmt->execute([
            'name' => $name,
            'description' => $description,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function insertWithType(int $tenantId, ?int $businessId, string $name, ?string $description, string $type): int {
        $stmt = $this->db->prepare("
            INSERT INTO roles (business_id, name, description, type)
            VALUES (:business_id, :name, :description, :type)
        ");
        $stmt->execute([
            'business_id' => $businessId,
            'name' => $name,
            'description' => $description,
            'type' => $type,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function findRoleType(int $roleId): ?string {
        $stmt = $this->db->prepare("SELECT type FROM roles WHERE id = :id");
        $stmt->execute(['id' => $roleId]);
        $result = $stmt->fetchColumn();
        return $result !== false ? $result : null;
    }

    public function countSystemOwnerRoles(int $businessId): int {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM roles
            WHERE business_id = :business_id AND type = 'SYSTEM_OWNER'
        ");
        $stmt->execute(['business_id' => $businessId]);
        return (int) $stmt->fetchColumn();
    }

    public function updateRole(int $id, string $name, ?string $description): void {
        $stmt = $this->db->prepare("
            UPDATE roles
            SET name = :name, description = :description
            WHERE id = :id
        ");
        $stmt->execute([
            'id' => $id,
            'name' => $name,
            'description' => $description,
        ]);
    }

    public function deleteRole(int $id): void {
        // Remove role_permissions first (FK is ON DELETE RESTRICT)
        $stmt = $this->db->prepare("DELETE FROM role_permissions WHERE role_id = :id");
        $stmt->execute(['id' => $id]);

        $stmt = $this->db->prepare("DELETE FROM roles WHERE id = :id");
        $stmt->execute(['id' => $id]);
    }

    public function syncPermissions(int $roleId, array $permissionIds): void {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("DELETE FROM role_permissions WHERE role_id = :role_id");
            $stmt->execute(['role_id' => $roleId]);

            $stmt = $this->db->prepare("
                INSERT INTO role_permissions (role_id, permission_id)
                VALUES (:role_id, :permission_id)
            ");
            foreach ($permissionIds as $permissionId) {
                $stmt->execute([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Find the global TENANT_OWNER role.
     * Since there's only one TENANT_OWNER role shared across all tenants,
     * the tenantId parameter is kept for API compatibility but not used in the query.
     */
    public function findTenantOwnerRoleForTenant(int $tenantId): ?array {
        $stmt = $this->db->prepare("
            SELECT id, business_id, name, description, type
            FROM roles
            WHERE type = 'TENANT_OWNER'
            LIMIT 1
        ");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Find all TENANT_OWNER role assignments for a user (across all tenants).
     * Tenant scoping comes from user_roles.tenant_id since the role itself is global.
     */
    public function findTenantOwnerRolesForUser(int $userId): array {
        $stmt = $this->db->prepare("
            SELECT r.id, ur.tenant_id, r.business_id, r.name, r.description, r.type
            FROM user_roles ur
            JOIN roles r ON r.id = ur.role_id
            WHERE ur.user_id = :user_id AND r.type = 'TENANT_OWNER'
            ORDER BY ur.tenant_id ASC
        ");
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Count the number of users assigned the TENANT_OWNER role for a given tenant.
     */
    public function countTenantOwnerAssignments(int $tenantId): int {
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM user_roles ur
            JOIN roles r ON r.id = ur.role_id
            WHERE ur.tenant_id = :tenant_id AND r.type = 'TENANT_OWNER'
        ");
        $stmt->execute(['tenant_id' => $tenantId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Get the global TENANT_OWNER role ID.
     * Since there's a single shared TENANT_OWNER role, this returns its ID.
     * The tenantId parameter is kept for API compatibility (used by callers to
     * create the user_roles assignment with the correct tenant_id).
     */
    public function createTenantOwnerRole(int $tenantId): int {
        // Return the existing global TENANT_OWNER role
        $stmt = $this->db->prepare("SELECT id FROM roles WHERE type = 'TENANT_OWNER' LIMIT 1");
        $stmt->execute();
        $id = $stmt->fetchColumn();

        if ($id !== false) {
            return (int) $id;
        }

        // Fallback: create it if it doesn't exist yet (first-time setup)
        $stmt = $this->db->prepare("
            INSERT INTO roles (business_id, name, description, type)
            VALUES (NULL, 'Tenant Owner', 'Immutable tenant-wide owner role', 'TENANT_OWNER')
        ");
        $stmt->execute();
        return (int) $this->db->lastInsertId();
    }
}
