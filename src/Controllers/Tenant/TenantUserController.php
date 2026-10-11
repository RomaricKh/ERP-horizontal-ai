<?php

namespace App\Controllers\Tenant;

use App\Config\Database;
use App\Controllers\BaseController;
use App\Support\AuthContext;
use App\Services\UserService;

class TenantUserController extends BaseController
{
    private UserService $userService;

    public function __construct()
    {
        $db = Database::getConnection();
        $this->userService = new UserService($db);
    }

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
}
