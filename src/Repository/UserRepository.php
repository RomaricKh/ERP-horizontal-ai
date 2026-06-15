<?php
	// src/Repository/UserRepository.php
namespace App\Repository;

use PDO;

class UserRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }
	
    public function findAllByBusiness(int $businessId): array {
        $stmt = $this->db->prepare("
            SELECT
                u.id AS user_id,
                u.username,
                CONCAT(u.first_name, ' ', u.last_name) AS name,
                u.email,
                u.is_active AS is_account_active,
                r.name AS role_name,
                b.name AS business_name,
                ur.is_active
            FROM user_roles ur
            JOIN users u ON u.id = ur.user_id
            JOIN roles r ON r.id = ur.role_id
            JOIN businesses b ON b.id = ur.business_id
            WHERE ur.business_id = :business
        ");

        $stmt->execute(['business' => $businessId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findByIdAndBusiness(int $user_id, int $businessId): ?array {
        $stmt = $this->db->prepare("
            SELECT u.id AS user_id,
				u.username,
                u.first_name, 
				u.last_name,
				u.email,
				ur.role_id,
				ur.is_active
            FROM users u
            JOIN user_roles ur ON ur.user_id = u.id
            WHERE u.id = :id AND ur.business_id = :business
        ");

        $stmt->execute([
            'id'       => $user_id,
            'business' => $businessId
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
	
	private function findIdentity(string $field, $value) {
		$allowed = ['id', 'username', 'email'];
		if (!in_array($field, $allowed, true)) {
			throw new \InvalidArgumentException('Invalid identity field');
		}

		$stmt = $this->db->prepare("
			SELECT
				id AS user_id,
				username,
				password_hash,
                first_name, 
				last_name,
				email,
				failed_attempts,
				lock_until,
				created_at,
				updated_at
			FROM users
			WHERE {$field} = :value
			  AND (lock_until IS NULL OR lock_until < NOW())
			LIMIT 1
		");

		$stmt->execute(['value' => $value]);
		return $stmt->fetch(PDO::FETCH_ASSOC);
	}
	
	public function findIdentityByUsername($username) {
		return $this->findIdentity('username', $username);
	}

	public function findIdentityById($id) {
		return $this->findIdentity('id', $id);
	}

	public function findIdentityByEmail($email) {
		return $this->findIdentity('email', $email);
	}
	
	public function findIdentityByIdForAdmin(int $id): ?array {
		$stmt = $this->db->prepare("
			SELECT
				id AS user_id,
				username,
				password_hash,
				first_name,
				last_name,
				email
			FROM users
			WHERE id = :id
			LIMIT 1
		");
		$stmt->execute(['id' => $id]);
		return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
	}

    public function fetchAuthContext($user_id) {
        $stmt = $this->db->prepare("
						WITH user_data AS (
							SELECT *
							FROM users
							WHERE id = :user_id
							  AND (lock_until IS NULL OR lock_until < NOW())
						),
						user_roles_cte AS (
							SELECT
								ur.user_id, ur.role_id, r.name AS role_name, ur.tenant_id,
								ur.business_id, b.name AS business_name, ur.is_active
							FROM user_roles ur
							INNER JOIN roles r ON r.id = ur.role_id
							LEFT JOIN businesses b ON b.id = ur.business_id
							WHERE ur.user_id = (SELECT id FROM user_data)
							  AND ur.is_active = 1
						),
						role_permissions_cte AS (
							SELECT rp.role_id, p.code AS permission_code
							FROM role_permissions rp
							INNER JOIN permissions p ON p.id = rp.permission_id
							WHERE rp.role_id IN (SELECT role_id FROM user_roles_cte)
							UNION
							SELECT ur.role_id, p.code AS permission_code
							FROM user_roles_cte ur
							INNER JOIN roles r ON r.id = ur.role_id
							CROSS JOIN permissions p
							WHERE r.type IN ('SYSTEM_OWNER', 'TENANT_OWNER')
						),
						roles_with_permissions AS (
							SELECT
								ur.role_id,
								ur.role_name, ur.business_id, ur.business_name, ur.is_active,
								JSON_ARRAYAGG(rp.permission_code) AS permissions
							FROM user_roles_cte ur
							LEFT JOIN role_permissions_cte rp ON rp.role_id = ur.role_id
							GROUP BY ur.role_id, ur.business_id
						),
						first_business_cte AS (
							SELECT b.id AS first_business_id
							FROM user_roles_cte urc
							INNER JOIN roles r ON r.id = urc.role_id AND r.type = 'TENANT_OWNER'
							INNER JOIN businesses b ON b.tenant_id = urc.tenant_id
							ORDER BY b.id ASC
							LIMIT 1
						)
						SELECT
							ud.id AS user_id,
							ud.username,
							ud.password_hash,
                			CONCAT(ud.first_name, ' ', ud.last_name) AS name,
							ud.email,
							ud.failed_attempts,
							ud.lock_until,
							ud.created_at,
							ud.updated_at,
							(SELECT first_business_id FROM first_business_cte) AS first_business_id,
							JSON_ARRAYAGG(
								JSON_OBJECT(
									'id', r.role_id,
									'name', r.role_name,
									'business_id', r.business_id,
									'business_name', r.business_name,
									'is_active', r.is_active,
									'permissions', r.permissions
								)
							) AS roles
						FROM user_data ud
						LEFT JOIN roles_with_permissions r ON 1=1
						GROUP BY ud.id
        ");
        $stmt->execute([
            'user_id' => $user_id
        ]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function incrementFailedAttempts($user_id) {
        $stmt = $this->db->prepare("
            UPDATE users 
            SET failed_attempts = failed_attempts + 1
            WHERE id = :id
        ");
        $stmt->execute(['id' => $user_id]);
    }

    public function lockAccount($user_id, $minutes = 15) {
        $stmt = $this->db->prepare("
            UPDATE users 
            SET lock_until = DATE_ADD(NOW(), INTERVAL :minutes MINUTE),
                failed_attempts = 0
            WHERE id = :id
        ");
        $stmt->execute(['id' => $user_id, 'minutes' => $minutes]);
    }

    public function resetFailedAttempts($user_id) {
        $stmt = $this->db->prepare("
            UPDATE users SET failed_attempts = 0, lock_until = NULL WHERE id = :id
        ");
        $stmt->execute(['id' => $user_id]);
    }
	
	public function getUserRoleIdForBusiness($user_id, $business_id) {
		$stmt = $this->db->prepare("
						SELECT r.id
						FROM user_roles ur
						JOIN roles r ON r.id = ur.role_id
						WHERE ur.user_id = :user_id
						  AND ur.business_id = :business_id
        				LIMIT 1
		");
		$stmt->execute([
            'user_id' => $user_id,
            'business_id' => $business_id
        ]);

		$roleId = $stmt->fetchColumn();
		return $roleId !== false ? (int)$roleId : null;
	}
	
	public function getUserBusinessAccessInfo($user_id, $business_id) {
		$stmt = $this->db->prepare("
						SELECT r.id, ur.is_active
						FROM user_roles ur
						JOIN roles r ON r.id = ur.role_id
						WHERE ur.user_id = :user_id
						  AND ur.business_id = :business_id
        				LIMIT 1
		");
		$stmt->execute([
            'user_id' => $user_id,
            'business_id' => $business_id
        ]);

		return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
	}
	
    public function createIdentity(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO users (username, email, first_name, last_name, password_hash, created_at)
            VALUES (:username, :email, :first_name, :last_name, :password_hash, NOW())
        ");

        $stmt->execute($data);
        return (int)$this->db->lastInsertId();
    }

	public function updateIdentity(int $user_id, array $data): void {
		$fields = [];
		$params = ['id' => $user_id];

		foreach ($data as $key => $value) {
			$fields[] = "$key = :$key";
			$params[$key] = $value;
		}

		$sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = :id";
		$stmt = $this->db->prepare($sql);
		$stmt->execute($params);
	}

    public function assignRole(int $user_id, int $businessId, int $roleId, $is_active): void {
        $stmt = $this->db->prepare("
            INSERT INTO user_roles (user_id, business_id, role_id, is_active, tenant_id)
            VALUES (:user, :business, :role, :is_active, (SELECT tenant_id FROM businesses WHERE id = :biz_lookup))
        ");

        $stmt->execute([
            'user'     => $user_id,
            'business' => $businessId,
            'role'     => $roleId,
			'is_active' => $is_active,
            'biz_lookup' => $businessId
        ]);
    }

    public function updateRole(int $user_id, int $businessId, int $roleId, $is_active): void {
        $stmt = $this->db->prepare("
            UPDATE user_roles
            SET role_id = :role, is_active = :is_active
            WHERE user_id = :user AND business_id = :business
        ");

        $stmt->execute([
            'role'     => $roleId,
            'user'     => $user_id,
            'business' => $businessId,
			'is_active' => $is_active
        ]);
    }

	public function deleteFromCurrentBusiness(int $user_id, int $business_id): void {
        $stmt = $this->db->prepare("
            DELETE FROM user_roles
            WHERE user_id = :user AND business_id = :business
        ");

        $stmt->execute([
            'user'     => $user_id,
            'business' => $business_id
        ]);
	}
	
	/**
	 * Set the is_active flag on the users table for a given user.
	 */
	public function setActive(int $userId, bool $active): void {
		$stmt = $this->db->prepare("UPDATE users SET is_active = :active WHERE id = :id");
		$stmt->execute([
			'active' => $active ? 1 : 0,
			'id' => $userId
		]);
	}

	/**
	 * Revoke all refresh tokens for a given user.
	 */
	public function revokeAllRefreshTokens(int $userId): void {
		$stmt = $this->db->prepare("
			UPDATE refresh_tokens SET revoked_at = NOW()
			WHERE user_id = :user_id AND revoked_at IS NULL
		");
		$stmt->execute(['user_id' => $userId]);
	}

	/**
	 * Check if a user holds the TENANT_OWNER role for a given tenant.
	 */
	public function isTenantOwner(int $userId, int $tenantId): bool {
		$stmt = $this->db->prepare("
			SELECT COUNT(*) FROM user_roles ur
			JOIN roles r ON r.id = ur.role_id
			WHERE ur.user_id = :userId AND ur.tenant_id = :tenantId AND r.type = 'TENANT_OWNER'
		");
		$stmt->execute(['userId' => $userId, 'tenantId' => $tenantId]);
		return (int) $stmt->fetchColumn() > 0;
	}

	public function getPermissionsForRole(int $role_id) {
		// Resolve role type from the roles table
		$stmt = $this->db->prepare("SELECT type FROM roles WHERE id = :role_id");
		$stmt->execute(['role_id' => $role_id]);
		$type = $stmt->fetchColumn();

		if ($type === 'SYSTEM_OWNER') {
			// SYSTEM_OWNER always has ALL permissions
			$stmt = $this->db->prepare("SELECT DISTINCT code FROM permissions ORDER BY code");
			$stmt->execute();
			return $stmt->fetchAll(PDO::FETCH_COLUMN);
		}

		// CUSTOM/TEMPLATE roles: return permissions via role_permissions join
		$stmt = $this->db->prepare("
						SELECT DISTINCT p.code
						FROM role_permissions rp
						JOIN permissions p ON p.id = rp.permission_id
						WHERE rp.role_id = :role_id
		");
		$stmt->execute([
            'role_id' => $role_id
        ]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
	}
}
