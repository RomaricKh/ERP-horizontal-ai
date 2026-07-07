<?php
namespace App\Repository;

use PDO;

class ItemPriceHistoryRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Insert a new price history record.
     *
     * @param array $data Associative array with keys: item_id, tenant_id, price_type, old_price, new_price, effective_date, source_type, source_id, changed_by
     * @return int The new record ID
     */
    public function insert(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO item_price_history (item_id, tenant_id, price_type, old_price, new_price, effective_date, source_type, source_id, changed_by, created_at)
            VALUES (:item_id, :tenant_id, :price_type, :old_price, :new_price, :effective_date, :source_type, :source_id, :changed_by, NOW())
        ");
        $stmt->execute([
            'item_id'        => $data['item_id'],
            'tenant_id'      => $data['tenant_id'],
            'price_type'     => $data['price_type'],
            'old_price'      => $data['old_price'] ?? null,
            'new_price'      => $data['new_price'],
            'effective_date' => $data['effective_date'],
            'source_type'    => $data['source_type'],
            'source_id'      => $data['source_id'] ?? null,
            'changed_by'     => $data['changed_by'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Find all price history records for an item within a tenant.
     * Returns records ordered by effective_date DESC, created_at DESC.
     * Joins users table to resolve changed_by into a user name.
     *
     * @param int $itemId   The item ID
     * @param int $tenantId The tenant ID for data isolation
     * @return array Raw data rows
     */
    public function findByItem(int $itemId, int $tenantId): array {
        $stmt = $this->db->prepare("
            SELECT iph.id, iph.item_id, iph.tenant_id, iph.price_type, iph.old_price, iph.new_price,
                   iph.effective_date, iph.source_type, iph.source_id, iph.changed_by, iph.created_at,
                   CONCAT(u.first_name, ' ', u.last_name) AS changed_by_name,
                   po.po_number
            FROM item_price_history iph
            LEFT JOIN users u ON u.id = iph.changed_by
            LEFT JOIN purchase_orders po ON po.id = iph.source_id AND iph.source_type = 'PURCHASE_ORDER'
            WHERE iph.item_id = :item_id
              AND iph.tenant_id = :tenant_id
            ORDER BY iph.effective_date DESC, iph.created_at DESC
        ");
        $stmt->execute([
            'item_id'   => $itemId,
            'tenant_id' => $tenantId,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
