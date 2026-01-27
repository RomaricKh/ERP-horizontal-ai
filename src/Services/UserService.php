<?php
namespace App\Services;

use App\Repository\UserRepository;
use App\Support\AuthContext;

class UserService {
    private UserRepository $repo;
    private AuditService $audit;

    public function __construct() {
        $this->repo  = new UserRepository();
        $this->audit = new AuditService();
    }

    public function listForCurrentBusiness($business_id): array {
        return $this->repo->findAllByBusiness($business_id);
    }

    public function getForCurrentBusiness(int $userId): array {
        $ctx = AuthContext::get();

        $user = $this->repo->findByIdAndBusiness(
            $userId,
            $ctx->business_id
        );

        if (!$user) {
            throw new \RuntimeException('User not found');
        }

        return $user;
    }

    public function createForCurrentBusiness(array $data): int {
        $ctx = AuthContext::get();

        $userId = $this->repo->create([
            'username' => $data['username'],
            'email'    => $data['email'],
            'name'     => $data['name'],
        ]);

        $this->repo->assignRole(
            $userId,
            $ctx->business_id,
            $data['role_id']
        );

        $this->audit->log(
            'USER',
            'users',
            $userId,
            'CREATE',
            null,
            $data
        );

        return $userId;
    }

    public function updateForCurrentBusiness(int $userId, array $data): void {
        $ctx = AuthContext::get();

        $before = $this->repo->findById($userId);

        $this->repo->update($userId, [
            'name'  => $data['name'],
            'email' => $data['email']
        ]);

        $this->repo->updateRole(
            $userId,
            $ctx->business_id,
            $data['role_id']
        );

        $this->audit->log(
            'USER',
            'users',
            $userId,
            'UPDATE',
            $before,
            $data
        );
    }

    public function toggleStatusForCurrentBusiness(int $userId): void {
        $before = $this->repo->findById($userId);

        $this->repo->toggleStatus($userId);

        $this->audit->log(
            'USER',
            'users',
            $userId,
            'STATUS_TOGGLE',
            ['is_active' => $before['is_active']],
            ['is_active' => !$before['is_active']]
        );
    }
	
	public function getUserRoleIdForBusiness($user_id, $business_id){
		return $this->repo->getUserRoleIdForBusiness($user_id, $business_id);
	}
	
	public function getUserPermissionsForRole($role_id){
		return $this->repo->getUserPermissionsForRole($role_id);
	}
}
