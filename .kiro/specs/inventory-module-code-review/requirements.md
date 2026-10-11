# Requirements Document

## Introduction

Full code review of the Inventory module encompassing four sub-modules: Items/Stock, Batches/Expiry, Manufacturing, and Services. The review targets both frontend (vanilla JS pages and modals) and backend (PHP controllers, services, repositories, routes) for security issues, bugs, performance concerns, code quality, and missing validations. Findings are organized incrementally by sub-module.

## Glossary

- **Review_System**: The code review process and its tooling that inspects source code for defects
- **Items_Subsystem**: Backend and frontend code handling item catalog CRUD, stock levels, stock adjustments, item types, and price management (ItemsController, ItemService, ItemVisibilityService, ItemRepository, ItemTypeRepository, ItemPriceRepository, ItemPriceHistoryRepository, SupplierItemPriceRepository, PendingPriceService, PriceHistoryService, PriceUpdater, StockValidator, PendingPricesController, items.js, itemForm.modal.js, itemInsights.modal.js, itemTypes.modal.js, pendingPrices.modal.js, priceHistory.modal.js, stockAdjust.modal.js, stockView.modal.js, items route file)
- **Batches_Subsystem**: Backend and frontend code handling batch tracking, expiry management, and batch-level stock (BatchService, BatchRepository, batches.js, batches route file)
- **Manufacturing_Subsystem**: Backend and frontend code handling recipes/BOM, production orders, component consumption, and yield tracking (ManufacturingController, ManufacturingService, ManufacturingRepository, productionOrders.js, productionOrderForm.modal.js, recipeForm.modal.js, manufacturing route file)
- **Services_Subsystem**: Backend and frontend code handling the service catalog, service categories, and service CRUD (ServiceService, ServiceRepository, ServiceCategoryRepository, services.js, serviceForm.modal.js, serviceCategories.modal.js, services route file)
- **Security_Finding**: A defect related to SQL injection, authorization bypass, authentication flaw, or insufficient input sanitization
- **Bug_Finding**: A logic error, incorrect conditional, race condition, or functional defect that produces wrong results
- **Performance_Finding**: A defect causing unnecessary resource consumption such as N+1 queries, missing database indexes, inefficient loops, or redundant API calls
- **Quality_Finding**: A code quality issue including inconsistent patterns, poor error handling, dead code, misleading naming, or missing documentation
- **Validation_Finding**: A missing or insufficient input validation including boundary conditions, type coercion, required field enforcement, or range checks

## Requirements

### Requirement 1: Items/Stock Sub-Module Security Review

**User Story:** As a development team lead, I want all security vulnerabilities in the Items/Stock sub-module identified and documented, so that authorization bypasses, injection vectors, and input sanitization gaps are remediated.

#### Acceptance Criteria

1. WHEN the Review_System inspects ItemsController and PendingPricesController, THE Review_System SHALL identify every instance where user-supplied input is interpolated into SQL queries without parameterized statements.
2. WHEN the Review_System inspects route definitions in the items route file, THE Review_System SHALL verify that every endpoint enforces appropriate permission checks via the router permission array.
3. WHEN the Review_System inspects ItemService, PendingPriceService, PriceUpdater, and StockValidator, THE Review_System SHALL identify every instance where tenant_id or business_id scoping is absent from data-modifying operations.
4. WHEN the Review_System inspects items.js, itemForm.modal.js, stockAdjust.modal.js, and pendingPrices.modal.js, THE Review_System SHALL identify every instance where user input is inserted into the DOM without proper escaping or sanitization.
5. WHEN the Review_System inspects ItemRepository, ItemPriceRepository, and SupplierItemPriceRepository, THE Review_System SHALL verify that all dynamic WHERE clause construction uses parameterized bindings rather than string concatenation.

### Requirement 2: Items/Stock Sub-Module Bug and Logic Review

**User Story:** As a development team lead, I want all logic errors and bugs in the Items/Stock sub-module identified, so that incorrect calculations, race conditions, and broken flows are fixed.

#### Acceptance Criteria

1. WHEN the Review_System inspects StockValidator, THE Review_System SHALL verify that negative stock prevention logic correctly handles concurrent adjustment requests and edge cases at zero stock level.
2. WHEN the Review_System inspects ItemService create and update flows, THE Review_System SHALL identify any missing or incorrect field assignments that could result in data corruption.
3. WHEN the Review_System inspects PriceUpdater, THE Review_System SHALL verify that price change calculations handle currency precision correctly and do not produce rounding errors.
4. WHEN the Review_System inspects PendingPriceService approval flow, THE Review_System SHALL verify that approving a pending price correctly updates the active price and creates a price history record.
5. WHEN the Review_System inspects itemForm.modal.js and stockAdjust.modal.js, THE Review_System SHALL identify any form submission logic that fails to prevent double-submission or ignores server error responses.
6. WHEN the Review_System inspects ItemVisibilityService, THE Review_System SHALL verify that item visibility filtering correctly respects module activation status and item type system flags.

### Requirement 3: Items/Stock Sub-Module Performance Review

**User Story:** As a development team lead, I want all performance inefficiencies in the Items/Stock sub-module identified, so that slow queries and redundant operations are optimized.

#### Acceptance Criteria

1. WHEN the Review_System inspects ItemRepository list and search methods, THE Review_System SHALL identify every query that executes inside a loop (N+1 pattern) instead of using batch fetching or joins.
2. WHEN the Review_System inspects ItemPriceHistoryRepository, THE Review_System SHALL identify queries that lack appropriate index usage for common filter combinations (item_id, business_id, date range).
3. WHEN the Review_System inspects items.js and stockView.modal.js, THE Review_System SHALL identify any DataTable initialization or API call pattern that fetches all records without server-side pagination for potentially large datasets.
4. WHEN the Review_System inspects ItemsController list endpoints, THE Review_System SHALL identify any response payload that includes unnecessary nested data not required by the frontend consumer.

### Requirement 4: Items/Stock Sub-Module Code Quality Review

**User Story:** As a development team lead, I want all code quality issues in the Items/Stock sub-module documented, so that maintainability, consistency, and readability are improved.

#### Acceptance Criteria

1. WHEN the Review_System inspects all Items_Subsystem PHP files, THE Review_System SHALL identify any dead code, unused variables, unreachable branches, or commented-out code blocks.
2. WHEN the Review_System inspects all Items_Subsystem PHP files, THE Review_System SHALL identify any inconsistency in error handling patterns compared to the BaseController and project conventions.
3. WHEN the Review_System inspects all Items_Subsystem JS files, THE Review_System SHALL identify any inconsistent usage of the apiFetch utility, missing error handling callbacks, or non-standard notification patterns.
4. WHEN the Review_System inspects all Items_Subsystem HTML templates, THE Review_System SHALL identify any accessibility issues including missing ARIA labels, missing form labels, or non-semantic markup in interactive elements.

### Requirement 5: Items/Stock Sub-Module Validation Review

**User Story:** As a development team lead, I want all missing input validations in the Items/Stock sub-module identified, so that boundary conditions and required field enforcement are properly implemented.

#### Acceptance Criteria

1. WHEN the Review_System inspects ItemsController and PendingPricesController request handling, THE Review_System SHALL identify every endpoint that accepts numeric input without validating type, range, or sign (negative values where only positive are valid).
2. WHEN the Review_System inspects ItemService create and update methods, THE Review_System SHALL verify that all required fields (name, type_id, unit) are validated as non-empty before database insertion.
3. WHEN the Review_System inspects StockValidator, THE Review_System SHALL verify that stock adjustment quantities are validated for zero values, excessively large values, and non-numeric input.
4. WHEN the Review_System inspects itemForm.modal.js, THE Review_System SHALL identify any form field that accepts user input without client-side validation matching the backend constraints.
5. WHEN the Review_System inspects PriceUpdater, THE Review_System SHALL verify that price values are validated to reject negative prices, zero prices where inappropriate, and values exceeding storage precision.

### Requirement 6: Batches/Expiry Sub-Module Security Review

**User Story:** As a development team lead, I want all security vulnerabilities in the Batches/Expiry sub-module identified, so that unauthorized batch manipulation and data leakage are prevented.

#### Acceptance Criteria

1. WHEN the Review_System inspects BatchRepository, THE Review_System SHALL verify that all queries enforce business_id scoping to prevent cross-business batch data access.
2. WHEN the Review_System inspects the batches route file, THE Review_System SHALL verify that every endpoint enforces appropriate permission checks and that batch identifiers in URL paths are validated as belonging to the current business.
3. WHEN the Review_System inspects BatchService, THE Review_System SHALL identify any operation that modifies batch stock levels without verifying the requesting user has authorization for that specific batch's parent item.
4. WHEN the Review_System inspects batches.js, THE Review_System SHALL identify any instance where batch data is rendered into the DOM without proper output encoding.

### Requirement 7: Batches/Expiry Sub-Module Bug and Logic Review

**User Story:** As a development team lead, I want all logic errors in the Batches/Expiry sub-module identified, so that incorrect expiry tracking and batch stock discrepancies are fixed.

#### Acceptance Criteria

1. WHEN the Review_System inspects BatchService, THE Review_System SHALL verify that batch stock deduction follows FEFO (First Expiry First Out) or the documented policy, and handles the case where a batch has insufficient stock.
2. WHEN the Review_System inspects BatchService, THE Review_System SHALL verify that expiry date comparisons use consistent timezone handling and do not produce off-by-one errors at day boundaries.
3. WHEN the Review_System inspects BatchRepository, THE Review_System SHALL verify that batch quantity updates use atomic operations to prevent race conditions during concurrent stock movements.
4. WHEN the Review_System inspects batches.js, THE Review_System SHALL verify that the batch list correctly refreshes after batch operations (create, adjust, consume) and does not display stale data.

### Requirement 8: Batches/Expiry Sub-Module Performance Review

**User Story:** As a development team lead, I want all performance issues in the Batches/Expiry sub-module identified, so that batch queries and expiry checks operate efficiently at scale.

#### Acceptance Criteria

1. WHEN the Review_System inspects BatchRepository list methods, THE Review_System SHALL identify any query that loads all batches for an item without pagination when the batch count could be large.
2. WHEN the Review_System inspects BatchService expiry notification logic, THE Review_System SHALL identify any pattern that scans all batches across all items instead of using indexed date-range queries.
3. WHEN the Review_System inspects batches.js, THE Review_System SHALL identify any frontend polling or repeated API call pattern that could cause excessive server load.

### Requirement 9: Batches/Expiry Sub-Module Quality and Validation Review

**User Story:** As a development team lead, I want all code quality and validation gaps in the Batches/Expiry sub-module documented, so that data integrity and code maintainability are improved.

#### Acceptance Criteria

1. WHEN the Review_System inspects BatchService, THE Review_System SHALL verify that batch creation validates batch_number uniqueness within an item, expiry_date is not in the past (or flagged appropriately), and initial quantity is positive.
2. WHEN the Review_System inspects BatchRepository, THE Review_System SHALL identify any inconsistency in error handling or return value patterns compared to other repository classes in the project.
3. WHEN the Review_System inspects batches.js and the batches HTML template, THE Review_System SHALL identify missing form validations for date fields, quantity fields, and required batch identifiers.
4. WHEN the Review_System inspects all Batches_Subsystem files, THE Review_System SHALL identify any dead code, unused imports, or redundant logic paths.

### Requirement 10: Manufacturing Sub-Module Security Review

**User Story:** As a development team lead, I want all security vulnerabilities in the Manufacturing sub-module identified, so that unauthorized recipe and production order manipulation is prevented.

#### Acceptance Criteria

1. WHEN the Review_System inspects ManufacturingController, THE Review_System SHALL identify every instance where user-supplied input (recipe data, production quantities, component lists) is processed without proper type validation or sanitization.
2. WHEN the Review_System inspects the manufacturing route file, THE Review_System SHALL verify that every endpoint enforces appropriate permission checks and that resource identifiers are validated as belonging to the current business.
3. WHEN the Review_System inspects ManufacturingRepository, THE Review_System SHALL verify that all queries enforce business_id scoping and use parameterized statements for all dynamic values.
4. WHEN the Review_System inspects productionOrderForm.modal.js and recipeForm.modal.js, THE Review_System SHALL identify any instance where recipe or production data is inserted into the DOM without sanitization.

### Requirement 11: Manufacturing Sub-Module Bug and Logic Review

**User Story:** As a development team lead, I want all logic errors in the Manufacturing sub-module identified, so that incorrect production calculations and stock inconsistencies are fixed.

#### Acceptance Criteria

1. WHEN the Review_System inspects ManufacturingService production order completion flow, THE Review_System SHALL verify that component consumption correctly reduces stock for each component item and batch, and that partial yields produce accurate fractional quantities.
2. WHEN the Review_System inspects ManufacturingService, THE Review_System SHALL verify that recipe validation enforces that only valid component types (Raw Material, Trading) are allowed as recipe ingredients and that manufactured items cannot be added as their own components.
3. WHEN the Review_System inspects ManufacturingService, THE Review_System SHALL verify that production order status transitions (Draft → In Progress → Completed/Cancelled) are enforced and that invalid transitions are rejected.
4. WHEN the Review_System inspects ManufacturingRepository, THE Review_System SHALL verify that production order completion and component consumption occur within a database transaction to maintain stock consistency.
5. WHEN the Review_System inspects productionOrders.js, THE Review_System SHALL verify that the UI correctly reflects production order status and disables inappropriate actions for each status.

### Requirement 12: Manufacturing Sub-Module Performance Review

**User Story:** As a development team lead, I want all performance inefficiencies in the Manufacturing sub-module identified, so that recipe and production queries are optimized.

#### Acceptance Criteria

1. WHEN the Review_System inspects ManufacturingRepository recipe retrieval methods, THE Review_System SHALL identify any N+1 query pattern where recipe components are loaded individually rather than in a batch query.
2. WHEN the Review_System inspects ManufacturingService production order listing, THE Review_System SHALL identify any pattern that loads full recipe details for each production order in a list view instead of using summary joins.
3. WHEN the Review_System inspects productionOrders.js, THE Review_System SHALL identify any redundant API calls during page initialization or form rendering that could be consolidated.

### Requirement 13: Manufacturing Sub-Module Quality and Validation Review

**User Story:** As a development team lead, I want all code quality and validation gaps in the Manufacturing sub-module documented, so that data integrity and maintainability are improved.

#### Acceptance Criteria

1. WHEN the Review_System inspects ManufacturingController, THE Review_System SHALL verify that recipe creation validates all required fields (output item, components list with quantities), rejects empty component lists, and validates component quantities as positive numbers.
2. WHEN the Review_System inspects ManufacturingService, THE Review_System SHALL verify that production order creation validates planned_quantity as a positive number, validates the referenced recipe exists and belongs to the current business, and validates planned_date is a valid date.
3. WHEN the Review_System inspects recipeForm.modal.js and productionOrderForm.modal.js, THE Review_System SHALL identify any client-side validation gaps where the form permits submission of invalid data that the backend would reject.
4. WHEN the Review_System inspects all Manufacturing_Subsystem files, THE Review_System SHALL identify any dead code, inconsistent error handling, or deviations from established project architectural patterns.

### Requirement 14: Services Sub-Module Security Review

**User Story:** As a development team lead, I want all security vulnerabilities in the Services sub-module identified, so that unauthorized service manipulation and data access are prevented.

#### Acceptance Criteria

1. WHEN the Review_System inspects the services route file, THE Review_System SHALL verify that every endpoint enforces appropriate permission checks and that the routing pattern matches project conventions.
2. WHEN the Review_System inspects ServiceRepository and ServiceCategoryRepository, THE Review_System SHALL verify that all queries enforce business_id or tenant_id scoping and use parameterized statements for all dynamic values.
3. WHEN the Review_System inspects ServiceService, THE Review_System SHALL identify any operation that modifies service data without verifying the requesting user has appropriate authorization.
4. WHEN the Review_System inspects services.js, serviceForm.modal.js, and serviceCategories.modal.js, THE Review_System SHALL identify any instance where service data is rendered without proper output encoding.

### Requirement 15: Services Sub-Module Bug and Logic Review

**User Story:** As a development team lead, I want all logic errors in the Services sub-module identified, so that incorrect service catalog behavior and category management issues are fixed.

#### Acceptance Criteria

1. WHEN the Review_System inspects ServiceService CRUD operations, THE Review_System SHALL verify that service creation and updates correctly handle category assignment, and that deleting a category with assigned services is handled gracefully.
2. WHEN the Review_System inspects ServiceCategoryRepository, THE Review_System SHALL verify that the system type pattern (is_system flag) is correctly enforced to prevent modification or deletion of system-defined categories.
3. WHEN the Review_System inspects services.js, THE Review_System SHALL verify that the service list correctly refreshes after CRUD operations and that category filtering functions correctly.
4. WHEN the Review_System inspects serviceForm.modal.js, THE Review_System SHALL verify that the form correctly populates in edit mode and handles the transition between create and edit states without stale data.

### Requirement 16: Services Sub-Module Performance, Quality, and Validation Review

**User Story:** As a development team lead, I want all performance, quality, and validation gaps in the Services sub-module documented, so that the sub-module is efficient, maintainable, and robust.

#### Acceptance Criteria

1. WHEN the Review_System inspects ServiceRepository list methods, THE Review_System SHALL identify any query that loads all services without pagination or that fetches unnecessary related data for list views.
2. WHEN the Review_System inspects ServiceService, THE Review_System SHALL verify that service creation validates all required fields (name, category_id) and that price fields are validated for type and range.
3. WHEN the Review_System inspects serviceForm.modal.js and serviceCategories.modal.js, THE Review_System SHALL identify any missing client-side validations for required fields, numeric fields, and field length limits.
4. WHEN the Review_System inspects all Services_Subsystem files, THE Review_System SHALL identify any dead code, inconsistent patterns, missing error handling, or deviations from the project's established architectural conventions.
5. WHEN the Review_System inspects the services route file, THE Review_System SHALL verify that the routing structure is consistent with other module route files in the project (items, batches, manufacturing).
