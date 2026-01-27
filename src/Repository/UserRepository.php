<?php
	// src/Repository/UserRepository.php
namespace App\Repository;

use App\Config\Database;
use PDO;

class UserRepository {
    private $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }
	
    public function findAllByBusiness(int $businessId): array {
        $stmt = $this->db->prepare("
            SELECT
                u.id AS user_id,
                u.username,
                u.name,
                u.email,
                u.is_active,
                r.name AS role_name,
                b.name AS business_name
            FROM user_roles ur
            JOIN users u ON u.id = ur.user_id
            JOIN roles r ON r.id = ur.role_id
            JOIN businesses b ON b.id = ur.business_id
            WHERE ur.business_id = :business
        ");

        $stmt->execute(['business' => $businessId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findByIdAndBusiness(int $userId, int $businessId): ?array {
        $stmt = $this->db->prepare("
            SELECT u.*
            FROM users u
            JOIN user_roles ur ON ur.user_id = u.id
            WHERE u.id = :id AND ur.business_id = :business
        ");

        $stmt->execute([
            'id'       => $userId,
            'business' => $businessId
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findByUsername($username) {
        $stmt = $this->db->prepare("
						WITH user_data AS (
							SELECT *
							FROM users
							WHERE username = :username
							  AND is_active = 1
							  AND (lock_until IS NULL OR lock_until < NOW())
						),
						user_roles_cte AS (
							SELECT 
								ur.user_id, ur.role_id, r.name AS role_name, 
								ur.business_id, b.name AS business_name, ur.tenant_id 
							FROM user_roles ur
							INNER JOIN roles r ON r.id = ur.role_id
							LEFT JOIN businesses b ON b.id = ur.business_id
							WHERE ur.user_id = (SELECT id FROM user_data)
						),
						role_permissions_cte AS (
							SELECT rp.role_id, p.code AS permission_code
							FROM role_permissions rp
							INNER JOIN permissions p ON p.id = rp.permission_id
							WHERE rp.role_id IN (SELECT role_id FROM user_roles_cte)
						),
						roles_with_permissions AS (
							SELECT
								ur.role_id,
								ur.role_name, ur.business_id, ur.business_name, ur.tenant_id,
								JSON_ARRAYAGG(rp.permission_code) AS permissions
							FROM user_roles_cte ur
							LEFT JOIN role_permissions_cte rp ON rp.role_id = ur.role_id
							GROUP BY ur.role_id, ur.role_name, ur.business_id
						)
						SELECT
							ud.id AS user_id,
							ud.username,
							ud.password_hash,
							ud.name,
							ud.email,
							ud.is_active,
							ud.failed_attempts,
							ud.lock_until,
							ud.created_at,
							ud.updated_at,
							JSON_ARRAYAGG(
								JSON_OBJECT(
									'id', r.role_id,
									'name', r.role_name,
									'business_id', r.business_id,
									'business_name', r.business_name,
									'tenant_id', r.tenant_id,
									'permissions', r.permissions
								)
							) AS roles
						FROM user_data ud
						LEFT JOIN roles_with_permissions r ON 1=1
						GROUP BY ud.id;

        ");
        $stmt->execute([
            'username' => $username
		]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findById($user_id) {
        $stmt = $this->db->prepare("
						WITH user_data AS (
							SELECT *
							FROM users
							WHERE id = :user_id
							  AND is_active = 1
							  AND (lock_until IS NULL OR lock_until < NOW())
						),
						user_roles_cte AS (
							SELECT
								ur.user_id, ur.role_id, r.name AS role_name, 
								ur.business_id, b.name AS business_name, ur.tenant_id
							FROM user_roles ur
							INNER JOIN roles r ON r.id = ur.role_id
							LEFT JOIN businesses b ON b.id = ur.business_id
							WHERE ur.user_id = (SELECT id FROM user_data)
						),
						role_permissions_cte AS (
							SELECT rp.role_id, p.code AS permission_code
							FROM role_permissions rp
							INNER JOIN permissions p ON p.id = rp.permission_id
							WHERE rp.role_id IN (SELECT role_id FROM user_roles_cte)
						),
						roles_with_permissions AS (
							SELECT
								ur.role_id,
								ur.role_name, ur.business_id, ur.business_name, ur.tenant_id,
								JSON_ARRAYAGG(rp.permission_code) AS permissions
							FROM user_roles_cte ur
							LEFT JOIN role_permissions_cte rp ON rp.role_id = ur.role_id
							GROUP BY ur.role_id, ur.role_name, ur.business_id
						)
						SELECT
							ud.id AS user_id,
							ud.username,
							ud.password_hash,
							ud.name,
							ud.email,
							ud.is_active,
							ud.failed_attempts,
							ud.lock_until,
							ud.created_at,
							ud.updated_at,
							JSON_ARRAYAGG(
								JSON_OBJECT(
									'id', r.role_id,
									'name', r.role_name,
									'business_id', r.business_id,
									'business_name', r.business_name,
									'tenant_id', r.tenant_id,
									'permissions', r.permissions
								)
							) AS roles
						FROM user_data ud
						LEFT JOIN roles_with_permissions r ON 1=1
						GROUP BY ud.id;
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
error_log("SQL getUserRoleIdForBusiness user_id=$user_id business_id=$business_id");

		$roleId = $stmt->fetchColumn();
		return $roleId !== false ? (int)$roleId : null;
	}
	
	public function getUserPermissionsForRole($role_id) {
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
	
    public function create(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO users (username, email, name, is_active, created_at)
            VALUES (:username, :email, :name, 1, NOW())
        ");

        $stmt->execute($data);
        return (int)$this->db->lastInsertId();
    }

    public function update(int $userId, array $data): void {
        $stmt = $this->db->prepare("
            UPDATE users
            SET name = :name, email = :email
            WHERE id = :id
        ");

        $stmt->execute([
            'id'    => $userId,
            'name'  => $data['name'],
            'email' => $data['email']
        ]);
    }

    public function toggleStatus(int $userId): void {
        $this->db->exec("
            UPDATE users
            SET is_active = NOT is_active
            WHERE id = {$userId}
        ");
    }

    public function assignRole(int $userId, int $businessId, int $roleId): void {
        $stmt = $this->db->prepare("
            INSERT INTO user_roles (user_id, business_id, role_id)
            VALUES (:user, :business, :role)
        ");

        $stmt->execute([
            'user'     => $userId,
            'business' => $businessId,
            'role'     => $roleId
        ]);
    }

    public function updateRole(int $userId, int $businessId, int $roleId): void {
        $stmt = $this->db->prepare("
            UPDATE user_roles
            SET role_id = :role
            WHERE user_id = :user AND business_id = :business
        ");

        $stmt->execute([
            'role'     => $roleId,
            'user'     => $userId,
            'business' => $businessId
        ]);
    }


}
