# Pass 3 — Manufacturing Sub-Module Fixes Applied

## Fixes Applied

### CRITICAL

| Finding | File | Fix Summary |
|---------|------|-------------|
| P3-SEC-001 | ManufacturingController.php | Replaced exception detail leak in storeOrder catch block with generic error + server-side logging via `logError()` |

### HIGH

| Finding | File | Fix Summary |
|---------|------|-------------|
| P3-SEC-003 | ManufacturingRepository.php, ManufacturingService.php | Added `tenant_id` and `business_id` parameters to `updateProductionOrderStatus()`, added WHERE scoping and rowCount check. Updated caller in `reverseProductionOrder`. |
| P3-SEC-004 | ManufacturingService.php | Added ownership validation (fetch item, verify tenant/business match) to both `dismissCostChangeFlag()` and `setAutoMarkupConfig()` |
| P3-BUG-003 | ManufacturingService.php | Added batch reversal logic in `reverseProductionOrder()` — sets matching PROD-* batch to status='reversed', quantity=0 before stock adjustment |

### MEDIUM

| Finding | File | Fix Summary |
|---------|------|-------------|
| P3-SEC-002 | ManufacturingController.php | Added regex validation (YYYY-MM-DD) for start_date and end_date query params in `indexOrders()` |
| P3-BUG-002 | ManufacturingService.php | Changed allowed component types from `['RAW_MATERIAL', 'CONSUMABLE']` to `['RAW_MATERIAL', 'TRADING', 'CONSUMABLE']` to allow all valid types |
| P3-QUAL-002 | ManufacturingController.php | Split `previewExpiry` catch-all into InvalidArgumentException (422), RuntimeException (422), and Throwable (500 + logError) |
| P3-QUAL-003 | ManufacturingController.php | Added controller-level validation: components must be non-empty array, yield_quantity must be positive number |
| P3-VAL-001 | ManufacturingController.php | Added range validation for markup_percent (0–1000) in `setAutoMarkup()` |

## Architectural Concerns — Documented Only (Not Fixed)

### P3-BUG-001: Production Order Has No Draft/In-Progress Status Transitions

**Status:** Intentional design simplification for "instant produce" workflow.

The current implementation creates production orders directly in COMPLETED status. This is a conscious design choice for small manufacturers using a single-step production model. The only transition supported is COMPLETED → REVERSED. If multi-step production workflows are needed in the future, this would require:
1. New status constants (DRAFT, IN_PROGRESS, CANCELLED)
2. Separate service methods for each transition
3. Stock movements only on completion, not on order creation
4. UI changes for the production order lifecycle

### P3-PERF-001: N+1 Query in previewExpiryForProduction

**Status:** Requires repository method refactoring.

The method performs 2N queries (item lookup + batch fetch) per recipe component inside a loop. Fixing requires batch-fetching items and batches using IN clauses. While straightforward, this requires adding new repository methods and testing the batch query approach. Deferred to a performance optimization pass.

### P3-PERF-002: Recipe Items Loaded Twice During Production

**Status:** Low priority, minimal performance impact.

The redundancy only manifests during recipe create/update flows where `resolveComponentDetails` re-fetches items already available from `getRecipeItems`. The production order path correctly uses `getRecipeItems` results without duplication.

### P3-QUAL-001: Dead Code — validateOutputType Method

**Status:** Safe to remove but low priority.

The private method is never called. It was likely intended for recipe output validation but the flow creates the manufactured item with an explicit type, making the check unnecessary. Removal is safe but provides no behavioral benefit.

## Test Updates

Updated mock signatures in:
- `tests/Unit/ManufacturingProductionOrderTest.php` — Updated `updateProductionOrderStatus` callback to accept new `tenantId` and `businessId` parameters
- `tests/Integration/ManufacturingProductionFlowTest.php` — Same update in all three mock instances
