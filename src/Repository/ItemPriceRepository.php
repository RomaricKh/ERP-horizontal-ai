<?php
namespace App\Repository;

use PDO;

class ItemPriceRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Insert a new price history record.
     *
     * @param array $data Associative array with keys: item_id, business_id, currency, cost_price, sale_price, valid_from
     * @return int The new record ID
     */
    public function insert(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO item_prices (item_id, business_id, currency, cost_price, sale_price, valid_from, created_at)
            VALUES (:item_id, :business_id, :currency, :cost_price, :sale_price, :valid_from, NOW())
        ");
        $stmt->execute([
            'item_id'     => $data['item_id'],
            'business_id' => $data['business_id'],
            'currency'    => $data['currency'],
            'cost_price'  => $data['cost_price'],
            'sale_price'  => $data['sale_price'],
            'valid_from'  => $data['valid_from'],
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Close the current open record for an item/business by setting valid_to.
     * Only updates records where valid_to IS NULL.
     *
     * Uses DATE_SUB logic for normal case (previous valid_from < new valid_from)
     * and same-day closure for edge case (previous valid_from >= new valid_from).
     *
     * @param int    $itemId       The item ID
     * @param int    $businessId   The business ID
     * @param string $newValidFrom The new record's valid_from date (Y-m-d)
     */
    public function closePreviousRecord(int $itemId, int $businessId, string $newValidFrom): void {
        $stmt = $this->db->prepare("
            UPDATE item_prices
            SET valid_to = CASE
                WHEN valid_from >= :new_valid_from1 THEN :new_valid_from2
                ELSE DATE_SUB(:new_valid_from3, INTERVAL 1 DAY)
            END
            WHERE item_id = :item_id
              AND business_id = :business_id
              AND valid_to IS NULL
        ");
        $stmt->execute([
            'new_valid_from1' => $newValidFrom,
            'new_valid_from2' => $newValidFrom,
            'new_valid_from3' => $newValidFrom,
            'item_id'         => $itemId,
            'business_id'     => $businessId,
        ]);
    }
}
