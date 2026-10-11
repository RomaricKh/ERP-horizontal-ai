<?php
namespace App\Services;

use App\Repository\ItemRepository;
use App\Support\AuthContext;
use PDO;

class StockValidator {
    private PDO $db;
    private ItemRepository $itemRepo;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->itemRepo = new ItemRepository($db);
    }

    /**
     * Assert that sufficient stock is available for an item in a business.
     * Uses SELECT ... FOR UPDATE to lock the row within the caller's transaction.
     * Throws RuntimeException if stock is insufficient.
     *
     * @param int   $itemId     The item to check
     * @param int   $businessId The business context
     * @param float $quantity   The required quantity
     *
     * @throws \InvalidArgumentException if quantity is invalid
     * @throws \RuntimeException if available stock < requested quantity
     */
    public function assertSufficientStock(int $itemId, int $businessId, float $quantity): void {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Quantity must be greater than zero');
        }
        if ($quantity > 999999999) {
            throw new \InvalidArgumentException('Quantity exceeds maximum allowed value');
        }

        // Use FOR UPDATE to lock the row within the caller's transaction
        $stmt = $this->db->prepare(
            "SELECT quantity FROM stock WHERE item_id = :item AND business_id = :business LIMIT 1 FOR UPDATE"
        );
        $stmt->execute(['item' => $itemId, 'business' => $businessId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $current = $row ? (float) $row['quantity'] : 0.0;

        if ($current < $quantity) {
            $item = $this->itemRepo->getById($itemId, AuthContext::tenantId());
            $name = $item['name'] ?? "Item #{$itemId}";
            throw new \RuntimeException(
                "Insufficient stock for '{$name}': available {$current}, requested {$quantity}"
            );
        }
    }

    /**
     * Check if requested quantity is available for an item.
     *
     * @throws \InvalidArgumentException if quantity is invalid
     * @throws \RuntimeException with formatted error if insufficient
     */
    public function assertAvailable(int $itemId, float $requestedQty, int $businessId): void {
        if ($requestedQty <= 0) {
            throw new \InvalidArgumentException('Quantity must be greater than zero');
        }
        if ($requestedQty > 999999999) {
            throw new \InvalidArgumentException('Quantity exceeds maximum allowed value');
        }
        $available = $this->getAvailableStock($itemId, $businessId);
        if ($available < $requestedQty) {
            $itemName = $this->getItemName($itemId);
            throw new \RuntimeException(
                "Insufficient stock for {$itemName}: available {$available}, requested {$requestedQty}"
            );
        }
    }

    /**
     * Batch check multiple items. Returns array of shortfall messages.
     * Empty array means all items have sufficient stock.
     *
     * @param array $requirements Array of ['item_id' => int, 'required' => float]
     * @param int   $businessId
     * @return array Array of shortfall message strings
     */
    public function checkBatch(array $requirements, int $businessId): array {
        $shortfalls = [];

        foreach ($requirements as $req) {
            $itemId  = (int) $req['item_id'];
            $needed  = (float) $req['required'];

            $available = $this->getAvailableStock($itemId, $businessId);
            if ($available < $needed) {
                $itemName = $this->getItemName($itemId);
                $shortfalls[] = "Insufficient stock for: {$itemName} (need {$needed}, have {$available})";
            }
        }

        return $shortfalls;
    }

    /**
     * Get the current available stock quantity for an item in a business.
     */
    private function getAvailableStock(int $itemId, int $businessId): float {
        $stmt = $this->db->prepare(
            "SELECT quantity FROM stock WHERE item_id = :item_id AND business_id = :business_id LIMIT 1"
        );
        $stmt->execute(['item_id' => $itemId, 'business_id' => $businessId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? (float) $row['quantity'] : 0.0;
    }

    /**
     * Get the name of an item by its ID.
     */
    private function getItemName(int $itemId): string {
        $stmt = $this->db->prepare("SELECT name FROM items WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $row['name'] : "Item #{$itemId}";
    }
}
