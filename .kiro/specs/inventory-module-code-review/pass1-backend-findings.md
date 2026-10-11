# Pass 1: Items/Stock — Backend Findings

## Summary

| Severity | Count |
|----------|-------|
| Critical | 1 |
| High | 5 |
| Medium | 8 |
| Low | 4 |
| **Total** | **18** |

| Category | Count |
|----------|-------|
| SEC | 3 |
| BUG | 5 |
| PERF | 3 |
| QUAL | 4 |
| VAL | 3 |

---

## Findings

### P1-SEC-001: Missing Permission Checks on Price History and Batch Routes

**File:** `public/api/routes/items.php`
**Line(s):** 82–84
**Severity:** High
**Category:** SEC

**Description:**
Three route definitions lack the permission array parameter, meaning they bypass the router's authorization enforcement entirely. Any authenticated user can access price history and batch data regardless of their role permissions.

**Current Code:**
```php
$router->get('/items/{id}/price-history', fn($id) => (new ItemsController())->priceHistory((int)$id));
$router->get('/items/{id}/batches', fn($id) => (new ItemsController())->batches((int)$id));
$router->get('/items/{id}/batch-transactions', fn($id) => (new ItemsController())->batchTransactions((int)$id));
```

**Recommended Fix:**
```php
$router->get('/items/{id}/price-history', fn($id) => (new ItemsController())->priceHistory((int)$id), ['items.view']);
$router->get('/items/{id}/batches', fn($id) => (new ItemsController())->batches((int)$id), ['items.view']);
$router->get('/items/{id}/batch-transactions', fn($id) => (new ItemsController())->batchTransactions((int)$id), ['items.view']);
```

---

### P1-SEC-002: PendingPricesController Does Not Extend BaseController — Missing Pattern Consistency and Potential Auth Gap

**File:** `src/Controllers/PendingPricesController.php`
**Line(s):** 9–11
**Severity:** Medium
**Category:** SEC

**Description:**
`PendingPricesController` does not extend `BaseController` and uses `AuthContext::get()` directly plus `Response::json()` instead of `$this->json()`. While it still retrieves the auth context, it deviates from the project's controller pattern. It also passes only `tenant_id` to `applyPendingPrices()` and `cancelPendingPrices()` without `business_id` scoping, meaning a user in one business could potentially approve/cancel pending prices for another business within the same tenant.

**Current Code:**
```php
class PendingPricesController {
    // ...
    public function apply(): void {
        $ctx = AuthContext::get();
        // ...
        $count = $this->service->applyPendingPrices($ids, $ctx->tenant_id, $ctx->user_id);
    }
}
```

**Recommended Fix:**
```php
class PendingPricesController extends BaseController {
    // ...
    public function apply(): void {
        $ctx = $this->auth();
        // ...
        $count = $this->service->applyPendingPrices($ids, $ctx->tenant_id, $ctx->business_id, $ctx->user_id);
    }
}
```

**Notes:** The `PendingPriceService::applyPendingPrices()` method should also accept and enforce `business_id` in its WHERE clause when selecting pending prices to apply.

---

### P1-SEC-003: PendingPriceService apply/cancel Missing business_id Scoping

**File:** `src/Services/PendingPriceService.php`
**Line(s):** 38–49
**Severity:** High
**Category:** SEC

**Description:**
`applyPendingPrices()` selects pending prices filtered only by `tenant_id` and IDs. A user in Business A could approve pending prices belonging to Business B (same tenant) by guessing or knowing the IDs. The `cancelPendingPrices()` method has the same issue. This is a cross-business authorization bypass within a tenant.

**Current Code:**
```php
$stmt = $this->db->prepare("
    SELECT id, item_id, pending_sale_price 
    FROM item_pending_prices 
    WHERE tenant_id = ? AND status = 'PENDING' AND id IN ($placeholders)
");
$stmt->execute($params);
```

**Recommended Fix:**
```php
public function applyPendingPrices(array $pendingPriceIds, int $tenantId, int $businessId, int $userId): int {
    // ...
    $params = array_merge([$tenantId, $businessId], $pendingPriceIds);
    $stmt = $this->db->prepare("
        SELECT id, item_id, pending_sale_price 
        FROM item_pending_prices 
        WHERE tenant_id = ? AND business_id = ? AND status = 'PENDING' AND id IN ($placeholders)
    ");
    $stmt->execute($params);
    // ...
}
```

---

### P1-BUG-001: Race Condition — Stock Adjustment Performs Business Logic After Stock Already Committed

**File:** `src/Controllers/ItemsController.php`
**Line(s):** 215–244
**Severity:** Critical
**Category:** BUG

**Description:**
In `adjustStock()`, the controller calls `$this->service->adjustStock()` first (which commits the stock change), and THEN checks if the item has expiry tracking and blocks `ADJ` type. This means:
1. The stock adjustment is already committed to the database before the validation check occurs.
2. For batch-tracked items with `type === 'ADJ'`, the stock is modified but the error response is returned — the database is now in an inconsistent state (stock changed but no batch was updated).
3. The `IN` + expiry batch creation in the controller duplicates logic already handled inside `ItemService::adjustStock()`, potentially creating double batches.

**Current Code:**
```php
$this->service->adjustStock($id, $type, $quantity, $data['reference'] ?? null, $data['notes'] ?? null);

$db = \App\Config\Database::getConnection();
// Check if item tracks expiry
$itemStmt = $db->prepare("SELECT has_expiry FROM items WHERE id = ? LIMIT 1");
$itemStmt->execute([$id]);
$hasExpiry = (int) $itemStmt->fetchColumn();

// ADJ is not allowed on batch-tracked items
if ($hasExpiry === 1 && $type === 'ADJ') {
    $this->json(false, null, 'Cannot use absolute adjustment on batch-tracked items...', 422);
    return;
}
```

**Recommended Fix:**
```php
public function adjustStock(int $id): void {
    try {
        $ctx = $this->auth();
        if ($ctx->business_id === null) {
            $this->json(false, null, 'Active business context required', 422);
            return;
        }
        $data = $this->input(['type', 'quantity']);
        $type = strtoupper($data['type']);
        $quantity = (float) $data['quantity'];

        // All validation and batch logic should be handled INSIDE the service
        $this->service->adjustStock(
            $id, $type, $quantity,
            $data['reference'] ?? null,
            $data['notes'] ?? null,
            $data['expiry_date'] ?? null,
            $data['batch_number'] ?? null,
            $data['manufacturing_date'] ?? null
        );

        $this->json(true, ['item_id' => $id]);
    } catch (\InvalidArgumentException | \RuntimeException $e) {
        $this->json(false, null, $e->getMessage(), 422);
    } catch (\Throwable $e) {
        $this->serverError('Failed to adjust stock');
    }
}
```

**Notes:** The `has_expiry` + `ADJ` validation should be moved into `ItemService::adjustStock()` BEFORE any data modifications. The batch creation logic in the controller (lines 230–244) should be removed since the service already handles it.

---

### P1-BUG-002: Duplicate business_id Null Check in Every Controller Method

**File:** `src/Controllers/ItemsController.php`
**Line(s):** Throughout (e.g., 23–27, 36–40, etc.)
**Severity:** Low
**Category:** QUAL

**Description:**
Every method in `ItemsController` contains an identical `business_id === null` check duplicated twice in succession. This is dead code — the second check can never reach a different outcome than the first.

**Current Code:**
```php
$ctx = $this->auth();
if ($ctx->business_id === null) {
    $this->json(false, null, 'Active business context required', 422);
    return;
}
if ($ctx->business_id === null) {
    $this->json(false, null, 'Active business context required', 422);
    return;
}
```

**Recommended Fix:**
```php
$ctx = $this->auth();
if ($ctx->business_id === null) {
    $this->json(false, null, 'Active business context required', 422);
    return;
}
```

---

### P1-BUG-003: StockValidator Does Not Handle Concurrent Requests — Non-Atomic Stock Check

**File:** `src/Services/StockValidator.php`
**Line(s):** 31–41
**Severity:** High
**Category:** BUG

**Description:**
`assertSufficientStock()` performs a SELECT to check stock availability, but between the SELECT and the subsequent UPDATE (in `ItemService::adjustStock()`), another concurrent request could modify the stock. This is a classic TOCTOU (time-of-check-to-time-of-use) race condition. The stock validation should use `SELECT ... FOR UPDATE` within the transaction to lock the row.

**Current Code:**
```php
public function assertSufficientStock(int $itemId, int $businessId, float $quantity): void {
    $stock = $this->itemRepo->getStockEntry($itemId, $businessId);
    $current = $stock ? (float) $stock['quantity'] : 0.0;
    if ($current < $quantity) {
        // throw
    }
}
```

**Recommended Fix:**
```php
public function assertSufficientStock(int $itemId, int $businessId, float $quantity): void {
    // Use FOR UPDATE to lock the row within the caller's transaction
    $stmt = $this->db->prepare(
        "SELECT quantity FROM stock WHERE item_id = :item AND business_id = :business LIMIT 1 FOR UPDATE"
    );
    $stmt->execute(['item' => $itemId, 'business' => $businessId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    $current = $row ? (float) $row['quantity'] : 0.0;
    if ($current < $quantity) {
        $item = $this->itemRepo->getById($itemId, AuthContext::tenantId());
        $name = $item['name'] ?? "Item #{$itemId}";
        throw new \RuntimeException(
            "Insufficient stock for '{$name}': available {$current}, requested {$quantity}"
        );
    }
}
```

**Notes:** This requires the caller (`ItemService::adjustStock`) to begin the transaction BEFORE calling `assertSufficientStock()`. Currently the transaction begins after validation, which defeats the purpose of the lock.

---

### P1-BUG-004: PriceUpdater Floating-Point Comparison May Miss Small Price Changes

**File:** `src/Services/PriceUpdater.php`
**Line(s):** 83
**Severity:** Medium
**Category:** BUG

**Description:**
The comparison `abs($oldPrice - $newPrice) < 0.001` uses a fixed epsilon of 0.001. For currencies with 2 decimal places, this means any price change of less than 0.001 is ignored, which is fine. However, for currencies with more precision (e.g., crypto or commodities with 4+ decimal places), legitimate price changes could be silently dropped. Additionally, the pending price logic in `ItemService::update()` uses strict equality (`$newSale !== $currentSale`) for the float comparison, which is inconsistent with PriceUpdater's epsilon approach.

**Current Code:**
```php
if ($oldPrice !== null && abs($oldPrice - $newPrice) < 0.001) {
    continue;
}
```

**Recommended Fix:**
```php
// Use bccomp for exact decimal comparison at 2 decimal places (standard currency precision)
if ($oldPrice !== null && bccomp((string)$oldPrice, (string)$newPrice, 2) === 0) {
    continue;
}
```

---

### P1-BUG-005: indexForPayee Bypasses Business-Scoped Payee Check

**File:** `src/Controllers/ItemsController.php`
**Line(s):** 114–128
**Severity:** Medium
**Category:** BUG

**Description:**
The `indexForPayee()` method queries the `payees` table by ID without scoping to the current business. A user could pass a `payeeId` from a different business and the query would still resolve a `people_id`, potentially filtering items incorrectly. The direct DB query in the controller also bypasses the service layer.

**Current Code:**
```php
$stmt = $db->prepare("SELECT people_id FROM payees WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $payeeId]);
$peopleId = $stmt->fetchColumn();
```

**Recommended Fix:**
```php
$stmt = $db->prepare("SELECT people_id FROM payees WHERE id = :id AND business_id = :business_id LIMIT 1");
$stmt->execute(['id' => $payeeId, 'business_id' => $ctx->business_id]);
$peopleId = $stmt->fetchColumn();
```

---

### P1-PERF-001: N+1 Pattern in PriceUpdater — ManufacturingService Instantiated Per Loop Iteration

**File:** `src/Services/PriceUpdater.php`
**Line(s):** 131–132
**Severity:** Medium
**Category:** PERF

**Description:**
Inside the `foreach ($uniqueLines ...)` loop, a new `ManufacturingService` is instantiated on every iteration. While PHP garbage collects, this creates unnecessary overhead. More importantly, `recalculateAffectedRecipes()` likely runs queries for each item, creating an N+1 pattern when many PO lines are processed.

**Current Code:**
```php
foreach ($uniqueLines as $itemId => $line) {
    // ... processing ...
    $manufacturingService = new ManufacturingService($this->db);
    $manufacturingService->recalculateAffectedRecipes((int) $itemId);
}
```

**Recommended Fix:**
```php
// Instantiate once before the loop
$manufacturingService = new ManufacturingService($this->db);

foreach ($uniqueLines as $itemId => $line) {
    // ... processing ...
    $manufacturingService->recalculateAffectedRecipes((int) $itemId);
}
```

---

### P1-PERF-002: Correlated Subquery in Item List Executes Per Row

**File:** `src/Repository/ItemRepository.php`
**Line(s):** 92 (within `getAllForBusiness`)
**Severity:** High
**Category:** PERF

**Description:**
The `getAllForBusiness()` query includes a correlated subquery `(SELECT COALESCE(SUM(sid.quantity), 0) FROM si_details sid JOIN sales_invoices si ...)` that calculates `total_sold` for every item in the result set. This executes once per row, creating an O(N) subquery execution pattern. For businesses with hundreds of items, this significantly impacts query time.

**Current Code:**
```php
(SELECT COALESCE(SUM(sid.quantity), 0) FROM si_details sid JOIN sales_invoices si ON si.id = sid.sales_invoice_id WHERE sid.item_id = i.id AND si.status IN ('DELIVERED', 'PAID')) AS total_sold
```

**Recommended Fix:**
```php
// Replace correlated subquery with a LEFT JOIN aggregate
LEFT JOIN (
    SELECT sid.item_id, COALESCE(SUM(sid.quantity), 0) AS total_sold
    FROM si_details sid
    JOIN sales_invoices si ON si.id = sid.sales_invoice_id
    WHERE si.status IN ('DELIVERED', 'PAID') AND si.business_id = :business3
    GROUP BY sid.item_id
) sold ON sold.item_id = i.id
```

**Notes:** The same pattern applies to the `costFlagJoin` subquery which already uses a derived table approach — the `total_sold` query should be refactored to match.

---

### P1-PERF-003: getAllForBusiness Over-Fetches Data for List Views

**File:** `src/Repository/ItemRepository.php`
**Line(s):** 80–100
**Severity:** Medium
**Category:** PERF

**Description:**
The item list endpoint fetches `total_sold` (via expensive correlated subquery), cost change flag data with a complex JOIN, and customer name for every item. Many list views (e.g., invoice item selectors, PO line pickers) only need id, name, sku, unit, price, and stock quantity. There's no option to fetch a lightweight result set for dropdown/selector use cases.

**Current Code:**
```php
// One method serves all consumers: full list view, item selectors, PO forms, etc.
public function getAllForBusiness(int $tenantId, int $businessId, array $excludeTypes = [], bool $includeInactive = false): array {
    // always includes total_sold, cost flag, customer_name JOINs
}
```

**Recommended Fix:**
Add a lightweight `getItemsForSelector()` method for dropdown/picker use cases:
```php
public function getItemsForSelector(int $tenantId, int $businessId, array $excludeTypes = []): array {
    // Only: id, name, sku, unit, cost_price, sale_price, type_name, stock_qty
    // No total_sold, no cost flag, no customer_name
}
```

---

### P1-VAL-001: Missing Validation on IDs Array in PendingPricesController

**File:** `src/Controllers/PendingPricesController.php`
**Line(s):** 24–28
**Severity:** Medium
**Category:** VAL

**Description:**
The `apply()` and `cancel()` methods validate that `$ids` is a non-empty array, but do not validate that each element is a positive integer. Non-numeric or negative values passed in the array would reach the SQL query. While parameterized queries prevent injection, invalid values could cause unexpected behavior or database errors.

**Current Code:**
```php
$ids = $data['ids'] ?? [];
if (!is_array($ids) || empty($ids)) {
    Response::json(['success' => false, 'message' => 'No IDs provided'], 400);
    return;
}
```

**Recommended Fix:**
```php
$ids = $data['ids'] ?? [];
if (!is_array($ids) || empty($ids)) {
    Response::json(['success' => false, 'message' => 'No IDs provided'], 400);
    return;
}
// Validate each ID is a positive integer
$ids = array_filter(array_map('intval', $ids), fn($id) => $id > 0);
if (empty($ids)) {
    Response::json(['success' => false, 'message' => 'Invalid IDs provided'], 400);
    return;
}
```

---

### P1-VAL-002: StockValidator Does Not Validate Zero or Excessively Large Quantities

**File:** `src/Services/StockValidator.php`
**Line(s):** 31–41
**Severity:** Medium
**Category:** VAL

**Description:**
`StockValidator::assertSufficientStock()` and `assertAvailable()` check if available stock is less than the requested quantity, but do not validate that the requested quantity itself is positive and within reasonable bounds. The validation for positive quantity exists in `ItemService::adjustStock()` but is absent from the validator methods that other callers (e.g., ManufacturingService) may invoke directly.

**Current Code:**
```php
public function assertSufficientStock(int $itemId, int $businessId, float $quantity): void {
    $stock = $this->itemRepo->getStockEntry($itemId, $businessId);
    $current = $stock ? (float) $stock['quantity'] : 0.0;
    if ($current < $quantity) {
        // throw
    }
}
```

**Recommended Fix:**
```php
public function assertSufficientStock(int $itemId, int $businessId, float $quantity): void {
    if ($quantity <= 0) {
        throw new \InvalidArgumentException('Quantity must be greater than zero');
    }
    if ($quantity > 999999999) {
        throw new \InvalidArgumentException('Quantity exceeds maximum allowed value');
    }
    $stock = $this->itemRepo->getStockEntry($itemId, $businessId);
    $current = $stock ? (float) $stock['quantity'] : 0.0;
    if ($current < $quantity) {
        // throw
    }
}
```

---

### P1-VAL-003: PriceUpdater Does Not Validate Against Negative or Zero Prices

**File:** `src/Services/PriceUpdater.php`
**Line(s):** 73–85
**Severity:** Medium
**Category:** VAL

**Description:**
`updatePricesFromPO()` calculates `$newPrice` by applying line and header discounts but never validates that the result is positive. If a PO has a 100%+ header discount (data error) or a line with negative `total_price_bc`, the calculated `$newPrice` could be zero or negative, and this would be saved directly as the item's cost price.

**Current Code:**
```php
$newPrice = round($effectiveLinePrice * $poMultiplier, 2);
$oldPrice = $item['cost_price'] !== null ? (float) $item['cost_price'] : null;

// Only update if price differs or current is NULL
if ($oldPrice !== null && abs($oldPrice - $newPrice) < 0.001) {
    continue;
}

// Update item cost price
$this->itemRepo->updateCostPrice((int) $itemId, $tenantId, $newPrice);
```

**Recommended Fix:**
```php
$newPrice = round($effectiveLinePrice * $poMultiplier, 2);

// Guard against invalid calculated prices
if ($newPrice <= 0) {
    error_log("[PriceUpdater] Skipping non-positive calculated price {$newPrice} for item {$itemId} from PO {$poId}");
    continue;
}

$oldPrice = $item['cost_price'] !== null ? (float) $item['cost_price'] : null;

if ($oldPrice !== null && abs($oldPrice - $newPrice) < 0.001) {
    continue;
}

$this->itemRepo->updateCostPrice((int) $itemId, $tenantId, $newPrice);
```

---

### P1-QUAL-001: Dead Code — Duplicate `insertType` / `updateType` / `deleteType` Methods

**File:** `src/Repository/ItemRepository.php`
**Line(s):** 30–68
**Severity:** Low
**Category:** QUAL

**Description:**
`ItemRepository` contains `insertType()`, `updateType()`, `deleteType()`, and `countItemsForType()` methods that duplicate functionality already provided by `ItemTypeRepository`. The `ItemService` calls `ItemRepository::insertType()` for creation but `ItemTypeRepository` for update/delete, creating an inconsistent pattern. The duplicated methods in `ItemRepository` appear to be leftovers from before `ItemTypeRepository` was extracted.

**Current Code:**
```php
// In ItemRepository - duplicates of ItemTypeRepository methods
public function insertType(...) { ... }
public function updateType(...) { ... }
public function deleteType(...) { ... }
public function countItemsForType(...) { ... }
```

**Recommended Fix:**
Remove `insertType()`, `updateType()`, `deleteType()`, and `countItemsForType()` from `ItemRepository`. Update `ItemService::createType()` to use `ItemTypeRepository::create()` instead.

---

### P1-QUAL-002: Controller Performs Raw DB Queries — Violates Service Layer Pattern

**File:** `src/Controllers/ItemsController.php`
**Line(s):** 119–125, 219–244
**Severity:** Medium
**Category:** QUAL

**Description:**
The `indexForPayee()` method directly accesses `Database::getConnection()` and runs SQL queries in the controller, bypassing the service layer. Similarly, `adjustStock()` performs post-adjustment batch operations directly via raw DB queries. This violates the Controller → Service → Repository architecture documented in the project conventions.

**Current Code:**
```php
// In indexForPayee():
$db = \App\Config\Database::getConnection();
$stmt = $db->prepare("SELECT people_id FROM payees WHERE id = :id LIMIT 1");

// In adjustStock():
$db = \App\Config\Database::getConnection();
$itemStmt = $db->prepare("SELECT has_expiry FROM items WHERE id = ? LIMIT 1");
```

**Recommended Fix:**
Move all database access into the service layer. The controller should only call service methods:
```php
public function indexForPayee(int $payeeId): void {
    // ...
    $items = $this->service->listForPayee($payeeId);
    $this->json(true, ['items' => $items]);
}
```

---

### P1-QUAL-003: ItemVisibilityService Unused Dependency — PeopleRepository

**File:** `src/Services/ItemVisibilityService.php`
**Line(s):** 11
**Severity:** Low
**Category:** QUAL

**Description:**
`ItemVisibilityService` imports and instantiates `PeopleRepository` and uses it for `validateCustomerForItem()`. However, the `hasCustomerPayeeType()` private method bypasses `PeopleRepository` and queries the database directly via `$this->db`. This is an inconsistency — either the payee type check should be in `PeopleRepository` or the repository should be removed in favor of direct queries.

**Current Code:**
```php
private function hasCustomerPayeeType(int $payeeId): bool {
    $stmt = $this->db->prepare("
        SELECT 1
        FROM people_types ptp
        JOIN payees py ON py.people_id = ptp.people_id
        ...
    ");
}
```

**Recommended Fix:**
Move `hasCustomerPayeeType()` logic into `PeopleRepository` as a public method, and call it via `$this->peopleRepo->hasCustomerPayeeType($payeeId)`.

---

### P1-QUAL-004: tableExists() Method Is Dead Code

**File:** `src/Repository/ItemRepository.php`
**Line(s):** 264–272
**Severity:** Low
**Category:** QUAL

**Description:**
The `tableExists()` private method is never called anywhere in `ItemRepository`. It appears to be leftover debug/development code.

**Current Code:**
```php
private function tableExists(string $table): bool
{
    try {
        $stmt = $this->db->query("SELECT 1 FROM `{$table}` LIMIT 0");
        return $stmt !== false;
    } catch (\Throwable $e) {
        return false;
    }
}
```

**Recommended Fix:**
Remove the method entirely.

**Notes:** The method also has a minor SQL injection vector since `$table` is interpolated directly. Since it's dead code, removal is the correct fix.

---

### P1-BUG-006: ItemService update() Pending Price Logic Runs Outside Transaction

**File:** `src/Services/ItemService.php`
**Line(s):** 208–232
**Severity:** High
**Category:** BUG

**Description:**
In `ItemService::update()`, the `$this->db->commit()` happens at line ~207, and then the pending price insertion logic (lines 208–232) runs OUTSIDE the transaction. If the pending price INSERT fails, the item's cost_price has already been committed but the corresponding pending sale price record is lost. Additionally, `ManufacturingService::recalculateAffectedRecipes()` runs outside the transaction, so recipe cost recalculations could fail independently without rolling back the item update.

**Current Code:**
```php
$this->db->commit();
// ... (outside transaction)
if ($costPriceChanged) {
    $manufacturingService = new ManufacturingService($this->db);
    $manufacturingService->recalculateAffectedRecipes($id);
    if (!$salePriceChanged) {
        // ... INSERT INTO item_pending_prices ...
    }
}
```

**Recommended Fix:**
Move the pending price logic inside the transaction block, or use a separate try/catch with explicit error logging for the post-commit operations:
```php
$this->db->beginTransaction();
try {
    // ... existing update logic ...
    
    // Pending price queue (inside transaction)
    if ($costPriceChanged && !$salePriceChanged) {
        $updatedItem = $this->repo->getById($id, $ctx->tenant_id);
        if ($updatedItem && (int)($updatedItem['markup_enabled'] ?? 0) === 1) {
            // ... insert pending price ...
        }
    }
    
    $this->db->commit();
} catch (\Throwable $e) {
    $this->db->rollBack();
    throw $e;
}

// Post-commit side effects (non-critical)
if ($costPriceChanged) {
    try {
        $manufacturingService = new ManufacturingService($this->db);
        $manufacturingService->recalculateAffectedRecipes($id);
    } catch (\Throwable $e) {
        error_log("[ItemService::update] Recipe recalculation failed: " . $e->getMessage());
    }
}
```

---

### P1-BUG-007: Insights Date Params Passed Unsanitized to SQL

**File:** `src/Controllers/ItemsController.php`
**Line(s):** 156–158
**Severity:** Low
**Category:** VAL

**Description:**
The `getInsights()` method passes `$_GET['start_date']` and `$_GET['end_date']` directly to the service without validating they are valid date strings. While they're bound as parameters (preventing SQL injection), invalid date values could cause MySQL comparison issues or return incorrect results silently.

**Current Code:**
```php
$startDate = $_GET['start_date'] ?? null;
$endDate = $_GET['end_date'] ?? null;
$insights = $this->service->getInsights($id, $startDate, $endDate);
```

**Recommended Fix:**
```php
$startDate = $_GET['start_date'] ?? null;
$endDate = $_GET['end_date'] ?? null;

// Validate date format
if ($startDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
    $this->json(false, null, 'Invalid start_date format (expected YYYY-MM-DD)', 422);
    return;
}
if ($endDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    $this->json(false, null, 'Invalid end_date format (expected YYYY-MM-DD)', 422);
    return;
}

$insights = $this->service->getInsights($id, $startDate, $endDate);
```

---

## Files Inspected — No Additional Findings

The following files were fully inspected and had no issues beyond those documented above:

- **`src/Services/PriceHistoryService.php`** — Clean implementation. Proper validation of price types. Uses repository pattern correctly. No SQL injection, proper parameterization.
- **`src/Repository/ItemPriceRepository.php`** — All queries use parameterized bindings. Business-scoped correctly.
- **`src/Repository/ItemPriceHistoryRepository.php`** — Properly parameterized, tenant-scoped queries. Clean implementation.
- **`src/Repository/SupplierItemPriceRepository.php`** — All queries use parameterized bindings with tenant scoping. The `setPreferred()` method correctly uses transactions for atomicity.
- **`src/Repository/ItemTypeRepository.php`** — System type protection correctly enforced. Parameterized queries throughout.

---

## Traceability Matrix

| Requirement | Findings |
|-------------|----------|
| 1.1 SQL injection in controllers | No SQL injection found — all controller queries use parameterized bindings |
| 1.2 Route permission checks | P1-SEC-001 |
| 1.3 Tenant/business scoping in services | P1-SEC-002, P1-SEC-003 |
| 1.5 Dynamic WHERE uses bindings | No issues — repositories use named/positional parameters |
| 2.1 StockValidator concurrency | P1-BUG-003 |
| 2.2 ItemService field assignments | P1-BUG-006 |
| 2.3 PriceUpdater precision | P1-BUG-004 |
| 2.4 PendingPrice approval flow | P1-SEC-003 (business_id gap affects correctness) |
| 2.6 ItemVisibilityService | No issues — correctly filters by module activation status |
| 3.1 N+1 queries | P1-PERF-001, P1-PERF-002 |
| 3.2 Index usage | No issues identified at code level (requires DB EXPLAIN analysis) |
| 3.4 Over-fetching | P1-PERF-003 |
| 4.1 Dead code | P1-BUG-002, P1-QUAL-001, P1-QUAL-004 |
| 4.2 Error handling patterns | P1-SEC-002 (pattern deviation) |
| 5.1 Numeric validation | P1-VAL-001 |
| 5.2 Required field validation | No issues — ItemService validates name and item_type_id |
| 5.3 StockValidator zero/large values | P1-VAL-002 |
| 5.5 PriceUpdater price validation | P1-VAL-003 |
