<?php
namespace App\Services;

use App\Repository\ManufacturingRepository;
use App\Repository\ItemRepository;
use App\Repository\ItemTypeRepository;
use App\Support\AuthContext;
use PDO;

class ManufacturingService {
    private ManufacturingRepository $repo;
    private ItemService $itemService;
    private AuditService $audit;
    private ItemRepository $itemRepo;
    private ItemTypeRepository $itemTypeRepo;
    private StockValidator $stockValidator;
    private NotificationService $notificationService;
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db                  = $db;
        $this->repo                = new ManufacturingRepository($db);
        $this->itemService         = new ItemService($db);
        $this->audit               = new AuditService($db);
        $this->itemRepo            = new ItemRepository($db);
        $this->itemTypeRepo        = new ItemTypeRepository($db);
        $this->stockValidator      = new StockValidator($db);
        $this->notificationService = new NotificationService($db);
    }

    // ── RECIPE CRUD ───────────────────────────────────────────────────────────

    /**
     * Create a new recipe and its associated manufactured item.
     *
     * @param array $data Expected keys: name, components (array of [item_id, quantity]), yield_quantity, notes?, sale_price?
     * @return array ['recipe_id' => int, 'item_id' => int]
     */
    public function createRecipe(array $data): array {
        $ctx = AuthContext::get();

        // ── Validation ──
        $this->validateRecipeInput($data, $ctx->tenant_id, $ctx->business_id);

        // Calculate unit cost from components
        $componentDetails = $this->resolveComponentDetails($data['components'], $ctx->tenant_id);
        $unitCost = $this->calculateUnitCost($componentDetails, (float) $data['yield_quantity']);

        $this->db->beginTransaction();
        try {
            // Find the "Manufactured" item type for this tenant/business
            $typeId = $this->getManufacturedTypeId($ctx->tenant_id, $ctx->business_id);

            // Create the manufactured item via ItemService
            $itemId = $this->itemRepo->insert([
                'tenant'     => $ctx->tenant_id,
                'business'   => $ctx->business_id,
                'type'       => $typeId,
                'name'       => trim($data['name']),
                'sku'        => null,
                'unit'       => $data['unit'] ?? null,
                'cost'       => $unitCost,
                'sale'       => $data['sale_price'] ?? null,
                'notes'      => null,
                'created_by' => $ctx->user_id,
            ]);

            // Insert recipe
            $recipeId = $this->repo->insertRecipe([
                'tenant_id'            => $ctx->tenant_id,
                'business_id'          => $ctx->business_id,
                'manufactured_item_id' => $itemId,
                'yield_quantity'       => (float) $data['yield_quantity'],
                'notes'                => $data['notes'] ?? null,
                'created_by'           => $ctx->user_id,
            ]);

            // Insert recipe items
            $recipeItems = [];
            foreach ($data['components'] as $component) {
                $recipeItems[] = [
                    'component_item_id' => (int) $component['item_id'],
                    'quantity'          => (float) $component['quantity'],
                ];
            }
            $this->repo->insertRecipeItems($recipeId, $recipeItems);

            // Audit log
            $this->audit->log(
                'CREATE', 'recipes', $recipeId, 'CREATE',
                null,
                ['name' => trim($data['name']), 'yield_quantity' => (float) $data['yield_quantity'], 'item_id' => $itemId],
                $ctx->user_id, $ctx->tenant_id, $ctx->business_id
            );

            // Save auto-markup config if provided
            if (!empty($data['auto_markup_percent'])) {
                $this->repo->setAutoMarkupConfig($itemId, (float) $data['auto_markup_percent']);
            }

            $this->db->commit();
            return ['recipe_id' => $recipeId, 'item_id' => $itemId];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Update an existing recipe.
     *
     * @param int   $id   Recipe ID
     * @param array $data Expected keys: name?, components (array of [item_id, quantity]), yield_quantity, notes?
     */
    public function updateRecipe(int $id, array $data): void {
        $ctx = AuthContext::get();

        // Fetch existing recipe
        $recipe = $this->repo->getRecipeById($id, $ctx->tenant_id, $ctx->business_id);
        if (!$recipe) {
            throw new \RuntimeException('Recipe not found');
        }

        // Validate inputs (pass manufactured_item_id for self-reference check)
        $this->validateRecipeInput($data, $ctx->tenant_id, $ctx->business_id, $recipe['manufactured_item_id']);

        // Get old recipe items for audit
        $oldRecipeItems = $this->repo->getRecipeItems($id);

        // Calculate new unit cost
        $componentDetails = $this->resolveComponentDetails($data['components'], $ctx->tenant_id);
        $newUnitCost = $this->calculateUnitCost($componentDetails, (float) $data['yield_quantity']);

        $this->db->beginTransaction();
        try {
            // Update recipe
            $this->repo->updateRecipe($id, $ctx->tenant_id, [
                'yield_quantity' => (float) $data['yield_quantity'],
                'notes'          => $data['notes'] ?? null,
            ]);

            // Replace recipe items: delete old, insert new
            $this->repo->deleteRecipeItems($id);
            $recipeItems = [];
            foreach ($data['components'] as $component) {
                $recipeItems[] = [
                    'component_item_id' => (int) $component['item_id'],
                    'quantity'          => (float) $component['quantity'],
                ];
            }
            $this->repo->insertRecipeItems($id, $recipeItems);

            // Update manufactured item cost_price
            $this->itemRepo->updateCostPrice(
                $recipe['manufactured_item_id'],
                $ctx->tenant_id,
                $newUnitCost
            );

            // Update manufactured item name if changed
            if (isset($data['name']) && trim($data['name']) !== $recipe['manufactured_item_name']) {
                $this->itemRepo->update(
                    $recipe['manufactured_item_id'],
                    $ctx->tenant_id,
                    ['name' => trim($data['name'])]
                );
            }

            // Update sale_price and unit if provided
            $itemUpdates = [];
            if (array_key_exists('sale_price', $data)) {
                $itemUpdates['sale_price'] = $data['sale_price'];
            }
            if (array_key_exists('unit', $data)) {
                $itemUpdates['unit'] = $data['unit'];
            }
            if (!empty($itemUpdates)) {
                $this->itemRepo->update(
                    $recipe['manufactured_item_id'],
                    $ctx->tenant_id,
                    $itemUpdates
                );
            }

            // Update auto-markup config if provided
            if (array_key_exists('auto_markup_percent', $data)) {
                if (!empty($data['auto_markup_percent'])) {
                    $this->repo->setAutoMarkupConfig($recipe['manufactured_item_id'], (float) $data['auto_markup_percent']);
                }
            }

            // Audit log with before/after
            $this->audit->log(
                'UPDATE', 'recipes', $id, 'UPDATE',
                [
                    'name'           => $recipe['manufactured_item_name'],
                    'yield_quantity' => $recipe['yield_quantity'],
                    'components'     => $oldRecipeItems,
                ],
                [
                    'name'           => trim($data['name'] ?? $recipe['manufactured_item_name']),
                    'yield_quantity' => (float) $data['yield_quantity'],
                    'components'     => $data['components'],
                ],
                $ctx->user_id, $ctx->tenant_id, $ctx->business_id
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Delete a recipe and deactivate its manufactured item.
     *
     * @param int $id Recipe ID
     */
    public function deleteRecipe(int $id): void {
        $ctx = AuthContext::get();

        $recipe = $this->repo->getRecipeById($id, $ctx->tenant_id, $ctx->business_id);
        if (!$recipe) {
            throw new \RuntimeException('Recipe not found');
        }

        // Check if recipe has production orders
        $orderCount = $this->repo->countProductionOrdersForRecipe($id);
        if ($orderCount > 0) {
            throw new \InvalidArgumentException(
                "Cannot delete recipe: it has been used in {$orderCount} production order(s)"
            );
        }

        $this->db->beginTransaction();
        try {
            // Delete recipe items
            $this->repo->deleteRecipeItems($id);

            // Delete recipe
            $this->repo->deleteRecipe($id, $ctx->tenant_id);

            // Deactivate manufactured item
            $this->itemRepo->deactivate($recipe['manufactured_item_id'], $ctx->tenant_id);

            // Audit log
            $this->audit->log(
                'DELETE', 'recipes', $id, 'DELETE',
                [
                    'name'                => $recipe['manufactured_item_name'],
                    'manufactured_item_id' => $recipe['manufactured_item_id'],
                    'yield_quantity'       => $recipe['yield_quantity'],
                ],
                null,
                $ctx->user_id, $ctx->tenant_id, $ctx->business_id
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // ── RECIPE READ ───────────────────────────────────────────────────────────

    /**
     * Get a single recipe with its items.
     */
    public function getRecipe(int $id): array {
        $ctx = AuthContext::get();

        $recipe = $this->repo->getRecipeById($id, $ctx->tenant_id, $ctx->business_id);
        if (!$recipe) {
            throw new \RuntimeException('Recipe not found');
        }

        $recipe['items'] = $this->repo->getRecipeItems($id);
        return $recipe;
    }

    /**
     * List all active recipes for the current business.
     */
    public function listRecipes(): array {
        $ctx = AuthContext::get();
        return $this->repo->getAllRecipes($ctx->tenant_id, $ctx->business_id);
    }

    /**
     * Get a recipe by its manufactured item ID.
     */
    public function getRecipeByItemId(int $itemId): ?array {
        $ctx = AuthContext::get();

        $recipe = $this->repo->getRecipeByItemId($itemId, $ctx->tenant_id, $ctx->business_id);
        if ($recipe) {
            $recipe['items'] = $this->repo->getRecipeItems($recipe['id']);
        }
        return $recipe;
    }

    // ── PRODUCTION ORDERS ─────────────────────────────────────────────────────

    /**
     * Create and execute a production order.
     *
     * @param array $data Expected keys: recipe_id, production_quantity, production_date?
     * @return int The new production order ID
     */
    public function createProductionOrder(array $data): int {
        $ctx = AuthContext::get();

        // Validate recipe exists
        $recipe = $this->repo->getRecipeById((int) ($data['recipe_id'] ?? 0), $ctx->tenant_id, $ctx->business_id);
        if (!$recipe) {
            throw new \InvalidArgumentException('Recipe not found');
        }

        // Validate production quantity
        $productionQuantity = (float) ($data['production_quantity'] ?? 0);
        if ($productionQuantity <= 0) {
            throw new \InvalidArgumentException('Production quantity must be greater than zero');
        }

        // Get recipe items (components)
        $recipeItems = $this->repo->getRecipeItems($recipe['id']);
        $yieldQuantity = (int) $recipe['yield_quantity'];

        // Calculate required quantities for each component
        $componentRequirements = [];
        $stockCheckRequirements = [];

        foreach ($recipeItems as $recipeItem) {
            $requiredQty = ((float) $recipeItem['quantity'] / $yieldQuantity) * $productionQuantity;
            $componentItemId = (int) $recipeItem['component_item_id'];

            $componentRequirements[] = [
                'component_item_id' => $componentItemId,
                'component_name'    => $recipeItem['component_item_name'],
                'required_qty'      => $requiredQty,
                'unit_cost'         => (float) $recipeItem['cost_price'],
            ];

            $stockCheckRequirements[] = [
                'item_id'  => $componentItemId,
                'required' => $requiredQty,
            ];
        }

        // Calculate manufacturing cost
        $manufacturingCost = 0.0;
        foreach ($componentRequirements as $req) {
            $manufacturingCost += $req['unit_cost'] * $req['required_qty'];
        }
        $unitManufacturingCost = $manufacturingCost / $productionQuantity;

        // Execute within a transaction — stock check is inside for atomicity (Requirement 4.3)
        $this->db->beginTransaction();
        try {
            // Stock sufficiency check within transaction to prevent race conditions
            foreach ($componentRequirements as $req) {
                $this->stockValidator->assertSufficientStock(
                    $req['component_item_id'],
                    $ctx->business_id,
                    $req['required_qty']
                );
            }
            // Deduct each component from stock
            foreach ($componentRequirements as $req) {
                $this->itemService->adjustStock(
                    $req['component_item_id'],
                    'OUT',
                    $req['required_qty'],
                    'PRODUCTION_ORDER',
                    null
                );
            }

            // Add manufactured item to stock
            $this->itemService->adjustStock(
                (int) $recipe['manufactured_item_id'],
                'IN',
                $productionQuantity,
                'PRODUCTION_ORDER',
                null
            );

            // If manufactured item tracks expiry, create a batch record
            $manufacturedItem = $this->db->prepare("SELECT has_expiry FROM items WHERE id = ?");
            $manufacturedItem->execute([(int) $recipe['manufactured_item_id']]);
            $hasExpiry = (int) $manufacturedItem->fetchColumn();

            if ($hasExpiry === 1 && !empty($data['expiry_date'])) {
                $batchNumber = $data['batch_number'] ?? ('PROD-' . date('Ymd-His'));
                $batchStmt = $this->db->prepare("
                    INSERT INTO item_batches (item_id, business_id, batch_number, quantity, manufacturing_date, expiry_date, status, created_at)
                    VALUES (:item_id, :business_id, :batch_number, :quantity, :mfg_date, :expiry_date, 'active', NOW())
                ");
                $batchStmt->execute([
                    'item_id'      => (int) $recipe['manufactured_item_id'],
                    'business_id'  => $ctx->business_id,
                    'batch_number' => $batchNumber,
                    'quantity'     => $productionQuantity,
                    'mfg_date'    => $data['manufacturing_date'] ?? date('Y-m-d'),
                    'expiry_date'  => $data['expiry_date'],
                ]);
            }

            // Insert production order
            $productionDate = $data['production_date'] ?? date('Y-m-d H:i:s');
            $orderId = $this->repo->insertProductionOrder([
                'tenant_id'               => $ctx->tenant_id,
                'business_id'             => $ctx->business_id,
                'recipe_id'               => (int) $recipe['id'],
                'manufactured_item_id'    => (int) $recipe['manufactured_item_id'],
                'production_quantity'     => $productionQuantity,
                'manufacturing_cost'      => $manufacturingCost,
                'unit_manufacturing_cost' => $unitManufacturingCost,
                'status'                  => 'COMPLETED',
                'production_date'         => $productionDate,
                'created_by'              => $ctx->user_id,
            ]);

            // Insert production order items
            $orderItems = [];
            foreach ($componentRequirements as $req) {
                $orderItems[] = [
                    'component_item_id' => $req['component_item_id'],
                    'quantity_consumed' => $req['required_qty'],
                    'unit_cost'         => $req['unit_cost'],
                ];
            }
            $this->repo->insertProductionOrderItems($orderId, $orderItems);

            // Audit log
            $this->audit->log(
                'CREATE', 'production_orders', $orderId, 'CREATE',
                null,
                [
                    'recipe_id'           => (int) $recipe['id'],
                    'manufactured_item'   => $recipe['manufactured_item_name'],
                    'production_quantity' => $productionQuantity,
                    'manufacturing_cost'  => $manufacturingCost,
                ],
                $ctx->user_id, $ctx->tenant_id, $ctx->business_id
            );

            $this->db->commit();

            // Notify users with "manufacturing.view" permission about completed production order
            try {
                $recipientIds = $this->resolveRecipientsByPermission('manufacturing.view', $ctx->business_id);
                if (!empty($recipientIds)) {
                    $this->notificationService->notify(
                        'SYSTEM_ALERT',
                        'Production Order Completed',
                        'Production Order #' . $orderId . ' for "' . $recipe['manufactured_item_name'] . '" has been completed (' . $productionQuantity . ' units produced)',
                        $recipientIds,
                        'production_orders',
                        $orderId,
                        $ctx->user_id
                    );
                }
            } catch (\Throwable $e) {
                error_log("[ManufacturingService::createProductionOrder] Notification error: " . $e->getMessage());
            }

            return $orderId;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Reverse a production order, restoring stock to pre-execution state.
     *
     * @param int $id Production order ID
     */
    public function reverseProductionOrder(int $id): void {
        $ctx = AuthContext::get();

        // Validate order exists
        $order = $this->repo->getProductionOrderById($id, $ctx->tenant_id, $ctx->business_id);
        if (!$order) {
            throw new \RuntimeException('Production order not found');
        }

        // Check if already reversed
        if ($order['status'] === 'REVERSED') {
            throw new \InvalidArgumentException('Production order has already been reversed');
        }

        // Get production order items
        $orderItems = $this->repo->getProductionOrderItems($id);

        $this->db->beginTransaction();
        try {
            // Add back each component to stock
            foreach ($orderItems as $item) {
                $this->itemService->adjustStock(
                    (int) $item['component_item_id'],
                    'IN',
                    (float) $item['quantity_consumed'],
                    'PRODUCTION_ORDER_REVERSAL',
                    null
                );
            }

            // Deduct manufactured item from stock
            $this->itemService->adjustStock(
                (int) $order['manufactured_item_id'],
                'OUT',
                (float) $order['production_quantity'],
                'PRODUCTION_ORDER_REVERSAL',
                null
            );

            // Update status to REVERSED
            $this->repo->updateProductionOrderStatus($id, 'REVERSED');

            // Audit log
            $this->audit->log(
                'UPDATE', 'production_orders', $id, 'REVERSE',
                ['status' => 'COMPLETED'],
                ['status' => 'REVERSED'],
                $ctx->user_id, $ctx->tenant_id, $ctx->business_id
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Get a single production order with its items.
     *
     * @param int $id Production order ID
     * @return array
     */
    public function getProductionOrder(int $id): array {
        $ctx = AuthContext::get();

        $order = $this->repo->getProductionOrderById($id, $ctx->tenant_id, $ctx->business_id);
        if (!$order) {
            throw new \RuntimeException('Production order not found');
        }

        $order['items'] = $this->repo->getProductionOrderItems($id);
        return $order;
    }

    /**
     * List all production orders for the current business with optional filters.
     *
     * @param array|null $filters Optional: start_date, end_date, manufactured_item_id
     * @return array
     */
    public function listProductionOrders(?array $filters = null): array {
        $ctx = AuthContext::get();
        return $this->repo->getAllProductionOrders($ctx->tenant_id, $ctx->business_id, $filters);
    }

    // ── COST CALCULATION ──────────────────────────────────────────────────────

    /**
     * Calculate the unit manufacturing cost.
     *
     * @param array $recipeItems Array of items with 'cost_price' and 'quantity' keys
     * @param int   $yieldQuantity Number of units produced per batch
     * @return float Cost per unit
     */
    public function calculateUnitCost(array $recipeItems, float $yieldQuantity): float {
        if ($yieldQuantity <= 0) {
            throw new \InvalidArgumentException('Yield quantity must be greater than zero');
        }

        $totalCost = 0.0;
        foreach ($recipeItems as $item) {
            $costPrice = (float) ($item['cost_price'] ?? 0);
            $quantity  = (float) ($item['quantity'] ?? 0);
            $totalCost += $costPrice * $quantity;
        }

        return $totalCost / $yieldQuantity;
    }

    // ── CASCADING RECALCULATION & COST FLAGS ──────────────────────────────────

    /**
     * Recalculate all recipes affected by a component item's cost_price change.
     *
     * For each recipe using this component:
     * 1. Recalculate unit cost from current component prices
     * 2. Update the manufactured item's cost_price
     * 3. Record price history if cost changed
     * 4. Set a cost change flag with old/new values
     * 5. Apply auto-markup if configured
     *
     * @param int $componentItemId The component item whose cost changed
     */
    public function recalculateAffectedRecipes(int $componentItemId): void {
        $ctx = AuthContext::get();

        $priceHistoryService = new PriceHistoryService($this->db);

        // Get all active recipes that use this component
        $affectedRecipes = $this->repo->getRecipesByComponentItem($componentItemId, $ctx->tenant_id);

        foreach ($affectedRecipes as $recipe) {
            $recipeId = (int) $recipe['id'];
            $manufacturedItemId = (int) $recipe['manufactured_item_id'];
            $yieldQuantity = (float) $recipe['yield_quantity'];

            // Get current recipe items with their latest cost_prices
            $recipeItems = $this->repo->getRecipeItems($recipeId);

            // Calculate the new unit cost
            $newCost = $this->calculateUnitCost($recipeItems, $yieldQuantity);

            // Get the current (old) cost_price of the manufactured item
            $manufacturedItem = $this->itemRepo->getById($manufacturedItemId, $ctx->tenant_id);
            $oldCost = (float) ($manufacturedItem['cost_price'] ?? 0);

            // Update the manufactured item's cost_price
            $this->itemRepo->updateCostPrice($manufacturedItemId, $ctx->tenant_id, $newCost);

            // Record price history if cost actually changed
            if ($oldCost != $newCost) {
                $priceHistoryService->recordFromManufacturing(
                    $manufacturedItemId,
                    $ctx->tenant_id,
                    $oldCost,
                    $newCost
                );
            }

            // Set the cost change flag with old and new values
            $this->repo->setCostChangeFlag($manufacturedItemId, $oldCost, $newCost);

            // Check if auto-markup is configured for this item
            $markupConfig = $this->repo->getAutoMarkupConfig($manufacturedItemId);
            if ($markupConfig) {
                $markupPercent = (float) $markupConfig['markup_percent'];
                $newSalePrice = $newCost * (1 + $markupPercent / 100);
                $this->itemRepo->update($manufacturedItemId, $ctx->tenant_id, [
                    'sale_price' => $newSalePrice,
                ]);
            }
        }
    }

    /**
     * Dismiss the cost change flag for a manufactured item.
     *
     * @param int $itemId The manufactured item ID
     */
    public function dismissCostChangeFlag(int $itemId): void {
        $this->repo->dismissCostChangeFlag($itemId);
    }

    /**
     * Set or update the auto-markup configuration for a manufactured item.
     *
     * @param int   $itemId        The manufactured item ID
     * @param float $markupPercent The markup percentage to apply
     */
    public function setAutoMarkupConfig(int $itemId, float $markupPercent): void {
        $this->repo->setAutoMarkupConfig($itemId, $markupPercent);
    }

    // ── PRIVATE HELPERS ───────────────────────────────────────────────────────

    /**
     * Validate recipe input data.
     *
     * @param array    $data
     * @param int      $tenantId
     * @param int      $businessId
     * @param int|null $manufacturedItemId For self-reference check on update
     */
    private function validateRecipeInput(array $data, int $tenantId, int $businessId, ?int $manufacturedItemId = null): void {
        // Name validation
        if (empty(trim($data['name'] ?? ''))) {
            throw new \InvalidArgumentException('Manufactured item name is required');
        }

        // Components validation
        if (empty($data['components']) || !is_array($data['components'])) {
            throw new \InvalidArgumentException('At least one component item is required');
        }

        // Yield validation
        if (!isset($data['yield_quantity']) || (int) $data['yield_quantity'] <= 0) {
            throw new \InvalidArgumentException('Yield quantity must be greater than zero');
        }

        // Validate each component
        foreach ($data['components'] as $component) {
            if (!isset($component['quantity']) || (float) $component['quantity'] <= 0) {
                throw new \InvalidArgumentException(
                    'Quantity for component must be greater than zero'
                );
            }

            if (empty($component['item_id'])) {
                throw new \InvalidArgumentException('Component item ID is required');
            }

            // Check component exists and belongs to tenant/business
            $item = $this->itemRepo->getById((int) $component['item_id'], $tenantId);
            if (!$item) {
                throw new \InvalidArgumentException(
                    "Component item not found or not accessible"
                );
            }

            // Verify business scope
            if ($item['business_id'] !== null && (int) $item['business_id'] !== $businessId) {
                throw new \InvalidArgumentException(
                    "Component item '{$item['name']}' not found or not accessible"
                );
            }

            // Self-reference check
            if ($manufacturedItemId !== null && (int) $component['item_id'] === $manufacturedItemId) {
                throw new \InvalidArgumentException(
                    'A recipe cannot use its own manufactured item as a component'
                );
            }

            // REQ-031: Validate BOM components are RAW_MATERIAL or CONSUMABLE
            $this->validateComponentType($item, $tenantId);
        }
    }

    /**
     * Validate that a BOM component item has an allowed type (RAW_MATERIAL or CONSUMABLE).
     * Items with other types (e.g., FINISHED_GOOD, SERVICE_ITEM) cannot be used as recipe components.
     *
     * @param array $item The item row (must include item_type_id)
     * @param int $tenantId
     * @throws \InvalidArgumentException If the item type is not allowed as a component
     */
    private function validateComponentType(array $item, int $tenantId): void {
        $typeId = (int) ($item['item_type_id'] ?? 0);
        if ($typeId === 0) return;

        $type = $this->itemTypeRepo->findById($typeId, $tenantId);
        if (!$type || empty($type['code'])) {
            // User-defined types without a code are allowed as components
            return;
        }

        $allowedComponentCodes = ['RAW_MATERIAL', 'CONSUMABLE'];
        if (!in_array($type['code'], $allowedComponentCodes, true)) {
            throw new \InvalidArgumentException(
                "Item '{$item['name']}' (type: {$type['name']}) cannot be used as a recipe component. " .
                "Only Raw Material and Consumable items are allowed."
            );
        }
    }

    /**
     * Resolve component details (cost_price) for cost calculation.
     *
     * @param array $components Array of [item_id, quantity]
     * @param int   $tenantId
     * @return array Array of [cost_price, quantity]
     */
    private function resolveComponentDetails(array $components, int $tenantId): array {
        $details = [];
        foreach ($components as $component) {
            $item = $this->itemRepo->getById((int) $component['item_id'], $tenantId);
            $details[] = [
                'cost_price' => (float) ($item['cost_price'] ?? 0),
                'quantity'   => (float) $component['quantity'],
            ];
        }
        return $details;
    }

    /**
     * Get the "Manufactured" item type ID for the given tenant/business.
     *
     * @param int $tenantId
     * @param int $businessId
     * @return int
     */
    private function getManufacturedTypeId(int $tenantId, int $businessId): int {
        $stmt = $this->db->prepare("
            SELECT id FROM item_types
            WHERE (code = 'MANUFACTURED' OR name = 'Manufactured')
              AND tenant_id = :tenant_id
              AND (business_id = :business_id OR business_id IS NULL)
            LIMIT 1
        ");
        $stmt->execute([
            'tenant_id'   => $tenantId,
            'business_id' => $businessId,
        ]);
        $result = $stmt->fetchColumn();

        if (!$result) {
            throw new \RuntimeException('Manufactured item type not found. Please run the manufacturing migration.');
        }

        return (int) $result;
    }

    /**
     * Validate that a recipe output item has an allowed type (MANUFACTURED or FINISHED_GOOD).
     * REQ-031: BOM outputs must be MANUFACTURED or FINISHED_GOOD.
     *
     * @param int $itemId The manufactured item ID
     * @param int $tenantId
     * @throws \InvalidArgumentException If the item type is not allowed as a BOM output
     */
    private function validateOutputType(int $itemId, int $tenantId): void {
        $item = $this->itemRepo->getById($itemId, $tenantId);
        if (!$item) return;

        $typeId = (int) ($item['item_type_id'] ?? 0);
        if ($typeId === 0) return;

        $type = $this->itemTypeRepo->findById($typeId, $tenantId);
        if (!$type || empty($type['code'])) {
            // User-defined types without a code are allowed
            return;
        }

        $allowedOutputCodes = ['MANUFACTURED', 'FINISHED_GOOD'];
        if (!in_array($type['code'], $allowedOutputCodes, true)) {
            throw new \InvalidArgumentException(
                "Item '{$item['name']}' (type: {$type['name']}) cannot be a recipe output. " .
                "Only Manufactured and Finished Good items are allowed as BOM outputs."
            );
        }
    }

    /**
     * Resolve all user IDs in a business that hold a given permission.
     * Business owners (role_id = 1) implicitly hold all permissions.
     *
     * @param string $permissionCode The permission code to check
     * @param int    $businessId     The business to scope the lookup to
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
}
