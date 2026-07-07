<?php
namespace App\Services;

use App\Repository\ItemRepository;
use App\Repository\ItemPriceRepository;
use App\Services\ManufacturingService;
use App\Support\AuthContext;
use PDO;

class PriceUpdater {
    private ItemRepository      $itemRepo;
    private ItemPriceRepository $priceRepo;
    private AuditService        $audit;
    private PDO                 $db;

    public function __construct(PDO $db) {
        $this->db        = $db;
        $this->itemRepo  = new ItemRepository($db);
        $this->priceRepo = new ItemPriceRepository($db);
        $this->audit     = new AuditService($db);
    }

    /**
     * Deduplicate lines by item_id, keeping the last occurrence.
     * Returns associative array: item_id => line data
     *
     * @param array $lines PO lines ordered by id ASC
     * @return array<int, array> Map of item_id => last line for that item
     */
    public function deduplicateLines(array $lines): array
    {
        $map = [];
        foreach ($lines as $line) {
            $map[$line['item_id']] = $line;
        }
        return $map;
    }

    /**
     * Process price updates for all lines of a received PO.
     * MUST be called within an existing transaction.
     *
     * @param array    $lines      PO lines from PurchaseOrderRepository::getLines()
     * @param string   $poDate     The PO date (used as valid_from for price records)
     * @param int      $poId       Purchase order ID (for audit reference)
     * @param string   $poNumber   Purchase order number (for audit reference)
     * @param int|null $supplierId Optional supplier payee ID (for future supplier-specific pricing)
     */
    public function updatePricesFromPO(array $lines, string $poDate, int $poId, string $poNumber, ?int $supplierId = null): void
    {
        $uniqueLines = $this->deduplicateLines($lines);

        $auth       = AuthContext::get();
        $tenantId   = $auth->tenant_id;
        $businessId = $auth->business_id;
        $userId     = $auth->user_id;

        // Fetch PO header discount
        $stmtPo = $this->db->prepare("SELECT discount_percent FROM purchase_orders WHERE id = :id");
        $stmtPo->execute(['id' => $poId]);
        $poDiscount = (float) $stmtPo->fetchColumn();
        $poMultiplier = 1 - ($poDiscount / 100);

        foreach ($uniqueLines as $itemId => $line) {
            // Skip lines where unit_price_bc is NULL
            if (!isset($line['unit_price_bc']) || $line['unit_price_bc'] === null) {
                continue;
            }

            // Fetch current item
            $item = $this->itemRepo->getById((int) $itemId, $tenantId);
            if ($item === null) {
                continue;
            }

            $qty = (float) ($line['quantity'] ?? 0);
            if ($qty > 0 && isset($line['total_price_bc'])) {
                // total_price_bc already has the line discount applied
                $effectiveLinePrice = (float) $line['total_price_bc'] / $qty;
            } else {
                $effectiveLinePrice = (float) $line['unit_price_bc'];
            }

            // Apply PO header discount
            $newPrice = round($effectiveLinePrice * $poMultiplier, 2);
            $oldPrice = $item['cost_price'] !== null ? (float) $item['cost_price'] : null;

            // Only update if price differs or current is NULL
            if ($oldPrice !== null && abs($oldPrice - $newPrice) < 0.001) {
                continue;
            }

            // Update item cost price
            $this->itemRepo->updateCostPrice((int) $itemId, $tenantId, $newPrice);

            // Close previous open price record
            $itemBusinessId = (int) $item['business_id'];
            $this->priceRepo->closePreviousRecord((int) $itemId, $itemBusinessId, $poDate);

            // Determine currency: use item's currency if available, otherwise from business context
            $currency = !empty($item['currency']) ? $item['currency'] : null;
            if ($currency === null) {
                $stmtCurr = $this->db->prepare("SELECT currency FROM businesses WHERE id = :id");
                $stmtCurr->execute(['id' => $itemBusinessId]);
                $currency = $stmtCurr->fetchColumn() ?: 'USD';
            }

            // Insert new price history record for COST change
            $this->priceRepo->insert([
                'item_id'     => (int) $itemId,
                'business_id' => $itemBusinessId,
                'currency'    => $currency,
                'cost_price'  => $newPrice,
                'sale_price'  => $item['sale_price'] ?? null,
                'valid_from'  => $poDate,
            ]);

            // Log audit entry
            $this->audit->log(
                'ITEMS',
                'items',
                $itemId,
                'PRICE_UPDATE',
                ['cost_price' => $oldPrice],
                ['cost_price' => $newPrice, 'po_id' => $poId, 'po_number' => $poNumber],
                $userId,
                $tenantId,
                $itemBusinessId
            );

            // Queue a Pending Price Update if automatic markup is enabled
            if (isset($item['markup_enabled']) && (int)$item['markup_enabled'] === 1 && isset($item['markup_percent'])) {
                $markupPct = (float) $item['markup_percent'];
                if ($markupPct > 0) {
                    $newSalePrice = round($newPrice * (1 + $markupPct / 100), 2);
                    $currentSalePrice = isset($item['sale_price']) ? (float)$item['sale_price'] : 0.0;
                    
                    if ($newSalePrice !== $currentSalePrice) {
                        // Insert into item_pending_prices
                        $stmt = $this->db->prepare("
                            INSERT INTO item_pending_prices
                            (tenant_id, business_id, item_id, pending_sale_price, source_type, source_id, status, created_at, created_by)
                            VALUES
                            (:tenant, :business, :item, :pending_sale, 'PURCHASE_ORDER', :po_id, 'PENDING', NOW(), :user)
                        ");
                        $stmt->execute([
                            'tenant'       => $tenantId,
                            'business'     => $itemBusinessId,
                            'item'         => $itemId,
                            'pending_sale' => $newSalePrice,
                            'po_id'        => $poId,
                            'user'         => $userId
                        ]);
                    }
                }
            }

            // Trigger cascading recalculation for any recipes using this item as a component
            $manufacturingService = new ManufacturingService($this->db);
            $manufacturingService->recalculateAffectedRecipes((int) $itemId);
        }
    }
}
