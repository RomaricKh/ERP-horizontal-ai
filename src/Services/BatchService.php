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
            $seq = $this->repo->getNextSequence($itemId, $ctx->business_id, date('Y-m-d'));
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
}
