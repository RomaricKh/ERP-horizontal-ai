<?php
namespace App\Services;

use App\Repository\ItemPriceHistoryRepository;
use App\Support\AuthContext;
use PDO;

class PriceHistoryService {
    private PDO $db;
    private ItemPriceHistoryRepository $repository;

    private const VALID_PRICE_TYPES  = ['COST', 'SALE'];
    private const VALID_SOURCE_TYPES = ['INITIAL', 'PURCHASE_ORDER', 'MANUAL_EDIT', 'MANUFACTURING'];

    public function __construct(PDO $db) {
        $this->db         = $db;
        $this->repository = new ItemPriceHistoryRepository($db);
    }

    /**
     * Get the current user ID from AuthContext, or null if not available.
     */
    private function currentUserId(): ?int {
        try {
            return AuthContext::userId();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Record initial price(s) when an item is created.
     * Creates one INITIAL record per non-null price. Skips if both are null.
     */
    public function recordInitial(int $itemId, int $tenantId, ?float $costPrice, ?float $salePrice): void
    {
        $today = date('Y-m-d');
        $userId = $this->currentUserId();

        if ($costPrice !== null) {
            $this->repository->insert([
                'item_id'        => $itemId,
                'tenant_id'      => $tenantId,
                'price_type'     => 'COST',
                'old_price'      => null,
                'new_price'      => $costPrice,
                'effective_date' => $today,
                'source_type'    => 'INITIAL',
                'source_id'      => null,
                'changed_by'     => $userId,
            ]);
        }

        if ($salePrice !== null) {
            $this->repository->insert([
                'item_id'        => $itemId,
                'tenant_id'      => $tenantId,
                'price_type'     => 'SALE',
                'old_price'      => null,
                'new_price'      => $salePrice,
                'effective_date' => $today,
                'source_type'    => 'INITIAL',
                'source_id'      => null,
                'changed_by'     => $userId,
            ]);
        }
    }

    /**
     * Record a price change from a PO receive.
     * Only inserts if oldPrice != newPrice.
     */
    public function recordFromPO(int $itemId, int $tenantId, float $oldPrice, float $newPrice, int $poId, string $poDate): void
    {
        if ($oldPrice == $newPrice) {
            return;
        }

        $this->repository->insert([
            'item_id'        => $itemId,
            'tenant_id'      => $tenantId,
            'price_type'     => 'COST',
            'old_price'      => $oldPrice,
            'new_price'      => $newPrice,
            'effective_date' => $poDate,
            'source_type'    => 'PURCHASE_ORDER',
            'source_id'      => $poId,
            'changed_by'     => $this->currentUserId(),
        ]);
    }

    /**
     * Record a manual price edit (cost or sale).
     * Validates priceType is COST or SALE.
     */
    public function recordManualEdit(int $itemId, int $tenantId, string $priceType, float $oldPrice, float $newPrice): void
    {
        if (!in_array($priceType, self::VALID_PRICE_TYPES, true)) {
            throw new \InvalidArgumentException(
                "Invalid price_type '{$priceType}'. Must be one of: " . implode(', ', self::VALID_PRICE_TYPES)
            );
        }

        $this->repository->insert([
            'item_id'        => $itemId,
            'tenant_id'      => $tenantId,
            'price_type'     => $priceType,
            'old_price'      => $oldPrice,
            'new_price'      => $newPrice,
            'effective_date' => date('Y-m-d'),
            'source_type'    => 'MANUAL_EDIT',
            'source_id'      => null,
            'changed_by'     => $this->currentUserId(),
        ]);
    }

    /**
     * Record a price change from manufacturing recalculation.
     * Always uses price_type COST and source_type MANUFACTURING.
     */
    public function recordFromManufacturing(int $itemId, int $tenantId, float $oldPrice, float $newPrice): void
    {
        $this->repository->insert([
            'item_id'        => $itemId,
            'tenant_id'      => $tenantId,
            'price_type'     => 'COST',
            'old_price'      => $oldPrice,
            'new_price'      => $newPrice,
            'effective_date' => date('Y-m-d'),
            'source_type'    => 'MANUFACTURING',
            'source_id'      => null,
            'changed_by'     => $this->currentUserId(),
        ]);
    }

    /**
     * Get price history for an item, ordered by effective_date DESC, created_at DESC.
     *
     * @return array Price history records
     */
    public function getHistory(int $itemId, int $tenantId): array
    {
        return $this->repository->findByItem($itemId, $tenantId);
    }
}
