<?php
namespace App\Services;

use App\Repository\UserRepository;
use App\Support\AuthContext;
use InvalidArgumentException;
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
            // Look up by email first, then by username
            $existing = $this->repo->findIdentityByEmail($data['email']);
            if (!$existing) {
                $existing = $this->repo->findIdentityByUsername($data['username']);
            }
            $user_id = null;

            if ($existing) {
                if ($this->repo->getUserBusinessAccessInfo($existing['user_id'], $business_id)) {
                    throw new \RuntimeException('User already assigned to this business');
                }
                $user_id = (int)$existing['user_id'];

                // Reject if user is already a tenant owner for this tenant
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) FROM user_roles ur
                    JOIN roles r ON r.id = ur.role_id
                    WHERE ur.user_id = :user_id AND ur.tenant_id = :tenant_id AND r.type = 'TENANT_OWNER'
                ");
                $stmt->execute(['user_id' => $user_id, 'tenant_id' => $ctx->tenant_id]);
                if ((int) $stmt->fetchColumn() > 0) {
                    throw new \InvalidArgumentException('This user is a Tenant Owner and cannot be assigned a business role in this tenant');
                }
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
                'CREATE',
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
					'UPDATE',
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
			
			if (empty($oldAccess)){
				throw new \RuntimeException('User is not assigned to this business context');
			}
			
			$this->repo->updateRole(
				$user_id,
				$business_id,
				$access['role_id'],
				$access['is_active']
			);

			$this->audit->log(
				'UPDATE',
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
				'DELETE',
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

    // ─── Tenant Owner User Management Methods ───────────────────────────────────

    /**
     * List all users with at least one user_roles record in any business under the tenant.
     * Returns paginated results with business assignments and roles.
     *
     * @param int $tenantId
     * @param int $page     Page number (1-based)
     * @param int $perPage  Items per page (default 20, max 100)
     * @return array{data: array, total: int, current_page: int, last_page: int, per_page: int}
     *
     * Validates: Requirement 9.1
     */
    public function listForTenant(int $tenantId, int $page = 1, int $perPage = 20): array
    {
        // Clamp pagination parameters
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        // Count distinct users with roles in businesses under this tenant
        $countStmt = $this->db->prepare("
            SELECT COUNT(DISTINCT ur.user_id)
            FROM user_roles ur
            JOIN businesses b ON b.id = ur.business_id
            WHERE b.tenant_id = :tenant_id
        ");
        $countStmt->execute(['tenant_id' => $tenantId]);
        $total = (int) $countStmt->fetchColumn();

        // Get paginated user IDs
        $userIdsStmt = $this->db->prepare("
            SELECT DISTINCT ur.user_id
            FROM user_roles ur
            JOIN businesses b ON b.id = ur.business_id
            WHERE b.tenant_id = :tenant_id
            ORDER BY ur.user_id ASC
            LIMIT :limit OFFSET :offset
        ");
        $userIdsStmt->bindValue('tenant_id', $tenantId, PDO::PARAM_INT);
        $userIdsStmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $userIdsStmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $userIdsStmt->execute();
        $userIds = $userIdsStmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($userIds)) {
            return [
                'data' => [],
                'total' => $total,
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
            ];
        }

        // Fetch user details with their business assignments
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $dataStmt = $this->db->prepare("
            SELECT
                u.id AS user_id,
                u.username,
                u.first_name,
                u.last_name,
                u.email,
                ur.business_id,
                b.name AS business_name,
                ur.role_id,
                r.name AS role_name,
                ur.is_active
            FROM users u
            JOIN user_roles ur ON ur.user_id = u.id
            JOIN businesses b ON b.id = ur.business_id
            JOIN roles r ON r.id = ur.role_id
            WHERE u.id IN ({$placeholders})
              AND b.tenant_id = ?
            ORDER BY u.id ASC, b.name ASC
        ");

        $params = array_merge($userIds, [$tenantId]);
        $dataStmt->execute($params);
        $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        // Group by user
        $users = [];
        foreach ($rows as $row) {
            $uid = (int) $row['user_id'];
            if (!isset($users[$uid])) {
                $users[$uid] = [
                    'user_id' => $uid,
                    'username' => $row['username'],
                    'first_name' => $row['first_name'],
                    'last_name' => $row['last_name'],
                    'email' => $row['email'],
                    'assignments' => [],
                ];
            }
            $users[$uid]['assignments'][] = [
                'business_id' => (int) $row['business_id'],
                'business_name' => $row['business_name'],
                'role_id' => (int) $row['role_id'],
                'role_name' => $row['role_name'],
                'is_active' => (int) $row['is_active'],
            ];
        }

        return [
            'data' => array_values($users),
            'total' => $total,
            'current_page' => $page,
            'last_page' => max(1, (int) ceil($total / $perPage)),
            'per_page' => $perPage,
        ];
    }

    /**
     * Create a user with multiple business+role assignments in one request.
     * Validates each business belongs to the tenant and each role belongs to the specified business.
     *
     * @param int   $tenantId
     * @param array $data {
     *     username: string, email: string, first_name: string, last_name: string, password: string,
     *     assignments: array<{business_id: int, role_id: int}>
     * }
     * @return int The created user's ID
     *
     * Validates: Requirement 9.2
     */
    public function createWithMultipleBusinesses(int $tenantId, array $data): int
    {
        $makeTenantOwner = !empty($data['make_tenant_owner']);

        // Validate required fields
        foreach (['username', 'email', 'first_name', 'last_name', 'password'] as $field) {
            if (empty($data[$field])) {
                throw new InvalidArgumentException("Field '{$field}' is required");
            }
        }

        // Validate email format
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email format');
        }

        // Validate password length (minimum 8 characters)
        if (strlen($data['password']) < 8) {
            throw new InvalidArgumentException('Password must be at least 8 characters');
        }

        $assignments = $data['assignments'] ?? [];
        if (!is_array($assignments)) {
            $assignments = [];
        }

        // At least one assignment OR tenant owner flag required
        if (count($assignments) === 0 && !$makeTenantOwner) {
            throw new InvalidArgumentException("At least one business assignment is required (or make the user a Tenant Owner)");
        }

        // Validate each assignment: business belongs to tenant, role belongs to business
        foreach ($assignments as $index => $assignment) {
            if (empty($assignment['business_id']) || empty($assignment['role_id'])) {
                throw new InvalidArgumentException("Assignment at index {$index} must have business_id and role_id");
            }

            // Verify business belongs to tenant
            $bizStmt = $this->db->prepare("
                SELECT id FROM businesses WHERE id = :business_id AND tenant_id = :tenant_id LIMIT 1
            ");
            $bizStmt->execute(['business_id' => $assignment['business_id'], 'tenant_id' => $tenantId]);
            if (!$bizStmt->fetch()) {
                throw new InvalidArgumentException("Business ID {$assignment['business_id']} does not belong to this tenant");
            }

            // Verify role belongs to the specified business
            $roleStmt = $this->db->prepare("
                SELECT id FROM roles WHERE id = :role_id AND business_id = :business_id LIMIT 1
            ");
            $roleStmt->execute(['role_id' => $assignment['role_id'], 'business_id' => $assignment['business_id']]);
            if (!$roleStmt->fetch()) {
                throw new InvalidArgumentException("Role ID {$assignment['role_id']} does not belong to business ID {$assignment['business_id']}");
            }
        }

        $this->db->beginTransaction();
        try {
            // Check if user already exists by email
            $existing = $this->repo->findIdentityByEmail($data['email']);
            $userId = null;

            if ($existing) {
                $userId = (int) $existing['user_id'];
            } else {
                $passwordHash = password_hash($data['password'], PASSWORD_DEFAULT);
                $userId = $this->repo->createIdentity([
                    'username' => $data['username'],
                    'email' => $data['email'],
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'password_hash' => $passwordHash,
                ]);
            }

            // Create all role assignments
            foreach ($assignments as $assignment) {
                // Check if user already has a role in this business
                $existingAccess = $this->repo->getUserBusinessAccessInfo($userId, $assignment['business_id']);
                if ($existingAccess) {
                    throw new InvalidArgumentException(
                        "User already has a role assignment in business ID {$assignment['business_id']}"
                    );
                }

                $this->repo->assignRole(
                    $userId,
                    $assignment['business_id'],
                    $assignment['role_id'],
                    1 // active by default
                );
            }

            // Assign TENANT_OWNER role if requested
            if ($makeTenantOwner) {
                $toRoleStmt = $this->db->prepare("SELECT id FROM roles WHERE type = 'TENANT_OWNER' LIMIT 1");
                $toRoleStmt->execute();
                $tenantOwnerRoleId = (int) $toRoleStmt->fetchColumn();

                if ($tenantOwnerRoleId) {
                    // Check if already a tenant owner for this tenant
                    $checkStmt = $this->db->prepare("
                        SELECT COUNT(*) FROM user_roles
                        WHERE user_id = :user_id AND role_id = :role_id AND tenant_id = :tenant_id
                    ");
                    $checkStmt->execute(['user_id' => $userId, 'role_id' => $tenantOwnerRoleId, 'tenant_id' => $tenantId]);
                    if ((int) $checkStmt->fetchColumn() === 0) {
                        $stmt = $this->db->prepare("
                            INSERT INTO user_roles (user_id, role_id, business_id, tenant_id, is_active)
                            VALUES (:user_id, :role_id, NULL, :tenant_id, 1)
                        ");
                        $stmt->execute([
                            'user_id' => $userId,
                            'role_id' => $tenantOwnerRoleId,
                            'tenant_id' => $tenantId,
                        ]);
                    }
                }
            }

            // Audit log
            $ctx = AuthContext::get();
            $this->audit->log(
                'CREATE',
                'users',
                $userId,
                'CREATE_MULTI_BUSINESS',
                null,
                [
                    'email' => $data['email'],
                    'assignments' => $data['assignments'],
                ],
                $ctx->user_id ?? null,
                $tenantId,
                null,
                true,
                true // tenantScope — user created by Tenant Owner (Req 6.4)
            );

            $this->db->commit();
            return $userId;

        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Assign a role to a user for a specific business (Tenant Owner operation).
     * Replaces existing user_roles record or creates a new one.
     * Validates user exists and business belongs to tenant.
     *
     * @param int $userId
     * @param int $businessId
     * @param int $roleId
     * @param int $tenantId
     *
     * Validates: Requirements 5.3, 5.4, 5.5
     */
    public function assignRoleForTenantOwner(int $userId, int $businessId, int $roleId, int $tenantId): void
    {
        // Validate user exists
        $userStmt = $this->db->prepare("SELECT id FROM users WHERE id = :user_id LIMIT 1");
        $userStmt->execute(['user_id' => $userId]);
        if (!$userStmt->fetch()) {
            throw new InvalidArgumentException("User does not exist");
        }

        // Guard: reject if user is already a tenant owner of this tenant
        if ($this->repo->isTenantOwner($userId, $tenantId)) {
            throw new InvalidArgumentException(
                'Tenant owners cannot be assigned business-level roles within their own tenant'
            );
        }

        // Validate business belongs to tenant
        $bizStmt = $this->db->prepare("
            SELECT id FROM businesses WHERE id = :business_id AND tenant_id = :tenant_id LIMIT 1
        ");
        $bizStmt->execute(['business_id' => $businessId, 'tenant_id' => $tenantId]);
        if (!$bizStmt->fetch()) {
            throw new InvalidArgumentException("Business does not belong to this tenant");
        }

        // Validate role belongs to the target business
        $roleStmt = $this->db->prepare("
            SELECT id FROM roles WHERE id = :role_id AND business_id = :business_id LIMIT 1
        ");
        $roleStmt->execute(['role_id' => $roleId, 'business_id' => $businessId]);
        if (!$roleStmt->fetch()) {
            throw new InvalidArgumentException("Role does not belong to the target business");
        }

        $this->db->beginTransaction();
        try {
            // Check if user already has a role in this business
            $existingAccess = $this->repo->getUserBusinessAccessInfo($userId, $businessId);

            if ($existingAccess) {
                // Replace existing role assignment
                $this->repo->updateRole($userId, $businessId, $roleId, 1);
            } else {
                // Create new role assignment
                $this->repo->assignRole($userId, $businessId, $roleId, 1);
            }

            // Audit log
            $ctx = AuthContext::get();
            $this->audit->log(
                'UPDATE',
                'user_roles',
                $userId,
                'ASSIGN_ROLE',
                $existingAccess ? ['role_id' => $existingAccess['id'], 'is_active' => $existingAccess['is_active']] : null,
                ['role_id' => $roleId, 'business_id' => $businessId],
                $ctx->user_id ?? null,
                $tenantId,
                $businessId,
                true,
                true // tenantScope — role assigned by Tenant Owner (Req 6.4)
            );

            $this->db->commit();

        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Reassign a user from one business to another within the tenant.
     * Removes user_roles record from source business and creates a new one in target business.
     * Rejects if user already has a role in the target business.
     *
     * @param int $userId
     * @param int $fromBusinessId
     * @param int $toBusinessId
     * @param int $newRoleId
     * @param int $tenantId
     *
     * Validates: Requirements 9.3, 9.4
     */
    public function reassignBusiness(int $userId, int $fromBusinessId, int $toBusinessId, int $newRoleId, int $tenantId): void
    {
        // Validate user exists
        $userStmt = $this->db->prepare("SELECT id FROM users WHERE id = :user_id LIMIT 1");
        $userStmt->execute(['user_id' => $userId]);
        if (!$userStmt->fetch()) {
            throw new InvalidArgumentException("User does not exist");
        }

        // Validate both businesses belong to tenant
        $bizStmt = $this->db->prepare("
            SELECT id FROM businesses WHERE id = :business_id AND tenant_id = :tenant_id LIMIT 1
        ");
        $bizStmt->execute(['business_id' => $fromBusinessId, 'tenant_id' => $tenantId]);
        if (!$bizStmt->fetch()) {
            throw new InvalidArgumentException("Source business does not belong to this tenant");
        }

        $bizStmt->execute(['business_id' => $toBusinessId, 'tenant_id' => $tenantId]);
        if (!$bizStmt->fetch()) {
            throw new InvalidArgumentException("Target business does not belong to this tenant");
        }

        // Validate new role belongs to target business
        $roleStmt = $this->db->prepare("
            SELECT id FROM roles WHERE id = :role_id AND business_id = :business_id LIMIT 1
        ");
        $roleStmt->execute(['role_id' => $newRoleId, 'business_id' => $toBusinessId]);
        if (!$roleStmt->fetch()) {
            throw new InvalidArgumentException("Role does not belong to the target business");
        }

        // Validate user has a role in the source business
        $sourceAccess = $this->repo->getUserBusinessAccessInfo($userId, $fromBusinessId);
        if (!$sourceAccess) {
            throw new InvalidArgumentException("User does not have a role in the source business");
        }

        // Reject if user already has a role in the target business (Req 9.4)
        $targetAccess = $this->repo->getUserBusinessAccessInfo($userId, $toBusinessId);
        if ($targetAccess) {
            throw new InvalidArgumentException("User already exists in the target business");
        }

        $this->db->beginTransaction();
        try {
            // Remove from source business
            $this->repo->deleteFromCurrentBusiness($userId, $fromBusinessId);

            // Add to target business
            $this->repo->assignRole($userId, $toBusinessId, $newRoleId, 1);

            // Audit log
            $ctx = AuthContext::get();
            $this->audit->log(
                'UPDATE',
                'user_roles',
                $userId,
                'REASSIGN_BUSINESS',
                ['business_id' => $fromBusinessId, 'role_id' => $sourceAccess['id']],
                ['business_id' => $toBusinessId, 'role_id' => $newRoleId],
                $ctx->user_id ?? null,
                $tenantId,
                null,
                true,
                true // tenantScope — user reassigned by Tenant Owner (Req 6.4)
            );

            $this->db->commit();

        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Deactivate a user across all businesses in the tenant.
     * Sets is_active=0 on all user_roles records for the user within the tenant.
     * Rejects if the user is the sole SYSTEM_OWNER of any business.
     *
     * @param int $userId
     * @param int $tenantId
     *
     * Validates: Requirements 9.5, 9.6
     */
    public function deactivateAcrossTenant(int $userId, int $tenantId): void
    {
        // Validate user exists
        $userStmt = $this->db->prepare("SELECT id FROM users WHERE id = :user_id LIMIT 1");
        $userStmt->execute(['user_id' => $userId]);
        if (!$userStmt->fetch()) {
            throw new InvalidArgumentException("User does not exist");
        }

        // Get all user_roles for this user in businesses under this tenant
        $rolesStmt = $this->db->prepare("
            SELECT ur.id, ur.business_id, ur.role_id, r.type AS role_type, b.name AS business_name
            FROM user_roles ur
            JOIN businesses b ON b.id = ur.business_id
            JOIN roles r ON r.id = ur.role_id
            WHERE ur.user_id = :user_id AND b.tenant_id = :tenant_id
        ");
        $rolesStmt->execute(['user_id' => $userId, 'tenant_id' => $tenantId]);
        $userRoles = $rolesStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($userRoles)) {
            throw new InvalidArgumentException("User has no roles in this tenant");
        }

        // Check if user is sole SYSTEM_OWNER of any business (Req 9.6)
        $affectedBusinesses = [];
        foreach ($userRoles as $ur) {
            if ($ur['role_type'] === 'SYSTEM_OWNER') {
                // Count other active SYSTEM_OWNER users in this business
                $countStmt = $this->db->prepare("
                    SELECT COUNT(*) FROM user_roles ur2
                    JOIN roles r2 ON r2.id = ur2.role_id
                    WHERE ur2.business_id = :business_id
                      AND ur2.user_id != :user_id
                      AND ur2.is_active = 1
                      AND r2.type = 'SYSTEM_OWNER'
                ");
                $countStmt->execute(['business_id' => $ur['business_id'], 'user_id' => $userId]);
                $otherOwners = (int) $countStmt->fetchColumn();

                if ($otherOwners === 0) {
                    $affectedBusinesses[] = $ur['business_name'];
                }
            }
        }

        if (!empty($affectedBusinesses)) {
            $businessList = implode(', ', $affectedBusinesses);
            throw new InvalidArgumentException(
                "Cannot deactivate: user is sole owner of businesses: {$businessList}"
            );
        }

        $this->db->beginTransaction();
        try {
            // Set is_active=0 on all user_roles for this user in the tenant
            $deactivateStmt = $this->db->prepare("
                UPDATE user_roles ur
                JOIN businesses b ON b.id = ur.business_id
                SET ur.is_active = 0
                WHERE ur.user_id = :user_id AND b.tenant_id = :tenant_id
            ");
            $deactivateStmt->execute(['user_id' => $userId, 'tenant_id' => $tenantId]);

            // Audit log
            $ctx = AuthContext::get();
            $this->audit->log(
                'UPDATE',
                'user_roles',
                $userId,
                'DEACTIVATE_ACROSS_TENANT',
                null,
                ['is_active' => 0, 'affected_roles' => count($userRoles)],
                $ctx->user_id ?? null,
                $tenantId,
                null,
                true,
                true // tenantScope — user deactivated by Tenant Owner (Req 6.4)
            );

            $this->db->commit();

        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Reactivate a user across all businesses in the tenant.
     * Sets is_active=1 on all user_roles for the user within the tenant's businesses.
     *
     * @param int $userId
     * @param int $tenantId
     */
    public function reactivateAcrossTenant(int $userId, int $tenantId): void
    {
        // Validate user exists
        $userStmt = $this->db->prepare("SELECT id FROM users WHERE id = :user_id LIMIT 1");
        $userStmt->execute(['user_id' => $userId]);
        if (!$userStmt->fetch()) {
            throw new InvalidArgumentException("User does not exist");
        }

        // Get all user_roles for this user in businesses under this tenant
        $rolesStmt = $this->db->prepare("
            SELECT ur.id
            FROM user_roles ur
            JOIN businesses b ON b.id = ur.business_id
            WHERE ur.user_id = :user_id AND b.tenant_id = :tenant_id
        ");
        $rolesStmt->execute(['user_id' => $userId, 'tenant_id' => $tenantId]);
        $userRoles = $rolesStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($userRoles)) {
            throw new InvalidArgumentException("User has no roles in this tenant");
        }

        $this->db->beginTransaction();
        try {
            // Set is_active=1 on all user_roles for this user in the tenant
            $activateStmt = $this->db->prepare("
                UPDATE user_roles ur
                JOIN businesses b ON b.id = ur.business_id
                SET ur.is_active = 1
                WHERE ur.user_id = :user_id AND b.tenant_id = :tenant_id
            ");
            $activateStmt->execute(['user_id' => $userId, 'tenant_id' => $tenantId]);

            // Audit log
            $ctx = AuthContext::get();
            $this->audit->log(
                'UPDATE',
                'user_roles',
                $userId,
                'REACTIVATE_ACROSS_TENANT',
                ['is_active' => 0],
                ['is_active' => 1, 'affected_roles' => count($userRoles)],
                $ctx->user_id ?? null,
                $tenantId,
                null,
                true,
                true
            );

            $this->db->commit();

        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Get a user's full profile across the tenant: all business assignments and last 100 activity log entries.
     *
     * @param int $userId
     * @param int $tenantId
     * @return array{user: array, assignments: array, activity_log: array}
     *
     * Validates: Requirement 9.8
     */
    public function getUserProfileAcrossTenant(int $userId, int $tenantId): array
    {
        // Validate user exists
        $userStmt = $this->db->prepare("
            SELECT id AS user_id, username, first_name, last_name, email, created_at
            FROM users WHERE id = :user_id LIMIT 1
        ");
        $userStmt->execute(['user_id' => $userId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            throw new InvalidArgumentException("User does not exist");
        }

        // Get all assignments in businesses under this tenant
        $assignmentsStmt = $this->db->prepare("
            SELECT
                ur.business_id,
                b.name AS business_name,
                ur.role_id,
                r.name AS role_name,
                r.type AS role_type,
                ur.is_active
            FROM user_roles ur
            JOIN businesses b ON b.id = ur.business_id
            JOIN roles r ON r.id = ur.role_id
            WHERE ur.user_id = :user_id AND b.tenant_id = :tenant_id
            ORDER BY b.name ASC
        ");
        $assignmentsStmt->execute(['user_id' => $userId, 'tenant_id' => $tenantId]);
        $assignments = $assignmentsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Check if user is a tenant owner
        $isTenantOwner = $this->repo->isTenantOwner($userId, $tenantId);

        // Get last 100 activity log entries for this user across the tenant
        $activityStmt = $this->db->prepare("
            SELECT
                al.id,
                al.event_type,
                al.table_name,
                al.record_id,
                al.action,
                al.old_values,
                al.new_values,
                al.business_id,
                b.name AS business_name,
                al.created_at
            FROM audit_logs al
            LEFT JOIN businesses b ON b.id = al.business_id
            WHERE al.user_id = :user_id
              AND (al.tenant_id = :tenant_id OR al.business_id IN (
                  SELECT id FROM businesses WHERE tenant_id = :tenant_id2
              ))
            ORDER BY al.created_at DESC
            LIMIT 100
        ");
        $activityStmt->execute([
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'tenant_id2' => $tenantId,
        ]);
        $activityLog = $activityStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'user' => $user,
            'is_tenant_owner' => $isTenantOwner,
            'assignments' => $assignments,
            'activity_log' => $activityLog,
        ];
    }

    /**
     * Toggle the is_active flag on the users table.
     * When deactivating, also revokes all refresh tokens for the user.
     *
     * @param int $userId
     * @return bool The new is_active state
     */
    public function toggleActive(int $userId): bool
    {
        // Get current state
        $stmt = $this->db->prepare("SELECT is_active FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $userId]);
        $current = $stmt->fetchColumn();

        if ($current === false) {
            throw new InvalidArgumentException("User not found");
        }

        $newState = (int) $current === 1 ? false : true;

        $this->db->beginTransaction();
        try {
            $this->repo->setActive($userId, $newState);

            // Revoke all refresh tokens when deactivating
            if (!$newState) {
                $this->repo->revokeAllRefreshTokens($userId);
            }

            // Audit log
            $ctx = AuthContext::get();
            $this->audit->log(
                'UPDATE',
                'users',
                $userId,
                $newState ? 'ACTIVATE_USER' : 'DEACTIVATE_USER',
                ['is_active' => (int) $current],
                ['is_active' => $newState ? 1 : 0],
                $ctx->user_id ?? null,
                $ctx->tenant_id ?? null,
                $ctx->business_id ?? null
            );

            $this->db->commit();
            return $newState;

        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Update the global profile attributes (first_name, last_name, email).
     */
    public function updateProfileIdentity(int $userId, array $updates): void {
        $this->db->beginTransaction();
        try {
            $this->repo->updateIdentity($userId, $updates);
            // Optionally log this global action in audit logs (if tenant context exists)
            $ctx = AuthContext::get();
            if ($ctx && $ctx->tenant_id) {
                $this->audit->log('UPDATE', 'users', $userId, 'UPDATE_PROFILE', null, $updates, $userId, $ctx->tenant_id, $ctx->business_id);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Verify current password and update to new password.
     */
    public function changeProfilePassword(int $userId, string $currentPassword, string $newPassword): void {
        $this->db->beginTransaction();
        try {
            $user = $this->repo->findIdentityById($userId);
            if (!$user) {
                throw new \RuntimeException('User not found');
            }

            if (!password_verify($currentPassword, $user['password_hash'])) {
                throw new \InvalidArgumentException('Current password is incorrect');
            }

            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $this->repo->updateIdentity($userId, ['password_hash' => $newHash]);

            $ctx = AuthContext::get();
            if ($ctx && $ctx->tenant_id) {
                $this->audit->log('UPDATE', 'users', $userId, 'CHANGE_PASSWORD', null, null, $userId, $ctx->tenant_id, $ctx->business_id);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
