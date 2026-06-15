<?php
namespace App\Middleware;

use App\Config\Database;
use App\Services\AuthService;
use App\Services\UserService;
use App\Services\BusinessService;
use App\Services\ModuleActivationService;
use App\Support\AuthContext;

class AuthMiddleware {

    private AuthService $auth;
    private UserService $userS;
    private BusinessService $businessS;
    private \PDO $db;

    public function __construct(string $jwtSecret) {
        $this->db = Database::getConnection();
        $this->auth = new AuthService($jwtSecret, $this->db);
        $this->userS = new UserService($this->db);
        $this->businessS = new BusinessService($this->db);
    }

    public function handle($requiredPermissions = null): void {
        $headers = getallheaders();

        if (empty($headers['Authorization'])) {
            $this->unauthorized('Missing Authorization header');
        }

        if (!preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $m)) {
            $this->unauthorized('Invalid Authorization format');
        }

        $decoded = $this->auth->verifyToken($m[1]);

        if (!$decoded) {
            $this->unauthorized('Invalid or expired token');
        }

        $userId = (int) $decoded['user_id'];

        // Check if user account is active
        $userActiveStmt = $this->db->prepare("SELECT is_active FROM users WHERE id = :id LIMIT 1");
        $userActiveStmt->execute(['id' => $userId]);
        $userActive = $userActiveStmt->fetchColumn();
        if ($userActive !== false && (int) $userActive === 0) {
            $this->unauthorized('Account is disabled');
        }

        // -------- TENANT CONTEXT FROM HEADER --------
        $tenantHeader =
            $headers['X-Tenant-Id']
            ?? $headers['x-tenant-id']
            ?? null;

        $requestedTenantId = $tenantHeader !== null ? (int) $tenantHeader : null;

        // -------- TENANT OWNER CHECK (via roles + user_roles table) --------
        $tenantOwnerRole = $this->findTenantOwnerRole($userId, $requestedTenantId);

        // Get business context from request header (used by both paths)
        $businessHeader =
            $headers['X-Business-Id']
            ?? $headers['x-business-id']
            ?? null;

        $businessId = $businessHeader !== null ? (int) $businessHeader : null;

        if ($tenantOwnerRole) {
            // -------- TENANT OWNER PATH --------
            $ownerTenantId = (int) $tenantOwnerRole['tenant_id'];

            // If X-Tenant-Id was provided, validate the user owns that tenant
            if ($requestedTenantId !== null && $requestedTenantId !== $ownerTenantId) {
                // The findTenantOwnerRole already filtered by tenantId, so if we got a result
                // it means the user owns the requested tenant. But if the result's tenant_id
                // doesn't match, something is wrong.
                $this->forbidden('Access denied: not an owner of the specified tenant');
            }

            if ($businessId) {
                // X-Business-Id header present: verify business belongs to owner's tenant
                $businessTenantId = $this->businessS->getTenantIdForBusiness($businessId);

                if (!$businessTenantId) {
                    $this->forbidden('Invalid business');
                }

                if ($businessTenantId !== $ownerTenantId) {
                    // Check if user holds TENANT_OWNER for the business's tenant
                    $matchedRole = $this->findTenantOwnerRole($userId, $businessTenantId);

                    if ($matchedRole === null) {
                        $this->forbidden('Business does not belong to your tenant');
                    }

                    // Use the matched tenant_id for multi-tenant owners
                    $ownerTenantId = $businessTenantId;
                }

                // Check if business is active (REQ-010)
                $bizActiveStmt = $this->db->prepare("SELECT is_active FROM businesses WHERE id = :id LIMIT 1");
                $bizActiveStmt->execute(['id' => $businessId]);
                $bizActive = $bizActiveStmt->fetchColumn();
                if ($bizActive !== false && (int) $bizActive === 0) {
                    $this->forbidden('Business is suspended');
                }

                $tenantId = $ownerTenantId;
            } else {
                // No X-Business-Id header: auto-resolve first business in tenant
                $tenantId = $ownerTenantId;
                $firstBizStmt = $this->db->prepare("
                    SELECT id FROM businesses WHERE tenant_id = :tenant_id AND is_active = 1 ORDER BY id ASC LIMIT 1
                ");
                $firstBizStmt->execute(['tenant_id' => $ownerTenantId]);
                $firstBiz = $firstBizStmt->fetchColumn();
                $businessId = $firstBiz ? (int) $firstBiz : null;
            }

            // Load ALL permission codes (Tenant Owner bypasses module filtering)
            $permissions = $this->loadAllPermissionCodes();

            // Establish Tenant Owner identity
            AuthContext::set((object)[
                'user_id'         => $userId,
                'tenant_id'       => $tenantId,
                'business_id'     => $businessId,
                'role_id'         => (int) $tenantOwnerRole['role_id'],
                'permissions'     => $permissions,
                'is_tenant_owner' => true,
            ]);
        } else {
            // -------- STANDARD (NON-TENANT-OWNER) PATH --------

            // If X-Tenant-Id was provided but user is not a TENANT_OWNER for it, reject
            if ($requestedTenantId !== null) {
                $this->forbidden('Access denied: not an owner of the specified tenant');
            }

            if (!$businessId) {
                $this->forbidden('Business context is required');
            }

            // 1. Resolve tenant from business
            $tenantId = $this->businessS->getTenantIdForBusiness($businessId);

            if (!$tenantId) {
                $this->forbidden('Invalid business');
            }

            // Check if business is active (REQ-010)
            $bizActiveStmt = $this->db->prepare("SELECT is_active FROM businesses WHERE id = :id LIMIT 1");
            $bizActiveStmt->execute(['id' => $businessId]);
            $bizActive = $bizActiveStmt->fetchColumn();
            if ($bizActive !== false && (int) $bizActive === 0) {
                $this->forbidden('Business is suspended');
            }

            // 2. Verify user is linked to business + fetch roles
            $role_id = $this->userS->getUserRoleIdForBusiness($userId, $businessId);

            if ($role_id === null) {
                $this->forbidden('User has no access to this business');
            }

            // 3. Resolve permissions from roles
            $permissions = $this->userS->getPermissionsForRole($role_id);

            // 3.5 Filter permissions by active modules (only for non-Tenant-Owner users;
            //     Tenant Owners bypass this entirely — see Tenant Owner path above)
            $moduleService = new ModuleActivationService($this->db);
            $permissions = $moduleService->filterPermissionsByActiveModules($permissions, $businessId);

            // 4. Establish backend identity (SOURCE OF TRUTH)
            AuthContext::set((object)[
                'user_id'         => $userId,
                'tenant_id'       => $tenantId,
                'business_id'     => $businessId,
                'role_id'         => $role_id,
                'permissions'     => $permissions,
                'is_tenant_owner' => false,
            ]);
        }

        // Authorization (optional)
        if ($requiredPermissions !== null) {
            $this->authorize($requiredPermissions);
        }
    }

    /**
     * Find a TENANT_OWNER role assignment for a user, optionally scoped to a specific tenant.
     * Since TENANT_OWNER is a single global role, tenant scoping is done via user_roles.tenant_id.
     *
     * @param int $userId The user to check
     * @param int|null $tenantId If provided, only check ownership for this specific tenant
     * @return array|null Returns ['role_id' => int, 'tenant_id' => int] or null if not a tenant owner
     */
    public function findTenantOwnerRole(int $userId, ?int $tenantId = null): ?array
    {
        $sql = "
            SELECT r.id AS role_id, ur.tenant_id
            FROM user_roles ur
            JOIN roles r ON r.id = ur.role_id
            WHERE ur.user_id = :user_id
              AND r.type = 'TENANT_OWNER'
        ";
        $params = ['user_id' => $userId];

        if ($tenantId !== null) {
            $sql .= " AND ur.tenant_id = :tenant_id";
            $params['tenant_id'] = $tenantId;
        }

        $sql .= " ORDER BY ur.tenant_id ASC LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Loads all permission codes from the permissions table.
     * Used for Tenant Owners who bypass module-based permission filtering.
     */
    private function loadAllPermissionCodes(): array {
        $stmt = $this->db->prepare("SELECT DISTINCT code FROM permissions ORDER BY code");
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function authorize($required): void {
        $user = AuthContext::get();
        $userPerms = $user->permissions ?? [];

        $required = (array)$required;

        foreach ($required as $perm) {
            if (!in_array($perm, $userPerms, true)) {
                $this->forbidden('Missing permission: ' . $perm);
            }
        }
    }

    private function unauthorized(string $msg): void {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => $msg]);
        exit;
    }

    private function forbidden(string $msg): void {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => $msg]);
        exit;
    }
}
