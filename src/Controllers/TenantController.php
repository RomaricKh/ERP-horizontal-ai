<?php

namespace App\Controllers;

use App\Config\Database;
use App\Controllers\BaseController;
use App\Support\AuthContext;
use App\Repository\TenantRepository;
use App\Repository\RoleRepository;
use App\Repository\ModuleRepository;
use App\Services\TenantOwnerService;
use App\Services\BusinessService;
use App\Services\BusinessTypeService;
use App\Services\UserService;
use App\Services\RoleService;
use App\Services\ReportService;

class TenantController extends BaseController
{
    private TenantRepository $tenantRepository;
    private RoleRepository $roleRepository;
    private ModuleRepository $moduleRepository;
    private TenantOwnerService $tenantOwnerService;
    private BusinessService $businessService;
    private BusinessTypeService $businessTypeService;
    private UserService $userService;
    private RoleService $roleService;
    private ReportService $reportService;

    public function __construct()
    {
        $db = Database::getConnection();
        $this->tenantRepository = new TenantRepository($db);
        $this->roleRepository = new RoleRepository($db);
        $this->moduleRepository = new ModuleRepository($db);
        $this->tenantOwnerService = new TenantOwnerService($db);
        $this->businessService = new BusinessService($db);
        $this->businessTypeService = new BusinessTypeService($db);
        $this->userService = new UserService($db);
        $this->roleService = new RoleService($db);
        $this->reportService = new ReportService($db);
    }

    // =========================================================================
    // Tenant CRUD
    // =========================================================================

    /**
     * GET /tenant/tenants
     * List all tenants where the current user holds the TENANT_OWNER role.
     */
    public function listTenants(): void
    {
        try {
            $userId = AuthContext::userId();
            $tenants = $this->tenantRepository->findAllByOwnerUserId($userId);

            $this->json(true, ['tenants' => $tenants]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch tenants');
        }
    }

    /**
     * POST /tenant/tenants
     * Create a new tenant, create a TENANT_OWNER role, and assign the creating user.
     */
    public function createTenant(): void
    {
        try {
            $userId = AuthContext::userId();

            $data = $this->input(['name']);
            $name = trim($data['name']);
            $description = isset($data['description']) ? trim($data['description']) : null;

            // Validate name: required, 2-100 chars
            if (strlen($name) < 2 || strlen($name) > 100) {
                $this->json(false, null, 'Tenant name must be between 2 and 100 characters', 422);
                return;
            }

            // Validate description: optional, max 500 chars
            if ($description !== null && strlen($description) > 500) {
                $this->json(false, null, 'Description must not exceed 500 characters', 422);
                return;
            }

            // Create the tenant
            $tenantId = $this->tenantRepository->create($name, $description);

            // Create the TENANT_OWNER role for the new tenant
            $roleId = $this->roleRepository->createTenantOwnerRole($tenantId);

            // Assign the creating user to the TENANT_OWNER role
            $db = Database::getConnection();
            $stmt = $db->prepare("
                INSERT INTO user_roles (user_id, role_id, business_id, tenant_id)
                VALUES (:user_id, :role_id, NULL, :tenant_id)
            ");
            $stmt->execute([
                'user_id' => $userId,
                'role_id' => $roleId,
                'tenant_id' => $tenantId,
            ]);

            $tenant = $this->tenantRepository->findById($tenantId);

            $this->json(true, ['tenant' => $tenant], 'Tenant created successfully', 201);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to create tenant');
        }
    }

    /**
     * PUT /tenant/tenants/{id}
     * Update a tenant's name and description.
     */
    public function updateTenant(int $tenantId): void
    {
        try {
            // Verify the current user owns this tenant
            $userId = AuthContext::userId();
            if (!$this->tenantRepository->userOwnsTenant($userId, $tenantId)) {
                $this->json(false, null, 'Access denied: you do not own this tenant', 403);
                return;
            }

            $data = $this->input(['name', 'description']);
            $name = trim($data['name'] ?? '');
            $description = isset($data['description']) ? trim($data['description']) : null;

            if (strlen($name) < 2 || strlen($name) > 100) {
                $this->json(false, null, 'Tenant name must be between 2 and 100 characters', 422);
                return;
            }

            if ($description !== null && strlen($description) > 500) {
                $this->json(false, null, 'Description must not exceed 500 characters', 422);
                return;
            }

            $this->tenantRepository->update($tenantId, $name, $description);

            $tenant = $this->tenantRepository->findById($tenantId);

            $this->json(true, ['tenant' => $tenant], 'Tenant updated successfully');
        } catch (\Throwable $e) {
            $this->serverError('Failed to update tenant');
        }
    }

    /**
     * PATCH /tenant/tenants/{id}/active
     * Toggle the is_active flag on a tenant (soft delete / restore).
     */
    public function toggleTenantActive(int $tenantId): void
    {
        try {
            $userId = AuthContext::userId();
            if (!$this->tenantRepository->userOwnsTenant($userId, $tenantId)) {
                $this->json(false, null, 'Access denied: you do not own this tenant', 403);
                return;
            }

            $data = $this->input(['active']);
            $active = (bool) ($data['active'] ?? false);

            $this->tenantRepository->setActive($tenantId, $active);

            $tenant = $this->tenantRepository->findById($tenantId);

            $this->json(true, ['tenant' => $tenant], $active ? 'Tenant activated' : 'Tenant deactivated');
        } catch (\Throwable $e) {
            $this->serverError('Failed to toggle tenant status');
        }
    }

    // =========================================================================
    // Business Management
    // =========================================================================

    /**
     * GET /tenant/businesses
     * List all businesses under the tenant with pagination.
     */
    public function listBusinesses(): void
    {
        try {
            $tenantId = AuthContext::tenantId();

            $page = max(1, (int) ($_GET['page'] ?? 1));
            $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 20)));

            $result = $this->tenantOwnerService->getBusinessSummaries($tenantId, $page, $perPage);

            $this->json(true, $result);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch businesses');
        }
    }

    /**
     * POST /tenant/businesses
     * Create a new business under the tenant.
     */
    public function createBusiness(): void
    {
        try {
            $tenantId = AuthContext::tenantId();
            $actingUserId = AuthContext::userId();

            $data = $this->input(['name', 'business_type_id', 'currency', 'legal_name', 'registration_no', 'address', 'phone', 'email']);

            $businessId = $this->businessService->createForTenantOwner($tenantId, $actingUserId, $data);

            $this->json(true, ['business_id' => $businessId], 'Business created successfully', 201);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 400);
        } catch (\Throwable $e) {
            $this->serverError('Failed to create business');
        }
    }

    /**
     * GET /tenant/businesses/{id}
     * View a single business by ID.
     */
    public function viewBusiness(int $businessId): void
    {
        try {
            $business = $this->businessService->getById($businessId);

            $this->json(true, ['business' => $business]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch business');
        }
    }

    /**
     * PUT /tenant/businesses/{id}
     * Update a business (name, currency, etc.).
     */
    public function updateBusiness(int $businessId): void
    {
        try {
            $actingUserId = AuthContext::userId();

            $data = $this->input(['name', 'currency', 'business_type_id', 'legal_name', 'registration_no', 'address', 'phone', 'email']);

            $result = $this->businessService->update($businessId, $actingUserId, $data);

            if ($result !== null && !$result['success']) {
                if ($result['warning'] ?? false) {
                    $this->json(false, $result['warning'], $result['message'], 409);
                    return;
                }
                $this->json(false, null, $result['message'], 422);
                return;
            }

            $this->json(true, ['business_id' => $businessId]);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to update business');
        }
    }

    /**
     * DELETE /tenant/businesses/{id}
     * Delete a business.
     */
    public function deleteBusiness(int $businessId): void
    {
        try {
            $actingUserId = AuthContext::userId();

            $this->businessService->delete($businessId, $actingUserId);

            $this->json(true, ['business_id' => $businessId]);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to delete business');
        }
    }

    /**
     * PUT /tenant/businesses/{id}/type
     * Change the business type of a business.
     */
    public function changeBusinessType(int $businessId): void
    {
        try {
            $tenantId = AuthContext::tenantId();
            $actingUserId = AuthContext::userId();

            $data = $this->input(['business_type_id']);
            $newTypeId = (int) $data['business_type_id'];
            $force = (bool) ($data['force'] ?? false);

            $result = $this->businessService->changeBusinessTypeForTenantOwner(
                $tenantId,
                $businessId,
                $newTypeId,
                $force,
                $actingUserId
            );

            $this->json(true, $result);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 409);
        } catch (\Throwable $e) {
            $this->serverError('Failed to change business type');
        }
    }

    // =========================================================================
    // Business Type Management
    // =========================================================================

    /**
     * GET /tenant/business-types
     * List all business types (system + custom) for the tenant.
     */
    public function listBusinessTypes(): void
    {
        try {
            $tenantId = AuthContext::tenantId();
            $types = $this->businessTypeService->listForTenant($tenantId);

            $this->json(true, ['business_types' => $types]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch business types');
        }
    }

    /**
     * GET /tenant/business-types/system
     * List system-defined business types (templates) with their modules.
     */
    public function listSystemBusinessTypes(): void
    {
        try {
            $stmt = \App\Config\Database::getConnection()->prepare("
                SELECT bt.id, bt.code, bt.name, bt.description
                FROM business_types bt
                WHERE bt.is_system = 1
                ORDER BY bt.id ASC
            ");
            $stmt->execute();
            $systemTypes = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Attach module codes to each system type
            foreach ($systemTypes as &$type) {
                $modStmt = \App\Config\Database::getConnection()->prepare("
                    SELECT m.code, m.name
                    FROM business_type_modules btm
                    JOIN module_registry m ON m.id = btm.module_id
                    WHERE btm.business_type_id = :type_id
                    ORDER BY m.name ASC
                ");
                $modStmt->execute(['type_id' => $type['id']]);
                $type['modules'] = $modStmt->fetchAll(\PDO::FETCH_ASSOC);
            }

            $this->json(true, ['system_types' => $systemTypes]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch system business types');
        }
    }

    /**
     * POST /tenant/business-types
     * Create a custom business type for the tenant.
     */
    public function createBusinessType(): void
    {
        try {
            $tenantId = AuthContext::tenantId();

            $data = $this->input(['name', 'description', 'module_codes']);
            $name = $data['name'];
            $description = $data['description'];
            $moduleCodes = $data['module_codes'];

            $typeId = $this->businessTypeService->createCustomType($tenantId, $name, $description, $moduleCodes);

            $this->json(true, ['business_type_id' => $typeId], 'Business type created successfully', 201);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 400);
        } catch (\Throwable $e) {
            $this->serverError('Failed to create business type');
        }
    }

    /**
     * PUT /tenant/business-types/{id}
     * Update a custom business type.
     */
    public function updateBusinessType(int $id): void
    {
        try {
            $tenantId = AuthContext::tenantId();

            $data = $this->input(['name', 'description', 'module_codes']);
            $name = $data['name'];
            $description = $data['description'];
            $moduleCodes = $data['module_codes'];

            $this->businessTypeService->updateCustomType($id, $tenantId, $name, $description, $moduleCodes);

            $this->json(true, null, 'Business type updated successfully');
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'system-defined')) {
                $this->json(false, null, $e->getMessage(), 403);
            } elseif (str_contains($e->getMessage(), 'not found')) {
                $this->json(false, null, $e->getMessage(), 404);
            } else {
                $this->json(false, null, $e->getMessage(), 400);
            }
        } catch (\Throwable $e) {
            $this->serverError('Failed to update business type');
        }
    }

    /**
     * DELETE /tenant/business-types/{id}
     * Delete a custom business type.
     */
    public function deleteBusinessType(int $id): void
    {
        try {
            $tenantId = AuthContext::tenantId();

            $this->businessTypeService->deleteCustomType($id, $tenantId);

            $this->json(true, null, 'Business type deleted successfully');
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'system-defined')) {
                $this->json(false, null, $e->getMessage(), 403);
            } elseif (str_contains($e->getMessage(), 'not found')) {
                $this->json(false, null, $e->getMessage(), 404);
            } elseif (str_contains($e->getMessage(), 'Cannot delete')) {
                $this->json(false, null, $e->getMessage(), 409);
            } else {
                $this->json(false, null, $e->getMessage(), 400);
            }
        } catch (\Throwable $e) {
            $this->serverError('Failed to delete business type');
        }
    }

    // =========================================================================
    // User Management
    // =========================================================================

    /**
     * GET /tenant/users
     * List all users across the tenant with pagination.
     */
    public function listUsers(): void
    {
        try {
            $tenantId = AuthContext::tenantId();

            $page = max(1, (int) ($_GET['page'] ?? 1));
            $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 20)));

            $result = $this->userService->listForTenant($tenantId, $page, $perPage);

            $this->json(true, $result);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch users');
        }
    }

    /**
     * GET /tenant/users/{id}
     * View a specific user's profile across the tenant.
     */
    public function viewUser(int $userId): void
    {
        try {
            $tenantId = AuthContext::tenantId();

            $profile = $this->userService->getUserProfileAcrossTenant($userId, $tenantId);

            $this->json(true, ['user' => $profile]);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 404);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 400);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch user profile');
        }
    }

    /**
     * POST /tenant/users
     * Create a new user with one or more business assignments.
     */
    public function createUser(): void
    {
        try {
            $tenantId = AuthContext::tenantId();

            $data = $this->input(['username', 'email', 'first_name', 'last_name', 'password', 'assignments']);

            $userId = $this->userService->createWithMultipleBusinesses($tenantId, $data);

            $this->json(true, ['user_id' => $userId], 'User created successfully', 201);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 400);
        } catch (\Throwable $e) {
            $this->serverError('Failed to create user');
        }
    }

    /**
     * POST /tenant/users/reassign
     * Reassign a user from one business to another.
     */
    public function reassignUser(): void
    {
        try {
            $tenantId = AuthContext::tenantId();

            $data = $this->input(['user_id', 'from_business_id', 'to_business_id', 'new_role_id']);
            $userId = (int) $data['user_id'];
            $fromBusinessId = (int) $data['from_business_id'];
            $toBusinessId = (int) $data['to_business_id'];
            $newRoleId = (int) $data['new_role_id'];

            $this->userService->reassignBusiness($userId, $fromBusinessId, $toBusinessId, $newRoleId, $tenantId);

            $this->json(true, null, 'User reassigned successfully');
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 409);
        } catch (\Throwable $e) {
            $this->serverError('Failed to reassign user');
        }
    }

    /**
     * PUT /tenant/users/{id}/deactivate
     * Deactivate a user across all businesses in the tenant.
     */
    public function deactivateUser(int $userId): void
    {
        try {
            $tenantId = AuthContext::tenantId();

            $this->userService->deactivateAcrossTenant($userId, $tenantId);

            $this->json(true, null, 'User deactivated across all businesses');
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 400);
        } catch (\Throwable $e) {
            $this->serverError('Failed to deactivate user');
        }
    }

    /**
     * PUT /tenant/users/{id}/activate
     * Reactivate a user across all businesses in the tenant.
     */
    public function reactivateUser(int $userId): void
    {
        try {
            $tenantId = AuthContext::tenantId();

            $this->userService->reactivateAcrossTenant($userId, $tenantId);

            $this->json(true, null, 'User reactivated across all businesses');
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 400);
        } catch (\Throwable $e) {
            $this->serverError('Failed to reactivate user');
        }
    }

    // =========================================================================
    // Role Management
    // =========================================================================

    /**
     * GET /tenant/businesses/{id}/roles
     * List all roles (business-scoped + templates) for a specific business under the tenant.
     */
    public function listRolesForBusiness(int $businessId): void
    {
        try {
            $tenantId = AuthContext::tenantId();

            // Verify business belongs to tenant
            $businessTenantId = $this->businessService->getTenantIdForBusiness($businessId);
            if (!$businessTenantId || (int)$businessTenantId !== (int)$tenantId) {
                $this->json(false, null, 'Business does not belong to this tenant', 403);
                return;
            }

            $roles = $this->roleService->getAllForBusiness($businessId, $tenantId);

            $this->json(true, ['roles' => $roles]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch roles for business');
        }
    }

    /**
     * POST /tenant/roles
     * Create a role for a specific business.
     */
    public function createRole(): void
    {
        try {
            $tenantId = AuthContext::tenantId();

            $data = $this->input(['business_id', 'name']);
            $businessId = (int) $data['business_id'];
            $name = $data['name'];
            $description = $data['description'] ?? null;
            $permissionIds = $data['permission_ids'] ?? [];

            $roleId = $this->roleService->createForBusinessAsTenantOwner(
                $businessId,
                $tenantId,
                $name,
                $description,
                $permissionIds
            );

            $this->json(true, ['role_id' => $roleId], 'Role created successfully', 201);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 403);
        } catch (\Throwable $e) {
            $this->serverError('Failed to create role');
        }
    }

    /**
     * POST /tenant/roles/assign
     * Assign a role to a user for a specific business.
     */
    public function assignRole(): void
    {
        try {
            $tenantId = AuthContext::tenantId();

            $data = $this->input(['user_id', 'business_id', 'role_id']);
            $userId = (int) $data['user_id'];
            $businessId = (int) $data['business_id'];
            $roleId = (int) $data['role_id'];

            $this->userService->assignRoleForTenantOwner($userId, $businessId, $roleId, $tenantId);

            $this->json(true, null, 'Role assigned successfully');
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 400);
        } catch (\Throwable $e) {
            $this->serverError('Failed to assign role');
        }
    }

    /**
     * POST /tenant/roles/template
     * Create a template role at the tenant level.
     */
    public function createTemplateRole(): void
    {
        try {
            $tenantId = AuthContext::tenantId();

            $data = $this->input(['name']);
            $name = $data['name'];
            $description = $data['description'] ?? null;
            $permissionIds = $data['permission_ids'] ?? [];

            $roleId = $this->roleService->createTemplateAsTenantOwner($tenantId, $name, $description, $permissionIds);

            $this->json(true, ['role_id' => $roleId], 'Template role created successfully', 201);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 403);
        } catch (\Throwable $e) {
            $this->serverError('Failed to create template role');
        }
    }

    // =========================================================================
    // Dashboard & Reports
    // =========================================================================

    /**
     * GET /tenant/dashboard
     * Get aggregated dashboard metrics across all businesses.
     */
    public function dashboard(): void
    {
        try {
            $tenantId = AuthContext::tenantId();

            $startDate = $_GET['start_date'] ?? date('Y-m-01');
            $endDate = $_GET['end_date'] ?? date('Y-m-d');
            $aggregation = $_GET['aggregation'] ?? 'monthly';

            $metrics = $this->tenantOwnerService->getDashboardMetrics($tenantId, $startDate, $endDate, $aggregation);

            $this->json(true, $metrics);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 400);
        } catch (\Throwable $e) {
            $this->serverError('Failed to load dashboard');
        }
    }

    /**
     * POST /tenant/reports
     * Generate a tenant-level or per-business report.
     */
    public function generateReport(): void
    {
        try {
            $tenantId = AuthContext::tenantId();

            $data = $this->input(['report_type', 'start_date', 'end_date', 'format']);
            $reportType = $data['report_type'];
            $startDate = $data['start_date'];
            $endDate = $data['end_date'];
            $format = $data['format'];
            $businessId = isset($data['business_id']) ? (int) $data['business_id'] : null;

            if ($businessId !== null) {
                $result = $this->reportService->generateBusinessReport(
                    $tenantId,
                    $businessId,
                    $reportType,
                    $startDate,
                    $endDate,
                    $format
                );
            } else {
                $result = $this->reportService->generateTenantReport(
                    $tenantId,
                    $reportType,
                    $startDate,
                    $endDate,
                    $format
                );
            }

            $this->json(true, $result);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'does not belong to your tenant')) {
                $this->json(false, null, $e->getMessage(), 403);
            } else {
                $this->json(false, null, $e->getMessage(), 400);
            }
        } catch (\Throwable $e) {
            $this->serverError('Failed to generate report');
        }
    }

    // =========================================================================
    // Module Registry
    // =========================================================================

    /**
     * GET /tenant/modules
     * List all available modules from the Module Registry.
     */
    public function listModules(): void
    {
        try {
            $modules = $this->moduleRepository->getAll();

            // Return module list for UI consumption (include id for module assignment)
            $result = array_map(function ($mod) {
                return [
                    'id'       => (int) $mod['id'],
                    'code'     => $mod['code'],
                    'name'     => $mod['name'],
                    'icon'     => $mod['icon'],
                    'category' => $mod['category'],
                ];
            }, $modules);

            $this->json(true, ['modules' => $result]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch modules');
        }
    }

    // =========================================================================
    // Tenant Owners
    // =========================================================================

    /**
     * GET /tenant/owners
     * List all users who hold the TENANT_OWNER role for the current tenant.
     */
    public function listOwners(): void
    {
        try {
            $tenantId = AuthContext::tenantId();
            $db = Database::getConnection();

            $stmt = $db->prepare("
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
            $owners = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $this->json(true, ['owners' => $owners]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch tenant owners');
        }
    }

    /**
     * POST /tenant/owners
     * Designate a user as Tenant Owner.
     * - If user exists: promote them, remove all business roles in this tenant.
     * - If user doesn't exist: create the user, assign TENANT_OWNER role.
     */
    public function designateOwner(): void
    {
        try {
            $tenantId = AuthContext::tenantId();
            $data = $this->input(['email', 'username']);

            $email = trim($data['email'] ?? '');
            $username = trim($data['username'] ?? '');
            $firstName = trim($data['first_name'] ?? '');
            $lastName = trim($data['last_name'] ?? '');
            $password = $data['password'] ?? null;

            if (!$email || !$username) {
                $this->json(false, null, 'Email and username are required', 422);
                return;
            }

            $db = Database::getConnection();
            $userRepo = new \App\Repository\UserRepository($db);

            // Check if user already exists
            $existing = $userRepo->findIdentityByEmail($email);
            if (!$existing) {
                $existing = $userRepo->findIdentityByUsername($username);
            }

            $db->beginTransaction();
            try {
                if ($existing) {
                    $userId = (int) $existing['user_id'];

                    // Check if already a tenant owner for this tenant
                    if ($this->tenantRepository->userOwnsTenant($userId, $tenantId)) {
                        $this->json(false, null, 'User is already a Tenant Owner for this tenant', 422);
                        $db->rollBack();
                        return;
                    }

                    // Remove all business roles in this tenant
                    $stmt = $db->prepare("
                        DELETE FROM user_roles
                        WHERE user_id = :user_id
                          AND tenant_id = :tenant_id
                          AND business_id IS NOT NULL
                    ");
                    $stmt->execute(['user_id' => $userId, 'tenant_id' => $tenantId]);

                } else {
                    // Create new user
                    if (!$firstName || !$lastName) {
                        $this->json(false, null, 'First name and last name are required for new users', 422);
                        $db->rollBack();
                        return;
                    }
                    if (!$password) {
                        $this->json(false, null, 'Password is required for new users', 422);
                        $db->rollBack();
                        return;
                    }

                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $userId = $userRepo->createIdentity([
                        'username' => $username,
                        'email' => $email,
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'password_hash' => $passwordHash,
                    ]);
                }

                // Assign TENANT_OWNER role
                $roleId = $this->roleRepository->createTenantOwnerRole($tenantId);

                $stmt = $db->prepare("
                    INSERT INTO user_roles (user_id, role_id, business_id, tenant_id, is_active)
                    VALUES (:user_id, :role_id, NULL, :tenant_id, 1)
                ");
                $stmt->execute([
                    'user_id' => $userId,
                    'role_id' => $roleId,
                    'tenant_id' => $tenantId,
                ]);

                $db->commit();
            } catch (\Throwable $e) {
                $db->rollBack();
                throw $e;
            }

            $this->json(true, ['user_id' => $userId], 'Tenant Owner designated successfully', 201);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to designate tenant owner');
        }
    }

    /**
     * DELETE /tenant/owners/{id}
     * Revoke Tenant Owner privileges from a user.
     * Cannot revoke if it would leave zero owners.
     */
    public function revokeOwner(int $userId): void
    {
        try {
            $tenantId = AuthContext::tenantId();
            $ctx = AuthContext::get();

            // Prevent self-removal
            if ($ctx->user_id === $userId) {
                $this->json(false, null, 'You cannot revoke your own Tenant Owner privileges', 403);
                return;
            }

            $this->roleService->removeTenantOwnerRole($userId, $tenantId);

            $this->json(true, null, 'Tenant Owner revoked successfully');
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 400);
        } catch (\Throwable $e) {
            $this->serverError('Failed to revoke tenant owner');
        }
    }
}
