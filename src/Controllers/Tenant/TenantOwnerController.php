<?php

namespace App\Controllers\Tenant;

use App\Config\Database;
use App\Controllers\BaseController;
use App\Support\AuthContext;
use App\Repository\TenantOwnerRepository;
use App\Services\TenantOwnerService;
use App\Services\RoleService;

class TenantOwnerController extends BaseController
{
    private TenantOwnerRepository $tenantOwnerRepository;
    private TenantOwnerService $tenantOwnerService;
    private RoleService $roleService;

    public function __construct()
    {
        $db = Database::getConnection();
        $this->tenantOwnerRepository = new TenantOwnerRepository($db);
        $this->tenantOwnerService = new TenantOwnerService($db);
        $this->roleService = new RoleService($db);
    }

    public function listOwners(): void
    {
        try {
            $tenantId = AuthContext::tenantId();
            $owners = $this->tenantOwnerRepository->listOwnersForTenant($tenantId);

            $this->json(true, ['owners' => $owners]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch tenant owners');
        }
    }

    public function designateOwner(): void
    {
        try {
            $tenantId = AuthContext::tenantId();
            $data = $this->input(['email', 'username']);
            $userId = $this->tenantOwnerService->designateFromController($tenantId, $data);
            $this->json(true, ['user_id' => $userId], 'Tenant Owner designated successfully', 201);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 400);
        } catch (\Throwable $e) {
            $this->serverError('Failed to designate tenant owner');
        }
    }

    public function revokeOwner(int $userId): void
    {
        try {
            $tenantId = AuthContext::tenantId();
            $ctx = AuthContext::get();

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
