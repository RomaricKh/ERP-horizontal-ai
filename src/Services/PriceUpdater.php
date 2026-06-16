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

            $newPrice = (float) $line['unit_price_bc'];
            $oldPrice = $item['cost_price'] !== null ? (float) $item['cost_price'] : null;

            // Only update if price differs or current is NULL
            if ($oldPrice !== null && $oldPrice == $newPrice) {
                continue;
            }

            // Update item cost price
            $this->itemRepo->updateCostPrice((int) $itemId, $tenantId, $newPrice);

            // Close previous open price record
            $itemBusinessId = (int) $item['business_id'];
            $this->priceRepo->closePreviousRecord((int) $itemId, $itemBusinessId, $poDate);

            // Determine currency: use item's currency if available, otherwise from business context
            $currency = !empty($item['currency']) ? $item['currency'] : null;

            // Insert new price history record
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

            // Trigger cascading recalculation for any recipes using this item as a component
            $manufacturingService = new ManufacturingService($this->db);
            $manufacturingService->recalculateAffectedRecipes((int) $itemId);
        }
    }
}
