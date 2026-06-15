<?php
namespace App\Repository;

use PDO;

class SupplierItemPriceRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Insert or update a supplier-item pricing record.
     *
     * If no existing record for (supplier_id, item_id, tenant_id): INSERT with all prices set to the given price.
     * If existing record: UPDATE using running average formula for avg_price, update min/max if needed.
     *
     * Running average formula: new_avg = ((old_avg * old_count) + new_price) / (old_count + 1)
     *
     * @param array $data Keys: supplier_id, item_id, tenant_id, price, last_purchase_date
     */
    public function upsert(array $data): void {
        $stmt = $this->db->prepare("
            INSERT INTO supplier_item_prices
                (supplier_id, item_id, tenant_id, last_price, avg_price, min_price, max_price, last_purchase_date, purchase_count, is_preferred)
            VALUES
                (:supplier_id, :item_id, :tenant_id, :price, :price2, :price3, :price4, :last_purchase_date, 1, 0)
            ON DUPLICATE KEY UPDATE
                last_price = :price_upd,
                avg_price = ((avg_price * purchase_count) + :price_avg) / (purchase_count + 1),
                min_price = LEAST(min_price, :price_min),
                max_price = GREATEST(max_price, :price_max),
                purchase_count = purchase_count + 1,
                last_purchase_date = :last_purchase_date_upd
        ");
        $stmt->execute([
            'supplier_id'           => $data['supplier_id'],
            'item_id'               => $data['item_id'],
            'tenant_id'             => $data['tenant_id'],
            'price'                 => $data['price'],
            'price2'                => $data['price'],
            'price3'                => $data['price'],
            'price4'                => $data['price'],
            'last_purchase_date'    => $data['last_purchase_date'],
            'price_upd'             => $data['price'],
            'price_avg'             => $data['price'],
            'price_min'             => $data['price'],
            'price_max'             => $data['price'],
            'last_purchase_date_upd' => $data['last_purchase_date'],
        ]);
    }

    /**
     * Find all supplier pricing records for an item within a tenant.
     * Returns records ordered by last_purchase_date DESC.
     * JOINs payees → people to get supplier name.
     *
     * @param int $itemId
     * @param int $tenantId
     * @return array
     */
    public function findByItem(int $itemId, int $tenantId): array {
        $stmt = $this->db->prepare("
            SELECT
                sip.id,
                sip.supplier_id,
                sip.item_id,
                sip.tenant_id,
                sip.last_price,
                sip.avg_price,
                sip.min_price,
                sip.max_price,
                sip.last_purchase_date,
                sip.purchase_count,
                sip.is_preferred,
                sip.created_at,
                sip.updated_at,
                p.name AS supplier_name
            FROM supplier_item_prices sip
            LEFT JOIN payees py ON py.id = sip.supplier_id
            LEFT JOIN people p ON p.id = py.people_id
            WHERE sip.item_id = :item_id
              AND sip.tenant_id = :tenant_id
            ORDER BY sip.last_purchase_date DESC
        ");
        $stmt->execute([
            'item_id'   => $itemId,
            'tenant_id' => $tenantId,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Set a supplier as preferred for an item within a tenant.
     * Clears all existing preferred flags for the item first, then sets the specified supplier.
     * Runs within a transaction to ensure atomicity.
     *
     * @param int $supplierId
     * @param int $itemId
     * @param int $tenantId
     */
    public function setPreferred(int $supplierId, int $itemId, int $tenantId): void {
        $this->db->beginTransaction();
        try {
            $this->clearPreferred($itemId, $tenantId);

            $stmt = $this->db->prepare("
                UPDATE supplier_item_prices
                SET is_preferred = 1
                WHERE supplier_id = :supplier_id
                  AND item_id = :item_id
                  AND tenant_id = :tenant_id
            ");
            $stmt->execute([
                'supplier_id' => $supplierId,
                'item_id'     => $itemId,
                'tenant_id'   => $tenantId,
            ]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Clear all preferred flags for an item within a tenant.
     *
     * @param int $itemId
     * @param int $tenantId
     */
    public function clearPreferred(int $itemId, int $tenantId): void {
        $stmt = $this->db->prepare("
            UPDATE supplier_item_prices
            SET is_preferred = 0
            WHERE item_id = :item_id
              AND tenant_id = :tenant_id
        ");
        $stmt->execute([
            'item_id'   => $itemId,
            'tenant_id' => $tenantId,
        ]);
    }

    /**
     * Remove preferred status from a specific supplier for an item within a tenant.
     *
     * @param int $supplierId
     * @param int $itemId
     * @param int $tenantId
     */
    public function removePreferred(int $supplierId, int $itemId, int $tenantId): void {
        $stmt = $this->db->prepare("
            UPDATE supplier_item_prices
            SET is_preferred = 0
            WHERE supplier_id = :supplier_id
              AND item_id = :item_id
              AND tenant_id = :tenant_id
        ");
        $stmt->execute([
            'supplier_id' => $supplierId,
            'item_id'     => $itemId,
            'tenant_id'   => $tenantId,
        ]);
    }
}
