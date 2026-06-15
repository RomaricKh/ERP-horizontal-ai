<?php
namespace App\Services;

use App\Repository\ItemRepository;
use App\Repository\ItemTypeRepository;
use App\Services\NotificationService;
use App\Services\PriceHistoryService;
use App\Support\AuthContext;
use PDO;

class ItemService {
    private ItemRepository        $repo;
    private ItemTypeRepository    $itemTypeRepo;
    private AuditService          $audit;
    private NotificationService   $notificationService;
    private PriceHistoryService   $priceHistoryService;
    private ItemVisibilityService $visibilityService;
    private PDO                   $db;

    public function __construct(PDO $db) {
        $this->db                  = $db;
        $this->repo                = new ItemRepository($db);
        $this->itemTypeRepo        = new ItemTypeRepository($db);
        $this->audit               = new AuditService($db);
        $this->notificationService = new NotificationService($db);
        $this->priceHistoryService = new PriceHistoryService($db);
        $this->visibilityService   = new ItemVisibilityService($db);
    }

    // ── ITEM TYPES ────────────────────────────────────────────────────────────

    public function listTypes(): array {
        $ctx = AuthContext::get();
        // REQ-031: Use module-gated query — system types with module_code
        // are only returned if that module is active for the business
        return $this->itemTypeRepo->findForBusiness($ctx->tenant_id, $ctx->business_id);
    }

    public function createType(string $name): int {
        $ctx = AuthContext::get();

        $this->db->beginTransaction();
        try {
            $id  = $this->repo->insertType(
                $ctx->tenant_id, $ctx->business_id, trim($name), $ctx->user_id
            );
            $this->audit->log('CREATE', 'item_types', $id, 'CREATE', null,
                ['name' => $name], $ctx->user_id, $ctx->tenant_id, $ctx->business_id);

            $this->db->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function updateType(int $id, string $name): void {
        $ctx = AuthContext::get();

        $this->db->beginTransaction();
        try {
            $before = $this->itemTypeRepo->findById($id, $ctx->tenant_id);
            if (!$before) throw new \RuntimeException('Item type not found');

            // REQ-031: ItemTypeRepository rejects edits on system types
            $this->itemTypeRepo->update($id, $ctx->tenant_id, trim($name));
            $this->audit->log('UPDATE', 'item_types', $id, 'UPDATE',
                ['name' => $before['name']], ['name' => $name],
                $ctx->user_id, $ctx->tenant_id, $ctx->business_id);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function deleteType(int $id): void {
        $ctx = AuthContext::get();

        $this->db->beginTransaction();
        try {
            $usage = $this->itemTypeRepo->countItemsForType($id);
            if ($usage > 0) {
                throw new \RuntimeException('Cannot delete: item type is in use by active items');
            }

            $before = $this->itemTypeRepo->findById($id, $ctx->tenant_id);
            if (!$before) {
                throw new \RuntimeException('Item type not found');
            }

            // REQ-031: ItemTypeRepository rejects deletes on system types
            $this->itemTypeRepo->delete($id, $ctx->tenant_id);

            $this->audit->log('DELETE', 'item_types', $id, 'DELETE', $before, null,
                $ctx->user_id, $ctx->tenant_id, $ctx->business_id);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // ── ITEMS ─────────────────────────────────────────────────────────────────

    public function listForCurrentBusiness(?string $excludeTypesParam = null): array {
        $ctx = AuthContext::get();
        $excludeTypes = $this->parseExcludeTypes($excludeTypesParam);
        return $this->repo->getAllForBusiness($ctx->tenant_id, $ctx->business_id, $excludeTypes);
    }

    /**
     * Parse a comma-separated exclude_types parameter into a cleaned array.
     * Returns empty array if param is null or whitespace-only.
     * Splits on comma, trims each segment, removes empty segments.
     */
    private function parseExcludeTypes(?string $param): array {
        if ($param === null || trim($param) === '') {
            return [];
        }
        $types = explode(',', $param);
        $cleaned = [];
        foreach ($types as $type) {
            $trimmed = trim($type);
            if ($trimmed !== '') {
                $cleaned[] = $trimmed;
            }
        }
        return $cleaned;
    }

    public function getById(int $id): array {
        $ctx  = AuthContext::get();
        $item = $this->repo->getById($id, $ctx->tenant_id);
        if (!$item) throw new \RuntimeException('Item not found');
        return $item;
    }

    public function create(array $input): int {
        $ctx = AuthContext::get();

        if (empty(trim($input['name'] ?? ''))) {
            throw new \InvalidArgumentException('Item name is required');
        }
        if (empty($input['item_type_id'])) {
            throw new \InvalidArgumentException('Item type is required');
        }

        // Reject Manufactured type when manufacturing module is inactive
        $this->guardManufacturedType((int) $input['item_type_id'], $ctx);

        // Validate customer_id when provided
        $customerId = null;
        if (!empty($input['customer_id'])) {
            $validation = $this->visibilityService->validateCustomerForItem(
                (int) $input['customer_id'],
                $ctx->tenant_id,
                $ctx->business_id
            );
            if (!$validation['valid']) {
                throw new \InvalidArgumentException($validation['error']);
            }
            $customerId = (int) $input['customer_id'];
        }

        $this->db->beginTransaction();
        try {
            $id = $this->repo->insert([
                'tenant'      => $ctx->tenant_id,
                'business'    => $ctx->business_id,
                'customer_id' => $customerId,
                'type'        => (int) $input['item_type_id'],
                'name'        => trim($input['name']),
                'sku'         => $input['sku']        ?? null,
                'unit'        => $input['unit']       ?? null,
                'cost'        => $input['cost_price'] ?? null,
                'sale'        => $input['sale_price'] ?? null,
                'notes'       => $input['notes']      ?? null,
                'has_expiry'  => isset($input['has_expiry']) ? (int) (bool) $input['has_expiry'] : 0,
                'created_by'  => $ctx->user_id,
            ]);

            $this->audit->log('CREATE', 'items', $id, 'CREATE', null,
                ['name' => $input['name'], 'sku' => $input['sku'] ?? null],
                $ctx->user_id, $ctx->tenant_id, $ctx->business_id);

            // Record initial price history for non-null prices
            $costPrice = isset($input['cost_price']) ? (float) $input['cost_price'] : null;
            $salePrice = isset($input['sale_price']) ? (float) $input['sale_price'] : null;
            $this->priceHistoryService->recordInitial($id, $ctx->tenant_id, $costPrice, $salePrice);

            $this->db->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function update(int $id, array $input): void {
        $ctx    = AuthContext::get();
        $before = $this->repo->getById($id, $ctx->tenant_id);
        if (!$before) throw new \RuntimeException('Item not found');

        // Reject changing to Manufactured type when manufacturing module is inactive
        if (!empty($input['item_type_id'])) {
            $this->guardManufacturedType((int) $input['item_type_id'], $ctx);
        }

        // Handle customer_id: only process when key is present in payload
        $customerIdInPayload = array_key_exists('customer_id', $input);
        if ($customerIdInPayload && $input['customer_id'] !== null) {
            $validation = $this->visibilityService->validateCustomerForItem(
                (int) $input['customer_id'],
                $ctx->tenant_id,
                $ctx->business_id
            );
            if (!$validation['valid']) {
                throw new \InvalidArgumentException($validation['error']);
            }
        }

        $fields = ['name','sku','unit','cost_price','sale_price','min_stock','markup_percent','markup_enabled','notes','item_type_id','has_expiry'];
        $changes = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $input) && $input[$f] !== $before[$f]) {
                $changes[$f] = $input[$f];
            }
        }

        // Include customer_id in changes when present in payload (even if null to clear assignment)
        if ($customerIdInPayload) {
            $newCustomerId = $input['customer_id'] !== null ? (int) $input['customer_id'] : null;
            $existingCustomerId = $before['customer_id'] !== null ? (int) $before['customer_id'] : null;
            if ($newCustomerId !== $existingCustomerId) {
                $changes['customer_id'] = $newCustomerId;
            }
        }

        if (empty($changes)) return;

        // Validate: reject disabling expiry tracking if active batches exist
        if (array_key_exists('has_expiry', $changes) && (int) $changes['has_expiry'] === 0 && (int) $before['has_expiry'] === 1) {
            $batchService = new BatchService($this->db);
            if ($batchService->hasActiveBatches($id, $ctx->business_id)) {
                throw new \RuntimeException(
                    'Cannot disable expiry tracking: item has active batches with stock. Write off or consume remaining batch quantities first.'
                );
            }
        }

        $costPriceChanged = array_key_exists('cost_price', $changes);

        $this->db->beginTransaction();
        try {
            $this->repo->update($id, $ctx->tenant_id, $changes);
            $this->audit->log('UPDATE', 'items', $id, 'UPDATE',
                array_intersect_key($before, $changes), $changes,
                $ctx->user_id, $ctx->tenant_id, $ctx->business_id);

            // Record price history for manual edits
            if ($costPriceChanged) {
                $oldCostPrice = (float) ($before['cost_price'] ?? 0);
                $newCostPrice = (float) $changes['cost_price'];
                $this->priceHistoryService->recordManualEdit($id, $ctx->tenant_id, 'COST', $oldCostPrice, $newCostPrice);
            }
            if (array_key_exists('sale_price', $changes)) {
                $oldSalePrice = (float) ($before['sale_price'] ?? 0);
                $newSalePrice = (float) $changes['sale_price'];
                $this->priceHistoryService->recordManualEdit($id, $ctx->tenant_id, 'SALE', $oldSalePrice, $newSalePrice);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        // Trigger cascading recalculation if cost_price changed
        if ($costPriceChanged) {
            $manufacturingService = new ManufacturingService($this->db);
            $manufacturingService->recalculateAffectedRecipes($id);

            // Auto-update sale price if markup is enabled on this item
            $updatedItem = $this->repo->getById($id, $ctx->tenant_id);
            if ($updatedItem && (int)($updatedItem['markup_enabled'] ?? 0) === 1) {
                $markupPct = (float)($updatedItem['markup_percent'] ?? 0);
                $newCost = (float)($changes['cost_price'] ?? $updatedItem['cost_price']);
                if ($markupPct > 0 && $newCost > 0) {
                    $newSale = $newCost * (1 + $markupPct / 100);
                    $this->repo->update($id, $ctx->tenant_id, ['sale_price' => round($newSale, 2)]);
                }
            }
        }
    }

    public function deactivate(int $id): void {
        $ctx = AuthContext::get();
        $before = $this->repo->getById($id, $ctx->tenant_id);
        if (!$before) throw new \RuntimeException('Item not found');

        $this->db->beginTransaction();
        try {
            $this->repo->deactivate($id, $ctx->tenant_id);
            $this->audit->log('DELETE', 'items', $id, 'DELETE', $before, null,
                $ctx->user_id, $ctx->tenant_id, $ctx->business_id);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // ── STOCK ─────────────────────────────────────────────────────────────────

    public function getStock(): array {
        $ctx = AuthContext::get();
        return $this->repo->getStockForBusiness($ctx->business_id);
    }

    /**
     * Get the available stock quantity for a specific item in the current business.
     */
    public function getAvailableStock(int $itemId): float {
        $ctx = AuthContext::get();
        $entry = $this->repo->getStockEntry($itemId, $ctx->business_id);
        return $entry ? (float) $entry['quantity'] : 0.0;
    }

    public function adjustStock(int $itemId, string $type, float $quantity, ?string $reference, ?string $notes): void {
        $ctx = AuthContext::get();

        if (!in_array($type, ['IN', 'OUT', 'ADJ'], true)) {
            throw new \InvalidArgumentException('Invalid transaction type');
        }
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Quantity must be greater than zero');
        }

        $item = $this->repo->getById($itemId, $ctx->tenant_id);
        if (!$item) throw new \RuntimeException('Item not found');

        // Validate sufficient stock before processing OUT adjustments
        if ($type === 'OUT') {
            $stockValidator = new StockValidator($this->db);
            $stockValidator->assertSufficientStock($itemId, $ctx->business_id, $quantity);
        }

        // If called from within an existing transaction (e.g., PO receive, invoice pay),
        // skip transaction management — the caller is responsible.
        $ownTransaction = !$this->db->inTransaction();

        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $current = $this->repo->getStockEntry($itemId, $ctx->business_id);
            $currentQty = $current ? (float) $current['quantity'] : 0.0;

            $newQty = 0.0;
            if ($type === 'IN') {
                $newQty = $currentQty + $quantity;
            } elseif ($type === 'OUT') {
                $newQty = $currentQty - $quantity;
            } else { // ADJ — set absolute value
                $newQty = $quantity;
            }

            if ($newQty < 0) {
                throw new \RuntimeException('Insufficient stock: cannot go below zero');
            }

            $this->repo->upsertStock($itemId, $ctx->business_id, $newQty);

            // Parse reference string into reference_type enum + reference_id
            $refType = null;
            $refId   = null;
            if ($reference !== null) {
                $refType = $this->parseReferenceType($reference);
            }

            $this->repo->insertStockTransaction([
                'business'       => $ctx->business_id,
                'item'           => $itemId,
                'type'           => $type,
                'reference_type' => $refType,
                'reference_id'   => $refId,
                'quantity'       => $quantity,
                'notes'          => $notes,
            ]);

            $this->audit->log('CREATE', 'stock_transactions', $itemId, 'CREATE', null,
                ['type' => $type, 'quantity' => $quantity, 'new_qty' => $newQty],
                $ctx->user_id, $ctx->tenant_id, $ctx->business_id);

            if ($ownTransaction) {
                $this->db->commit();
            }

            // Check if stock fell below minimum threshold and send low stock notification
            $minStock = isset($item['min_stock']) ? (float) $item['min_stock'] : null;
            if ($minStock !== null && $newQty < $minStock) {
                try {
                    $recipientIds = $this->resolveRecipientsByPermission('items.edit', $ctx->business_id);
                    if (!empty($recipientIds)) {
                        $this->notificationService->notify(
                            'STOCK_LOW',
                            'Low Stock Alert',
                            'Item "' . $item['name'] . '" stock is low (' . $newQty . ' remaining, minimum: ' . $minStock . ')',
                            $recipientIds,
                            'items',
                            $itemId,
                            null
                        );
                    }
                } catch (\Throwable $e) {
                    error_log("[ItemService::adjustStock] Notification error: " . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            if ($ownTransaction) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function getStockTransactions(int $itemId): array {
        $ctx = AuthContext::get();
        return $this->repo->getStockTransactions($itemId, $ctx->business_id);
    }

    /**
     * Parse a reference string (e.g. 'PO-123', 'INV-456') into a reference_type enum value.
     * Returns null for unrecognized formats.
     */
    private function parseReferenceType(?string $reference): ?string {
        if ($reference === null) {
            return null;
        }

        $map = [
            'PO-'                        => 'PURCHASE_ORDER',
            'INV-'                       => 'SALES_INVOICE',
            'PRODUCTION_ORDER'           => 'PRODUCTION',
            'PRODUCTION_ORDER_REVERSAL'  => 'PRODUCTION',
            'CN-'                        => 'CREDIT_NOTE',
            'DN-'                        => 'DEBIT_NOTE',
            'WRITE-OFF'                  => 'MANUAL',
        ];

        foreach ($map as $prefix => $type) {
            if (strpos($reference, $prefix) === 0) {
                return $type;
            }
        }

        return 'MANUAL';
    }

    /**
     * Resolve all user IDs in a business that hold a given permission.
     * Business owners (role_id = 1) implicitly hold all permissions.
     *
     * @return int[] Array of user IDs holding the permission
     */
    private function resolveRecipientsByPermission(string $permissionCode, int $businessId): array {
        $stmt = $this->db->prepare("
            SELECT DISTINCT ur.user_id
            FROM user_roles ur
            WHERE ur.business_id = :business_id
              AND ur.is_active = 1
              AND (
                  ur.role_id = 1
                  OR ur.role_id IN (
                      SELECT rp.role_id
                      FROM role_permissions rp
                      JOIN permissions p ON p.id = rp.permission_id
                      WHERE p.code = :permission_code
                  )
              )
        ");
        $stmt->execute([
            'business_id'     => $businessId,
            'permission_code' => $permissionCode,
        ]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Guard against using the "Manufactured" item type when manufacturing module is inactive.
     *
     * @throws \InvalidArgumentException if the type is Manufactured and module is off
     */
    private function guardManufacturedType(int $itemTypeId, object $ctx): void {
        $moduleService = new ModuleActivationService($this->db);
        if ($moduleService->isModuleActive('manufacturing', $ctx->business_id)) {
            return; // Module is active, no restriction
        }

        // Check if the given type ID is the "Manufactured" type
        $stmt = $this->db->prepare("
            SELECT name FROM item_types
            WHERE id = :id
              AND tenant_id = :tenant_id
              AND (business_id = :business_id OR business_id IS NULL)
            LIMIT 1
        ");
        $stmt->execute([
            'id'          => $itemTypeId,
            'tenant_id'   => $ctx->tenant_id,
            'business_id' => $ctx->business_id,
        ]);
        $typeName = $stmt->fetchColumn();

        if ($typeName === 'Manufactured') {
            throw new \InvalidArgumentException(
                'Cannot use "Manufactured" item type: manufacturing module is not active for this business.'
            );
        }
    }
}
