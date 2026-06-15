<?php
namespace App\Services;

use App\Repository\RoleRepository;
use App\Services\AuditService;
use InvalidArgumentException;
use RuntimeException;
use PDO;

class RoleService {
    private PDO $db;
    private RoleRepository $repo;
    private AuditService $audit;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->repo = new RoleRepository($db);
        $this->audit = new AuditService($db);
    }

    /**
     * Get all roles for a business context: business-specific roles + tenant templates in separate groups.
     */
    public function getAllForBusiness(int $businessId, int $tenantId): array {
        $businessRoles = $this->repo->findAllByBusiness($businessId);
        $templateRoles = $this->repo->findTemplatesByTenant($tenantId);

        return [
            'business_roles' => $businessRoles,
            'template_roles' => $templateRoles,
        ];
    }

    /**
     * Get a single role by ID, verifying it belongs to the given business.
     * Returns null if the role does not exist or belongs to a different business.
     */
    public function getById(int $id, int $businessId): ?array {
        $role = $this->repo->findByIdAndBusiness($id, $businessId);
        if ($role === null) {
            return null;
        }
        $role['permissions'] = $this->repo->findPermissionsByRoleId($role['id']);
        return $role;
    }

    public function getAllPermissions(): array {
        return $this->repo->findAllPermissions();
    }

    /**
     * Create a new CUSTOM role scoped to a business.
     */
    public function create(int $businessId, int $tenantId, string $name, ?string $description, array $permissionIds): int {
        // 1. Validate name length (2-50 chars trimmed)
        $trimmedName = trim($name);
        if (mb_strlen($trimmedName) < 2 || mb_strlen($trimmedName) > 50) {
            throw new InvalidArgumentException("Role name must be between 2 and 50 characters");
        }

        // 2. Validate description length (≤255 chars)
        if ($description !== null && mb_strlen($description) > 255) {
            throw new InvalidArgumentException("Description must not exceed 255 characters");
        }

        // 3. Check duplicate name within the business
        if ($this->repo->existsByNameAndBusiness($trimmedName, $businessId)) {
            throw new InvalidArgumentException("A role with this name already exists in this business");
        }

        // 4. Validate all permission IDs exist
        if (!empty($permissionIds)) {
            $allPermissions = $this->repo->findAllPermissions();
            $validIds = array_column($allPermissions, 'id');
            $validIds = array_map('intval', $validIds);
            foreach ($permissionIds as $pid) {
                if (!in_array((int) $pid, $validIds, true)) {
                    throw new InvalidArgumentException("Invalid permission IDs provided");
                }
            }
        }

        // 5. Insert role with type CUSTOM and business_id, then sync permissions
        $roleId = $this->repo->insertWithType($tenantId, $businessId, $trimmedName, $description, 'CUSTOM');
        $this->repo->syncPermissions($roleId, $permissionIds);

        // 6. Log creation via AuditService
        $this->audit->log(
            'CREATE',
            'roles',
            $roleId,
            'create',
            null,
            ['name' => $trimmedName, 'description' => $description, 'permission_ids' => $permissionIds],
            null,
            $tenantId
        );

        return $roleId;
    }

    /**
     * Update a CUSTOM role. Verifies the role belongs to the business and is type CUSTOM.
     */
    public function update(int $id, int $businessId, string $name, ?string $description, array $permissionIds): void {
        // 1. Verify role belongs to business
        $role = $this->repo->findByIdAndBusiness($id, $businessId);
        if ($role === null) {
            throw new InvalidArgumentException("Role not found");
        }

        // 2. Type-based guard: reject TENANT_OWNER modification (immutable)
        $roleType = $this->repo->findRoleType($id);
        if ($roleType === 'TENANT_OWNER') {
            throw new RuntimeException("Cannot modify system-managed role");
        }

        // 3. Type-based guard: reject SYSTEM_OWNER modification
        if ($roleType === 'SYSTEM_OWNER') {
            throw new RuntimeException("Cannot modify the system owner role");
        }

        // 4. Only CUSTOM roles can be updated
        if ($roleType !== 'CUSTOM') {
            throw new RuntimeException("Cannot modify this role type");
        }

        // 4. Validate name length (2-50 chars trimmed)
        $trimmedName = trim($name);
        if (mb_strlen($trimmedName) < 2 || mb_strlen($trimmedName) > 50) {
            throw new InvalidArgumentException("Role name must be between 2 and 50 characters");
        }

        // 5. Validate description length (≤255 chars)
        if ($description !== null && mb_strlen($description) > 255) {
            throw new InvalidArgumentException("Description must not exceed 255 characters");
        }

        // 6. Check duplicate name within the business (excluding current role)
        if ($this->repo->existsByNameAndBusiness($trimmedName, $businessId, $id)) {
            throw new InvalidArgumentException("A role with this name already exists in this business");
        }

        // 7. Validate all permission IDs exist
        if (!empty($permissionIds)) {
            $allPermissions = $this->repo->findAllPermissions();
            $validIds = array_column($allPermissions, 'id');
            $validIds = array_map('intval', $validIds);
            foreach ($permissionIds as $pid) {
                if (!in_array((int) $pid, $validIds, true)) {
                    throw new InvalidArgumentException("Invalid permission IDs provided");
                }
            }
        }

        // Capture old data for audit log
        $oldPermissions = $this->repo->findPermissionsByRoleId($id);
        $oldData = [
            'name' => $role['name'] ?? null,
            'description' => $role['description'] ?? null,
            'permission_ids' => array_column($oldPermissions, 'id'),
        ];

        // 8. Update role and sync permissions
        $this->repo->updateRole($id, $trimmedName, $description);
        $this->repo->syncPermissions($id, $permissionIds);

        // 9. Log update via AuditService
        $newData = [
            'name' => $trimmedName,
            'description' => $description,
            'permission_ids' => $permissionIds,
        ];
        $this->audit->log(
            'UPDATE',
            'roles',
            $id,
            'update',
            $oldData,
            $newData,
            null,
            null
        );
    }

    /**
     * Delete a CUSTOM role. Verifies the role belongs to the business, is type CUSTOM,
     * and has no users assigned.
     */
    public function delete(int $id, int $businessId): void {
        // 1. Verify role belongs to business
        $role = $this->repo->findByIdAndBusiness($id, $businessId);
        if ($role === null) {
            throw new InvalidArgumentException("Role not found");
        }

        // 2. Type-based guard: reject TENANT_OWNER deletion (immutable)
        $roleType = $this->repo->findRoleType($id);
        if ($roleType === 'TENANT_OWNER') {
            throw new RuntimeException("Cannot modify system-managed role");
        }

        // 3. Type-based guard: reject SYSTEM_OWNER deletion
        if ($roleType === 'SYSTEM_OWNER') {
            throw new RuntimeException("Cannot delete the system owner role");
        }

        // 4. Only CUSTOM roles can be deleted
        if ($roleType !== 'CUSTOM') {
            throw new RuntimeException("Cannot delete this role type");
        }

        // 4. In-use check via countUsersWithRoleInBusiness
        $userCount = $this->repo->countUsersWithRoleInBusiness($id, $businessId);
        if ($userCount > 0) {
            throw new InvalidArgumentException("Cannot delete role: assigned to {$userCount} user(s)");
        }

        // 5. Delete role via repository (cascades role_permissions removal)
        $this->repo->deleteRole($id);

        // 6. Log deletion via AuditService
        $this->audit->log(
            'DELETE',
            'roles',
            $id,
            'delete',
            null,
            null,
            null,
            null
        );
    }

    /**
     * Create a new TEMPLATE role scoped to a tenant.
     */
    public function createTemplate(int $tenantId, string $name, ?string $description, array $permissionIds): int {
        // 1. Validate name length (2-50 chars trimmed)
        $trimmedName = trim($name);
        if (mb_strlen($trimmedName) < 2 || mb_strlen($trimmedName) > 50) {
            throw new InvalidArgumentException("Role name must be between 2 and 50 characters");
        }

        // 2. Validate description length (≤255 chars)
        if ($description !== null && mb_strlen($description) > 255) {
            throw new InvalidArgumentException("Description must not exceed 255 characters");
        }

        // 3. Check duplicate template name within the tenant
        if ($this->repo->existsTemplateByNameAndTenant($trimmedName, $tenantId)) {
            throw new InvalidArgumentException("A template role with this name already exists");
        }

        // 4. Validate all permission IDs exist
        if (!empty($permissionIds)) {
            $allPermissions = $this->repo->findAllPermissions();
            $validIds = array_column($allPermissions, 'id');
            $validIds = array_map('intval', $validIds);
            foreach ($permissionIds as $pid) {
                if (!in_array((int) $pid, $validIds, true)) {
                    throw new InvalidArgumentException("Invalid permission IDs provided");
                }
            }
        }

        // 5. Insert role with type TEMPLATE and business_id = NULL, then sync permissions
        $roleId = $this->repo->insertWithType($tenantId, null, $trimmedName, $description, 'TEMPLATE');
        $this->repo->syncPermissions($roleId, $permissionIds);

        // 6. Log creation via AuditService
        $this->audit->log(
            'CREATE',
            'roles',
            $roleId,
            'create',
            null,
            ['name' => $trimmedName, 'description' => $description, 'permission_ids' => $permissionIds],
            null,
            $tenantId
        );

        return $roleId;
    }

    /**
     * Update a TEMPLATE role. Verifies the role belongs to the tenant and is type TEMPLATE.
     */
    public function updateTemplate(int $id, int $tenantId, string $name, ?string $description, array $permissionIds): void {
        // 1. Verify role belongs to tenant
        $role = $this->repo->findByIdAndTenant($id, $tenantId);
        if ($role === null) {
            throw new InvalidArgumentException("Role not found");
        }

        // 2. Verify role is type TEMPLATE
        $roleType = $this->repo->findRoleType($id);
        if ($roleType !== 'TEMPLATE') {
            throw new InvalidArgumentException("Only template roles can be modified/deleted");
        }

        // 3. Validate name length (2-50 chars trimmed)
        $trimmedName = trim($name);
        if (mb_strlen($trimmedName) < 2 || mb_strlen($trimmedName) > 50) {
            throw new InvalidArgumentException("Role name must be between 2 and 50 characters");
        }

        // 4. Validate description length (≤255 chars)
        if ($description !== null && mb_strlen($description) > 255) {
            throw new InvalidArgumentException("Description must not exceed 255 characters");
        }

        // 5. Check duplicate template name within the tenant (excluding current role)
        if ($this->repo->existsTemplateByNameAndTenant($trimmedName, $tenantId, $id)) {
            throw new InvalidArgumentException("A template role with this name already exists");
        }

        // 6. Validate all permission IDs exist
        if (!empty($permissionIds)) {
            $allPermissions = $this->repo->findAllPermissions();
            $validIds = array_column($allPermissions, 'id');
            $validIds = array_map('intval', $validIds);
            foreach ($permissionIds as $pid) {
                if (!in_array((int) $pid, $validIds, true)) {
                    throw new InvalidArgumentException("Invalid permission IDs provided");
                }
            }
        }

        // Capture old data for audit log
        $oldPermissions = $this->repo->findPermissionsByRoleId($id);
        $oldData = [
            'name' => $role['name'] ?? null,
            'description' => $role['description'] ?? null,
            'permission_ids' => array_column($oldPermissions, 'id'),
        ];

        // 7. Update role and sync permissions
        $this->repo->updateRole($id, $trimmedName, $description);
        $this->repo->syncPermissions($id, $permissionIds);

        // 8. Log update via AuditService
        $newData = [
            'name' => $trimmedName,
            'description' => $description,
            'permission_ids' => $permissionIds,
        ];
        $this->audit->log(
            'UPDATE',
            'roles',
            $id,
            'update',
            $oldData,
            $newData,
            null,
            $tenantId
        );
    }

    /**
     * Delete a TEMPLATE role. Verifies the role belongs to the tenant and is type TEMPLATE.
     */
    public function deleteTemplate(int $id, int $tenantId): void {
        // 1. Verify role belongs to tenant
        $role = $this->repo->findByIdAndTenant($id, $tenantId);
        if ($role === null) {
            throw new InvalidArgumentException("Role not found");
        }

        // 2. Verify role is type TEMPLATE
        $roleType = $this->repo->findRoleType($id);
        if ($roleType !== 'TEMPLATE') {
            throw new InvalidArgumentException("Only template roles can be modified/deleted");
        }

        // 3. Delete role via repository (cascades role_permissions removal)
        $this->repo->deleteRole($id);

        // 4. Log deletion via AuditService
        $this->audit->log(
            'DELETE',
            'roles',
            $id,
            'delete',
            ['name' => $role['name'] ?? null, 'description' => $role['description'] ?? null],
            null,
            null,
            $tenantId
        );
    }

    /**
     * Clone a TEMPLATE role into a business as an independent CUSTOM role.
     * Creates a copy with the same name, description, and permissions.
     * If a name conflict exists, appends a numeric suffix "(2)", "(3)", etc.
     */
    public function cloneTemplate(int $templateId, int $businessId, int $tenantId): int {
        // 1. Verify the source role exists and belongs to the tenant
        $template = $this->repo->findByIdAndTenant($templateId, $tenantId);
        if ($template === null) {
            throw new InvalidArgumentException("Role not found");
        }

        // 2. Verify the source role is type TEMPLATE
        $roleType = $this->repo->findRoleType($templateId);
        if ($roleType !== 'TEMPLATE') {
            throw new InvalidArgumentException("Only template roles can be cloned");
        }

        // 3. Resolve name conflicts by appending numeric suffix
        $baseName = $template['name'];
        $cloneName = $baseName;
        $suffix = 2;
        while ($this->repo->existsByNameAndBusiness($cloneName, $businessId)) {
            $cloneName = $baseName . ' (' . $suffix . ')';
            $suffix++;
        }

        // 4. Get template permissions for independent copy
        $templatePermissions = $this->repo->findPermissionsByRoleId($templateId);
        $permissionIds = array_map(function ($p) {
            return (int) $p['id'];
        }, $templatePermissions);

        // 5. Execute within a single transaction (insert role + sync permissions)
        $this->db->beginTransaction();
        try {
            $roleId = $this->repo->insertWithType($tenantId, $businessId, $cloneName, $template['description'] ?? null, 'CUSTOM');

            // Sync permissions inline to avoid nested transaction from syncPermissions()
            $stmt = $this->db->prepare("DELETE FROM role_permissions WHERE role_id = :role_id");
            $stmt->execute(['role_id' => $roleId]);

            $stmt = $this->db->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (:role_id, :permission_id)");
            foreach ($permissionIds as $permissionId) {
                $stmt->execute(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }

        // 6. Log clone via AuditService
        $this->audit->log(
            'CREATE',
            'roles',
            $roleId,
            'create',
            null,
            ['name' => $cloneName, 'description' => $template['description'] ?? null, 'permission_ids' => $permissionIds, 'source_template_id' => $templateId],
            null,
            $tenantId
        );

        return $roleId;
    }

    /**
     * Create the SYSTEM_OWNER role for a business. Used during business creation.
     * Enforces that exactly one SYSTEM_OWNER role exists per business.
     */
    public function createSystemOwnerRole(int $businessId, int $tenantId): int {
        if ($this->repo->countSystemOwnerRoles($businessId) > 0) {
            throw new RuntimeException("A SYSTEM_OWNER role already exists for this business");
        }

        return $this->repo->insertWithType($tenantId, $businessId, 'Business Owner', null, 'SYSTEM_OWNER');
    }

    // ─── Tenant Owner Role Operations ───────────────────────────────────────────

    /**
     * Get all roles for a business under the Tenant Owner's tenant.
     * Returns business-specific roles + tenant templates.
     * Validates that the business belongs to the given tenant.
     *
     * Requirements: 5.1
     */
    public function getAllForBusinessAsTenantOwner(int $businessId, int $tenantId): array {
        // Verify business belongs to tenant
        $this->verifyBusinessBelongsToTenant($businessId, $tenantId);

        $businessRoles = $this->repo->findAllByBusinessAndTenant($businessId, $tenantId);
        $templateRoles = $this->repo->findTemplatesByTenant($tenantId);

        return [
            'business_roles' => $businessRoles,
            'template_roles' => $templateRoles,
        ];
    }

    /**
     * Get a single role by ID for a Tenant Owner.
     * Verifies the role belongs to a business under the tenant.
     *
     * Requirements: 5.1
     */
    public function getByIdAsTenantOwner(int $id, int $tenantId): ?array {
        $role = $this->repo->findById($id);
        if ($role === null) {
            return null;
        }

        // If role has a business_id, verify that business belongs to tenant
        if ($role['business_id'] !== null) {
            $businessTenantId = $this->getBusinessTenantId((int) $role['business_id']);
            if ($businessTenantId === null || $businessTenantId !== $tenantId) {
                return null;
            }
        } else if ($role['type'] !== 'TENANT_OWNER') {
            // Non-business, non-TENANT_OWNER roles (e.g. TEMPLATE) — 
            // can't verify tenant ownership without business_id
            return null;
        }

        $role['permissions'] = $this->repo->findPermissionsByRoleId((int) $role['id']);
        return $role;
    }

    /**
     * Create a CUSTOM role for a specific business as a Tenant Owner.
     * The role is scoped to the business_id with type=CUSTOM.
     *
     * Requirements: 5.1, 5.2
     */
    public function createForBusinessAsTenantOwner(int $businessId, int $tenantId, string $name, ?string $description, array $permissionIds): int {
        // Verify business belongs to tenant
        $this->verifyBusinessBelongsToTenant($businessId, $tenantId);

        // Delegate to existing create method which handles all validation
        $roleId = $this->create($businessId, $tenantId, $name, $description, $permissionIds);

        // Additional audit log with tenant_scope flag (Req 6.4)
        $this->audit->log(
            'CREATE',
            'roles',
            $roleId,
            'create',
            null,
            ['name' => trim($name), 'business_id' => $businessId, 'permission_ids' => $permissionIds],
            null,
            $tenantId,
            $businessId,
            true,
            true // tenantScope
        );

        return $roleId;
    }

    /**
     * Update a CUSTOM role in any business under the Tenant Owner's tenant.
     *
     * Requirements: 5.1
     */
    public function updateForBusinessAsTenantOwner(int $id, int $businessId, int $tenantId, string $name, ?string $description, array $permissionIds): void {
        // Verify business belongs to tenant
        $this->verifyBusinessBelongsToTenant($businessId, $tenantId);

        // Delegate to existing update method which handles all validation
        $this->update($id, $businessId, $name, $description, $permissionIds);

        // Additional audit log with tenant_scope flag (Req 6.4)
        $this->audit->log(
            'UPDATE',
            'roles',
            $id,
            'update',
            null,
            ['name' => trim($name), 'business_id' => $businessId, 'permission_ids' => $permissionIds],
            null,
            $tenantId,
            $businessId,
            true,
            true // tenantScope
        );
    }

    /**
     * Delete a CUSTOM role in any business under the Tenant Owner's tenant.
     *
     * Requirements: 5.1
     */
    public function deleteForBusinessAsTenantOwner(int $id, int $businessId, int $tenantId): void {
        // Verify business belongs to tenant
        $this->verifyBusinessBelongsToTenant($businessId, $tenantId);

        // Delegate to existing delete method which handles all validation
        $this->delete($id, $businessId);

        // Additional audit log with tenant_scope flag (Req 6.4)
        $this->audit->log(
            'DELETE',
            'roles',
            $id,
            'delete',
            null,
            null,
            null,
            $tenantId,
            $businessId,
            true,
            true // tenantScope
        );
    }

    /**
     * Create a TEMPLATE role at the tenant level (business_id=NULL, tenant_id set).
     * Available for cloning in all businesses under the tenant.
     *
     * Requirements: 5.7
     */
    public function createTemplateAsTenantOwner(int $tenantId, string $name, ?string $description, array $permissionIds): int {
        // Delegate to existing createTemplate method which handles all validation
        $roleId = $this->createTemplate($tenantId, $name, $description, $permissionIds);

        // Additional audit log with tenant_scope flag (Req 6.4)
        $this->audit->log(
            'CREATE',
            'roles',
            $roleId,
            'create_template',
            null,
            ['name' => trim($name), 'type' => 'TEMPLATE', 'permission_ids' => $permissionIds],
            null,
            $tenantId,
            null,
            true,
            true // tenantScope
        );

        return $roleId;
    }

    /**
     * Validate that a role can be assigned to a user in a target business.
     * Rejects if the role's business_id does not match the target business_id.
     *
     * Requirements: 5.6
     *
     * @throws InvalidArgumentException if role does not belong to the target business
     */
    public function validateRoleAssignment(int $roleId, int $targetBusinessId, int $tenantId): void {
        $role = $this->repo->findById($roleId);
        if ($role === null) {
            throw new InvalidArgumentException("Role not found");
        }

        // Verify the role belongs to the tenant via its business
        if ($role['business_id'] !== null) {
            $roleTenantId = $this->getBusinessTenantId((int) $role['business_id']);
            if ($roleTenantId === null || $roleTenantId !== $tenantId) {
                throw new InvalidArgumentException("Role not found");
            }
        } else {
            // Roles without business_id (TENANT_OWNER, TEMPLATE) cannot be directly assigned
            throw new InvalidArgumentException("Template roles cannot be assigned directly; clone the template into the target business first");
        }

        // Cross-business role assignment rejection
        if ((int) $role['business_id'] !== $targetBusinessId) {
            throw new InvalidArgumentException("Role does not belong to the target business");
        }
    }

    // ─── Private Helpers ─────────────────────────────────────────────────────────

    /**
     * Verify that a business belongs to the given tenant.
     *
     * @throws InvalidArgumentException if business does not belong to tenant
     */
    private function verifyBusinessBelongsToTenant(int $businessId, int $tenantId): void {
        $businessTenantId = $this->getBusinessTenantId($businessId);
        if ($businessTenantId === null || $businessTenantId !== $tenantId) {
            throw new InvalidArgumentException("Business does not belong to your tenant");
        }
    }

    /**
     * Get the tenant_id for a business.
     */
    private function getBusinessTenantId(int $businessId): ?int {
        $stmt = $this->db->prepare("SELECT tenant_id FROM businesses WHERE id = ?");
        $stmt->execute([$businessId]);
        $result = $stmt->fetchColumn();
        return $result !== false ? (int) $result : null;
    }

    // ─── TENANT_OWNER Assignment/Removal ────────────────────────────────────────

    /**
     * Assign the TENANT_OWNER role to a user.
     * Prerequisite: the target user must already have at least one user_roles record
     * in any business under the tenant.
     *
     * Requirements: 2.2, 2.6
     *
     * @throws InvalidArgumentException if user has no existing user_roles in the tenant
     * @throws RuntimeException if TENANT_OWNER role doesn't exist for the tenant
     */
    public function assignTenantOwnerRole(int $targetUserId, int $tenantId): void {
        // 1. Verify the target user has at least one user_roles record in the tenant
        if (!$this->userHasRoleInTenant($targetUserId, $tenantId)) {
            throw new InvalidArgumentException("User must have at least one business assignment in this tenant");
        }

        // 2. Find the TENANT_OWNER role for this tenant
        $tenantOwnerRole = $this->repo->findTenantOwnerRoleForTenant($tenantId);
        if ($tenantOwnerRole === null) {
            throw new RuntimeException("TENANT_OWNER role not found for this tenant");
        }

        $roleId = (int) $tenantOwnerRole['id'];

        // 3. Check if user already has this role assigned for this specific tenant
        $checkStmt = $this->db->prepare("
            SELECT COUNT(*) FROM user_roles
            WHERE user_id = :user_id AND role_id = :role_id AND tenant_id = :tenant_id
        ");
        $checkStmt->execute([
            'user_id' => $targetUserId,
            'role_id' => $roleId,
            'tenant_id' => $tenantId,
        ]);
        if ((int) $checkStmt->fetchColumn() > 0) {
            throw new InvalidArgumentException("User already has the Tenant Owner role for this tenant");
        }

        // 4. Insert user_roles record (business_id = NULL for TENANT_OWNER)
        $stmt = $this->db->prepare("
            INSERT INTO user_roles (user_id, business_id, role_id, is_active, tenant_id)
            VALUES (:user_id, NULL, :role_id, 1, :tenant_id)
        ");
        $stmt->execute([
            'user_id' => $targetUserId,
            'role_id' => $roleId,
            'tenant_id' => $tenantId,
        ]);

        // 5. Audit log
        $this->audit->log(
            'CREATE',
            'user_roles',
            (int) $this->db->lastInsertId(),
            'assign_tenant_owner',
            null,
            ['user_id' => $targetUserId, 'role_id' => $roleId, 'tenant_id' => $tenantId],
            null,
            $tenantId
        );
    }

    /**
     * Remove the TENANT_OWNER role from a user.
     * Rejects if removal would leave zero Tenant Owners for the tenant.
     *
     * Requirements: 2.5
     *
     * @throws RuntimeException if removal would leave zero owners
     * @throws InvalidArgumentException if user doesn't have the TENANT_OWNER role
     */
    public function removeTenantOwnerRole(int $targetUserId, int $tenantId): void {
        // 1. Find the TENANT_OWNER role for this tenant
        $tenantOwnerRole = $this->repo->findTenantOwnerRoleForTenant($tenantId);
        if ($tenantOwnerRole === null) {
            throw new RuntimeException("TENANT_OWNER role not found for this tenant");
        }

        $roleId = (int) $tenantOwnerRole['id'];

        // 2. Verify the user actually has this role
        if (!$this->userHasTenantOwnerRole($targetUserId, $roleId)) {
            throw new InvalidArgumentException("User does not have the Tenant Owner role");
        }

        // 3. Check minimum owner count — reject if this would leave zero owners
        $currentCount = $this->repo->countTenantOwnerAssignments($tenantId);
        if ($currentCount <= 1) {
            throw new RuntimeException("At least one Tenant Owner must exist per tenant");
        }

        // 4. Remove the user_roles record for this specific tenant
        $stmt = $this->db->prepare("
            DELETE FROM user_roles
            WHERE user_id = :user_id AND role_id = :role_id AND tenant_id = :tenant_id
        ");
        $stmt->execute([
            'user_id' => $targetUserId,
            'role_id' => $roleId,
            'tenant_id' => $tenantId,
        ]);

        // 5. Audit log
        $this->audit->log(
            'DELETE',
            'user_roles',
            0,
            'remove_tenant_owner',
            ['user_id' => $targetUserId, 'role_id' => $roleId, 'tenant_id' => $tenantId],
            null,
            null,
            $tenantId
        );
    }

    /**
     * Check if a user has at least one user_roles record in any business under the tenant.
     */
    private function userHasRoleInTenant(int $userId, int $tenantId): bool {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM user_roles ur
            INNER JOIN businesses b ON b.id = ur.business_id
            WHERE ur.user_id = :user_id
              AND b.tenant_id = :tenant_id
        ");
        $stmt->execute([
            'user_id'   => $userId,
            'tenant_id' => $tenantId,
        ]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Check if a user already has the TENANT_OWNER role assigned.
     */
    private function userHasTenantOwnerRole(int $userId, int $roleId): bool {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM user_roles
            WHERE user_id = :user_id AND role_id = :role_id
        ");
        $stmt->execute([
            'user_id' => $userId,
            'role_id' => $roleId,
        ]);
        return (int) $stmt->fetchColumn() > 0;
    }

    // ─── Legacy methods (retained for backward compatibility until controller is updated) ───

    /**
     * @deprecated Use getAllForBusiness() instead. Retained until RolesController is updated.
     */
    public function getAllForTenant(int $tenantId): array {
        return $this->repo->findAllByTenant($tenantId);
    }
}
