<?php
	// src/Repository/ItemRepository.php
namespace App\Repository;

use App\Config\Database;
use PDO;

class ItemRepository {
    private $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    public function getAllForBusiness(int $tenantId, int $businessId ): array {
        $stmt = $this->db->prepare("
            SELECT *
            FROM items
            WHERE tenant_id = :tenant
              AND (business_id = :business OR business_id IS NULL)
              AND is_active = 1
            ORDER BY name
        ");
        $stmt->execute([
            'tenant'   => $tenantId,
            'business' => $businessId
		]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById(int $tenantId, int $itemId ): ?array {
        $stmt = $this->db->prepare("
            SELECT *
            FROM items
            WHERE id = :id
              AND tenant_id = :tenant
            LIMIT 1
        ");
        $stmt->execute([
            'id'     => $itemId,
            'tenant' => $tenantId
		]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function insert(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO items
            (tenant_id, business_id, item_type_id, name, sku, unit,
             cost_price, sale_price, notes, created_at, created_by)
            VALUES
            (:tenant, :business, :type, :name, :sku, :unit,
             :cost, :sale, :notes, NOW(), :created_by)
        ");

        $stmt->execute($data);
        return (int)$this->db->lastInsertId();
    }

    public function update(int $itemId, int $tenantId, array $data): void {
        $stmt = $this->db->prepare("
            UPDATE items
            SET name = :name,
                unit = :unit,
                cost_price = :cost,
                sale_price = :sale,
                notes = :notes,
                updated_at = NOW()
            WHERE id = :id
              AND tenant_id = :tenant
        ");

        $data['id']     = $itemId;
        $data['tenant'] = $tenantId;

        $stmt->execute($data);
    }

    public function deactivate(int $itemId, int $tenantId): void {
        $stmt = $this->db->prepare("
            UPDATE items
            SET is_active = 0, updated_at = NOW()
            WHERE id = :id AND tenant_id = :tenant
        ");

        $stmt->execute([
            'id'     => $itemId,
            'tenant' => $tenantId
        ]);
    }
}
