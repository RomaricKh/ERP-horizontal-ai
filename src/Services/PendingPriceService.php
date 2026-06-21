<?php
namespace App\Services;

use PDO;
use App\Support\AuthContext;
use App\Repository\ItemRepository;

class PendingPriceService {
    private PDO $db;
    private ItemRepository $itemRepo;
    private PriceHistoryService $priceHistory;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->itemRepo = new ItemRepository($db);
        $this->priceHistory = new PriceHistoryService($db);
    }

    public function getPendingPrices(int $tenantId, int $businessId): array {
        $stmt = $this->db->prepare("
            SELECT p.*, i.name AS item_name, i.sku, i.sale_price AS current_sale_price,
                   po.po_number
            FROM item_pending_prices p
            JOIN items i ON i.id = p.item_id
            LEFT JOIN purchase_orders po ON po.id = p.source_id AND p.source_type = 'PURCHASE_ORDER'
            WHERE p.tenant_id = :tenant
              AND p.business_id = :business
              AND p.status = 'PENDING'
            ORDER BY p.created_at DESC
        ");
        $stmt->execute([
            'tenant'   => $tenantId,
            'business' => $businessId
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function applyPendingPrices(array $pendingPriceIds, int $tenantId, int $userId): int {
        if (empty($pendingPriceIds)) return 0;
        
        $placeholders = str_repeat('?,', count($pendingPriceIds) - 1) . '?';
        $params = array_merge([$tenantId], $pendingPriceIds);
        
        $stmt = $this->db->prepare("
            SELECT id, item_id, pending_sale_price 
            FROM item_pending_prices 
            WHERE tenant_id = ? AND status = 'PENDING' AND id IN ($placeholders)
        ");
        $stmt->execute($params);
        $pendingPrices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $appliedCount = 0;
        $this->db->beginTransaction();
        try {
            foreach ($pendingPrices as $p) {
                $itemId = (int)$p['item_id'];
                $newPrice = (float)$p['pending_sale_price'];
                
                // Get old price
                $item = $this->itemRepo->getById($itemId, $tenantId);
                if (!$item) continue;
                $oldPrice = (float)($item['sale_price'] ?? 0);
                
                // Update the item
                $this->itemRepo->update($itemId, $tenantId, ['sale_price' => $newPrice]);
                
                // Log price history
                $this->priceHistory->recordManualEdit($itemId, $tenantId, 'SALE', $oldPrice, $newPrice);
                
                // Update pending status
                $updateStmt = $this->db->prepare("
                    UPDATE item_pending_prices 
                    SET status = 'APPLIED', applied_at = NOW(), applied_by = :user 
                    WHERE id = :id
                ");
                $updateStmt->execute(['user' => $userId, 'id' => $p['id']]);
                
                $appliedCount++;
            }
            $this->db->commit();
            return $appliedCount;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function cancelPendingPrices(array $pendingPriceIds, int $tenantId, int $userId): int {
        if (empty($pendingPriceIds)) return 0;
        
        $placeholders = str_repeat('?,', count($pendingPriceIds) - 1) . '?';
        $params = array_merge([$userId, $tenantId], $pendingPriceIds);
        
        $stmt = $this->db->prepare("
            UPDATE item_pending_prices 
            SET status = 'CANCELLED', applied_at = NOW(), applied_by = ? 
            WHERE tenant_id = ? AND status = 'PENDING' AND id IN ($placeholders)
        ");
        $stmt->execute($params);
        
        return $stmt->rowCount();
    }
}
