<?php
namespace App\Repository;

use PDO;

class ItemRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    // ── ITEM TYPES ────────────────────────────────────────────────────────────

    public function getAllTypes(int $tenantId, int $businessId): array {
        $stmt = $this->db->prepare("
            SELECT id, name, created_at
            FROM item_types
            WHERE tenant_id = :tenant
              AND (business_id = :business OR business_id IS NULL)
            ORDER BY name
        ");
        $stmt->execute(['tenant' => $tenantId, 'business' => $businessId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTypeById(int $id, int $tenantId): ?array {
        $stmt = $this->db->prepare(
            "SELECT id, name FROM item_types WHERE id = :id AND tenant_id = :tenant LIMIT 1"
        );
        $stmt->execute(['id' => $id, 'tenant' => $tenantId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function insertType(int $tenantId, int $businessId, string $name, int $createdBy): int {
        $stmt = $this->db->prepare("
            INSERT INTO item_types (tenant_id, business_id, name, created_at, created_by)
            VALUES (:tenant, :business, :name, NOW(), :created_by)
        ");
        $stmt->execute([
            'tenant'     => $tenantId,
            'business'   => $businessId,
            'name'       => $name,
            'created_by' => $createdBy,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function updateType(int $id, int $tenantId, string $name): void {
        $stmt = $this->db->prepare(
            "UPDATE item_types SET name = :name, updated_at = NOW()
             WHERE id = :id AND tenant_id = :tenant"
        );
        $stmt->execute(['id' => $id, 'tenant' => $tenantId, 'name' => $name]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Item type not found or not allowed');
        }
    }

    public function deleteType(int $id, int $tenantId): void {
        $stmt = $this->db->prepare(
            "DELETE FROM item_types WHERE id = :id AND tenant_id = :tenant"
        );
        $stmt->execute(['id' => $id, 'tenant' => $tenantId]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Item type not found or not allowed');
        }
    }

    public function countItemsForType(int $typeId): int {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM items WHERE item_type_id = ? AND is_active = 1"
        );
        $stmt->execute([$typeId]);
        return (int) $stmt->fetchColumn();
    }

    // ── ITEMS ─────────────────────────────────────────────────────────────────

    public function getAllForBusiness(int $tenantId, int $businessId, array $excludeTypes = [], bool $includeInactive = false): array {
        $params = ['tenant' => $tenantId, 'business1' => $businessId, 'business2' => $businessId];
        $excludeClause = '';

        if (!empty($excludeTypes)) {
            $placeholders = [];
            foreach ($excludeTypes as $i => $type) {
                $key = "excl_{$i}";
                $placeholders[] = ":{$key}";
                $params[$key] = strtolower(trim($type));
            }
            $excludeClause = "AND LOWER(TRIM(it.name)) NOT IN (" . implode(',', $placeholders) . ")";
        }

        $activeClause = $includeInactive ? '' : 'AND i.is_active = 1';

        // Cost change flag data comes from items.has_cost_change_flag + item_price_history
        $costFlagSelect = "i.has_cost_change_flag, iph.old_price AS flag_old_cost, iph.new_price AS flag_new_cost";

        $costFlagJoin = "LEFT JOIN (
                SELECT iph1.item_id, iph1.old_price, iph1.new_price
                FROM item_price_history iph1
                INNER JOIN (
                    SELECT item_id, MAX(created_at) AS max_created_at
                    FROM item_price_history
                    WHERE price_type = 'COST'
                    GROUP BY item_id
                ) iph2 ON iph1.item_id = iph2.item_id AND iph1.created_at = iph2.max_created_at
                WHERE iph1.price_type = 'COST'
            ) iph ON iph.item_id = i.id AND i.has_cost_change_flag = 1";

        $sql = "
            SELECT i.id, i.name, i.sku, i.unit,
                   i.cost_price, i.sale_price, i.is_active, i.notes,
                   i.business_id, i.has_expiry, i.customer_id, i.min_stock, i.shelf_life_days,
                   {$costFlagSelect},
                   it.name AS type_name,
                   p.name AS customer_name,
                   COALESCE(s.quantity, 0) AS stock_qty,
                   (SELECT COALESCE(SUM(sid.quantity), 0) FROM si_details sid JOIN sales_invoices si ON si.id = sid.sales_invoice_id WHERE sid.item_id = i.id AND si.status IN ('DELIVERED', 'PAID')) AS total_sold
            FROM items i
            JOIN item_types it ON it.id = i.item_type_id
            LEFT JOIN stock s ON s.item_id = i.id AND s.business_id = :business1
            LEFT JOIN people p ON p.id = i.customer_id
            {$costFlagJoin}
            WHERE i.tenant_id = :tenant
              AND (i.business_id = :business2 OR i.business_id IS NULL)
              {$activeClause}
              {$excludeClause}
            ORDER BY i.name
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById(int $id, int $tenantId): ?array {
        $stmt = $this->db->prepare("
            SELECT i.*, it.name AS type_name
            FROM items i
            JOIN item_types it ON it.id = i.item_type_id
            WHERE i.id = :id AND i.tenant_id = :tenant
            LIMIT 1
        ");
        $stmt->execute(['id' => $id, 'tenant' => $tenantId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getItemInsights(int $itemId, int $businessId, ?string $startDate = null, ?string $endDate = null): array {
        $dateFilterSi = "";
        $dateFilterPo = "";
        $paramsSi = [
            'item1' => $itemId, 'biz1' => $businessId,
            'item2' => $itemId, 'biz2' => $businessId
        ];
        $paramsPo = ['item' => $itemId, 'biz' => $businessId];
        $paramsCust = ['item' => $itemId, 'biz' => $businessId];

        if ($startDate) {
            $dateFilterSi .= " AND si.invoice_date >= :start_date";
            $dateFilterPo .= " AND po.po_date >= :start_date";
            $paramsSi['start_date'] = $startDate;
            $paramsPo['start_date'] = $startDate;
            $paramsCust['start_date'] = $startDate;
        }
        if ($endDate) {
            $dateFilterSi .= " AND si.invoice_date <= :end_date";
            $dateFilterPo .= " AND po.po_date <= :end_date";
            $paramsSi['end_date'] = $endDate;
            $paramsPo['end_date'] = $endDate;
            $paramsCust['end_date'] = $endDate;
        }

        // Basic metrics: Total Sold, Revenue (FC), Last Sold Date, 30-Day Velocity
        $metricsStmt = $this->db->prepare("
            SELECT 
                COALESCE(SUM(sid.quantity), 0) AS total_sold,
                COALESCE(SUM(sid.total_price_fc), 0) AS total_revenue,
                MAX(si.invoice_date) AS last_sold_date,
                (SELECT COALESCE(SUM(sid2.quantity), 0) 
                 FROM si_details sid2 
                 JOIN sales_invoices si2 ON si2.id = sid2.sales_invoice_id 
                 WHERE sid2.item_id = :item1 
                   AND si2.business_id = :biz1 
                   AND si2.status IN ('DELIVERED', 'PAID')
                   AND si2.invoice_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                ) AS units_sold_30_days
            FROM si_details sid
            JOIN sales_invoices si ON si.id = sid.sales_invoice_id
            WHERE sid.item_id = :item2 
              AND si.business_id = :biz2 
              AND si.status IN ('DELIVERED', 'PAID')
              {$dateFilterSi}
        ");
        $metricsStmt->execute($paramsSi);
        $metrics = $metricsStmt->fetch(PDO::FETCH_ASSOC);

        // Purchases Metrics: Total Bought, Cost (FC), Last Bought Date
        $poStmt = $this->db->prepare("
            SELECT 
                COALESCE(SUM(pod.quantity), 0) AS total_bought,
                COALESCE(SUM(pod.total_price_fc), 0) AS total_cost,
                MAX(po.po_date) AS last_bought_date
            FROM po_details pod
            JOIN purchase_orders po ON po.id = pod.purchase_order_id
            WHERE pod.item_id = :item 
              AND po.business_id = :biz 
              AND po.status IN ('RECEIVED')
              {$dateFilterPo}
        ");
        $poStmt->execute($paramsPo);
        $poMetrics = $poStmt->fetch(PDO::FETCH_ASSOC);

        // Top 3 Customers
        $customersStmt = $this->db->prepare("
            SELECT pe.name AS customer_name,
                   COALESCE(SUM(sid.quantity), 0) AS quantity,
                   COALESCE(SUM(sid.total_price_fc), 0) AS revenue
            FROM si_details sid
            JOIN sales_invoices si ON si.id = sid.sales_invoice_id
            JOIN payees p ON p.id = si.customer_id
            JOIN people pe ON pe.id = p.people_id
            WHERE sid.item_id = :item
              AND si.business_id = :biz
              AND si.status IN ('DELIVERED', 'PAID')
              {$dateFilterSi}
            GROUP BY pe.id, pe.name
            ORDER BY quantity DESC, revenue DESC
            LIMIT 3
        ");
        $customersStmt->execute($paramsCust);
        $topCustomers = $customersStmt->fetchAll(PDO::FETCH_ASSOC);

        $totalSold = (float)($metrics['total_sold'] ?? 0);
        $totalRevenue = (float)($metrics['total_revenue'] ?? 0);
        $avgPrice = $totalSold > 0 ? $totalRevenue / $totalSold : 0;

        $totalBought = (float)($poMetrics['total_bought'] ?? 0);
        $totalCost = (float)($poMetrics['total_cost'] ?? 0);
        $avgBuyingPrice = $totalBought > 0 ? $totalCost / $totalBought : 0;

        // Check if item is manufactured
        $typeStmt = $this->db->prepare("
            SELECT it.code AS type_code, it.name AS type_name 
            FROM items i 
            JOIN item_types it ON i.item_type_id = it.id 
            WHERE i.id = :item
        ");
        $typeStmt->execute(['item' => $itemId]);
        $typeRow = $typeStmt->fetch(PDO::FETCH_ASSOC);
        $isManufactured = ($typeRow && (
            in_array($typeRow['type_code'], ['MANUFACTURED', 'FINISHED_GOOD']) ||
            in_array(strtolower($typeRow['type_name']), ['manufactured', 'finished good', 'manufactured item', 'produced'])
        ));

        $prodMetrics = null;
        if ($isManufactured) {
            $dateFilterProd = "";
            $paramsProd = ['item' => $itemId, 'biz' => $businessId];
            if ($startDate) {
                $dateFilterProd .= " AND po.production_date >= :start_date";
                $paramsProd['start_date'] = $startDate;
            }
            if ($endDate) {
                $dateFilterProd .= " AND po.production_date <= :end_date";
                $paramsProd['end_date'] = $endDate;
            }

            $prodStmt = $this->db->prepare("
                SELECT 
                    COALESCE(SUM(po.production_quantity), 0) AS total_produced,
                    COALESCE(SUM(po.manufacturing_cost), 0) AS total_production_cost,
                    MAX(po.production_date) AS last_produced_date
                FROM production_orders po
                WHERE po.manufactured_item_id = :item
                  AND po.business_id = :biz
                  AND po.status IN ('COMPLETED')
                  {$dateFilterProd}
            ");
            $prodStmt->execute($paramsProd);
            $prodMetrics = $prodStmt->fetch(PDO::FETCH_ASSOC);
        }

        $totalProduced = $prodMetrics ? (float)($prodMetrics['total_produced'] ?? 0) : 0;
        $totalProductionCost = $prodMetrics ? (float)($prodMetrics['total_production_cost'] ?? 0) : 0;
        $avgProductionCost = $totalProduced > 0 ? $totalProductionCost / $totalProduced : 0;

        return [
            'is_manufactured' => $isManufactured,
            'total_sold' => $totalSold,
            'total_revenue' => $totalRevenue,
            'average_price' => $avgPrice,
            'last_sold_date' => $metrics['last_sold_date'] ?? null,
            'units_sold_30_days' => (float)($metrics['units_sold_30_days'] ?? 0),
            'total_bought' => $totalBought,
            'total_cost' => $totalCost,
            'average_buying_price' => $avgBuyingPrice,
            'last_bought_date' => $poMetrics['last_bought_date'] ?? null,
            'total_produced' => $totalProduced,
            'total_production_cost' => $totalProductionCost,
            'average_production_cost' => $avgProductionCost,
            'last_produced_date' => $prodMetrics['last_produced_date'] ?? null,
            'top_customers' => $topCustomers
        ];
    }

    public function insert(array $data): int {
        $hasExpiry = isset($data['has_expiry']) ? (int) $data['has_expiry'] : 0;
        unset($data['has_expiry']);
        $data['has_expiry'] = $hasExpiry;

        // Extract optional customer_id (default to NULL for global items)
        $customerId = array_key_exists('customer_id', $data) ? $data['customer_id'] : null;
        unset($data['customer_id']);
        $data['customer_id'] = $customerId;

        $shelfLifeDays = array_key_exists('shelf_life_days', $data) ? $data['shelf_life_days'] : null;
        unset($data['shelf_life_days']);
        $data['shelf_life_days'] = $shelfLifeDays;

        $stmt = $this->db->prepare("
            INSERT INTO items
              (tenant_id, business_id, customer_id, item_type_id, name, sku, unit,
               cost_price, sale_price, notes, is_active, has_expiry, shelf_life_days, created_at, created_by)
            VALUES
              (:tenant, :business, :customer_id, :type, :name, :sku, :unit,
               :cost, :sale, :notes, 1, :has_expiry, :shelf_life_days, NOW(), :created_by)
        ");
        $stmt->execute($data);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, int $tenantId, array $data): void {
        $allowed = ['name','sku','unit','cost_price','sale_price','min_stock','markup_percent','markup_enabled','notes','item_type_id','has_expiry','shelf_life_days','customer_id'];
        $fields  = [];
        $params  = ['id' => $id, 'tenant' => $tenantId];

        foreach ($data as $key => $value) {
            if (in_array($key, $allowed, true)) {
                $fields[]    = "$key = :$key";
                $params[$key] = $value;
            }
        }
        if (empty($fields)) return;

        $sql = "UPDATE items SET " . implode(', ', $fields) . ", updated_at = NOW()
                WHERE id = :id AND tenant_id = :tenant";
        $this->db->prepare($sql)->execute($params);
    }

    public function deactivate(int $id, int $tenantId): void {
        $stmt = $this->db->prepare(
            "UPDATE items SET is_active = 0, updated_at = NOW()
             WHERE id = :id AND tenant_id = :tenant"
        );
        $stmt->execute(['id' => $id, 'tenant' => $tenantId]);
    }

    // ── STOCK ─────────────────────────────────────────────────────────────────

    public function getStockForBusiness(int $businessId): array {
        $stmt = $this->db->prepare("
            SELECT s.id, s.item_id, s.quantity, s.updated_at,
                   i.name AS item_name, i.sku, i.unit,
                   it.name AS type_name
            FROM stock s
            JOIN items i ON i.id = s.item_id
            JOIN item_types it ON it.id = i.item_type_id
            WHERE s.business_id = :business
              AND i.is_active = 1
            ORDER BY i.name
        ");
        $stmt->execute(['business' => $businessId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getStockEntry(int $itemId, int $businessId): ?array {
        $stmt = $this->db->prepare(
            "SELECT id, quantity FROM stock WHERE item_id = :item AND business_id = :business LIMIT 1"
        );
        $stmt->execute(['item' => $itemId, 'business' => $businessId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function upsertStock(int $itemId, int $businessId, float $newQty): void {
        $stmt = $this->db->prepare("
            INSERT INTO stock (business_id, item_id, quantity, updated_at)
            VALUES (:business, :item, :qty1, NOW())
            ON DUPLICATE KEY UPDATE quantity = :qty2, updated_at = NOW()
        ");
        $stmt->execute(['business' => $businessId, 'item' => $itemId, 'qty1' => $newQty, 'qty2' => $newQty]);
    }

    public function insertStockTransaction(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO stock_transactions
              (business_id, item_id, trx_date, type, reference_type, reference_id, quantity, notes, created_at, created_by)
            VALUES
              (:business, :item, NOW(), :type, :reference_type, :reference_id, :quantity, :notes, NOW(), :created_by)
        ");
        try {
            $data['created_by'] = $data['created_by'] ?? \App\Support\AuthContext::userId();
        } catch (\RuntimeException $e) {
            $data['created_by'] = null;
        }
        $stmt->execute($data);
        return (int) $this->db->lastInsertId();
    }

    public function getStockTransactions(int $itemId, int $businessId, int $limit = 50): array {
        $stmt = $this->db->prepare("
            SELECT id, trx_date, type, reference_type, reference_id, quantity, notes, created_at
            FROM stock_transactions
            WHERE item_id = :item AND business_id = :business
            ORDER BY trx_date DESC, id DESC
            LIMIT :lim
        ");
        $stmt->bindValue(':item',     $itemId,     PDO::PARAM_INT);
        $stmt->bindValue(':business', $businessId, PDO::PARAM_INT);
        $stmt->bindValue(':lim',      $limit,      PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── CUSTOMER-FILTERED QUERIES ─────────────────────────────────────────────

    /**
     * Get items visible to a specific customer:
     * all global items (customer_id IS NULL) + items specific to this customer.
     *
     * @param int $customerId  The people.id of the customer
     * @param int $tenantId
     * @param int $businessId
     * @return array  List of items with type_name and stock_qty
     */
    public function getItemsForCustomer(int $customerId, int $tenantId, int $businessId): array
    {
        $stmt = $this->db->prepare("
            SELECT i.id, i.name, i.sku, i.unit,
                   i.cost_price, i.sale_price, i.is_active, i.notes,
                   i.business_id, i.customer_id, i.has_expiry,
                   it.name AS type_name,
                   COALESCE(s.quantity, 0) AS stock_qty
            FROM items i
            JOIN item_types it ON it.id = i.item_type_id
            LEFT JOIN stock s ON s.item_id = i.id AND s.business_id = :business1
            WHERE i.tenant_id = :tenant
              AND (i.business_id = :business2 OR i.business_id IS NULL)
              AND i.is_active = 1
              AND (i.customer_id IS NULL OR i.customer_id = :customer_id)
            ORDER BY i.name
        ");
        $stmt->execute([
            'tenant'      => $tenantId,
            'business1'   => $businessId,
            'business2'   => $businessId,
            'customer_id' => $customerId,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get only global items (customer_id IS NULL).
     * Used when no customer is selected on the invoice form.
     *
     * @param int $tenantId
     * @param int $businessId
     * @return array  List of items with type_name and stock_qty
     */
    public function getGlobalItems(int $tenantId, int $businessId): array
    {
        $stmt = $this->db->prepare("
            SELECT i.id, i.name, i.sku, i.unit,
                   i.cost_price, i.sale_price, i.is_active, i.notes,
                   i.business_id, i.customer_id, i.has_expiry,
                   it.name AS type_name,
                   COALESCE(s.quantity, 0) AS stock_qty
            FROM items i
            JOIN item_types it ON it.id = i.item_type_id
            LEFT JOIN stock s ON s.item_id = i.id AND s.business_id = :business1
            WHERE i.tenant_id = :tenant
              AND (i.business_id = :business2 OR i.business_id IS NULL)
              AND i.is_active = 1
              AND i.customer_id IS NULL
            ORDER BY i.name
        ");
        $stmt->execute([
            'tenant'    => $tenantId,
            'business1' => $businessId,
            'business2' => $businessId,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get all items specific to a given customer.
     * Used in the customer insights view.
     *
     * @param int $customerId
     * @param int $tenantId
     * @param int $businessId
     * @return array  List of items (name, sku, type_name, sale_price)
     */
    public function getCustomerSpecificItems(int $customerId, int $tenantId, int $businessId): array
    {
        $stmt = $this->db->prepare("
            SELECT i.id, i.name, i.sku, i.unit,
                   i.cost_price, i.sale_price, i.is_active, i.notes,
                   i.business_id, i.customer_id, i.has_expiry,
                   it.name AS type_name,
                   COALESCE(s.quantity, 0) AS stock_qty
            FROM items i
            JOIN item_types it ON it.id = i.item_type_id
            LEFT JOIN stock s ON s.item_id = i.id AND s.business_id = :business1
            WHERE i.tenant_id = :tenant
              AND (i.business_id = :business2 OR i.business_id IS NULL)
              AND i.is_active = 1
              AND i.customer_id = :customer_id
            ORDER BY i.name
        ");
        $stmt->execute([
            'tenant'      => $tenantId,
            'business1'   => $businessId,
            'business2'   => $businessId,
            'customer_id' => $customerId,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── HELPERS ───────────────────────────────────────────────────────────────

    /**
     * Check if a table exists in the database.
     */
    private function tableExists(string $table): bool
    {
        try {
            $stmt = $this->db->query("SELECT 1 FROM `{$table}` LIMIT 0");
            return $stmt !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ── COST PRICE UPDATE ─────────────────────────────────────────────────────

    /**
     * Update only the cost_price and updated_at for an item.
     *
     * @param int   $itemId
     * @param int   $tenantId
     * @param float $costPrice
     */
    public function updateCostPrice(int $itemId, int $tenantId, float $costPrice): void
    {
        $stmt = $this->db->prepare("
            UPDATE items
            SET cost_price = :cost_price, updated_at = NOW()
            WHERE id = :id AND tenant_id = :tenant_id
        ");
        $stmt->execute([
            'cost_price' => $costPrice,
            'id'         => $itemId,
            'tenant_id'  => $tenantId,
        ]);
    }
}
