<?php
namespace App\Repository;

use PDO;

class ManufacturingRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /* =====================================================================
     * RECIPES
     * ===================================================================== */

    /**
     * Insert a new recipe and return the new id.
     *
     * Expected keys: tenant_id, business_id, manufactured_item_id, yield_quantity, notes, created_by
     */
    public function insertRecipe(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO recipes
              (tenant_id, business_id, manufactured_item_id, yield_quantity, notes, is_active, created_by, created_at, updated_at)
            VALUES
              (:tenant_id, :business_id, :manufactured_item_id, :yield_quantity, :notes, 1, :created_by, NOW(), NOW())
        ");
        $stmt->execute([
            'tenant_id'            => $data['tenant_id'],
            'business_id'          => $data['business_id'],
            'manufactured_item_id' => $data['manufactured_item_id'],
            'yield_quantity'       => $data['yield_quantity'],
            'notes'                => $data['notes'] ?? null,
            'created_by'           => $data['created_by'],
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Update an existing recipe.
     */
    public function updateRecipe(int $id, int $tenantId, array $data): void {
        $stmt = $this->db->prepare("
            UPDATE recipes
            SET yield_quantity = :yield_quantity,
                notes          = :notes,
                updated_at     = NOW()
            WHERE id        = :id
              AND tenant_id = :tenant_id
        ");
        $stmt->execute([
            'yield_quantity' => $data['yield_quantity'],
            'notes'          => $data['notes'] ?? null,
            'id'             => $id,
            'tenant_id'      => $tenantId,
        ]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Recipe not found or not allowed');
        }
    }

    /**
     * Delete a recipe scoped to tenant.
     */
    public function deleteRecipe(int $id, int $tenantId): void {
        $stmt = $this->db->prepare("
            DELETE FROM recipes
            WHERE id        = :id
              AND tenant_id = :tenant_id
        ");
        $stmt->execute(['id' => $id, 'tenant_id' => $tenantId]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Recipe not found or not allowed');
        }
    }

    /**
     * Return a single recipe by id, scoped to tenant and business.
     */
    public function getRecipeById(int $id, int $tenantId, int $businessId): ?array {
        $stmt = $this->db->prepare("
            SELECT r.id, r.tenant_id, r.business_id, r.manufactured_item_id,
                   r.yield_quantity, r.notes, r.is_active, r.created_by,
                   r.created_at, r.updated_at,
                   i.name AS manufactured_item_name,
                   i.unit, i.sale_price, i.has_expiry, i.min_stock, i.shelf_life_days,
                   CASE WHEN i.markup_enabled = 1 THEN i.markup_percent ELSE NULL END AS markup_percent
            FROM recipes r
            JOIN items i ON i.id = r.manufactured_item_id
            WHERE r.id          = :id
              AND r.tenant_id   = :tenant_id
              AND r.business_id = :business_id
            LIMIT 1
        ");
        $stmt->execute([
            'id'          => $id,
            'tenant_id'   => $tenantId,
            'business_id' => $businessId,
        ]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Return a recipe by its manufactured item id, scoped to tenant and business.
     */
    public function getRecipeByItemId(int $itemId, int $tenantId, int $businessId): ?array {
        $stmt = $this->db->prepare("
            SELECT r.id, r.tenant_id, r.business_id, r.manufactured_item_id,
                   r.yield_quantity, r.notes, r.is_active, r.created_by,
                   r.created_at, r.updated_at,
                   i.name AS manufactured_item_name,
                   i.unit, i.sale_price,
                   CASE WHEN i.markup_enabled = 1 THEN i.markup_percent ELSE NULL END AS markup_percent
            FROM recipes r
            JOIN items i ON i.id = r.manufactured_item_id
            WHERE r.manufactured_item_id = :item_id
              AND r.tenant_id            = :tenant_id
              AND r.business_id          = :business_id
            LIMIT 1
        ");
        $stmt->execute([
            'item_id'     => $itemId,
            'tenant_id'   => $tenantId,
            'business_id' => $businessId,
        ]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Return all active recipes for a tenant/business.
     */
    public function getAllRecipes(int $tenantId, int $businessId): array {
        $stmt = $this->db->prepare("
            SELECT r.id, r.tenant_id, r.business_id, r.manufactured_item_id,
                   r.yield_quantity, r.notes, r.is_active, r.created_by,
                   r.created_at, r.updated_at,
                   i.name AS manufactured_item_name,
                   i.cost_price AS manufactured_item_cost_price
            FROM recipes r
            JOIN items i ON i.id = r.manufactured_item_id
            WHERE r.tenant_id   = :tenant_id
              AND r.business_id = :business_id
              AND r.is_active   = 1
            ORDER BY i.name
        ");
        $stmt->execute([
            'tenant_id'   => $tenantId,
            'business_id' => $businessId,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return all recipes that use a given component item (for cascading recalculation).
     * Scoped by tenant_id to ensure data isolation.
     */
    public function getRecipesByComponentItem(int $itemId, int $tenantId): array {
        $stmt = $this->db->prepare("
            SELECT r.id, r.tenant_id, r.business_id, r.manufactured_item_id,
                   r.yield_quantity, r.notes, r.is_active, r.created_by,
                   r.created_at, r.updated_at
            FROM recipes r
            JOIN recipe_items ri ON ri.recipe_id = r.id
            WHERE ri.component_item_id = :item_id
              AND r.tenant_id          = :tenant_id
              AND r.is_active          = 1
        ");
        $stmt->execute([
            'item_id'   => $itemId,
            'tenant_id' => $tenantId,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* =====================================================================
     * RECIPE ITEMS
     * ===================================================================== */

    /**
     * Insert recipe items (batch insert).
     *
     * Each item in $items should have: component_item_id, quantity
     */
    public function insertRecipeItems(int $recipeId, array $items): void {
        $stmt = $this->db->prepare("
            INSERT INTO recipe_items (recipe_id, component_item_id, quantity)
            VALUES (:recipe_id, :component_item_id, :quantity)
        ");
        foreach ($items as $item) {
            $stmt->execute([
                'recipe_id'         => $recipeId,
                'component_item_id' => $item['component_item_id'],
                'quantity'          => $item['quantity'],
            ]);
        }
    }

    /**
     * Delete all recipe items for a given recipe.
     */
    public function deleteRecipeItems(int $recipeId): void {
        $stmt = $this->db->prepare("
            DELETE FROM recipe_items WHERE recipe_id = :recipe_id
        ");
        $stmt->execute(['recipe_id' => $recipeId]);
    }

    /**
     * Return all recipe items for a given recipe, including component item details.
     */
    public function getRecipeItems(int $recipeId, ?int $businessId = null): array {
        $sql = "
            SELECT ri.id, ri.recipe_id, ri.component_item_id, ri.quantity,
                   i.name AS component_item_name, i.unit, i.cost_price";

        if ($businessId !== null) {
            $sql .= ", COALESCE(s.quantity, 0) AS available_stock";
        }

        $sql .= "
            FROM recipe_items ri
            JOIN items i ON i.id = ri.component_item_id";

        if ($businessId !== null) {
            $sql .= " LEFT JOIN stock s ON s.item_id = ri.component_item_id AND s.business_id = :business_id";
        }

        $sql .= " WHERE ri.recipe_id = :recipe_id";

        $stmt = $this->db->prepare($sql);
        $params = ['recipe_id' => $recipeId];
        if ($businessId !== null) {
            $params['business_id'] = $businessId;
        }
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* =====================================================================
     * PRODUCTION ORDERS
     * ===================================================================== */

    /**
     * Insert a new production order and return the new id.
     *
     * Expected keys: tenant_id, business_id, recipe_id, manufactured_item_id,
     *                production_quantity, manufacturing_cost, unit_manufacturing_cost,
     *                status, production_date, created_by
     */
    public function insertProductionOrder(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO production_orders
              (tenant_id, business_id, recipe_id, manufactured_item_id,
               production_quantity, manufacturing_cost, unit_manufacturing_cost,
               status, production_date, created_by, created_at)
            VALUES
              (:tenant_id, :business_id, :recipe_id, :manufactured_item_id,
               :production_quantity, :manufacturing_cost, :unit_manufacturing_cost,
               :status, :production_date, :created_by, NOW())
        ");
        $stmt->execute([
            'tenant_id'               => $data['tenant_id'],
            'business_id'             => $data['business_id'],
            'recipe_id'               => $data['recipe_id'],
            'manufactured_item_id'    => $data['manufactured_item_id'],
            'production_quantity'     => $data['production_quantity'],
            'manufacturing_cost'      => $data['manufacturing_cost'],
            'unit_manufacturing_cost' => $data['unit_manufacturing_cost'],
            'status'                  => $data['status'] ?? 'COMPLETED',
            'production_date'         => $data['production_date'],
            'created_by'              => $data['created_by'],
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Insert production order items (batch insert).
     *
     * Each item in $items should have: component_item_id, quantity_consumed, unit_cost
     */
    public function insertProductionOrderItems(int $orderId, array $items): void {
        $stmt = $this->db->prepare("
            INSERT INTO production_order_items
              (production_order_id, component_item_id, quantity_consumed, unit_cost)
            VALUES
              (:production_order_id, :component_item_id, :quantity_consumed, :unit_cost)
        ");
        foreach ($items as $item) {
            $stmt->execute([
                'production_order_id' => $orderId,
                'component_item_id'   => $item['component_item_id'],
                'quantity_consumed'   => $item['quantity_consumed'],
                'unit_cost'           => $item['unit_cost'],
            ]);
        }
    }

    /**
     * Insert production order waste items (batch insert).
     *
     * Each item in $items should have: item_id, quantity
     */
    public function insertProductionOrderWaste(int $orderId, array $items): void {
        $stmt = $this->db->prepare("
            INSERT INTO production_order_waste
              (production_order_id, item_id, quantity)
            VALUES
              (:production_order_id, :item_id, :quantity)
        ");
        foreach ($items as $item) {
            $stmt->execute([
                'production_order_id' => $orderId,
                'item_id'             => $item['item_id'],
                'quantity'            => $item['quantity'],
            ]);
        }
    }

    /**
     * Return all waste items for a given production order.
     */
    public function getProductionOrderWaste(int $orderId): array {
        $stmt = $this->db->prepare("
            SELECT pow.id, pow.production_order_id, pow.item_id,
                   pow.quantity, i.name AS item_name, i.unit
            FROM production_order_waste pow
            JOIN items i ON i.id = pow.item_id
            WHERE pow.production_order_id = :order_id
        ");
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return all items for a given production order.
     */
    public function getProductionOrderItems(int $orderId): array {
        $stmt = $this->db->prepare("
            SELECT poi.id, poi.production_order_id, poi.component_item_id,
                   poi.quantity_consumed, poi.unit_cost,
                   i.name AS component_item_name, i.unit
            FROM production_order_items poi
            JOIN items i ON i.id = poi.component_item_id
            WHERE poi.production_order_id = :order_id
        ");
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return a single production order by id, scoped to tenant and business.
     */
    public function getProductionOrderById(int $id, int $tenantId, int $businessId): ?array {
        $stmt = $this->db->prepare("
            SELECT po.id, po.tenant_id, po.business_id, po.recipe_id,
                   po.manufactured_item_id, po.production_quantity,
                   po.manufacturing_cost, po.unit_manufacturing_cost,
                   po.status, po.production_date, po.created_by, po.created_at,
                   i.name AS manufactured_item_name,
                   r.yield_quantity AS recipe_yield_quantity
            FROM production_orders po
            JOIN items i ON i.id = po.manufactured_item_id
            JOIN recipes r ON r.id = po.recipe_id
            WHERE po.id          = :id
              AND po.tenant_id   = :tenant_id
              AND po.business_id = :business_id
            LIMIT 1
        ");
        $stmt->execute([
            'id'          => $id,
            'tenant_id'   => $tenantId,
            'business_id' => $businessId,
        ]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Return all production orders for a tenant/business with optional filters.
     *
     * Supported filters:
     *   - start_date: only orders with production_date >= this value
     *   - end_date: only orders with production_date <= this value
     *   - manufactured_item_id: only orders for this manufactured item
     *
     * Results are ordered by production_date DESC.
     */
    public function getAllProductionOrders(int $tenantId, int $businessId, ?array $filters = null): array {
        $sql = "
            SELECT po.id, po.tenant_id, po.business_id, po.recipe_id,
                   po.manufactured_item_id, po.production_quantity,
                   po.manufacturing_cost, po.unit_manufacturing_cost,
                   po.status, po.production_date, po.created_by, po.created_at,
                   i.name AS manufactured_item_name
            FROM production_orders po
            JOIN items i ON i.id = po.manufactured_item_id
            WHERE po.tenant_id   = :tenant_id
              AND po.business_id = :business_id
        ";

        $params = [
            'tenant_id'   => $tenantId,
            'business_id' => $businessId,
        ];

        if (!empty($filters['start_date'])) {
            $sql .= " AND po.production_date >= :start_date";
            $params['start_date'] = $filters['start_date'];
        }

        if (!empty($filters['end_date'])) {
            $sql .= " AND po.production_date <= :end_date";
            $params['end_date'] = $filters['end_date'];
        }

        if (!empty($filters['manufactured_item_id'])) {
            $sql .= " AND po.manufactured_item_id = :manufactured_item_id";
            $params['manufactured_item_id'] = $filters['manufactured_item_id'];
        }

        $sql .= " ORDER BY po.production_date DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update the status of a production order.
     */
    public function updateProductionOrderStatus(int $id, string $status, int $tenantId, int $businessId): void {
        $stmt = $this->db->prepare("
            UPDATE production_orders
            SET status = :status
            WHERE id = :id
              AND tenant_id = :tenant_id
              AND business_id = :business_id
        ");
        $stmt->execute([
            'status'      => $status,
            'id'          => $id,
            'tenant_id'   => $tenantId,
            'business_id' => $businessId,
        ]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Production order not found or not allowed');
        }
    }

    /**
     * Count the number of production orders associated with a recipe.
     */
    public function countProductionOrdersForRecipe(int $recipeId): int {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM production_orders
            WHERE recipe_id = :recipe_id
        ");
        $stmt->execute(['recipe_id' => $recipeId]);
        return (int) $stmt->fetchColumn();
    }

    /* =====================================================================
     * COST FLAGS & AUTO-MARKUP
     * ===================================================================== */

    /**
     * Set a cost change flag for a manufactured item.
     * Sets items.has_cost_change_flag = 1 for quick UI lookups.
     * The old/new cost values are recorded in item_price_history.
     */
    public function setCostChangeFlag(int $itemId, float $oldCost, float $newCost): void {
        $stmt = $this->db->prepare("
            UPDATE items SET has_cost_change_flag = 1 WHERE id = :item_id
        ");
        $stmt->execute(['item_id' => $itemId]);
    }

    /**
     * Get the active (non-dismissed) cost change flag for an item.
     * Now reads from items.has_cost_change_flag and item_price_history for old/new cost values.
     */
    public function getCostChangeFlag(int $itemId): ?array {
        $stmt = $this->db->prepare("
            SELECT i.id AS item_id, i.has_cost_change_flag,
                   iph.old_cost AS old_cost_price, iph.new_cost AS new_cost_price,
                   iph.changed_at AS created_at
            FROM items i
            LEFT JOIN item_price_history iph ON iph.item_id = i.id
            WHERE i.id = :item_id
              AND i.has_cost_change_flag = 1
            ORDER BY iph.changed_at DESC
            LIMIT 1
        ");
        $stmt->execute(['item_id' => $itemId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$result || !$result['has_cost_change_flag']) {
            return null;
        }
        return [
            'item_id' => $result['item_id'],
            'old_cost_price' => $result['old_cost_price'],
            'new_cost_price' => $result['new_cost_price'],
            'is_dismissed' => 0,
            'created_at' => $result['created_at'],
            'dismissed_at' => null,
        ];
    }

    /**
     * Dismiss the cost change flag for an item.
     * Also sets items.has_cost_change_flag = 0.
     */
    public function dismissCostChangeFlag(int $itemId): void {
        $stmt = $this->db->prepare("
            UPDATE items SET has_cost_change_flag = 0 WHERE id = :item_id
        ");
        $stmt->execute(['item_id' => $itemId]);
    }

    /**
     * Get the auto-markup configuration for an item.
     */
    public function getAutoMarkupConfig(int $itemId): ?array {
        $stmt = $this->db->prepare("
            SELECT id AS item_id, markup_percent, markup_enabled AS is_enabled
            FROM items
            WHERE id = :item_id
              AND markup_enabled = 1
            LIMIT 1
        ");
        $stmt->execute(['item_id' => $itemId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Set or update the auto-markup configuration for an item.
     * Updates the markup_percent and markup_enabled columns directly on the items table.
     */
    public function setAutoMarkupConfig(int $itemId, float $markupPercent): void {
        $stmt = $this->db->prepare("
            UPDATE items
            SET markup_percent = :markup_percent,
                markup_enabled = 1
            WHERE id = :item_id
        ");
        $stmt->execute([
            'item_id'        => $itemId,
            'markup_percent' => $markupPercent,
        ]);
    }
}
