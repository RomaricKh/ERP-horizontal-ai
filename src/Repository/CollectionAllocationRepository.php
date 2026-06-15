<?php
namespace App\Repository;

use PDO;

class CollectionAllocationRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Insert a batch of collection allocations for a given slip.
     *
     * @param int   $slipId      The collection_slip_id
     * @param array $allocations Array of [{reference_type, reference_id, amount_fc, amount_bc}]
     */
    public function createBatch(int $slipId, array $allocations): void {
        $stmt = $this->db->prepare("
            INSERT INTO collection_allocations
              (collection_slip_id, reference_type, reference_id, amount_fc, amount_bc, created_at)
            VALUES
              (:slip_id, :reference_type, :reference_id, :amount_fc, :amount_bc, NOW())
        ");

        foreach ($allocations as $alloc) {
            $stmt->execute([
                'slip_id'        => $slipId,
                'reference_type' => $alloc['reference_type'],
                'reference_id'   => (int) $alloc['reference_id'],
                'amount_fc'      => (float) $alloc['amount_fc'],
                'amount_bc'      => (float) $alloc['amount_bc'],
            ]);
        }
    }

    /**
     * Find all allocations for a given collection slip.
     *
     * @param int $slipId The collection_slip_id
     * @return array Array of allocation rows
     */
    public function findBySlip(int $slipId): array {
        $stmt = $this->db->prepare("
            SELECT id, collection_slip_id, reference_type, reference_id, amount_fc, amount_bc, created_at
            FROM collection_allocations
            WHERE collection_slip_id = :slip_id
            ORDER BY id ASC
        ");
        $stmt->execute(['slip_id' => $slipId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Sum the total allocated amount (in base currency) for a given document.
     *
     * @param string $type The reference_type (SALES_INVOICE)
     * @param int    $id   The reference_id (invoice ID)
     * @return float Total allocated amount in base currency
     */
    public function sumForDocument(string $type, int $id): float {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount_bc), 0)
            FROM collection_allocations
            WHERE reference_type = :type
              AND reference_id = :id
        ");
        $stmt->execute([
            'type' => $type,
            'id'   => $id,
        ]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Delete all allocations for a given collection slip.
     * Used when deleting a collection slip.
     *
     * @param int $slipId The collection_slip_id
     */
    public function deleteBySlip(int $slipId): void {
        $stmt = $this->db->prepare("
            DELETE FROM collection_allocations WHERE collection_slip_id = :slip_id
        ");
        $stmt->execute(['slip_id' => $slipId]);
    }
}
