<?php
	// src/Models/BusinessRepository.php
namespace App\Repository;

use App\Config\Database;
use PDO;

class BusinessRepository {
    private $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }
	
	public function getAllForCurrentUser(int $user_id): array {
		$stmt = $this->db->prepare("
						SELECT 	b.id, b.name, b.legal_name, b.registration_no, 
								b.address, b.phone, b.email, b.currency
						FROM 	businesses b
						INNER JOIN user_roles ur ON ur.business_id = b.id AND ur.user_id = :user_id
		");
        $stmt->execute([
            'user_id' => $user_id
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
				address, phone, email, currency, created_at
			) VALUES (
				:tenant_id, :name, :legal_name, :registration_no,
				:address, :phone, :email, :currency, NOW()
			)
		");

		$stmt->execute([
			'tenant_id'       => $data['tenant_id'],
			'name'            => $data['name'],
			'legal_name'      => $data['legal_name'] ?? null,
			'registration_no' => $data['registration_no'] ?? null,
			'address'         => $data['address'] ?? null,
			'phone'           => $data['phone'] ?? null,
			'email'           => $data['email'] ?? null,
			'currency'        => $data['currency'] ?? 'USD'
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

	
	public function getTenantIdForBusiness(int $businessId): ?int {
		$stmt = $this->db->prepare(
			"SELECT tenant_id FROM businesses WHERE id = ?"
		);
		$stmt->execute([$businessId]);
		return $stmt->fetchColumn();
	}

}
