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
	
	public function getAllForCurrentUser($user_id) {
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
	
	public function getTenantIdForBusiness(int $businessId): ?int {
		$stmt = $this->db->prepare(
			"SELECT tenant_id FROM businesses WHERE id = ?"
		);
		$stmt->execute([$businessId]);
		return $stmt->fetchColumn();
	}

}
