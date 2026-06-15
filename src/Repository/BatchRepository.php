<?php
namespace App\Repository;

use PDO;
use App\Support\AuthContext;

class BatchRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Insert a new batch record into item_batches.
     *
     * @param array $data Associative array with keys: item_id, business_id, batch_number,
     *                    quantity, expiry_date, manufacturing_date (optional),
     *                    purchase_order_id (optional), status (optional)
     * @return int The inserted batch ID
     */
    public function insert(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO item_batches
              (item_id, business_id, batch_number, quantity, manufacturing_date,
               expiry_date, purchase_order_id, status, created_at)
            VALUES
              (:item_id, :business_id, :batch_number, :quantity, :manufacturing_date,
               :expiry_date, :purchase_order_id, :status, NOW())
        ");
        $stmt->execute([
            'item_id'             => $data['item_id'],
            'business_id'         => $data['business_id'],
            'batch_number'        => $data['batch_number'],
            'quantity'            => $data['quantity'],
            'manufacturing_date'  => $data['manufacturing_date'] ?? null,
            'expiry_date'         => $data['expiry_date'],
            'purchase_order_id'   => $data['purchase_order_id'] ?? null,
            'status'              => $data['status'] ?? 'active',
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Find a batch by its ID, scoped by business_id.
     */
    public function findById(int $id, int $businessId): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM item_batches
            WHERE id = :id AND business_id = :business_id
            LIMIT 1
        ");
        $stmt->execute(['id' => $id, 'business_id' => $businessId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Find all batches for an item within a business, optionally filtered by status.
     * Ordered by expiry_date ASC (FEFO).
     */
    public function findByItemId(int $itemId, int $businessId, ?string $status = null): array {
        $sql = "
            SELECT * FROM item_batches
            WHERE item_id = :item_id AND business_id = :business_id
        ";
        $params = ['item_id' => $itemId, 'business_id' => $businessId];

        if ($status !== null) {
            $sql .= " AND status = :status";
            $params['status'] = $status;
        }

        $sql .= " ORDER BY expiry_date ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get active batches with quantity > 0 for FEFO consumption.
     * Ordered by expiry_date ASC (earliest expiry first).
     */
    public function getActiveBatchesFEFO(int $itemId, int $businessId): array {
        $stmt = $this->db->prepare("
            SELECT * FROM item_batches
            WHERE item_id = :item_id
              AND business_id = :business_id
              AND status = 'active'
              AND quantity > 0
            ORDER BY expiry_date ASC
        ");
        $stmt->execute(['item_id' => $itemId, 'business_id' => $businessId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get expired batches with quantity > 0 for FEFO fallback consumption.
     * Ordered by expiry_date ASC (earliest expiry first).
     */
    public function getExpiredBatchesFEFO(int $itemId, int $businessId): array {
        $stmt = $this->db->prepare("
            SELECT * FROM item_batches
            WHERE item_id = :item_id
              AND business_id = :business_id
              AND status = 'expired'
              AND quantity > 0
            ORDER BY expiry_date ASC
        ");
        $stmt->execute(['item_id' => $itemId, 'business_id' => $businessId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update the quantity of a batch.
     */
    public function updateQuantity(int $batchId, float $newQuantity): void {
        $stmt = $this->db->prepare("
            UPDATE item_batches
            SET quantity = :quantity, updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute(['quantity' => $newQuantity, 'id' => $batchId]);
    }

    /**
     * Update the status of a batch.
     */
    public function updateStatus(int $batchId, string $status): void {
        $stmt = $this->db->prepare("
            UPDATE item_batches
            SET status = :status, updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute(['status' => $status, 'id' => $batchId]);
    }

    /**
     * Insert a batch transaction record.
     *
     * @param array $data Associative array with keys: batch_id, transaction_type,
     *                    quantity, reference (optional), notes (optional)
     * @return int The inserted transaction ID
     */
    public function insertTransaction(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO batch_transactions
              (batch_id, transaction_type, quantity, reference, notes, created_at, created_by)
            VALUES
              (:batch_id, :transaction_type, :quantity, :reference, :notes, NOW(), :created_by)
        ");
        $stmt->execute([
            'batch_id'         => $data['batch_id'],
            'transaction_type' => $data['transaction_type'],
            'quantity'         => $data['quantity'],
            'reference'        => $data['reference'] ?? null,
            'notes'            => $data['notes'] ?? null,
            'created_by'       => $data['created_by'] ?? self::safeUserId(),
        ]);
        return (int) $this->db->lastInsertId();
    }

    private static function safeUserId(): ?int {
        try {
            return \App\Support\AuthContext::userId();
        } catch (\RuntimeException $e) {
            return null;
        }
    }

    /**
     * Get the next sequential number for batch number generation.
     * Counts existing batches for the item+business where batch_number starts with BTH-{date}-.
     *
     * @return int The next sequence number (count + 1)
     */
    public function getNextSequence(int $itemId, int $businessId, string $date): int {
        $prefix = "BTH-{$date}-";
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM item_batches
            WHERE item_id = :item_id
              AND business_id = :business_id
              AND batch_number LIKE :prefix
        ");
        $stmt->execute([
            'item_id'     => $itemId,
            'business_id' => $businessId,
            'prefix'      => $prefix . '%',
        ]);
        return (int) $stmt->fetchColumn() + 1;
    }

    /**
     * Get the sum of quantities for all active batches of an item.
     */
    public function sumActiveBatchQuantity(int $itemId, int $businessId): float {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(quantity), 0) FROM item_batches
            WHERE item_id = :item_id
              AND business_id = :business_id
              AND status IN ('active', 'expired')
        ");
        $stmt->execute(['item_id' => $itemId, 'business_id' => $businessId]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Check if an item has any active batches with quantity > 0.
     */
    public function hasActiveBatchesWithQuantity(int $itemId, int $businessId): bool {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM item_batches
            WHERE item_id = :item_id
              AND business_id = :business_id
              AND status = 'active'
              AND quantity > 0
        ");
        $stmt->execute(['item_id' => $itemId, 'business_id' => $businessId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Get batches that are expiring within the threshold number of days.
     * Returns active batches with quantity > 0 whose expiry_date is between
     * today and today + thresholdDays.
     */
    public function getExpiringBatches(int $businessId, int $thresholdDays): array {
        $stmt = $this->db->prepare("
            SELECT ib.*, i.name AS item_name
            FROM item_batches ib
            JOIN items i ON i.id = ib.item_id
            WHERE ib.business_id = :business_id
              AND ib.status = 'active'
              AND ib.quantity > 0
              AND ib.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :threshold DAY)
            ORDER BY ib.expiry_date ASC
        ");
        $stmt->bindValue(':business_id', $businessId, PDO::PARAM_INT);
        $stmt->bindValue(':threshold', $thresholdDays, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get batches that have expired (expiry_date < today) but still have
     * status 'expired' and quantity > 0.
     */
    public function getExpiredActiveBatches(int $businessId): array {
        $stmt = $this->db->prepare("
            SELECT ib.*, i.name AS item_name
            FROM item_batches ib
            JOIN items i ON i.id = ib.item_id
            WHERE ib.business_id = :business_id
              AND ib.status = 'expired'
              AND ib.quantity > 0
            ORDER BY ib.expiry_date ASC
        ");
        $stmt->execute(['business_id' => $businessId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Transition all active batches whose expiry_date is strictly before today
     * to 'expired' status. Does NOT modify quantities.
     *
     * @return int Number of batches transitioned
     */
    public function transitionExpiredBatches(int $businessId): int {
        $stmt = $this->db->prepare("
            UPDATE item_batches
            SET status = 'expired', updated_at = NOW()
            WHERE status = 'active'
              AND expiry_date < CURDATE()
              AND business_id = :business_id
        ");
        $stmt->execute(['business_id' => $businessId]);
        return $stmt->rowCount();
    }
}
