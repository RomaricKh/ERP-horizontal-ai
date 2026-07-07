<?php
namespace App\Services;

use App\Repository\BatchRepository;
use App\Support\AuthContext;
use PDO;

class BatchService {
    private BatchRepository $repo;
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db   = $db;
        $this->repo = new BatchRepository($db);
    }

    /**
     * Create a new batch for an expiry-tracked item (e.g., on PO receipt).
     */
    public function createBatch(
        int $itemId,
        float $quantity,
        string $expiryDate,
        ?string $manufacturingDate = null,
        ?string $batchNumber = null,
        ?int $referenceId = null
    ): int {
        $ctx = AuthContext::get();

        // Generate batch number if not provided
        if (empty($batchNumber)) {
            $seq = $this->repo->getNextSequence($itemId, $ctx->business_id, date('Ymd'));
            $batchNumber = 'B-' . date('Ymd') . '-' . str_pad($seq, 3, '0', STR_PAD_LEFT);
        }

        $batchId = $this->repo->insert([
            'item_id'            => $itemId,
            'business_id'        => $ctx->business_id,
            'batch_number'       => $batchNumber,
            'quantity'           => $quantity,
            'expiry_date'        => $expiryDate,
            'manufacturing_date' => $manufacturingDate,
            'status'             => 'ACTIVE',
            'created_by'         => $ctx->user_id,
        ]);

        // Record the initial stock-in transaction for the batch
        $this->repo->insertTransaction([
            'batch_id'         => $batchId,
            'transaction_type' => 'IN',
            'quantity'         => $quantity,
            'reference'        => $referenceId ? "PURCHASE_ORDER:{$referenceId}" : null,
            'notes'            => 'Initial batch stock from receipt',
            'created_by'       => $ctx->user_id,
        ]);

        return $batchId;
    }

    /**
     * Check whether an item has any active batches with remaining quantity.
     */
    public function hasActiveBatches(int $itemId, int $businessId): bool {
        return $this->repo->hasActiveBatchesWithQuantity($itemId, $businessId);
    }

    /**
     * Consume batch quantities using FEFO logic.
     */
    public function consumeFEFO(int $itemId, int $businessId, float $quantity, ?string $reference = null, ?string $notes = null): void {
        $ctx = AuthContext::get();
        
        $remaining = $quantity;
        $batchesStmt = $this->db->prepare("
            SELECT id, quantity FROM item_batches
            WHERE item_id = :item_id AND business_id = :business_id AND status = 'active' AND quantity > 0
            ORDER BY expiry_date ASC
        ");
        $batchesStmt->execute(['item_id' => $itemId, 'business_id' => $businessId]);
        $batches = $batchesStmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($batches as $batch) {
            if ($remaining <= 0) break;
            $batchQty = (float) $batch['quantity'];
            $deduct = min($remaining, $batchQty);

            // Update batch quantity
            $newBatchQty = $batchQty - $deduct;
            $updateStmt = $this->db->prepare("UPDATE item_batches SET quantity = :qty, updated_at = NOW() WHERE id = :id");
            $updateStmt->execute(['qty' => $newBatchQty, 'id' => $batch['id']]);

            // Record batch transaction
            $btStmt = $this->db->prepare("
                INSERT INTO batch_transactions (batch_id, transaction_type, quantity, reference, notes, created_at, created_by)
                VALUES (:batch_id, 'OUT', :quantity, :reference, :notes, NOW(), :user_id)
            ");
            $btStmt->execute([
                'batch_id'  => $batch['id'],
                'quantity'  => $deduct,
                'reference' => $reference,
                'notes'     => $notes,
                'user_id'   => $ctx->user_id,
            ]);

            $remaining -= $deduct;
        }

        if ($remaining > 0) {
            throw new \RuntimeException("Insufficient batch quantity available. Missing {$remaining} units in active batches.");
        }
    }
}
