<?php
namespace App\Controllers;

use App\Config\Database;
use App\Controllers\BaseController;
use App\Repository\RoleRepository;
use App\Services\UserService;
use App\Support\AuthContext;

class UsersController extends BaseController {
    private UserService $service;
    private RoleRepository $roleRepo;

    public function __construct() {
        $db = Database::getConnection();
        $this->service = new UserService($db);
        $this->roleRepo = new RoleRepository($db);
    }

    // GET /users
    public function index(): void {
        try {
			$ctx = $this->auth();
            $businessId = $ctx->business_id;
            if ($businessId === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
            $users = $this->service->listForCurrentBusiness($businessId);
            $this->json(true, ['users' => $users]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch users '.$e);
        }
    }

    // GET /users/{id}
    public function viewById(int $id): void {
        try {
			$ctx = $this->auth();
            $businessId = $ctx->business_id;
            if ($businessId === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
            $user = $this->service->getByUserId($id, $businessId);
            $this->json(true, ['user' => $user]);
        } catch (\Throwable $e) {
            $this->notFound('User not found '.$e);
        }
    }
	
	// GET /users/lookup?{params}
	public function lookup() {
		try {
			$ctx = $this->auth();
            $businessId = $ctx->business_id;
            if ($businessId === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
			$result = $this->service->lookup($_GET);

            $this->json(true, $result);
		} catch (Throwable $e) {
			return $this->serverError('Lookup failed '.$e);
		}
	}

    // POST /users
    public function store(): void {
        try {
			$ctx = $this->auth();
            $businessId = $ctx->business_id;
            if ($businessId === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
			
            $data = $this->input(['username', 'email', 'first_name', 'last_name', 'password', 'role_id', 'is_active']);

            // Validate role assignment: the selected role must belong to the current business
            $roleId = (int) $data['role_id'];
            $role = $this->roleRepo->findById($roleId);

            if ($role === null) {
                $this->json(false, null, 'Selected role not found', 422);
                return;
            }

            // Business Admins cannot assign TENANT_OWNER roles
            if (!AuthContext::isTenantOwner() && $role['type'] === 'TENANT_OWNER') {
                $this->json(false, null, 'Cannot assign tenant owner role', 403);
                return;
            }

            // The selected role must belong to the current business (business-scoped roles only)
            if ($role['business_id'] === null || (int) $role['business_id'] !== $businessId) {
                $this->json(false, null, 'Selected role does not belong to this business', 422);
                return;
            }

            $userId = $this->service->createForCurrentBusiness($businessId, $ctx->user_id, $data);
			
            $this->json(true, ['user_id' => $userId]);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 400);
        } catch (\Throwable $e) {
            $this->serverError('Failed to create user');
        }
    }

    // PUT /users/{id}
    public function update(int $id): void {
        try {
			$ctx = $this->auth();
            $businessId = $ctx->business_id;
            if ($businessId === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
			$selfModified = ($id === $ctx->user_id);
			
            // Add 'password' to the identity input extraction
            $identity = $this->input(['username', 'email', 'first_name', 'last_name', 'password']);
            $access = $this->input(['role_id', 'is_active']);

            // Guard: reject if target user is a Tenant Owner in this tenant
            $db = \App\Config\Database::getConnection();
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM user_roles ur
                JOIN roles r ON r.id = ur.role_id
                WHERE ur.user_id = :user_id AND ur.tenant_id = :tenant_id AND r.type = 'TENANT_OWNER'
            ");
            $stmt->execute(['user_id' => $id, 'tenant_id' => $ctx->tenant_id]);
            if ((int) $stmt->fetchColumn() > 0) {
                $this->json(false, null, 'This user is a Tenant Owner and cannot be assigned a business-level role or modified by a business admin in this tenant', 403);
                return;
            }

            // Validate role assignment if role_id is provided
            if (isset($access['role_id']) && $access['role_id'] !== null) {
                $roleId = (int) $access['role_id'];
                $role = $this->roleRepo->findById($roleId);

                if ($role === null) {
                    $this->json(false, null, 'Selected role not found', 422);
                    return;
                }

                // Business Admins cannot assign TENANT_OWNER roles
                if (!AuthContext::isTenantOwner() && $role['type'] === 'TENANT_OWNER') {
                    $this->json(false, null, 'Cannot assign tenant owner role', 403);
                    return;
                }

                // The selected role must belong to the current business
                if ($role['business_id'] === null || (int) $role['business_id'] !== $businessId) {
                    $this->json(false, null, 'Selected role does not belong to this business', 422);
                    return;
                }
            }

            $this->service->updateForCurrentBusiness($id, $businessId, $ctx->user_id, $identity, $access);
			
            $this->json(true, ['selfModified' => $selfModified]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to update user');
        }
    }

    // DELETE /users/{id}
    public function delete(int $id): void {
        try {
			$ctx = $this->auth();
            $businessId = $ctx->business_id;
            if ($businessId === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
			
			if ($id === $ctx->user_id){
				$this->json(false, null, "You cannot delete yourself", 403);
				return;
			}
			
            $this->service->deleteFromCurrentBusiness($id, $businessId, $ctx->user_id);
			
            $this->json(true, ['selfDeleted' => false]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to delete user');
        }
    }

    // PATCH /users/{id}/status
    public function toggleStatus(int $id): void {
        try {
            $ctx = $this->auth();

            $data = $this->input(['is_active']);
            $is_active = (int) $data['is_active'];

            $oldAccess = $this->service->getUserRoleIdForBusiness($id, $ctx->business_id);
            if ($oldAccess === null) {
                $this->notFound('User not found in this business');
            }

            $this->service->updateForCurrentBusiness(
                $id,
                $ctx->business_id,
                $ctx->user_id,
                [],
                ['role_id' => $oldAccess, 'is_active' => $is_active]
            );

            $this->json(true, ['user_id' => $id, 'is_active' => $is_active]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to toggle user status');
        }
    }

    // PATCH /users/me — update own profile (first_name, last_name, email)
    public function updateMe(): void {
        try {
            $ctx = $this->auth();
            $data = $this->input([]);

            $allowed = ['first_name', 'last_name', 'email'];
            $updates = [];
            foreach ($allowed as $field) {
                if (isset($data[$field]) && $data[$field] !== '') {
                    $updates[$field] = trim($data[$field]);
                }
            }

            if (empty($updates)) {
                $this->json(false, null, 'No valid fields to update', 422);
                return;
            }

            // Validate email format if provided
            if (isset($updates['email']) && !filter_var($updates['email'], FILTER_VALIDATE_EMAIL)) {
                $this->json(false, null, 'Invalid email format', 422);
                return;
            }

            $this->service->updateProfileIdentity($ctx->user_id, $updates);

            $this->json(true, null, 'Profile updated successfully');
        } catch (\Throwable $e) {
            $this->serverError('Failed to update profile');
        }
    }

    // POST /users/me/password — change own password
    public function changePassword(): void {
        try {
            $ctx = $this->auth();
            $data = $this->input(['current_password', 'new_password']);

            $currentPassword = $data['current_password'];
            $newPassword = $data['new_password'];

            // Validate new password length
            if (strlen($newPassword) < 8) {
                $this->json(false, null, 'New password must be at least 8 characters', 422);
                return;
            }

            try {
                $this->service->changeProfilePassword($ctx->user_id, $currentPassword, $newPassword);
                $this->json(true, null, 'Password updated successfully');
            } catch (\InvalidArgumentException $e) {
                $this->json(false, null, $e->getMessage(), 403);
            } catch (\RuntimeException $e) {
                $this->json(false, null, $e->getMessage(), 404);
            }
        } catch (\Throwable $e) {
            $this->serverError('Failed to change password');
        }
    }

    // PATCH /users/{id}/active
    public function toggleActive(int $id): void {
        try {
            $ctx = $this->auth();

            $newState = $this->service->toggleActive($id);

            $this->json(true, ['user_id' => $id, 'is_active' => $newState ? 1 : 0]);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to toggle user active state');
        }
    }
}
