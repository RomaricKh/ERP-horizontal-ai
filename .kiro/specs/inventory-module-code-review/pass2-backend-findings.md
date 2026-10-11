# Pass 2 — Batches/Expiry Backend Findings

## Summary

| Severity | Count |
|----------|-------|
| Critical | 1 |
| High | 3 |
| Medium | 4 |
| Low | 2 |

| Category | Count |
|----------|-------|
| SEC | 2 |
| BUG | 3 |
| PERF | 1 |
| QUAL | 2 |
| VAL | 2 |

**Files Inspected:**

- `src/Services/BatchService.php` ✓
- `src/Repository/BatchRepository.php` ✓
- `public/api/routes/batches.php` ✓

---

## Findings

### P2-SEC-001: Missing permission enforcement on batch-related routes in items.php

**File:** `public/api/routes/items.php`
**Line(s):** 86–87
**Severity:** High
**Category:** SEC

**Description:**
The routes `/items/{id}/batches` and `/items/{id}/batch-transactions` are registered without a permission array. Per project convention, every route should declare its required permissions as the third argument. Without this, any authenticated user (regardless of their role/permissions) can access batch data for any item, bypassing the RBAC system. The parallel route in `batches.php` (line 9) correctly specifies `['items.view']`.

**Current Code:**
```php
$router->get('/items/{id}/batches', fn($id) => (new ItemsController())->batches((int)$id));
$router->get('/items/{id}/batch-transactions', fn($id) => (new ItemsController())->batchTransactions((int)$id));
```

**Recommended Fix:**
```php
$router->get('/items/{id}/batches',
    fn($id) => (new ItemsController())->batches((int)$id),
    ['items.view']
);
$router->get('/items/{id}/batch-transactions',
    fn($id) => (new ItemsController())->batchTransactions((int)$id),
    ['items.view']
);
```

**Validates:** Requirements 6.2

---

### P2-SEC-002: updateQuantity and updateStatus lack business_id scoping

**File:** `src/Repository/BatchRepository.php`
**Line(s):** 117–135
**Severity:** Medium
**Category:** SEC

**Description:**
The `updateQuantity()` and `updateStatus()` methods update a batch record using only the batch `id` as a filter. They do not include `AND business_id = :business_id` in the WHERE clause. If a caller ever passes an incorrect batch ID (e.g., from a different business), the update would succeed against a batch belonging to another business. While current callers (ItemService, BatchService) provide business-scoped batch IDs obtained from earlier queries, the repository methods themselves lack defense-in-depth scoping.

**Current Code:**
```php
public function updateQuantity(int $batchId, float $newQuantity): void {
    $stmt = $this->db->prepare("
        UPDATE item_batches
        SET quantity = :quantity, updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute(['quantity' => $newQuantity, 'id' => $batchId]);
}

public function updateStatus(int $batchId, string $status): void {
    $stmt = $this->db->prepare("
        UPDATE item_batches
        SET status = :status, updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute(['status' => $status, 'id' => $batchId]);
}
```

**Recommended Fix:**
```php
public function updateQuantity(int $batchId, int $businessId, float $newQuantity): void {
    $stmt = $this->db->prepare("
        UPDATE item_batches
        SET quantity = :quantity, updated_at = NOW()
        WHERE id = :id AND business_id = :business_id
    ");
    $stmt->execute(['quantity' => $newQuantity, 'id' => $batchId, 'business_id' => $businessId]);
}

public function updateStatus(int $batchId, int $businessId, string $status): void {
    $stmt = $this->db->prepare("
        UPDATE item_batches
        SET status = :status, updated_at = NOW()
        WHERE id = :id AND business_id = :business_id
    ");
    $stmt->execute(['status' => $status, 'id' => $batchId, 'business_id' => $businessId]);
}
```

**Notes:** All callers must be updated to pass `$businessId`. This is a defense-in-depth improvement.

**Validates:** Requirements 6.1, 6.3

---

### P2-BUG-001: consumeFEFO lacks database transaction — non-atomic multi-batch deduction

**File:** `src/Services/BatchService.php`
**Line(s):** 71–100
**Severity:** Critical
**Category:** BUG

**Description:**
The `consumeFEFO` method performs multi-step stock operations (SELECT batches → UPDATE each batch → INSERT transaction for each) without wrapping them in a database transaction. If the method is called outside an existing transaction (e.g., from a test script or a future caller that doesn't pre-open a transaction), a partial failure midway through the loop would leave batch quantities in an inconsistent state — some batches deducted, others not, with stock records out of sync.

Additionally, the SELECT that reads batch quantities does not use `SELECT ... FOR UPDATE`, so concurrent requests can read the same batch quantity simultaneously and both deduct from it, resulting in negative batch quantities (double-spend race condition).

**Current Code:**
```php
public function consumeFEFO(int $itemId, int $businessId, float $quantity, ?string $reference = null, ?string $notes = null): void {
    $ctx = AuthContext::get();
    
    $remaining = $quantity;
    $batchesStmt = $this->db->prepare("
        SELECT id, quantity FROM item_batches
        WHERE item_id = :item_id AND business_id = :business_id AND status = 'active' AND quantity > 0
        ORDER BY expiry_date ASC
    ");
    $batchesStmt->execute(['item_id' => $itemId, 'business_id' => $businessId]);
    $batches = $batchesStmt->fetchAll(\PDO::FETCH_ASSOC);

    foreach ($batches as $batch) {
        // ... deduction logic without transaction protection
    }
}
```

**Recommended Fix:**
```php
public function consumeFEFO(int $itemId, int $businessId, float $quantity, ?string $reference = null, ?string $notes = null): void {
    $ctx = AuthContext::get();

    $ownTransaction = !$this->db->inTransaction();
    if ($ownTransaction) {
        $this->db->beginTransaction();
    }

    try {
        $remaining = $quantity;
        // Use FOR UPDATE to lock rows against concurrent deductions
        $batchesStmt = $this->db->prepare("
            SELECT id, quantity FROM item_batches
            WHERE item_id = :item_id AND business_id = :business_id AND status = 'active' AND quantity > 0
            ORDER BY expiry_date ASC
            FOR UPDATE
        ");
        $batchesStmt->execute(['item_id' => $itemId, 'business_id' => $businessId]);
        $batches = $batchesStmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($batches as $batch) {
            if ($remaining <= 0) break;
            $batchQty = (float) $batch['quantity'];
            $deduct = min($remaining, $batchQty);

            $newBatchQty = $batchQty - $deduct;
            $updateStmt = $this->db->prepare("UPDATE item_batches SET quantity = :qty, updated_at = NOW() WHERE id = :id");
            $updateStmt->execute(['qty' => $newBatchQty, 'id' => $batch['id']]);

            $btStmt = $this->db->prepare("
                INSERT INTO batch_transactions (batch_id, transaction_type, quantity, reference, notes, created_at, created_by)
                VALUES (:batch_id, 'OUT', :quantity, :reference, :notes, NOW(), :user_id)
            ");
            $btStmt->execute([
                'batch_id'  => $batch['id'],
                'quantity'  => $deduct,
                'reference' => $reference,
                'notes'     => $notes,
                'user_id'   => $ctx->user_id,
            ]);

            $remaining -= $deduct;
        }

        if ($remaining > 0) {
            throw new \RuntimeException("Insufficient batch quantity available for Item ID {$itemId}. Missing {$remaining} units in active batches.");
        }

        if ($ownTransaction) {
            $this->db->commit();
        }
    } catch (\Throwable $e) {
        if ($ownTransaction && $this->db->inTransaction()) {
            $this->db->rollBack();
        }
        throw $e;
    }
}
```

**Notes:** When called from `ItemService::adjustStock`, the caller already opens a transaction. The `$ownTransaction` pattern (used elsewhere in the project) handles both cases safely.

**Validates:** Requirements 7.1, 7.3

---

### P2-BUG-002: consumeFEFO bypasses BatchRepository — direct SQL in service layer

**File:** `src/Services/BatchService.php`
**Line(s):** 74–97
**Severity:** Medium
**Category:** BUG

**Description:**
The `consumeFEFO` method uses raw `$this->db->prepare()` calls instead of delegating to `BatchRepository` methods (`getActiveBatchesFEFO`, `updateQuantity`, `insertTransaction`). This creates a parallel code path that can diverge from the repository's logic. If a repository method is updated (e.g., to add audit columns or status transitions), `consumeFEFO` won't benefit from that change. The repository already provides `getActiveBatchesFEFO()` and `updateQuantity()` methods that do exactly what the inlined SQL does.

**Current Code:**
```php
$batchesStmt = $this->db->prepare("
    SELECT id, quantity FROM item_batches
    WHERE item_id = :item_id AND business_id = :business_id AND status = 'active' AND quantity > 0
    ORDER BY expiry_date ASC
");
// ... direct SQL for updates and inserts
```

**Recommended Fix:**
```php
// Use repository methods instead of direct SQL
$batches = $this->repo->getActiveBatchesFEFO($itemId, $businessId);

foreach ($batches as $batch) {
    if ($remaining <= 0) break;
    $batchQty = (float) $batch['quantity'];
    $deduct = min($remaining, $batchQty);

    $this->repo->updateQuantity($batch['id'], $batchQty - $deduct);
    $this->repo->insertTransaction([
        'batch_id'         => $batch['id'],
        'transaction_type' => 'OUT',
        'quantity'         => $deduct,
        'reference'        => $reference,
        'notes'            => $notes,
        'created_by'       => $ctx->user_id,
    ]);

    $remaining -= $deduct;
}
```

**Notes:** This refactor should be combined with P2-BUG-001's transaction/locking fix. The repository `getActiveBatchesFEFO` method would need a `FOR UPDATE` variant or parameter.

**Validates:** Requirements 9.2

---

### P2-BUG-003: Duplicate batch creation — controller creates batch after service already does

**File:** `src/Controllers/ItemsController.php`
**Line(s):** 330–360
**Severity:** High
**Category:** BUG

**Description:**
When a user performs a stock-IN adjustment on a batch-tracked item with an `expiry_date` provided, the controller's `adjustStock` method calls `$this->service->adjustStock(...)` (which internally creates a batch via `BatchService::createBatch` for IN transactions on expiry-tracked items), and then the controller ALSO creates a batch with raw SQL if `$data['expiry_date']` is present. This results in TWO batches being created for a single stock-in operation — one by the service layer and one by the controller.

Additionally, the ADJ type validation happens AFTER `$this->service->adjustStock()` has already been called and committed, meaning the validation is ineffective (the operation has already occurred).

**Current Code:**
```php
// Controller calls service (which internally creates a batch)
$this->service->adjustStock($id, $type, $quantity, ...);

// Then controller ALSO creates a batch
if ($hasExpiry === 1) {
    if ($type === 'IN' && !empty($data['expiry_date'])) {
        $batchNumber = $data['batch_number'] ?? ('BATCH-' . date('Ymd-His'));
        $stmt = $db->prepare("
            INSERT INTO item_batches (item_id, business_id, batch_number, quantity, manufacturing_date, expiry_date, status, created_at)
            VALUES (:item_id, :business_id, :batch_number, :quantity, :mfg_date, :expiry_date, 'active', NOW())
        ");
        // ...
    }
}
```

**Recommended Fix:**
```php
// Remove the duplicate batch creation from the controller entirely.
// The service layer already handles batch creation for IN transactions.
// If the user provides a specific expiry_date or batch_number, pass it
// to the service instead:

$this->service->adjustStock(
    $id,
    $type,
    $quantity,
    $data['reference'] ?? null,
    $data['notes'] ?? null,
    $data['expiry_date'] ?? null,      // Pass through to service
    $data['batch_number'] ?? null       // Pass through to service
);

// Remove all the duplicate batch creation SQL below.
// Move the ADJ validation BEFORE the service call.
```

**Notes:** This is causing real data corruption — duplicate batches inflate the total batch quantity vs the item's stock level.

**Validates:** Requirements 7.1, 9.1

---

### P2-VAL-001: createBatch lacks input validation

**File:** `src/Services/BatchService.php`
**Line(s):** 21–56
**Severity:** High
**Category:** VAL

**Description:**
The `createBatch` method performs no input validation before inserting:
1. No check that `$quantity > 0` — zero or negative quantities would create invalid batches
2. No check that `$expiryDate` is a valid date format or not in the past
3. No check for `batch_number` uniqueness within the item (the DB has a unique constraint `uq_batch_number(item_id, business_id, batch_number)` that would throw a raw PDO exception instead of a user-friendly error)
4. No check that `$itemId` refers to a valid item that actually has expiry tracking enabled

**Current Code:**
```php
public function createBatch(
    int $itemId,
    float $quantity,
    string $expiryDate,
    ?string $manufacturingDate = null,
    ?string $batchNumber = null,
    ?int $referenceId = null
): int {
    $ctx = AuthContext::get();

    // Generate batch number if not provided
    if (empty($batchNumber)) {
        $seq = $this->repo->getNextSequence($itemId, $ctx->business_id, date('Ymd'));
        $batchNumber = 'B-' . date('Ymd') . '-' . str_pad($seq, 3, '0', STR_PAD_LEFT);
    }

    $batchId = $this->repo->insert([...]);
    // ...
}
```

**Recommended Fix:**
```php
public function createBatch(
    int $itemId,
    float $quantity,
    string $expiryDate,
    ?string $manufacturingDate = null,
    ?string $batchNumber = null,
    ?int $referenceId = null
): int {
    $ctx = AuthContext::get();

    // Validate quantity
    if ($quantity <= 0) {
        throw new \InvalidArgumentException('Batch quantity must be greater than zero');
    }

    // Validate expiry_date format
    $parsedExpiry = \DateTime::createFromFormat('Y-m-d', $expiryDate);
    if (!$parsedExpiry || $parsedExpiry->format('Y-m-d') !== $expiryDate) {
        throw new \InvalidArgumentException('Invalid expiry date format. Expected Y-m-d.');
    }

    // Generate batch number if not provided
    if (empty($batchNumber)) {
        $seq = $this->repo->getNextSequence($itemId, $ctx->business_id, date('Ymd'));
        $batchNumber = 'B-' . date('Ymd') . '-' . str_pad($seq, 3, '0', STR_PAD_LEFT);
    }

    // Check uniqueness proactively for better error message
    $existing = $this->repo->findByItemId($itemId, $ctx->business_id);
    foreach ($existing as $b) {
        if ($b['batch_number'] === $batchNumber) {
            throw new \InvalidArgumentException("Batch number '{$batchNumber}' already exists for this item");
        }
    }

    $batchId = $this->repo->insert([...]);
    // ...
}
```

**Validates:** Requirements 9.1

---

### P2-VAL-002: consumeFEFO does not validate quantity parameter

**File:** `src/Services/BatchService.php`
**Line(s):** 71
**Severity:** Medium
**Category:** VAL

**Description:**
The `consumeFEFO` method accepts `float $quantity` without validating that it is positive. A zero quantity would silently succeed (no batches deducted), and a negative quantity would never satisfy the `$remaining <= 0` break condition — it would iterate all batches, deducting negative amounts (effectively adding stock), and then throw an "Insufficient batch quantity" exception.

**Current Code:**
```php
public function consumeFEFO(int $itemId, int $businessId, float $quantity, ?string $reference = null, ?string $notes = null): void {
    $ctx = AuthContext::get();
    $remaining = $quantity;
    // No validation on $quantity
```

**Recommended Fix:**
```php
public function consumeFEFO(int $itemId, int $businessId, float $quantity, ?string $reference = null, ?string $notes = null): void {
    if ($quantity <= 0) {
        throw new \InvalidArgumentException('Consumption quantity must be greater than zero');
    }
    
    $ctx = AuthContext::get();
    $remaining = $quantity;
    // ...
```

**Validates:** Requirements 9.1

---

### P2-PERF-001: getExpiringBatches scans all items without item-level filtering

**File:** `src/Repository/BatchRepository.php`
**Line(s):** 196–212
**Severity:** Medium
**Category:** PERF

**Description:**
The `getExpiringBatches` method scans ALL active batches across ALL items for a business when checking for upcoming expiry. While there is an index `idx_batch_fefo(item_id, business_id, status, expiry_date)`, this method filters by `business_id` + `status` + `expiry_date` range without `item_id`, so the composite index is less effective (leading column `item_id` is not used). For businesses with many items and batches, this becomes a full scan of matching rows.

Additionally, the method JOINs to the `items` table to get `item_name`, which is fine for the query itself but means this is likely called as a batch operation (e.g., cron/notification) that could benefit from a dedicated index on `(business_id, status, expiry_date)`.

**Current Code:**
```php
public function getExpiringBatches(int $businessId, int $thresholdDays): array {
    $stmt = $this->db->prepare("
        SELECT ib.*, i.name AS item_name
        FROM item_batches ib
        JOIN items i ON i.id = ib.item_id
        WHERE ib.business_id = :business_id
          AND ib.status = 'active'
          AND ib.quantity > 0
          AND ib.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :threshold DAY)
        ORDER BY ib.expiry_date ASC
    ");
}
```

**Recommended Fix:**
Add a dedicated index for business-level expiry scanning:
```sql
CREATE INDEX idx_batch_expiry_scan ON item_batches (business_id, status, expiry_date)
    WHERE quantity > 0;
-- Note: MySQL doesn't support partial indexes. Use a covering index instead:
CREATE INDEX idx_batch_biz_status_expiry ON item_batches (business_id, status, expiry_date, quantity);
```

**Notes:** The existing `idx_batch_fefo` index starts with `item_id`, making it suboptimal for business-wide scans. This is most impactful if the expiry notification is called frequently or the batch table grows large.

**Validates:** Requirements 8.2

---

### P2-QUAL-001: Status enum case mismatch — 'ACTIVE' vs 'active'

**File:** `src/Services/BatchService.php`
**Line(s):** 43 vs 76
**Severity:** Low
**Category:** QUAL

**Description:**
`createBatch` inserts `status => 'ACTIVE'` (uppercase) on line 43, while `consumeFEFO` queries for `status = 'active'` (lowercase) on line 76. The database schema defines the enum as `enum('active','expired','written_off')` with lowercase values. MySQL enums are case-insensitive for comparison but store the canonical form, so this works functionally. However, the inconsistency is confusing and could break if the database or ORM ever enforces strict case matching.

The `BatchRepository` consistently uses lowercase `'active'` and `'expired'` in all its queries, matching the schema.

**Current Code:**
```php
// BatchService::createBatch (line 43)
'status' => 'ACTIVE',

// BatchService::consumeFEFO (line 76)
AND status = 'active'
```

**Recommended Fix:**
```php
// BatchService::createBatch (line 43) — use lowercase to match schema enum
'status' => 'active',
```

**Validates:** Requirements 9.4

---

### P2-QUAL-002: Route file is minimal — batches.php duplicates items.php endpoint

**File:** `public/api/routes/batches.php`
**Line(s):** 1–12
**Severity:** Low
**Category:** QUAL

**Description:**
The `batches.php` route file contains a single route (`GET /batches/{id}`) that duplicates the functionality of `GET /items/{id}/batch-transactions` in `items.php`. Both call `ItemsController::batchTransactions()`. The `batches.php` route is misleading because:
1. The URL `GET /batches/{id}` implies `{id}` is a batch ID, but it's actually an item ID (the controller calls `getTransactionsByItem($id, ...)`)
2. It creates two URL paths for the same operation
3. It doesn't follow RESTful naming — a `/batches/{id}` endpoint should return a single batch resource, not batch transactions for an item

**Current Code:**
```php
// GET /batches/{id}  — list batches for a specific item
$router->get('/batches/{id}',
    fn($id) => (new ItemsController())->batchTransactions((int)$id),
    ['items.view']
);
```

**Recommended Fix:**
Either:
1. Remove this route entirely (since `items/{id}/batch-transactions` already exists), or
2. Rename to `GET /batches/by-item/{id}` for clarity, or
3. Repurpose `GET /batches/{id}` to return a single batch record by batch ID (which is what REST consumers would expect)

**Notes:** The comment says "list batches for a specific item" but the method is `batchTransactions` which returns transaction history, not the batch list itself. There's a semantic mismatch between the comment, the URL, and the behavior.

**Validates:** Requirements 9.4

---

## Architectural Observations (No Finding — Document Only)

### Transaction Coverage

When `consumeFEFO` is called from `ItemService::adjustStock`, the caller already wraps the operation in a PDO transaction via `$this->db->beginTransaction()`. This provides partial protection. However, `consumeFEFO` is also called directly from a test script (`testFiles/fix_labneh.php`) without any transaction, confirming that the method must be self-protecting.

### No Batch Management API

There is currently no API endpoint for:
- Creating a batch manually (outside of stock-IN)
- Updating a batch's expiry date
- Writing off a batch
- Viewing a single batch by ID

All batch operations are side effects of stock movements or purchase order receipts. This is a design choice, not a bug, but limits administrative flexibility.

### Expiry Date Handling

The `BatchRepository::transitionExpiredBatches` method uses `expiry_date < CURDATE()` (strictly before today), which is correct — a batch expiring today is still valid. The `getExpiringBatches` method uses `BETWEEN CURDATE() AND DATE_ADD(...)` for the threshold window, which correctly includes today's date. These are consistent.

---

## Requirements Traceability

| Requirement | Findings | Status |
|-------------|----------|--------|
| 6.1 — business_id scoping in BatchRepository | P2-SEC-002 | Issue found |
| 6.2 — Permission checks on batch routes | P2-SEC-001 | Issue found |
| 6.3 — Authorization for batch stock modifications | P2-SEC-002 | Issue found |
| 7.1 — FEFO logic correctness | P2-BUG-001, P2-BUG-003 | Issues found |
| 7.2 — Expiry date timezone/off-by-one handling | No issues | ✓ Correct |
| 7.3 — Atomic batch quantity updates | P2-BUG-001 | Issue found |
| 8.1 — Pagination for batch list queries | No issues found — queries are item-scoped | ✓ Acceptable |
| 8.2 — Expiry notification scan efficiency | P2-PERF-001 | Issue found |
| 9.1 — Batch creation validation | P2-VAL-001, P2-BUG-003 | Issues found |
| 9.2 — Repository error handling consistency | P2-BUG-002 | Issue found |
| 9.4 — Dead code and pattern deviations | P2-QUAL-001, P2-QUAL-002 | Issues found |
