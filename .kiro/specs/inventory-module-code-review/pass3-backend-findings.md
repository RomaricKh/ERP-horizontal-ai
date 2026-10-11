# Pass 3 — Manufacturing Sub-Module Backend Findings

## Summary

| Severity | Count |
|----------|-------|
| Critical | 1     |
| High     | 3     |
| Medium   | 6     |
| Low      | 3     |
| **Total** | **13** |

| Category | Count |
|----------|-------|
| SEC      | 4     |
| BUG      | 3     |
| PERF     | 2     |
| QUAL     | 3     |
| VAL      | 1     |

---

## Findings

### P3-SEC-001: Information Disclosure in storeOrder Error Handler

**File:** `src/Controllers/ManufacturingController.php`
**Line(s):** 152
**Severity:** Critical
**Category:** SEC

**Description:**
The `storeOrder` method's catch-all `\Throwable` handler exposes the internal exception message, file path, and line number directly in the API response. This leaks server filesystem structure and internal implementation details to any authenticated user, which can aid attackers in crafting targeted exploits.

**Current Code:**
```php
} catch (\Throwable $e) {
    $this->serverError($e->getMessage() . ' ' . $e->getFile() . ':' . $e->getLine());
}
```

**Recommended Fix:**
```php
} catch (\Throwable $e) {
    $this->logError('Manufacturing', 'storeOrder', $e);
    $this->serverError('Failed to create production order');
}
```

**Notes:** This is the only endpoint in ManufacturingController that leaks internal details. All other handlers correctly use generic messages. The `logError()` method from `BaseController` should be used to record the details server-side.

---

### P3-SEC-002: Unvalidated Date Filter Parameters Passed to SQL

**File:** `src/Controllers/ManufacturingController.php`
**Line(s):** 109–114
**Severity:** Medium
**Category:** SEC

**Description:**
The `indexOrders` method passes `$_GET['start_date']` and `$_GET['end_date']` directly to the service/repository without validating that they are well-formed date strings. While these values are used in parameterized queries (not injectable), a malformed string like `"not-a-date"` will silently produce unexpected query results rather than returning a validation error. This could cause confusing behavior or allow probe-based reconnaissance of database behavior.

**Current Code:**
```php
if (!empty($_GET['start_date'])) {
    $filters['start_date'] = $_GET['start_date'];
}
if (!empty($_GET['end_date'])) {
    $filters['end_date'] = $_GET['end_date'];
}
```

**Recommended Fix:**
```php
if (!empty($_GET['start_date'])) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['start_date'])) {
        $this->json(false, null, 'Invalid start_date format (expected YYYY-MM-DD)', 422);
        return;
    }
    $filters['start_date'] = $_GET['start_date'];
}
if (!empty($_GET['end_date'])) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['end_date'])) {
        $this->json(false, null, 'Invalid end_date format (expected YYYY-MM-DD)', 422);
        return;
    }
    $filters['end_date'] = $_GET['end_date'];
}
```

---

### P3-SEC-003: updateProductionOrderStatus Lacks Tenant/Business Scoping

**File:** `src/Repository/ManufacturingRepository.php`
**Line(s):** 431–438
**Severity:** High
**Category:** SEC

**Description:**
The `updateProductionOrderStatus` method updates a production order by `id` alone without scoping to `tenant_id` or `business_id`. While the calling code in `reverseProductionOrder` fetches and validates ownership before calling this method, the repository method itself could be misused by future code paths. All other write methods in this repository (e.g., `updateRecipe`, `deleteRecipe`) include tenant scoping in the WHERE clause. This is a defense-in-depth gap.

**Current Code:**
```php
public function updateProductionOrderStatus(int $id, string $status): void {
    $stmt = $this->db->prepare("
        UPDATE production_orders
        SET status = :status
        WHERE id = :id
    ");
    $stmt->execute([
        'status' => $status,
        'id'     => $id,
    ]);
}
```

**Recommended Fix:**
```php
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
```

**Notes:** The caller in `ManufacturingService::reverseProductionOrder` would need to pass `$ctx->tenant_id` and `$ctx->business_id` to the updated method signature.

---

### P3-SEC-004: dismissCostChangeFlag and setAutoMarkupConfig Lack Ownership Validation

**File:** `src/Services/ManufacturingService.php`
**Line(s):** 891–893 (dismissCostChangeFlag), 901–903 (setAutoMarkupConfig)
**Severity:** High
**Category:** SEC

**Description:**
Both `dismissCostChangeFlag` and `setAutoMarkupConfig` in the service layer directly call the repository without verifying that the `$itemId` belongs to the current tenant/business. An authenticated user with manufacturing permissions could manipulate any item's cost flag or markup configuration across all tenants by guessing item IDs.

**Current Code:**
```php
public function dismissCostChangeFlag(int $itemId): void {
    $this->repo->dismissCostChangeFlag($itemId);
}

public function setAutoMarkupConfig(int $itemId, float $markupPercent): void {
    $this->repo->setAutoMarkupConfig($itemId, $markupPercent);
}
```

**Recommended Fix:**
```php
public function dismissCostChangeFlag(int $itemId): void {
    $ctx = AuthContext::get();
    $item = $this->itemRepo->getById($itemId, $ctx->tenant_id);
    if (!$item || ((int) ($item['business_id'] ?? 0) !== $ctx->business_id && $item['business_id'] !== null)) {
        throw new \RuntimeException('Item not found');
    }
    $this->repo->dismissCostChangeFlag($itemId);
}

public function setAutoMarkupConfig(int $itemId, float $markupPercent): void {
    $ctx = AuthContext::get();
    $item = $this->itemRepo->getById($itemId, $ctx->tenant_id);
    if (!$item || ((int) ($item['business_id'] ?? 0) !== $ctx->business_id && $item['business_id'] !== null)) {
        throw new \RuntimeException('Item not found');
    }
    $this->repo->setAutoMarkupConfig($itemId, $markupPercent);
}
```

---

### P3-BUG-001: Production Order Has No Draft/In-Progress Status Transitions

**File:** `src/Services/ManufacturingService.php`
**Line(s):** 361–550 (createProductionOrder)
**Severity:** Medium
**Category:** BUG

**Description:**
Requirement 11.3 specifies that production orders should follow a status transition model (Draft → In Progress → Completed/Cancelled) with invalid transitions rejected. However, the current implementation creates production orders with status `'COMPLETED'` immediately upon creation — there is no draft or in-progress state. The only other transition is `COMPLETED → REVERSED`. This is a design simplification that skips the planned state machine entirely. While functional for the current "instant produce" workflow, it does not match the specified behavior and prevents future workflow flexibility.

**Current Code:**
```php
$orderId = $this->repo->insertProductionOrder([
    // ...
    'status' => 'COMPLETED',
    // ...
]);
```

**Recommended Fix:**
This is an architectural decision. If the "instant completion" model is intentional (production orders are always one-step), document this explicitly. If the multi-step workflow is desired, implement:
1. `createProductionOrder()` → status = `'DRAFT'`
2. `startProductionOrder($id)` → transitions DRAFT → IN_PROGRESS
3. `completeProductionOrder($id)` → transitions IN_PROGRESS → COMPLETED (does stock moves)
4. `cancelProductionOrder($id)` → transitions DRAFT/IN_PROGRESS → CANCELLED

**Notes:** The current approach is a valid simplification for small manufacturers but deviates from Requirement 11.3.

---

### P3-BUG-002: Component Type Validation Uses RAW_MATERIAL + CONSUMABLE Instead of RAW_MATERIAL + TRADING

**File:** `src/Services/ManufacturingService.php`
**Line(s):** 975
**Severity:** Medium
**Category:** BUG

**Description:**
Requirement 11.2 states that "only valid component types (Raw Material, Trading) are allowed as recipe ingredients." However, the code validates against `['RAW_MATERIAL', 'CONSUMABLE']` instead. This means Trading items cannot be used as recipe components (incorrectly rejected), while Consumable items can be used (possibly unintended). This may be a conscious design deviation, but it contradicts the stated requirement.

**Current Code:**
```php
$allowedComponentCodes = ['RAW_MATERIAL', 'CONSUMABLE'];
if (!in_array($type['code'], $allowedComponentCodes, true)) {
    throw new \InvalidArgumentException(
        "Item '{$item['name']}' (type: {$type['name']}) cannot be used as a recipe component. " .
        "Only Raw Material and Consumable items are allowed."
    );
}
```

**Recommended Fix:**
Align with Requirement 11.2:
```php
$allowedComponentCodes = ['RAW_MATERIAL', 'TRADING'];
if (!in_array($type['code'], $allowedComponentCodes, true)) {
    throw new \InvalidArgumentException(
        "Item '{$item['name']}' (type: {$type['name']}) cannot be used as a recipe component. " .
        "Only Raw Material and Trading items are allowed."
    );
}
```

**Notes:** If the intent is to allow both TRADING and CONSUMABLE, the allowed list should be `['RAW_MATERIAL', 'TRADING', 'CONSUMABLE']` and the requirement should be updated. Clarification from the product owner is needed.

---

### P3-BUG-003: reverseProductionOrder Does Not Reverse Batch Records

**File:** `src/Services/ManufacturingService.php`
**Line(s):** 688–749
**Severity:** High
**Category:** BUG

**Description:**
When a production order is reversed, the code restores component stock and deducts the manufactured item's stock, but it does not deactivate or zero-out the `item_batches` record that was created during production (if the manufactured item tracks expiry). This means the batch record remains `status = 'active'` with its original quantity, creating a phantom batch that inflates the batch-tracked stock. The total stock level is correctly reduced via `adjustStock`, but batch-level queries will show stale data.

**Current Code:**
```php
// (in reverseProductionOrder)
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
// No batch cleanup
```

**Recommended Fix:**
```php
// Deactivate or zero-out batch created by this production order
$batchStmt = $this->db->prepare("
    UPDATE item_batches
    SET status = 'reversed', quantity = 0
    WHERE item_id = :item_id
      AND business_id = :business_id
      AND batch_number LIKE 'PROD-%'
      AND quantity = :production_quantity
      AND status = 'active'
    ORDER BY created_at DESC
    LIMIT 1
");
$batchStmt->execute([
    'item_id'             => (int) $order['manufactured_item_id'],
    'business_id'         => $ctx->business_id,
    'production_quantity' => (float) $order['production_quantity'],
]);

// Deduct manufactured item from stock
$this->itemService->adjustStock(
    (int) $order['manufactured_item_id'],
    'OUT',
    (float) $order['production_quantity'],
    'PRODUCTION_ORDER_REVERSAL',
    null
);
```

**Notes:** A more robust approach would store the `batch_id` in the `production_orders` table when the batch is created, so reversal can target the exact batch. The LIKE-based approach above is fragile.

---

### P3-PERF-001: N+1 Query in previewExpiryForProduction

**File:** `src/Services/ManufacturingService.php`
**Line(s):** 302–345
**Severity:** Medium
**Category:** PERF

**Description:**
The `previewExpiryForProduction` method iterates over each recipe component and executes two separate queries per component inside the loop: one to check `has_expiry` from the `items` table, and another to fetch batches from `item_batches`. For a recipe with N components, this results in 2N individual queries. This could be reduced to 2 queries total (one batch fetch for all component IDs, one items fetch).

**Current Code:**
```php
foreach ($recipeItems as $ri) {
    // Query 1: per component
    $itemStmt = $this->db->prepare("SELECT has_expiry, name FROM items WHERE id = ? LIMIT 1");
    $itemStmt->execute([$componentItemId]);
    $componentItem = $itemStmt->fetch(\PDO::FETCH_ASSOC);

    if (!$componentItem || (int) $componentItem['has_expiry'] !== 1) {
        continue;
    }

    // Query 2: per component
    $batchStmt = $this->db->prepare("
        SELECT id, quantity, expiry_date FROM item_batches
        WHERE item_id = :item_id AND business_id = :business_id AND status = 'active' AND quantity > 0
        ORDER BY expiry_date ASC
    ");
    $batchStmt->execute(['item_id' => $componentItemId, 'business_id' => $businessId]);
    $batches = $batchStmt->fetchAll(\PDO::FETCH_ASSOC);
    // ...
}
```

**Recommended Fix:**
```php
// Batch fetch all component item details
$componentIds = array_map(fn($ri) => (int) $ri['component_item_id'], $recipeItems);
$placeholders = implode(',', array_fill(0, count($componentIds), '?'));
$itemsStmt = $this->db->prepare("SELECT id, has_expiry, name FROM items WHERE id IN ($placeholders)");
$itemsStmt->execute($componentIds);
$itemsMap = [];
foreach ($itemsStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
    $itemsMap[(int) $row['id']] = $row;
}

// Batch fetch all batches for components that track expiry
$expiryItemIds = array_keys(array_filter($itemsMap, fn($i) => (int) $i['has_expiry'] === 1));
$allBatches = [];
if (!empty($expiryItemIds)) {
    $ph2 = implode(',', array_fill(0, count($expiryItemIds), '?'));
    $batchStmt = $this->db->prepare("
        SELECT item_id, id, quantity, expiry_date FROM item_batches
        WHERE item_id IN ($ph2) AND business_id = ? AND status = 'active' AND quantity > 0
        ORDER BY expiry_date ASC
    ");
    $batchStmt->execute([...$expiryItemIds, $businessId]);
    foreach ($batchStmt->fetchAll(\PDO::FETCH_ASSOC) as $b) {
        $allBatches[(int) $b['item_id']][] = $b;
    }
}
```

**Notes:** For recipes with many components (5+), this eliminates significant query overhead. The IN clause approach uses positional parameters which are safe from injection.

---

### P3-PERF-002: Recipe Items Loaded Twice During Production Order Creation

**File:** `src/Services/ManufacturingService.php`
**Line(s):** 392 and referenced in resolveComponentDetails
**Severity:** Low
**Category:** PERF

**Description:**
During `createProductionOrder`, the recipe items are fetched via `$this->repo->getRecipeItems($recipe['id'])` (line 392). However, cost data for each component was already fetched during recipe creation via `resolveComponentDetails`, which calls `$this->itemRepo->getById()` per component. The `getRecipeItems` query already JOINs the items table and returns `cost_price`. This double-fetch is redundant in the production order path since `getRecipeItems` already provides all needed data.

**Current Code:**
The method uses `getRecipeItems` which returns `cost_price` via JOIN, so no separate cost resolution is needed for production orders.

**Recommended Fix:**
This is already handled correctly in the production order flow (uses `recipeItem['cost_price']` from `getRecipeItems`). The redundancy only appears in `createRecipe`/`updateRecipe` where `resolveComponentDetails` re-fetches items. Low priority — document for future refactoring.

---

### P3-QUAL-001: Dead Code — validateOutputType Method Never Called

**File:** `src/Services/ManufacturingService.php`
**Line(s):** 1028–1060
**Severity:** Low
**Category:** QUAL

**Description:**
The private method `validateOutputType` is defined but never called anywhere in the codebase. It was presumably intended to validate that the output item of a recipe has an allowed type (MANUFACTURED or FINISHED_GOOD), but the validation was never wired into the recipe creation or update flows. This is dead code that should either be integrated into the workflow or removed.

**Current Code:**
```php
private function validateOutputType(int $itemId, int $tenantId): void {
    // ... 30 lines of validation logic ...
    $allowedOutputCodes = ['MANUFACTURED', 'FINISHED_GOOD'];
    if (!in_array($type['code'], $allowedOutputCodes, true)) {
        throw new \InvalidArgumentException(
            "Item '{$item['name']}' (type: {$type['name']}) cannot be a recipe output. " .
            "Only Manufactured and Finished Good items are allowed as BOM outputs."
        );
    }
}
```

**Recommended Fix:**
Either remove the dead method or integrate it into `createRecipe()` after the item is created (to verify the type assignment). Since `createRecipe` explicitly sets the type to "Manufactured", the validation is likely unnecessary and the method can be safely deleted.

---

### P3-QUAL-002: Inconsistent Error Handling — previewExpiry Catches All as 422

**File:** `src/Controllers/ManufacturingController.php`
**Line(s):** 225–226
**Severity:** Low
**Category:** QUAL

**Description:**
The `previewExpiry` method's catch-all handler returns HTTP 422 for all exceptions, including server errors that should be 500. Every other method in this controller correctly distinguishes between `\InvalidArgumentException` (422), `\RuntimeException` (404/422), and `\Throwable` (500). This inconsistency means an internal database failure during expiry preview will misleadingly return 422 instead of 500.

**Current Code:**
```php
} catch (\Throwable $e) {
    $this->json(false, null, $e->getMessage(), 422);
}
```

**Recommended Fix:**
```php
} catch (\InvalidArgumentException $e) {
    $this->json(false, null, $e->getMessage(), 422);
} catch (\RuntimeException $e) {
    $this->json(false, null, $e->getMessage(), 422);
} catch (\Throwable $e) {
    $this->logError('Manufacturing', 'previewExpiry', $e);
    $this->serverError('Failed to preview expiry');
}
```

---

### P3-QUAL-003: Controller storeRecipe Missing Input Fields in Required Array

**File:** `src/Controllers/ManufacturingController.php`
**Line(s):** 63
**Severity:** Medium
**Category:** QUAL

**Description:**
The `storeRecipe` method declares `$this->input(['name', 'components', 'yield_quantity'])` as required fields. However, the `input()` method from BaseController only checks `array_key_exists` — it does not validate that `components` is an array or non-empty. An attacker could send `{"name": "Test", "components": null, "yield_quantity": 1}` and bypass the required-field check, reaching the service layer where it would throw a less informative error. The `storeOrder` method has a similar pattern with `['recipe_id', 'production_quantity']`.

**Current Code:**
```php
$data = $this->input(['name', 'components', 'yield_quantity']);
$result = $this->service->createRecipe($data);
```

**Recommended Fix:**
```php
$data = $this->input(['name', 'components', 'yield_quantity']);

// Type validation at controller level
if (!is_array($data['components']) || empty($data['components'])) {
    $this->json(false, null, 'Components must be a non-empty array', 422);
    return;
}
if (!is_numeric($data['yield_quantity']) || (float) $data['yield_quantity'] <= 0) {
    $this->json(false, null, 'Yield quantity must be a positive number', 422);
    return;
}

$result = $this->service->createRecipe($data);
```

**Notes:** The service layer does validate these, but controller-level validation provides clearer error messages and aligns with the pattern used in other controllers that validate early.

---

### P3-VAL-001: setAutoMarkup Does Not Validate Markup Percentage Range

**File:** `src/Controllers/ManufacturingController.php`
**Line(s):** 183–185
**Severity:** Medium
**Category:** VAL

**Description:**
The `setAutoMarkup` endpoint accepts a `markup_percent` value and passes it to the service as a float without validating that it's within a reasonable range. Negative percentages, zero, or extremely large values (e.g., 99999%) would be accepted and stored. The service layer also lacks this validation. This could lead to nonsensical sale prices being auto-calculated.

**Current Code:**
```php
$data = $this->input(['markup_percent']);
$this->service->setAutoMarkupConfig($itemId, (float) $data['markup_percent']);
```

**Recommended Fix:**
```php
$data = $this->input(['markup_percent']);
$markupPercent = (float) $data['markup_percent'];

if ($markupPercent < 0 || $markupPercent > 1000) {
    $this->json(false, null, 'Markup percentage must be between 0 and 1000', 422);
    return;
}

$this->service->setAutoMarkupConfig($itemId, $markupPercent);
```

---

## Files Inspected — No Additional Issues

The following files were inspected with the full checklist and no additional findings beyond those documented above:

| File | Notes |
|------|-------|
| `public/api/routes/manufacturing.php` | All routes have permission checks. Pattern is consistent with other route files. Resource IDs are cast to `(int)`. No issues found beyond items covered by P3-SEC-004 (controller-level). |

---

## Requirement Traceability

| Requirement | Coverage |
|-------------|----------|
| 10.1 — Input sanitization in controller | P3-QUAL-003, P3-SEC-002 |
| 10.2 — Route permission checks | Routes verified — all endpoints have permissions ✓ |
| 10.3 — Repository business_id scoping and parameterized queries | P3-SEC-003; all queries use prepared statements ✓ |
| 11.1 — Component consumption correctness | Verified — proportional calculation is correct ✓ (but see P3-BUG-003 for batch reversal gap) |
| 11.2 — Recipe component type validation | P3-BUG-002 |
| 11.3 — Production order status transitions | P3-BUG-001 |
| 11.4 — Transaction usage for production + consumption | Verified — wrapped in transaction ✓ |
| 12.1 — N+1 queries in recipe retrieval | P3-PERF-001 |
| 12.2 — Production order listing over-fetching | Verified — list query uses summary JOIN, no full recipe details ✓ |
| 13.1 — Recipe creation required field validation | P3-QUAL-003; service-level validation is thorough ✓ |
| 13.2 — Production order creation validation | Verified — validates quantity > 0, recipe exists, recipe belongs to business ✓ |
| 13.4 — Dead code and pattern deviations | P3-QUAL-001, P3-QUAL-002, P3-SEC-001 |
