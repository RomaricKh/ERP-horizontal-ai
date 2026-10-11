# Pass 1: Architectural Concerns (Document Only)

These findings require design decisions and were intentionally NOT fixed during the direct-fix pass.

## LOW Severity — Not Fixed

### P1-BUG-002: Duplicate business_id Null Check in Every Controller Method
- **File:** `src/Controllers/ItemsController.php` (throughout)
- **Issue:** Every method contains an identical `business_id === null` check duplicated twice. The second check is dead code.
- **Decision Needed:** Whether to clean this up in bulk across all controller methods (cosmetic change with high diff noise) or leave as-is.
- **Risk:** None (cosmetic only).

### P1-QUAL-001: Dead Code — Duplicate Type Methods in ItemRepository
- **File:** `src/Repository/ItemRepository.php` (lines 30–68)
- **Issue:** `insertType()`, `updateType()`, `deleteType()`, `countItemsForType()` duplicate `ItemTypeRepository` functionality. `ItemService::createType()` uses `ItemRepository::insertType()` but other operations use `ItemTypeRepository`.
- **Decision Needed:** Remove duplicates from `ItemRepository` and update `ItemService::createType()` to use `ItemTypeRepository::create()` instead. Requires verifying no other callers exist.
- **Risk:** Low — needs a grep/reference check before removal.

### P1-QUAL-003: ItemVisibilityService Unused Dependency Pattern
- **File:** `src/Services/ItemVisibilityService.php` (line 11)
- **Issue:** `hasCustomerPayeeType()` bypasses the injected `PeopleRepository` and queries the database directly. Either the method should be in the repository or the repository dependency should be removed.
- **Decision Needed:** Whether to move `hasCustomerPayeeType()` into `PeopleRepository` or remove the repository dependency.
- **Risk:** None — works correctly, just inconsistent pattern.

### P1-QUAL-004: tableExists() Dead Code in ItemRepository
- **File:** `src/Repository/ItemRepository.php` (lines 264–272)
- **Issue:** Private method `tableExists()` is never called. Also has a minor SQL injection vector since `$table` is interpolated directly.
- **Decision Needed:** Whether to remove it now (safe since it's dead code) or leave for a bulk dead-code pass.
- **Risk:** None — dead code, no callers.

### P1-BUG-007: Insights Date Params Passed Unsanitized
- **File:** `src/Controllers/ItemsController.php` (lines 156–158)
- **Issue:** `start_date` and `end_date` from `$_GET` reach service without format validation. Not a security risk (parameterized), but invalid dates could return wrong results silently.
- **Decision Needed:** Whether to add date format validation here or create a shared input validation utility for dates used across all controllers.
- **Risk:** Low — no data corruption, just potentially confusing empty results.

## Structural Notes

The following broader patterns were observed but not addressed:

1. **Controller raw DB access:** `indexForPayee()` still performs a direct DB query in the controller (now scoped to business_id). Full extraction to service layer would require creating a `PayeeService` or expanding `ItemService` with a `resolvePayeePeopleId()` method. This was partially addressed by the BUG-005 fix (added business_id scoping) but the architectural deviation remains.

2. **StockValidator transaction dependency:** The `assertSufficientStock()` now uses `FOR UPDATE`, which requires it to be called within a transaction. All current callers (`ItemService::adjustStock`) already begin a transaction before calling it, so this is safe. Future callers must be aware of this requirement.
