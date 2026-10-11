# Implementation Plan: Inventory Module Code Review

## Overview

Comprehensive code review of the Inventory module executed as four independent passes. Each pass inspects a sub-module's backend (controller → service → repository → routes) and frontend (page JS → modal JS → HTML) for security vulnerabilities, logic bugs, performance issues, code quality gaps, and missing validations. Findings are documented using the standardized format from the design document, and direct fixes are applied for clear, isolated issues.

## Tasks

- [ ] 1. Pass 1: Items/Stock Sub-Module Review
  - [x] 1.1 Review Items/Stock backend — controllers, services, repositories, routes
    - Read and inspect in dependency order: `ItemsController.php`, `PendingPricesController.php` → `ItemService.php`, `ItemVisibilityService.php`, `PendingPriceService.php`, `PriceHistoryService.php`, `PriceUpdater.php`, `StockValidator.php` → `ItemRepository.php`, `ItemTypeRepository.php`, `ItemPriceRepository.php`, `ItemPriceHistoryRepository.php`, `SupplierItemPriceRepository.php` → `public/api/routes/items.php`
    - Apply full inspection checklist: SQL injection, authorization, tenant scoping, logic errors, race conditions, validation gaps, N+1 queries, over-fetching, dead code, pattern deviations
    - Document all findings using `P1-[CATEGORY]-[NNN]` format with severity, location, current code, and recommended fix
    - _Requirements: 1.1, 1.2, 1.3, 1.5, 2.1, 2.2, 2.3, 2.4, 2.6, 3.1, 3.2, 3.4, 4.1, 4.2, 5.1, 5.2, 5.3, 5.5_

  - [x] 1.2 Review Items/Stock frontend — page JS, modal JS, HTML templates
    - Read and inspect: `items.js` → `itemForm.modal.js`, `itemInsights.modal.js`, `itemTypes.modal.js`, `pendingPrices.modal.js`, `priceHistory.modal.js`, `stockAdjust.modal.js`, `stockView.modal.js` → all HTML templates in `public/html/Items/`
    - Check for XSS (innerHTML, unescaped template literals), missing error handling, double-submit prevention, stale data after operations, inconsistent apiFetch usage, missing client-side validations, accessibility issues (ARIA labels, form labels, semantic markup)
    - Cross-layer validation: verify frontend field names, validation rules, and response shape assumptions match backend behavior
    - Document all findings using `P1-[CATEGORY]-[NNN]` format
    - _Requirements: 1.4, 2.5, 3.3, 4.3, 4.4, 5.4_

  - [x] 1.3 Apply direct fixes for Pass 1 findings
    - Apply code fixes for all Critical and High severity findings that are localized and unambiguous
    - Apply fixes for Medium findings that are isolated (single file, few lines, no architectural decision needed)
    - Document architectural concerns that require design decisions separately without fixing
    - Follow fix priority: Critical security → High bugs → Medium validation/performance → Low quality (document only)
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 3.1, 3.2, 3.3, 3.4, 4.1, 4.2, 4.3, 4.4, 5.1, 5.2, 5.3, 5.4, 5.5_

- [ ] 2. Pass 2: Batches/Expiry Sub-Module Review
  - [x] 2.1 Review Batches/Expiry backend — service, repository, routes
    - Read and inspect in dependency order: `BatchService.php` → `BatchRepository.php` → `public/api/routes/batches.php`
    - Apply full inspection checklist: SQL injection, authorization, business_id scoping, FEFO logic correctness, expiry date handling, atomic stock operations, race conditions, validation gaps, N+1 queries, dead code, pattern deviations
    - Document all findings using `P2-[CATEGORY]-[NNN]` format with severity, location, current code, and recommended fix
    - _Requirements: 6.1, 6.2, 6.3, 7.1, 7.2, 7.3, 8.1, 8.2, 9.1, 9.2, 9.4_

  - [x] 2.2 Review Batches/Expiry frontend — page JS, HTML template
    - Read and inspect: `batches.js` → `public/html/Items/batches.html`
    - Check for XSS, missing output encoding, stale data after operations, excessive polling, missing form validations for date/quantity fields, accessibility issues
    - Cross-layer validation: verify batch data rendering, refresh behavior after CRUD operations, pagination assumptions
    - Document all findings using `P2-[CATEGORY]-[NNN]` format
    - _Requirements: 6.4, 7.4, 8.3, 9.3_

  - [x] 2.3 Apply direct fixes for Pass 2 findings
    - Apply code fixes for Critical and High severity findings that are localized
    - Apply Medium fixes that are isolated and unambiguous
    - Document architectural concerns (e.g., batch deduction strategy redesigns) without fixing
    - _Requirements: 6.1, 6.2, 6.3, 6.4, 7.1, 7.2, 7.3, 7.4, 8.1, 8.2, 8.3, 9.1, 9.2, 9.3, 9.4_

- [ ] 3. Pass 3: Manufacturing Sub-Module Review
  - [x] 3.1 Review Manufacturing backend — controller, service, repository, routes
    - Read and inspect in dependency order: `ManufacturingController.php` → `ManufacturingService.php` → `ManufacturingRepository.php` → `public/api/routes/manufacturing.php`
    - Apply full inspection checklist: SQL injection, authorization, business_id scoping, input sanitization, recipe validation logic, production order status transitions, component consumption correctness, transaction usage, N+1 queries, dead code, pattern deviations
    - Document all findings using `P3-[CATEGORY]-[NNN]` format with severity, location, current code, and recommended fix
    - _Requirements: 10.1, 10.2, 10.3, 11.1, 11.2, 11.3, 11.4, 12.1, 12.2, 13.1, 13.2, 13.4_

  - [x] 3.2 Review Manufacturing frontend — page JS, modal JS, HTML templates
    - Read and inspect: `productionOrders.js` → `productionOrderForm.modal.js`, `recipeForm.modal.js` → `public/html/Items/productionOrders.html`, `productionOrderForm.html`, `recipeForm.html`
    - Check for XSS, unsanitized DOM insertion, status-dependent UI behavior correctness, missing client-side validations (positive quantities, required fields, valid dates), redundant API calls, accessibility issues
    - Cross-layer validation: verify form submission data matches backend expectations, status transitions reflected correctly
    - Document all findings using `P3-[CATEGORY]-[NNN]` format
    - _Requirements: 10.4, 11.5, 12.3, 13.3_

  - [x] 3.3 Apply direct fixes for Pass 3 findings
    - Apply code fixes for Critical and High severity findings that are localized
    - Apply Medium fixes that are isolated and unambiguous
    - Document architectural concerns (e.g., concurrency in production order completion) without fixing
    - _Requirements: 10.1, 10.2, 10.3, 10.4, 11.1, 11.2, 11.3, 11.4, 11.5, 12.1, 12.2, 12.3, 13.1, 13.2, 13.3, 13.4_

- [ ] 4. Pass 4: Services Sub-Module Review
  - [x] 4.1 Review Services backend — service, repositories, routes
    - Read and inspect in dependency order: `ServiceService.php` → `ServiceRepository.php`, `ServiceCategoryRepository.php` → `public/api/routes/services.php`
    - Apply full inspection checklist: SQL injection, authorization, business_id/tenant_id scoping, parameterized statements, is_system enforcement, CRUD logic correctness, category deletion handling, validation gaps, N+1 queries, dead code, pattern deviations, routing consistency with other modules
    - Document all findings using `P4-[CATEGORY]-[NNN]` format with severity, location, current code, and recommended fix
    - _Requirements: 14.1, 14.2, 14.3, 15.1, 15.2, 16.1, 16.2, 16.4, 16.5_

  - [x] 4.2 Review Services frontend — page JS, modal JS, HTML templates
    - Read and inspect: `services.js` → `serviceForm.modal.js`, `serviceCategories.modal.js` → `public/html/Services/services.html`, `serviceForm.html`, `serviceCategories.html`
    - Check for XSS, unsanitized output, list refresh after CRUD, category filtering correctness, create/edit mode transitions, stale data, missing client-side validations (required fields, numeric fields, field length), accessibility issues
    - Cross-layer validation: verify frontend assumptions match backend response shapes and validation rules
    - Document all findings using `P4-[CATEGORY]-[NNN]` format
    - _Requirements: 14.4, 15.3, 15.4, 16.3_

  - [x] 4.3 Apply direct fixes for Pass 4 findings
    - Apply code fixes for Critical and High severity findings that are localized
    - Apply Medium fixes that are isolated and unambiguous
    - Document architectural concerns without fixing
    - _Requirements: 14.1, 14.2, 14.3, 14.4, 15.1, 15.2, 15.3, 15.4, 16.1, 16.2, 16.3, 16.4, 16.5_

- [ ] 5. Cross-pass summary and regression verification
  - [x] 5.1 Produce cross-pass summary of recurring patterns
    - Identify systemic issues that appear across multiple sub-modules
    - Document shared architectural concerns that warrant project-wide fixes
    - Suggest shared utility improvements (sanitization helpers, validation middleware)
    - Produce per-pass summary with finding counts by category and severity
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 3.1, 3.2, 3.3, 3.4, 4.1, 4.2, 4.3, 4.4, 5.1, 5.2, 5.3, 5.4, 5.5, 6.1, 6.2, 6.3, 6.4, 7.1, 7.2, 7.3, 7.4, 8.1, 8.2, 8.3, 9.1, 9.2, 9.3, 9.4, 10.1, 10.2, 10.3, 10.4, 11.1, 11.2, 11.3, 11.4, 11.5, 12.1, 12.2, 12.3, 13.1, 13.2, 13.3, 13.4, 14.1, 14.2, 14.3, 14.4, 15.1, 15.2, 15.3, 15.4, 16.1, 16.2, 16.3, 16.4, 16.5_

  - [ ] 5.2 Run test suites to verify no regressions from applied fixes
    - Run `vendor/bin/phpunit` to verify all PHP tests pass
    - Run `npx vitest --run` to verify all JS tests pass
    - If any test fails, identify the causing fix and revert or correct it
    - _Requirements: 1.1, 1.5, 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 5.1, 5.2, 5.3, 5.5, 6.1, 7.1, 7.2, 7.3, 9.1, 10.1, 10.3, 11.1, 11.2, 11.3, 11.4, 13.1, 13.2, 14.2, 15.1, 15.2, 16.2_

## Notes

- Each pass (1–4) is independent and can execute in any order — no cross-pass dependencies exist between passes 1–4
- Task 5 (cross-pass summary + regression check) depends on all four passes completing
- The review does not produce new features — output is findings documentation + direct code fixes
- Findings use the `[PASS]-[CATEGORY]-[NUMBER]` format defined in the design (e.g., `P1-SEC-001`)
- Direct fixes are applied only when: localized, unambiguous, no architectural decision required
- Architectural concerns are documented for later design discussion without immediate fixes
- The inspection checklist from the design document is applied to every file in the defined scope

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1", "2.1", "3.1", "4.1"] },
    { "id": 1, "tasks": ["1.2", "2.2", "3.2", "4.2"] },
    { "id": 2, "tasks": ["1.3", "2.3", "3.3", "4.3"] },
    { "id": 3, "tasks": ["5.1"] },
    { "id": 4, "tasks": ["5.2"] }
  ]
}
```
