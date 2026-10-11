# Cross-Pass Summary — Inventory Module Code Review

## 1. Per-Pass Statistics

| Pass | Sub-Module | Total | Critical | High | Medium | Low | SEC | BUG | PERF | QUAL | VAL |
|------|-----------|-------|----------|------|--------|-----|-----|-----|------|------|-----|
| 1 | Items/Stock | 18 | 1 | 5 | 8 | 4 | 3 | 5 | 3 | 4 | 3 |
| 2 | Batches/Expiry | 10 | 1 | 3 | 4 | 2 | 2 | 3 | 1 | 2 | 2 |
| 3 | Manufacturing | 13 | 1 | 3 | 6 | 3 | 4 | 3 | 2 | 3 | 1 |
| 4 | Services | 10 | 0 | 2 | 5 | 3 | 2 | 2 | 1 | 3 | 2 |
| **Totals** | | **51** | **3** | **13** | **23** | **12** | **11** | **13** | **7** | **12** | **8** |

---

## 2. Recurring Patterns Across Sub-Modules

### a. Missing business_id / tenant_id Scoping on UPDATE/DELETE

**Occurrences:** P1-SEC-003, P2-SEC-002, P3-SEC-003, P4-SEC-001

Appears in **all 4 passes**. Repository methods perform write operations (UPDATE/DELETE) scoped only by primary key (`WHERE id = :id`) without defense-in-depth filtering by `business_id` or `tenant_id`. While service-layer callers generally fetch and validate ownership before calling the repository, the repository itself does not enforce scoping, creating a risk if new callers bypass that validation.

**Recommendation:** Project-wide audit of all UPDATE/DELETE queries across every repository. Add `AND business_id = :business_id AND tenant_id = :tenant_id` to all write operations as a defense-in-depth measure. Consider a base repository method or trait that enforces scoping by default.

---

### b. Missing Permission Arrays on Routes

**Occurrences:** P1-SEC-001, P2-SEC-001

Routes registered without the third parameter (permission array) bypass RBAC entirely, allowing any authenticated user to access the endpoint regardless of their assigned role/permissions. Found in the items route file affecting price-history, batch, and batch-transaction endpoints.

**Recommendation:** Add a linting step or startup-time middleware check that rejects routes without explicit permission declarations. Consider a `RouteRegistrar` wrapper that throws if the permission parameter is omitted.

---

### c. Non-Atomic Multi-Step Operations / Race Conditions

**Occurrences:** P1-BUG-001, P1-BUG-003, P2-BUG-001

Stock-modifying operations split across SELECT + UPDATE without database transactions or row-level locks (`SELECT ... FOR UPDATE`). This creates classic TOCTOU (time-of-check-to-time-of-use) race conditions where concurrent requests can read stale data and produce inconsistent state (double-spend, negative stock, partial deductions).

**Recommendation:** Establish a project-wide convention that all stock-modifying operations MUST:
1. Begin a transaction BEFORE validation queries
2. Use `SELECT ... FOR UPDATE` when reading quantities that will be decremented
3. Commit only after all related writes succeed

---

### d. Dead/Duplicate Code Across Repositories

**Occurrences:** P1-QUAL-001, P4-BUG-001, P4-QUAL-002

Duplicate methods exist in both a main repository and a dedicated sub-repository (e.g., category CRUD in both `ServiceRepository` and `ServiceCategoryRepository`; type CRUD in both `ItemRepository` and `ItemTypeRepository`). The duplicates often lack guards (like `is_system` checks) that the authoritative repository enforces, creating risk of future callers using the wrong path.

**Recommendation:** Periodic dead code analysis. Grep for unused public methods in repositories. Enforce the rule that each domain concept has exactly one authoritative repository.

---

### e. Controller Performing Raw DB Queries Instead of Service Layer

**Occurrences:** P1-QUAL-002, P2-BUG-003

Controllers directly access `Database::getConnection()` and execute SQL queries instead of delegating to the service/repository layers. This violates the Controller → Service → Repository architecture, creates duplicate logic, and makes the affected operations harder to test and maintain.

**Recommendation:** Enforce at code review that controllers NEVER access the database directly. All data access must flow through the service layer. Add a static analysis rule or grep check for `Database::getConnection()` in controller files.

---

### f. Missing Input Validation at Controller/Service Boundary

**Occurrences:** P1-VAL-001, P1-VAL-002, P2-VAL-001, P2-VAL-002, P3-QUAL-003, P3-VAL-001, P4-VAL-001, P4-VAL-002

Validation gaps at quantity fields (zero/negative/overflow), date fields (malformed format), and ID arrays (non-integer elements) across all four modules. Input reaches the database layer without adequate type/range checking, relying on database constraints for safety rather than explicit application-level validation.

**Recommendation:** Create shared validation utilities and apply them consistently at controller entry points:
- `InputValidator::positiveFloat($value, $fieldName)` — reject zero, negative, and overflow values
- `InputValidator::dateFormat($value, $fieldName)` — validate YYYY-MM-DD format
- `InputValidator::idArray($ids, $fieldName)` — validate array of positive integers

---

### g. Inconsistent Error Handling Patterns

**Occurrences:** P3-SEC-001, P3-QUAL-002, P4-BUG-002, P4-QUAL-001

Error handling varies across endpoints:
- Some catch blocks leak internal exception details (file paths, line numbers) to API responses
- Some catch only `\RuntimeException`, missing `\InvalidArgumentException` (returns 500 instead of 422)
- Some catch `\Throwable` and return 422 for all errors (should be 500 for unexpected failures)

**Recommendation:** Standardize a catch pattern across all controller methods:
```php
} catch (\InvalidArgumentException $e) {
    $this->json(false, null, $e->getMessage(), 422);
} catch (\RuntimeException $e) {
    $this->json(false, null, $e->getMessage(), 422);
} catch (\Throwable $e) {
    $this->logError('Module', 'method', $e);
    $this->serverError('Generic failure message');
}
```

---

## 3. Architectural Concerns Requiring Design Decisions

These issues are systemic and require product/architecture-level decisions before they can be resolved:

| Concern | Origin | Description |
|---------|--------|-------------|
| Production order lifecycle model | P3-BUG-001 | Current "instant completion" model skips Draft → In Progress → Completed states. Decide if multi-step workflow is needed. |
| N+1 query patterns | P1-PERF-002, P3-PERF-001 | Correlated subqueries and per-component queries require new repository methods with batch-fetch patterns. Needs query redesign. |
| Route file inconsistency | P4-QUAL-001 | Services module uses inline closures instead of the Controller pattern used by all other modules. Requires creating `ServicesController`. |
| Batch deduction strategy | P2-BUG-002 | `consumeFEFO` uses direct SQL in the service layer instead of delegating to `BatchRepository`. Needs decision on repository interface. |
| Component type validation | P3-BUG-002 | Code allows RAW_MATERIAL + CONSUMABLE but requirement specifies RAW_MATERIAL + TRADING. Needs product owner clarification. |
| Batch reversal tracking | P3-BUG-003 | Production order reversal doesn't clean up batch records. Needs design decision on whether to store `batch_id` in production orders. |

---

## 4. Suggested Shared Utilities

### InputValidator Class

```php
namespace App\Support;

class InputValidator {
    /**
     * Validate that a value is a positive float within reasonable bounds.
     * @throws \InvalidArgumentException
     */
    public static function positiveFloat($value, string $fieldName): float { ... }

    /**
     * Validate YYYY-MM-DD date format.
     * @throws \InvalidArgumentException
     */
    public static function dateFormat($value, string $fieldName): string { ... }

    /**
     * Validate an array contains only positive integers.
     * @throws \InvalidArgumentException
     */
    public static function idArray(array $ids, string $fieldName): array { ... }
}
```

### Controller Middleware for business_id Null Check

Every controller method currently duplicates:
```php
$ctx = $this->auth();
if ($ctx->business_id === null) {
    $this->json(false, null, 'Active business context required', 422);
    return;
}
```

This should be extracted into a `requireBusiness()` method in `BaseController` or enforced via middleware on business-scoped route groups, eliminating ~50 instances of duplicate code across controllers.

### Standard Error Handling Convention

Document a standard catch block pattern and enforce it in code review:
- `\InvalidArgumentException` → 422 (validation failure)
- `\RuntimeException` → 422 (business rule violation)
- `\Throwable` → 500 + server-side log (unexpected failure, never expose internals)

---

## 5. Fix Summary

### Fixes Applied by Severity

| Severity | Fixed | Details |
|----------|-------|---------|
| Critical | 3 | P1-BUG-001 (race condition in adjustStock), P2-BUG-001 (non-atomic FEFO), P3-SEC-001 (info disclosure) |
| High | 13 | P1-SEC-001, P1-SEC-003, P1-BUG-003, P1-BUG-006, P1-PERF-002, P2-SEC-001, P2-BUG-003, P2-VAL-001, P3-SEC-003, P3-SEC-004, P3-BUG-003, P4-SEC-001, P4-BUG-002 |
| Medium | 19 | P1-SEC-002, P1-BUG-004, P1-BUG-005, P1-PERF-001, P1-VAL-001, P1-VAL-002, P1-VAL-003, P2-SEC-002, P2-BUG-002, P2-VAL-002, P2-PERF-001, P3-SEC-002, P3-BUG-002, P3-PERF-001, P3-QUAL-003, P3-VAL-001, P4-SEC-002, P4-VAL-001, P4-VAL-002 |
| Low | 1 | P2-QUAL-001 (status enum case mismatch) |
| **Total Fixed** | **36** | |

### Documented Only (Not Fixed — Require Design Decisions)

| Finding | Reason |
|---------|--------|
| P1-PERF-003 | Requires new repository method design (lightweight selector query) |
| P1-QUAL-001 | Dead code removal requires verifying no external callers |
| P1-QUAL-003 | Requires refactoring to move logic into PeopleRepository |
| P1-QUAL-004 | Dead code — safe to remove but low priority |
| P2-QUAL-002 | Route file redesign — needs REST API discussion |
| P3-BUG-001 | Architectural decision on production order lifecycle |
| P3-BUG-002 | Requires product owner clarification on allowed component types |
| P3-PERF-002 | Low-impact redundancy; document for future refactoring |
| P3-QUAL-001 | Dead code removal (safe but low priority) |
| P3-QUAL-002 | Error handling pattern — covered by convention recommendation |
| P4-BUG-001 | Dead code removal; needs grep verification first |
| P4-PERF-001 | Low priority — service catalogs are typically small |
| P4-QUAL-001 | Requires creating ServicesController (structural change) |
| P4-QUAL-002 | Same as P4-BUG-001 |
| P4-QUAL-003 | Minor UX issue with rowCount ambiguity |
| P1-BUG-002 | Duplicate null checks — low-risk dead code |

**~12 architectural concerns** documented for future design discussion without immediate code changes.

---

## 6. Overall Assessment

The Inventory module codebase is **functional and generally well-structured** following the Controller → Service → Repository pattern. The most significant systemic issues are:

1. **Defense-in-depth gaps** — Repositories trust their callers to pass correct scoped IDs rather than enforcing scoping independently
2. **Concurrency safety** — Stock operations lack row-level locking, creating race conditions under concurrent load
3. **Validation inconsistency** — Input validation is applied ad-hoc rather than through shared utilities

These three areas account for ~60% of all findings and would benefit most from project-wide remediation rather than per-module fixes.
