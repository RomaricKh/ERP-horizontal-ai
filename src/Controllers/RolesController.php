<?php
namespace App\Controllers;

use App\Config\Database;
use App\Controllers\BaseController;
use App\Repository\RoleRepository;
use App\Services\RoleService;
use App\Support\AuthContext;

class RolesController extends BaseController {

    private RoleService $service;
    private RoleRepository $roleRepo;

    public function __construct() {
        $db = Database::getConnection();
        $this->service = new RoleService($db);
        $this->roleRepo = new RoleRepository($db);
    }

    // GET /roles
    public function index(): void {
        try {
            $ctx = $this->auth();
            $businessId = AuthContext::businessIdOrNull();

            if ($businessId === null) {
                // Tenant owner with no business context — return empty roles
                $roles = ['business_roles' => [], 'template_roles' => []];
            } else {
                $roles = $this->service->getAllForBusiness($businessId, $ctx->tenant_id);
            }

            // Include TENANT_OWNER role in the listing only if requesting user is a Tenant Owner
            if (AuthContext::isTenantOwner()) {
                $tenantOwnerRole = $this->roleRepo->findTenantOwnerRoleForTenant($ctx->tenant_id);
                if ($tenantOwnerRole !== null) {
                    $roles['tenant_owner_role'] = $tenantOwnerRole;
                }
            }

            $this->json(true, ['roles' => $roles]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch roles');
        }
    }

    // GET /roles/{id}
    public function show(int $id): void {
        try {
            $ctx = $this->auth();
            $role = $this->service->getById($id, $ctx->business_id);

            if ($role === null) {
                $this->notFound('Role not found');
                return;
            }

            $this->json(true, ['role' => $role]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch role');
        }
    }

    // GET /roles/permissions
    public function permissions(): void {
        try {
            $this->auth();
            $permissions = $this->service->getAllPermissions();
            $this->json(true, ['permissions' => $permissions]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch permissions');
        }
    }

    // POST /roles
    public function store(): void {
        try {
            $ctx  = $this->auth();
            $data = $this->input(['name']);

            $name          = $data['name'];
            $description   = $data['description'] ?? null;
            $permissionIds = $data['permission_ids'] ?? [];

            // Business Admins: force business_id to current context and type to CUSTOM
            // (The service already creates with type=CUSTOM, but we enforce business_id here)
            $businessId = $ctx->business_id;
            if (!AuthContext::isTenantOwner()) {
                // Business Admin — always scope to their current business context
                $businessId = AuthContext::businessId();
            }

            $this->service->create($businessId, $ctx->tenant_id, $name, $description, $permissionIds);

            $this->json(true, null, 'Role created successfully', 201);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to create role');
        }
    }

    // PUT /roles/{id}
    public function update(int $id): void {
        try {
            $ctx  = $this->auth();

            // Enforce role immutability rules before processing
            $roleType = $this->roleRepo->findRoleType($id);

            // TENANT_OWNER roles: NEVER editable by anyone
            if ($roleType === 'TENANT_OWNER') {
                $this->json(false, null, 'Cannot modify system-managed role', 403);
                return;
            }

            // SYSTEM_OWNER roles: NOT editable by Business Admins (only Tenant Owners can)
            if ($roleType === 'SYSTEM_OWNER' && !AuthContext::isTenantOwner()) {
                $this->json(false, null, 'Cannot modify system-managed role', 403);
                return;
            }

            $data = $this->input(['name']);

            $name          = $data['name'];
            $description   = $data['description'] ?? null;
            $permissionIds = $data['permission_ids'] ?? [];

            $this->service->update($id, $ctx->business_id, $name, $description, $permissionIds);

            $this->json(true, null, 'Role updated successfully');
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 403);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to update role');
        }
    }

    // DELETE /roles/{id}
    public function delete(int $id): void {
        try {
            $ctx = $this->auth();

            // Enforce role immutability rules before processing
            $roleType = $this->roleRepo->findRoleType($id);

            // TENANT_OWNER roles: NEVER deletable by anyone
            if ($roleType === 'TENANT_OWNER') {
                $this->json(false, null, 'Cannot modify system-managed role', 403);
                return;
            }

            // SYSTEM_OWNER roles: NOT deletable by Business Admins (only Tenant Owners can)
            if ($roleType === 'SYSTEM_OWNER' && !AuthContext::isTenantOwner()) {
                $this->json(false, null, 'Cannot modify system-managed role', 403);
                return;
            }

            $this->service->delete($id, $ctx->business_id);

            $this->json(true, null, 'Role deleted successfully');
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 403);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to delete role');
        }
    }

    // POST /roles/clone/{id}
    public function clone(int $templateId): void {
        try {
            $ctx = $this->auth();

            $this->service->cloneTemplate($templateId, $ctx->business_id, $ctx->tenant_id);

            $this->json(true, null, 'Role cloned successfully', 201);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 403);
        } catch (\Throwable $e) {
            $this->serverError('Failed to clone role');
        }
    }

    // POST /roles/tenant-owner/assign
    public function assignTenantOwner(): void {
        try {
            $ctx = $this->auth();

            // Only Tenant Owners can assign the TENANT_OWNER role
            if (!AuthContext::isTenantOwner()) {
                $this->forbidden('Access denied: Tenant Owner privileges required');
                return;
            }

            $data = $this->input(['user_id']);
            $targetUserId = (int) $data['user_id'];

            $this->service->assignTenantOwnerRole($targetUserId, $ctx->tenant_id);

            $this->json(true, null, 'Tenant Owner role assigned successfully');
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 400);
        } catch (\Throwable $e) {
            $this->serverError('Failed to assign Tenant Owner role');
        }
    }

    // POST /roles/tenant-owner/remove
    public function removeTenantOwner(): void {
        try {
            $ctx = $this->auth();

            // Only Tenant Owners can remove the TENANT_OWNER role
            if (!AuthContext::isTenantOwner()) {
                $this->forbidden('Access denied: Tenant Owner privileges required');
                return;
            }

            $data = $this->input(['user_id']);
            $targetUserId = (int) $data['user_id'];

            $this->service->removeTenantOwnerRole($targetUserId, $ctx->tenant_id);

            $this->json(true, null, 'Tenant Owner role removed successfully');
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 400);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 400);
        } catch (\Throwable $e) {
            $this->serverError('Failed to remove Tenant Owner role');
        }
    }
}
