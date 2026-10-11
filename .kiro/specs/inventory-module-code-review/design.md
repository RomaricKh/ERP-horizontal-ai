# Design Document: Inventory Module Code Review

## Overview

This design defines the methodology, file scope, finding categories, output format, and fix approach for a comprehensive code review of the Inventory module. The review is structured as four incremental passes, each targeting a specific sub-module across both the PHP backend and vanilla JS frontend.

The review is a manual inspection activity — it does not produce new features but rather a catalog of findings with direct code fixes for clear bugs and architectural documentation for systemic concerns.

## Architecture

### Review Methodology

Each pass follows a consistent inspection protocol:

1. **Backend-first scan** — Read controller → service → repository → route file in dependency order
2. **Frontend scan** — Read page JS → modal JS → HTML template
3. **Cross-layer validation** — Verify frontend assumptions match backend behavior (field names, validation rules, response shapes)
4. **Finding documentation** — Record each finding with severity, category, location, and fix recommendation

### Inspection Checklist Per File

For each source file, the reviewer applies these checks in order:

| Check | Applies To | What to Look For |
|-------|-----------|-----------------|
| SQL Injection | Repository, Controller | String interpolation in queries, dynamic WHERE without bindings |
| Authorization | Controller, Service, Routes | Missing permission checks, absent tenant/business scoping |
| XSS | JS files, HTML templates | innerHTML, template literals in DOM, missing output encoding |
| Logic Errors | Service, Controller | Incorrect conditionals, missing edge cases, wrong calculations |
| Race Conditions | Service, Repository | Non-atomic multi-step operations, missing transactions |
| Validation Gaps | Controller, Service, JS | Missing type/range/sign checks, absent required-field enforcement |
| N+1 Queries | Repository, Service | Queries inside loops, repeated single-row fetches |
| Over-fetching | Repository, Controller | Unnecessary JOINs or nested data in list endpoints |
| Dead Code | All files | Unused variables, unreachable branches, commented blocks |
| Pattern Deviations | All files | Inconsistency with BaseController, apiFetch conventions, project patterns |
| Accessibility | HTML templates | Missing ARIA labels, missing form labels, non-semantic markup |

---

## Components and Interfaces

### Pass 1: Items/Stock

**Backend files:**
```
src/Controllers/ItemsController.php
src/Controllers/PendingPricesController.php
src/Services/ItemService.php
src/Services/ItemVisibilityService.php
src/Services/PendingPriceService.php
src/Services/PriceHistoryService.php
src/Services/PriceUpdater.php
src/Services/StockValidator.php
src/Repository/ItemRepository.php
src/Repository/ItemTypeRepository.php
src/Repository/ItemPriceRepository.php
src/Repository/ItemPriceHistoryRepository.php
src/Repository/SupplierItemPriceRepository.php
public/api/routes/items.php
```

**Frontend files:**
```
public/assets/js/pages/Items/items.js
public/assets/js/pages/Items/itemForm.modal.js
public/assets/js/pages/Items/itemInsights.modal.js
public/assets/js/pages/Items/itemTypes.modal.js
public/assets/js/pages/Items/pendingPrices.modal.js
public/assets/js/pages/Items/priceHistory.modal.js
public/assets/js/pages/Items/stockAdjust.modal.js
public/assets/js/pages/Items/stockView.modal.js
public/html/Items/items.html
public/html/Items/itemForm.html
public/html/Items/itemInsights.html
public/html/Items/itemTypes.html
public/html/Items/pendingPrices.html
public/html/Items/priceHistory.html
public/html/Items/stockAdjust.html
public/html/Items/stockView.html
```

### Pass 2: Batches/Expiry

**Backend files:**
```
src/Services/BatchService.php
src/Repository/BatchRepository.php
public/api/routes/batches.php
```

**Frontend files:**
```
public/assets/js/pages/Items/batches.js
public/html/Items/batches.html
```

### Pass 3: Manufacturing

**Backend files:**
```
src/Controllers/ManufacturingController.php
src/Services/ManufacturingService.php
src/Repository/ManufacturingRepository.php
public/api/routes/manufacturing.php
```

**Frontend files:**
```
public/assets/js/pages/Items/productionOrders.js
public/assets/js/pages/Items/productionOrderForm.modal.js
public/assets/js/pages/Items/recipeForm.modal.js
public/html/Items/productionOrders.html
public/html/Items/productionOrderForm.html
public/html/Items/recipeForm.html
```

### Pass 4: Services

**Backend files:**
```
src/Services/ServiceService.php
src/Repository/ServiceRepository.php
src/Repository/ServiceCategoryRepository.php
public/api/routes/services.php
```

**Frontend files:**
```
public/assets/js/pages/Services/services.js
public/assets/js/pages/Services/serviceForm.modal.js
public/assets/js/pages/Services/serviceCategories.modal.js
public/html/Services/services.html
public/html/Services/serviceForm.html
public/html/Services/serviceCategories.html
```

---

### Finding Categories

Each finding is classified into exactly one category with a severity level:

### Category Definitions

| Category | Code | Description |
|----------|------|-------------|
| Security | SEC | SQL injection, XSS, authorization bypass, missing tenant scoping |
| Bug | BUG | Logic errors, incorrect calculations, race conditions, broken flows |
| Performance | PERF | N+1 queries, missing pagination, over-fetching, redundant calls |
| Quality | QUAL | Dead code, pattern deviations, missing error handling, naming issues |
| Validation | VAL | Missing input validation, boundary conditions, type coercion gaps |

### Severity Levels

| Severity | Meaning | Action |
|----------|---------|--------|
| Critical | Exploitable security flaw or data corruption risk | Immediate fix required |
| High | Bug causing incorrect behavior or major performance issue | Fix in current pass |
| Medium | Missing validation, minor performance issue, or quality gap | Fix recommended |
| Low | Code style, minor dead code, or nice-to-have improvement | Fix optional |

---

## Data Models

Each finding is documented using this structure:

```
### [PASS]-[CATEGORY]-[NUMBER]: [Short Title]

**File:** `path/to/file.ext`
**Line(s):** [line number or range]
**Severity:** Critical | High | Medium | Low
**Category:** SEC | BUG | PERF | QUAL | VAL

**Description:**
[Clear explanation of the issue, why it's a problem, and what could go wrong]

**Current Code:**
```[language]
// problematic code snippet
```

**Recommended Fix:**
```[language]
// corrected code snippet
```

**Notes:** [Optional context, related findings, or architectural considerations]
```

Example finding ID: `P1-SEC-001` (Pass 1, Security, finding #1)

---

## Error Handling

The review produces two types of output:

### Direct Code Fixes (for clear, isolated bugs)

Apply directly when:
- The fix is localized (single file, few lines)
- The correct behavior is unambiguous
- No architectural decision is required
- Examples: adding a missing parameterized binding, adding a permission check to a route, fixing an off-by-one comparison, adding input validation

### Documentation Only (for architectural concerns)

Document without fixing when:
- The fix requires cross-module coordination
- Multiple valid approaches exist and a design decision is needed
- The change would alter public API behavior
- Examples: restructuring how stock validation handles concurrency, redesigning the batch deduction strategy, refactoring N+1 patterns that require new repository methods

### Fix Priority Order Within Each Pass

1. **Critical security findings** — fix immediately
2. **High-severity bugs** — fix in same pass
3. **Medium validation/performance** — fix if isolated, document if architectural
4. **Low quality findings** — document for batch cleanup later

---

## Implementation Strategy

### Pass Execution Order

Each pass is independent and produces its own findings document. Passes execute sequentially:

```
Pass 1 (Items/Stock) → Pass 2 (Batches/Expiry) → Pass 3 (Manufacturing) → Pass 4 (Services)
```

### Per-Pass Workflow

```
1. Read all backend files (controller → service → repository → routes)
2. Read all frontend files (page JS → modal JS → HTML)
3. Apply inspection checklist to each file
4. Document all findings with category, severity, location
5. Apply direct fixes for clear, isolated issues
6. Summarize architectural concerns separately
7. Produce pass summary with finding counts by category/severity
```

### Cross-Pass Patterns

After all four passes, identify cross-cutting patterns:
- Recurring issues that appear in multiple sub-modules
- Systemic architectural concerns that warrant project-wide fixes
- Shared utility improvements (e.g., a sanitization helper, a validation middleware)

---

## Testing Strategy

This is a code review spec — the "testing" is the review itself. Validation of the review process is performed by:

1. **Completeness check** — Verify every file listed in each pass's file set was inspected
2. **Format compliance** — Verify every finding uses the standardized `[PASS]-[CATEGORY]-[NUMBER]` format
3. **Traceability audit** — Verify every acceptance criterion from requirements.md has at least one corresponding finding or explicit "no issues found" note
4. **Fix verification** — For each direct code fix applied, run the project's existing test suite (`vendor/bin/phpunit` and `npx vitest --run`) to confirm no regressions

No property-based tests are generated for this spec because the acceptance criteria describe manual inspection activities rather than runtime behaviors with varying inputs.

---

## Correctness Properties

*A property is a characteristic or behavior that should hold true across all valid executions of a system — essentially, a formal statement about what the system should do. Properties serve as the bridge between human-readable specifications and machine-verifiable correctness guarantees.*

This is a code review spec. The acceptance criteria describe manual inspection activities (reviewing source code for defects) rather than testable runtime behaviors. There are no universal properties of the form "for all inputs X, property P(X) holds" because the outputs (finding reports) are qualitative judgments produced by human inspection, not deterministic functions.

### Property 1: Review completeness invariant

For any pass P and any file F in the defined file set for P, the review output SHALL contain at least one inspection note referencing F (either a finding or an explicit "no issues found" annotation).

**Validates: Requirements 1.1, 1.2, 1.3, 1.4, 1.5, 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 3.1, 3.2, 3.3, 3.4, 4.1, 4.2, 4.3, 4.4, 5.1, 5.2, 5.3, 5.4, 5.5, 6.1, 6.2, 6.3, 6.4, 7.1, 7.2, 7.3, 7.4, 8.1, 8.2, 8.3, 9.1, 9.2, 9.3, 9.4, 10.1, 10.2, 10.3, 10.4, 11.1, 11.2, 11.3, 11.4, 11.5, 12.1, 12.2, 12.3, 13.1, 13.2, 13.3, 13.4, 14.1, 14.2, 14.3, 14.4, 15.1, 15.2, 15.3, 15.4, 16.1, 16.2, 16.3, 16.4, 16.5**

### Property 2: Fix regression safety

For any direct code fix applied during the review, the project's existing test suite SHALL continue to pass after the fix is applied.

**Validates: Requirements 1.1, 1.5, 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 5.1, 5.2, 5.3, 5.5, 6.1, 7.1, 7.2, 7.3, 9.1, 10.1, 10.3, 11.1, 11.2, 11.3, 11.4, 13.1, 13.2, 14.2, 15.1, 15.2, 16.2**
