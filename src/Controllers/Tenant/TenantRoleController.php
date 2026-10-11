<?php

namespace App\Controllers\Tenant;

use App\Config\Database;
use App\Controllers\BaseController;
use App\Support\AuthContext;
use App\Services\RoleService;
use App\Services\BusinessService;
use App\Services\UserService;

class TenantRoleController extends BaseController
{
    private RoleService $roleService;
    private BusinessService $businessService;
    private UserService $userService;

    public function __construct()
    {
        $db = Database::getConnection();
        $this->roleService = new RoleService($db);
        $this->businessService = new BusinessService($db);
        $this->userService = new UserService($db);
    }

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
}
