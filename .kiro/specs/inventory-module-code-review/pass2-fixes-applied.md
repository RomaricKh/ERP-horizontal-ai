# Pass 2 — Fixes Applied & Architectural Concerns

## Fixes Applied

### P2-BUG-001 (Critical) — consumeFEFO transaction + locking ✅
**File:** `src/Services/BatchService.php`
**Changes:**
- Wrapped the entire method in `$ownTransaction` pattern (checks `$this->db->inTransaction()` before beginning)
- Added `SELECT ... FOR UPDATE` to lock batch rows against concurrent deductions
- Added proper try/catch with rollback on failure
- Error thrown if `$remaining > 0` after exhausting all batches (insufficient quantity — already existed, now inside transaction)

### P2-VAL-002 (Medium) — consumeFEFO quantity validation ✅
**File:** `src/Services/BatchService.php`
**Changes:**
- Added `if ($quantity <= 0)` check at the top of `consumeFEFO` before any DB operations

### P2-VAL-001 (High) — createBatch input validation ✅
**File:** `src/Services/BatchService.php`
**Changes:**
- Added validation: `$quantity > 0`
- Added validation: `$expiryDate` must be valid Y-m-d format
- Added batch_number uniqueness check (iterates existing batches for the item)

### P2-QUAL-001 (Low) — Status enum case mismatch ✅
**File:** `src/Services/BatchService.php`
**Changes:**
- Changed `'status' => 'ACTIVE'` to `'status' => 'active'` in `createBatch` to match the schema enum definition

### P2-SEC-001 (High) — Missing permissions on batch routes ✅ (already fixed)
**File:** `public/api/routes/items.php`
**Status:** Already fixed by Pass 1 (P1-SEC-001). All three routes (`/items/{id}/price-history`, `/items/{id}/batches`, `/items/{id}/batch-transactions`) already have `['items.view']` permission.

### P2-BUG-003 (High) — Duplicate batch creation in controller ✅ (already fixed)
**File:** `src/Controllers/ItemsController.php`
**Status:** Already fixed by Pass 1 (P1-BUG-001). The controller's `adjustStock` method has been simplified — it passes `expiry_date`, `batch_number`, and `manufacturing_date` through to the service and no longer has duplicate batch creation SQL. ADJ validation for batch-tracked items is also already in `ItemService::adjustStock`.

### P2-SEC-002 (Medium) — business_id scoping on updateQuantity/updateStatus ✅
**File:** `src/Repository/BatchRepository.php`
**Changes:**
- Added `int $businessId` parameter to both `updateQuantity()` and `updateStatus()`
- Added `AND business_id = :business_id` to both WHERE clauses
- No existing production callers needed updating (these methods are not currently called from production code — `consumeFEFO` uses direct SQL, and `transitionExpiredBatches` has its own SQL)

---

## Architectural Concerns — Documented Only (NOT Fixed)

### P2-PERF-001: getExpiringBatches index suggestion
**File:** `src/Repository/BatchRepository.php`
**Issue:** The existing `idx_batch_fefo` index starts with `item_id`, making it suboptimal for business-wide expiry scans. A dedicated `(business_id, status, expiry_date, quantity)` covering index would improve performance.
**Why not fixed:** Requires DDL migration. Not a code fix — needs DBA review and migration script.

### P2-BUG-002: consumeFEFO bypasses BatchRepository
**File:** `src/Services/BatchService.php`
**Issue:** `consumeFEFO` uses raw `$this->db->prepare()` instead of delegating to repository methods (`getActiveBatchesFEFO`, `updateQuantity`, `insertTransaction`). Creates a parallel code path that can diverge.
**Why not fixed:** Architectural refactor that would require the repository's `getActiveBatchesFEFO` to support `FOR UPDATE` (a locking variant). Combined with BUG-001's transaction fix above, the immediate data-integrity risk is mitigated. Full refactor deferred to design discussion.

### P2-QUAL-002: Route file duplication (batches.php)
**File:** `public/api/routes/batches.php`
**Issue:** `GET /batches/{id}` duplicates `GET /items/{id}/batch-transactions` — same handler, confusing URL semantics (`{id}` implies batch ID but is actually item ID).
**Why not fixed:** Design decision needed — remove, rename, or repurpose the route. Needs team input.
