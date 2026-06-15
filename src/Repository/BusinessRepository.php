<?php
	// src/Models/BusinessRepository.php
namespace App\Repository;

use PDO;

class BusinessRepository {
    private $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }
	
	public function getAllForCurrentUser(int $user_id): array {
		$stmt = $this->db->prepare("
						SELECT 	b.id, b.name, b.legal_name, b.registration_no, 
								b.address, b.phone, b.email, b.currency
						FROM 	businesses b
						INNER JOIN user_roles ur ON ur.business_id = b.id AND ur.user_id = :user_id
						GROUP BY b.id
		");
        $stmt->execute([
            'user_id' => $user_id
		]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	public function getAllForTenant(int $tenantId): array {
		$stmt = $this->db->prepare("
						SELECT 	b.id, b.name, b.legal_name, b.registration_no, 
								b.address, b.phone, b.email, b.currency, b.is_active,
								bt.name AS business_type
						FROM 	businesses b
						LEFT JOIN business_types bt ON bt.id = b.business_type_id
						WHERE 	b.tenant_id = :tenant_id
						ORDER BY b.id ASC
		");
        $stmt->execute([
            'tenant_id' => $tenantId
		]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}
	
	public function getBusinessByID(int $business_id): ?array {
		$stmt = $this->db->prepare("
						SELECT 	b.id, b.name, b.legal_name, b.registration_no, 
								b.address, b.phone, b.email, b.currency
						FROM 	businesses b
						WHERE b.id = :business_id
		");
        $stmt->execute([
            'business_id' => $business_id
		]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
	}
	
	public function create(array $data): int {
		$stmt = $this->db->prepare("
			INSERT INTO businesses (
				tenant_id, name, legal_name, registration_no, 
				address, phone, email, currency, business_type_id, created_at
			) VALUES (
				:tenant_id, :name, :legal_name, :registration_no,
				:address, :phone, :email, :currency, :business_type_id, NOW()
			)
		");

		$stmt->execute([
			'tenant_id'        => $data['tenant_id'],
			'name'             => $data['name'],
			'legal_name'       => $data['legal_name'] ?? null,
			'registration_no'  => $data['registration_no'] ?? null,
			'address'          => $data['address'] ?? null,
			'phone'            => $data['phone'] ?? null,
			'email'            => $data['email'] ?? null,
			'currency'         => $data['currency'] ?? 'USD',
			'business_type_id' => $data['business_type_id'] ?? 1
		]);

		return (int) $this->db->lastInsertId();
	}
	public function update(int $businessId, int $tenantId, array $data): void {
		$allowedFields = [
			'name',
			'legal_name',
			'registration_no',
			'address',
			'phone',
			'email',
			'currency'
		];

		$fields = [];
		$params = [
			'id' => $businessId,
			'tenant_id' => $tenantId
		];

		foreach ($data as $key => $value) {
			if (in_array($key, $allowedFields, true)) {
				$fields[] = "$key = :$key";
				$params[$key] = $value;
			}
		}

		if (empty($fields)) {
			return;
		}

		$sql = "
			UPDATE businesses
			SET " . implode(', ', $fields) . ",
				updated_at = NOW()
			WHERE id = :id
			  AND tenant_id = :tenant_id
		";

		$stmt = $this->db->prepare($sql);
		$stmt->execute($params);

		if ($stmt->rowCount() === 0) {
			throw new \RuntimeException('Business not found or not allowed');
		}
	}

	
	public function countUsersForBusiness(int $businessId): int {
		$stmt = $this->db->prepare(
			"SELECT COUNT(*) FROM user_roles WHERE business_id = ?"
		);
		$stmt->execute([$businessId]);
		return (int) $stmt->fetchColumn();
	}

	public function delete(int $businessId, int $tenantId): void {
		$stmt = $this->db->prepare(
			"DELETE FROM businesses WHERE id = :id AND tenant_id = :tenant_id"
		);
		$stmt->execute(['id' => $businessId, 'tenant_id' => $tenantId]);

		if ($stmt->rowCount() === 0) {
			throw new \RuntimeException('Business not found or not allowed');
		}
	}

	public function getTenantIdForBusiness(int $businessId): ?int {
		$stmt = $this->db->prepare(
			"SELECT tenant_id FROM businesses WHERE id = ?"
		);
		$stmt->execute([$businessId]);
		return $stmt->fetchColumn();
	}

	/**
	 * Activate or deactivate a business.
	 * REQ-010: Add is_active to businesses
	 */
	public function setActive(int $businessId, int $tenantId, bool $active): void {
		$stmt = $this->db->prepare("
			UPDATE businesses
			SET is_active = :active, updated_at = NOW()
			WHERE id = :id AND tenant_id = :tenant_id
		");
		$stmt->execute([
			'active'    => $active ? 1 : 0,
			'id'        => $businessId,
			'tenant_id' => $tenantId,
		]);

		if ($stmt->rowCount() === 0) {
			throw new \RuntimeException('Business not found or not allowed');
		}
	}

}
