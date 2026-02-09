<?php
namespace App\Services;

use App\Repository\UserRepository;
use App\Support\AuthContext;
use PDO;

class UserService {
    private UserRepository $repo;
    private AuditService $audit;
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->repo  = new UserRepository($this->db);
        $this->audit = new AuditService($this->db);
    }

    public function listForCurrentBusiness(int $business_id): array {
		
        return $this->repo->findAllByBusiness($business_id);
    }

    public function getByUserId(int $user_id, int $business_id): array {

        $user = $this->repo->findByIdAndBusiness($user_id, $business_id);

        if (!$user) {
            throw new \RuntimeException('User not found');
        }

        return $user;
    }

    public function lookup(array $params): array {
        $email = isset($params['email']) ? trim($params['email']) : null;
        $username = isset($params['username']) ? trim($params['username']) : null;

        if (!$email && !$username) {
            return ['exists' => false];
        }

        $user = $email
            ? $this->repo->findIdentityByEmail($email)
            : $this->repo->findIdentityByUsername($username);

        if (!$user) {
            return ['exists' => false];
        }

        unset($user['password_hash'], $user['failed_attempts'], $user['lock_until']);

        return [
            'exists' => true,
            'user' => $user
        ];
    }

    public function createForCurrentBusiness(int $business_id, int $editing_user_id,array $data): int {
        $ctx = AuthContext::get();
        $this->db->beginTransaction();

        try {
            $existing = $this->repo->findIdentityByEmail($data['email']);
            $user_id = null;

            if ($existing) {
                if ($this->repo->getUserBusinessAccessInfo($existing['user_id'], $business_id)) {
                    throw new \RuntimeException('User already assigned to this business');
                }
                $user_id = (int)$existing['user_id'];
            } else {
				$passwordHash = password_hash($data['password'], PASSWORD_DEFAULT);
                $user_id = $this->repo->createIdentity([
                    'username'      => $data['username'],
                    'email'         => $data['email'],
                    'first_name'    => $data['first_name'],
                    'last_name'     => $data['last_name'],
                    'password_hash' => $passwordHash
                ]);
            }

            $this->repo->assignRole(
                $user_id,
                $business_id,
                $data['role_id'],
                $data['is_active']
            );

            // ✅ AUDIT (sanitized)
            $this->audit->log(
                'USER',
                'users',
                $user_id,
                'CREATE',
                null,
                [
                    'email'      => $data['email'],
                    'role_id'    => $data['role_id'],
                    'is_active'  => $data['is_active'],
                    'business_id'=> $business_id
                ],
                $editing_user_id,
                null,
                $business_id
            );

            $this->db->commit();
            return $user_id;

        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

	public function updateForCurrentBusiness(int $user_id, int $business_id, int $editing_user_id, array $identity, array $access): void {
		$this->db->beginTransaction();

		try {
			$before = $this->repo->findIdentityByIdForAdmin($user_id);

			$identityChanges = [];
			$updatePayload = [];

			// Identity fields
			foreach (['username', 'first_name', 'last_name', 'email'] as $field) {
				if (isset($identity[$field]) && $identity[$field] !== $before[$field]) {
					$identityChanges[$field] = $identity[$field];
					$updatePayload[$field] = $identity[$field];
				}
			}

			// Password reset (never log the hash)
			if (!empty($identity['password'])) {
				$updatePayload['password_hash'] =
					password_hash($identity['password'], PASSWORD_DEFAULT);

				$identityChanges['password_reset'] = true;
			}

			// Apply identity update if needed
			if (!empty($updatePayload)) {
				$this->repo->updateIdentity($user_id, $updatePayload);

				$this->audit->log(
					'USER',
					'users',
					$user_id,
					'UPDATE_IDENTITY',
					array_intersect_key($before, $identityChanges),
					$identityChanges,
					$editing_user_id,
					null,
					$business_id
				);
			}

			// Access update
			$oldAccess = $this->repo->getUserBusinessAccessInfo($user_id, $business_id);
			
			if (!empty($oldAccess)){
				$this->repo->updateRole(
					$user_id,
					$business_id,
					$access['role_id'],
					$access['is_active']
				);
			} else{
				$this->repo->assignRole(
					$user_id,
					$business_id,
					$access['role_id'],
					$access['is_active']
				);
			}				

			$this->audit->log(
				'USER',
				'users',
				$user_id,
				'UPDATE_ACCESS',
				[
					'role_id'   => $oldAccess['id'] ?? null,
					'is_active' => $oldAccess['is_active'] ?? null
				],
				[
					'role_id'   => $access['role_id'],
					'is_active' => $access['is_active']
				],
				$editing_user_id,
				null,
				$business_id
			);

			$this->db->commit();

		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}
	
	public function deleteFromCurrentBusiness(int $user_id, int $business_id, int $editing_user_id): void {
		$this->db->beginTransaction();
		try{
			$oldAccess = $this->repo->getUserBusinessAccessInfo($user_id, $business_id);
			
			$result = $this->repo->deleteFromCurrentBusiness($user_id, $business_id);
			
			$this->audit->log(
				'USER',
				'user_roles',
				$user_id,
				'DELETE',
				[
					'role_id'   => $oldAccess['id'] ?? null,
					'is_active' => $oldAccess['is_active'] ?? null
				],
				null,
				$editing_user_id,
				null,
				$business_id
			);

			$this->db->commit();
			
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}


    public function getUserRoleIdForBusiness(int $user_id, int $business_id) {
        return $this->repo->getUserRoleIdForBusiness($user_id, $business_id);
    }

    public function getPermissionsForRole(int $role_id) {
        return $this->repo->getPermissionsForRole($role_id);
    }
}
