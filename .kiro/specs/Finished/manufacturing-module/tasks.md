# Implementation Plan: Manufacturing Module

## Overview

This plan implements the manufacturing module following the established ERP architecture: database migrations → repository → service → controller/routes → PriceUpdater integration → frontend (JS + HTML templates). Each task builds incrementally, ensuring no orphaned code. The module introduces recipe-driven manufactured item creation, production order execution with atomic stock adjustments, cascading cost recalculation, and cost change flagging with optional auto-markup.

## Tasks

- [x] 1. Database migration and seed data
  - [x] 1.1 Create the manufacturing database migration file
    - Create `database/migrations/add_manufacturing_module.sql`
    - Add `recipes` table with columns: id, tenant_id, business_id, manufactured_item_id, yield_quantity, notes, is_active, created_by, created_at, updated_at
    - Add `recipe_items` table with columns: id, recipe_id, component_item_id, quantity; unique constraint on (recipe_id, component_item_id)
    - Add `production_orders` table with columns: id, tenant_id, business_id, recipe_id, manufactured_item_id, production_quantity, manufacturing_cost, unit_manufacturing_cost, status (ENUM COMPLETED/REVERSED), production_date, created_by, created_at
    - Add `production_order_items` table with columns: id, production_order_id, component_item_id, quantity_consumed, unit_cost
    - Add `item_cost_flags` table with columns: id, item_id (UNIQUE), old_cost_price, new_cost_price, is_dismissed, created_at, dismissed_at
    - Add `item_auto_markup` table with columns: id, item_id (UNIQUE), markup_percent, is_enabled, created_at, updated_at
    - Add `has_cost_change_flag` TINYINT(1) DEFAULT 0 column to `items` table
    - Add foreign key constraints and indexes
    - _Requirements: 1.8, 5.9, 6.3, 6.8, 8.1, 8.2, 11.4_

  - [x] 1.2 Create seed data for Manufactured item type and permissions
    - Insert "Manufactured" item type into `item_types` for all existing businesses (idempotent)
    - Insert manufacturing permissions into `permissions` table: manufacturing.view, manufacturing.create, manufacturing.edit, manufacturing.delete
    - Assign all manufacturing permissions to the business_owner role
    - _Requirements: 1.2, 9.1, 9.2, 9.3, 9.4_

- [x] 2. Backend repository layer
  - [x] 2.1 Create ManufacturingRepository class
    - Create `src/Repository/ManufacturingRepository.php`
    - Implement constructor accepting PDO instance
    - Implement recipe methods: insertRecipe, updateRecipe, deleteRecipe, getRecipeById, getRecipeByItemId, getAllRecipes, getRecipesByComponentItem
    - Implement recipe items methods: insertRecipeItems, deleteRecipeItems, getRecipeItems
    - All recipe queries must scope by tenant_id and business_id
    - Use prepared statements for all queries (following existing repository patterns)
    - _Requirements: 1.8, 2.1, 3.1, 4.3, 4.4, 8.1_

  - [x] 2.2 Add production order methods to ManufacturingRepository
    - Implement insertProductionOrder, insertProductionOrderItems, getProductionOrderById, getAllProductionOrders, updateProductionOrderStatus, countProductionOrdersForRecipe
    - getAllProductionOrders must support optional filters: date range (start_date, end_date) and manufactured_item_id
    - getAllProductionOrders must order by production_date DESC
    - All queries scoped by tenant_id and business_id
    - _Requirements: 5.9, 7.1, 7.2, 7.3, 8.2_

  - [x] 2.3 Add cost flag and auto-markup methods to ManufacturingRepository
    - Implement setCostChangeFlag, getCostChangeFlag, dismissCostChangeFlag
    - Implement getAutoMarkupConfig, setAutoMarkupConfig
    - setCostChangeFlag must also update items.has_cost_change_flag = 1
    - dismissCostChangeFlag must also update items.has_cost_change_flag = 0
    - _Requirements: 6.8, 11.1, 11.2, 11.4_

- [x] 3. Backend service layer
  - [x] 3.1 Create ManufacturingService class with recipe CRUD
    - Create `src/Services/ManufacturingService.php`
    - Implement constructor accepting PDO, instantiate ManufacturingRepository, ItemService, AuditService
    - Implement createRecipe: validate inputs (name not empty, at least one component, quantities > 0, yield > 0, components exist in tenant/business, no self-reference), begin transaction, create item via ItemService with type "Manufactured" and calculated cost_price, insert recipe, insert recipe_items, audit log, commit, return recipe_id and item_id
    - Implement updateRecipe: validate inputs, begin transaction, update recipe, delete old recipe_items, insert new recipe_items, recalculate and update manufactured item cost_price, update manufactured item name if changed, audit log with before/after, commit
    - Implement deleteRecipe: check countProductionOrdersForRecipe > 0 → reject with error, begin transaction, delete recipe_items, delete recipe, deactivate manufactured item (is_active = 0), audit log, commit
    - Implement getRecipe, listRecipes, getRecipeByItemId
    - Implement calculateUnitCost(recipeItems, yieldQuantity): sum of (cost_price × quantity) / yieldQuantity
    - _Requirements: 1.1–1.11, 2.1–2.6, 3.1–3.4, 4.3, 4.4_

  - [x] 3.2 Add production order methods to ManufacturingService
    - Implement createProductionOrder: validate recipe exists, production_quantity > 0, calculate required quantities per component, check stock sufficiency for all components (collect all shortfalls before rejecting), begin transaction, deduct each component via ItemService.adjustStock(type: OUT), add manufactured item via ItemService.adjustStock(type: IN), insert production_order and production_order_items, audit log, commit
    - Implement reverseProductionOrder: validate order exists, check status !== REVERSED (reject if already reversed), begin transaction, add back each component via adjustStock(IN), deduct manufactured item via adjustStock(OUT), update status to REVERSED, audit log, commit
    - Implement getProductionOrder, listProductionOrders (with optional filters)
    - _Requirements: 5.1–5.10, 6.1–6.3, 7.1–7.3, 10.1–10.7_

  - [x] 3.3 Add cascading recalculation and cost flag methods to ManufacturingService
    - Implement recalculateAffectedRecipes(componentItemId): get all recipes using this component, for each recipe recalculate unit cost from current component prices, update manufactured item cost_price, set cost change flag with old/new values, apply auto-markup if configured (new_cost × (1 + markup_percent/100))
    - Implement dismissCostChangeFlag(itemId): delegate to repository
    - _Requirements: 6.5–6.8, 11.1–11.5_

- [x] 4. Backend controller and API routes
  - [x] 4.1 Create ManufacturingController class
    - Create `src/Controllers/ManufacturingController.php` extending BaseController
    - Implement indexRecipes: call listRecipes, return JSON array
    - Implement viewRecipe(id): call getRecipe, return 404 if not found, return JSON
    - Implement storeRecipe: parse JSON body, call createRecipe, return 201 with recipe_id and item_id
    - Implement updateRecipe(id): parse JSON body, call updateRecipe, return 200
    - Implement deleteRecipe(id): call deleteRecipe, return 200 or 422 if has production orders
    - Implement indexOrders: call listProductionOrders with query filters, return JSON array
    - Implement viewOrder(id): call getProductionOrder, return 404 if not found, return JSON
    - Implement storeOrder: parse JSON body, call createProductionOrder, return 201
    - Implement reverseOrder(id): call reverseProductionOrder, return 200 or 422 if already reversed
    - Implement dismissCostFlag(itemId): call dismissCostChangeFlag, return 200
    - Implement setAutoMarkup(itemId): parse JSON body, call setAutoMarkupConfig, return 200
    - Wrap all methods in try/catch, return 422 for validation errors, 500 for unexpected errors
    - _Requirements: 1.1–1.11, 2.1–2.6, 3.1–3.4, 5.1–5.10, 7.1–7.3, 9.5, 10.1–10.7, 11.2, 11.4_

  - [x] 4.2 Create manufacturing API routes file
    - Create `public/api/routes/manufacturing.php`
    - Register all 11 routes with permission guards:
      - GET /manufacturing/recipes → ['manufacturing.view']
      - GET /manufacturing/recipes/{id} → ['manufacturing.view']
      - POST /manufacturing/recipes → ['manufacturing.create']
      - PUT /manufacturing/recipes/{id} → ['manufacturing.edit']
      - DELETE /manufacturing/recipes/{id} → ['manufacturing.delete']
      - GET /manufacturing/orders → ['manufacturing.view']
      - GET /manufacturing/orders/{id} → ['manufacturing.view']
      - POST /manufacturing/orders → ['manufacturing.create']
      - POST /manufacturing/orders/{id}/reverse → ['manufacturing.create']
      - POST /manufacturing/items/{id}/dismiss-cost-flag → ['manufacturing.edit']
      - POST /manufacturing/items/{id}/auto-markup → ['manufacturing.edit']
    - Include the routes file in `public/api/index.php`
    - _Requirements: 9.1–9.5_

- [x] 5. Checkpoint - Backend core complete
  - Ensure all backend files are syntactically correct and follow existing patterns
  - Ensure all tests pass, ask the user if questions arise.

- [x] 6. PriceUpdater integration for cascading recalculation
  - [x] 6.1 Extend PriceUpdater to trigger cascading recalculation
    - Modify `src/Services/PriceUpdater.php`: after updating an item's cost_price in `updatePricesFromPO()`, instantiate ManufacturingService and call `recalculateAffectedRecipes($itemId)`
    - _Requirements: 6.5, 6.6, 6.7, 6.8_

  - [x] 6.2 Extend ItemService to trigger cascading recalculation on manual cost_price edit
    - Modify `src/Services/ItemService.php`: in the update method, detect if cost_price has changed, and if so call ManufacturingService::recalculateAffectedRecipes($itemId)
    - _Requirements: 6.5, 6.6, 6.7_

- [x] 7. Frontend HTML templates
  - [x] 7.1 Create recipe form HTML template
    - Create `public/html/Items/recipeForm.html`
    - Include: manufactured item name input, component items table (item selector dropdown, quantity input, unit display, cost display per row, add/remove row buttons), yield quantity input, sale price input (optional), auto-markup percentage input (optional), real-time cost-per-unit display area, notes textarea, save/cancel buttons
    - Follow existing modal form patterns (Bootstrap 5 structure matching itemForm.html)
    - _Requirements: 1.1, 1.10, 11.4, 12.1_

  - [x] 7.2 Create production order form HTML template
    - Create `public/html/Items/productionOrderForm.html`
    - Include: recipe selector dropdown, production quantity input, calculated material requirements table (read-only), estimated total cost display, estimated unit cost display, execute/cancel buttons
    - Follow existing modal form patterns
    - _Requirements: 5.1, 5.2, 6.1, 6.2_

- [x] 8. Frontend JavaScript - Recipe form
  - [x] 8.1 Create recipeForm.modal.js
    - Create `public/assets/js/pages/Items/recipeForm.modal.js`
    - Implement mount({ modalRoot, data }): load template, if data.recipe_id provided fetch recipe data and populate form (edit mode), otherwise initialize empty form (create mode)
    - Implement addComponentRow(): add a new row to the component items table with item selector and quantity input
    - Implement removeComponentRow(index): remove the specified row
    - Implement recalculateCost(): on every input change (component added/removed, quantity changed, yield changed), calculate and display cost per unit = sum(component_cost_price × quantity) / yield_quantity
    - Implement validateForm(): check name not empty, at least one component, all quantities > 0, yield > 0
    - Implement saveRecipe(): POST (create) or PUT (update) to /api/manufacturing/recipes, handle success (close modal, refresh items list) and error responses (display validation errors)
    - Implement unmount(): cleanup event listeners
    - Use apiFetch for all API calls (following existing patterns)
    - _Requirements: 1.1, 1.3, 1.10, 2.1, 12.1–12.6_

- [x] 9. Frontend JavaScript - Production order form
  - [x] 9.1 Create productionOrderForm.modal.js
    - Create `public/assets/js/pages/Items/productionOrderForm.modal.js`
    - Implement mount({ modalRoot, data }): load template, if data.recipe_id provided pre-select recipe, populate recipe dropdown from /api/manufacturing/recipes
    - On recipe selection: fetch recipe details, display component requirements
    - On production quantity change: recalculate required quantities per component (recipe_qty / yield × production_qty), display estimated cost
    - Implement validateForm(): recipe selected, production_quantity > 0
    - Implement executeOrder(): POST to /api/manufacturing/orders, handle success (close modal, refresh) and errors (display insufficient stock details)
    - Implement unmount(): cleanup
    - _Requirements: 5.1–5.4, 6.1, 6.2_

- [x] 10. Frontend JavaScript - Items page modifications
  - [x] 10.1 Modify items.js for manufacturing integration
    - Modify `public/assets/js/pages/Items/items.js`
    - Add "Create Recipe" button alongside existing "Add Item" button (check manufacturing.create permission)
    - Wire "Create Recipe" button to open recipeForm.modal.js
    - Intercept edit action: if item type_name === 'Manufactured', open recipeForm.modal.js (fetch recipe by item_id via GET /api/manufacturing/recipes?item_id={id} or dedicated endpoint) instead of itemForm.modal.js
    - Display cost change flag indicator (badge/icon) on items with has_cost_change_flag === 1, showing old and new cost on hover/click
    - Add "Produce" button for manufactured items that opens productionOrderForm.modal.js with the recipe pre-selected
    - Add "Dismiss" action for cost change flags (POST /api/manufacturing/items/{id}/dismiss-cost-flag)
    - _Requirements: 4.1, 4.2, 4.5, 4.6, 11.1, 11.2, 11.3_

- [x] 11. Checkpoint - Full feature integration
  - Ensure all frontend files load correctly and follow existing patterns
  - Ensure all tests pass, ask the user if questions arise.

- [x] 12. Property-based tests
  - [x] 12.1 Write property test for unit cost calculation correctness
    - Create `tests/Property/ManufacturingCostCalculationPropertyTest.php`
    - **Property 1: Unit cost calculation correctness**
    - Generate random sets of component items with positive cost_prices and positive quantities, random yield > 0
    - Assert calculated unit cost equals sum(cost_price × quantity) / yield_quantity
    - **Validates: Requirements 1.3, 2.5, 6.1, 6.2, 6.4, 12.6**

  - [x] 12.2 Write property test for invalid recipe input rejection
    - Create `tests/Property/ManufacturingValidationPropertyTest.php`
    - **Property 3: Invalid recipe input rejection**
    - Generate invalid inputs: empty names, zero/negative quantities, zero/negative yield, non-existent component IDs
    - Assert all are rejected with no data persisted
    - **Validates: Requirements 1.4, 1.5, 1.6, 1.7, 2.2, 2.3, 8.3**

  - [x] 12.3 Write property test for production order required quantity calculation
    - Add to `tests/Property/ManufacturingCostCalculationPropertyTest.php`
    - **Property 4: Required quantity calculation**
    - Generate random recipe_item quantities, yield quantities, and production quantities (all > 0)
    - Assert required_qty = (recipe_item_qty / yield_qty) × production_qty
    - **Validates: Requirements 5.2**

  - [x] 12.4 Write property test for stock conservation
    - Create `tests/Property/ManufacturingStockConservationPropertyTest.php`
    - **Property 5: Production order stock conservation**
    - Generate recipes with random components/quantities and sufficient stock, execute production order
    - Assert each component decreased by exactly required_qty and manufactured item increased by production_qty
    - **Validates: Requirements 5.5, 5.6**

  - [x] 12.5 Write property test for reversal as inverse
    - Add to `tests/Property/ManufacturingStockConservationPropertyTest.php`
    - **Property 6: Production order reversal is the inverse of execution**
    - Execute then reverse a production order, assert all stock levels return to pre-execution state
    - **Validates: Requirements 10.1, 10.2, 10.5**

  - [x] 12.6 Write property test for insufficient stock rejection
    - Create `tests/Property/ManufacturingInsufficientStockPropertyTest.php`
    - **Property 7: Insufficient stock prevents production order execution**
    - Generate scenarios where at least one component has stock < required_qty
    - Assert order is rejected, no stock changes occur, error identifies short items
    - **Validates: Requirements 5.3, 5.4**

  - [x] 12.7 Write property test for cascading recalculation
    - Create `tests/Property/ManufacturingCascadingPropertyTest.php`
    - **Property 8: Cascading recalculation updates all affected manufactured items**
    - Generate component price changes, verify all affected recipes have updated manufactured item cost_price and cost change flags set
    - **Validates: Requirements 6.5, 6.6, 6.7, 6.8**

  - [x] 12.8 Write property test for auto-markup calculation
    - Add to `tests/Property/ManufacturingCascadingPropertyTest.php`
    - **Property 9: Auto-markup applies correct sale price on cascading recalculation**
    - Generate items with auto-markup enabled, trigger recalculation, assert sale_price = new_cost × (1 + markup_percent / 100)
    - **Validates: Requirements 11.5**

  - [x] 12.9 Write property test for double reversal prevention
    - Create `tests/Property/ManufacturingIdempotencePropertyTest.php`
    - **Property 12: Double reversal prevention**
    - Execute and reverse a production order, attempt second reversal, assert rejection with no stock changes
    - **Validates: Requirements 10.6**

  - [x] 12.10 Write property test for production order list ordering
    - Create `tests/Property/ManufacturingOrderFilterPropertyTest.php`
    - **Property 14: Production order list ordering**
    - Generate production orders with random dates, assert list returns them in descending production_date order
    - **Validates: Requirements 7.1**

  - [x] 12.11 Write property test for production order filtering
    - Add to `tests/Property/ManufacturingOrderFilterPropertyTest.php`
    - **Property 15: Production order date and item filtering**
    - Generate orders with various dates and items, apply filters, assert only matching orders returned
    - **Validates: Requirements 7.3**

- [x] 13. Unit and integration tests
  - [x] 13.1 Write unit tests for ManufacturingService recipe operations
    - Create `tests/Unit/ManufacturingRecipeTest.php`
    - Test recipe creation happy path with known values (Property 2 example-based)
    - Test recipe update recalculates cost correctly
    - Test recipe deletion deactivates manufactured item (Property 10 example-based)
    - Test recipe deletion rejected when production orders exist (Property 11 example-based)
    - Test self-referencing component rejection
    - _Requirements: 1.2, 1.3, 1.11, 2.5, 3.1, 3.2, 3.3_

  - [x] 13.2 Write unit tests for ManufacturingService production order operations
    - Create `tests/Unit/ManufacturingProductionOrderTest.php`
    - Test production order creation with known recipe and quantities
    - Test reversal restores stock to original levels
    - Test double reversal is rejected
    - Test insufficient stock returns descriptive error with all short items
    - _Requirements: 5.1–5.8, 10.1–10.6_

  - [x] 13.3 Write unit tests for cost flag and auto-markup
    - Create `tests/Unit/ManufacturingCostFlagTest.php`
    - Test cost change flag is set during cascading recalculation (Property 16 example-based)
    - Test flag dismissal clears the flag
    - Test auto-markup applies correct sale price
    - _Requirements: 6.8, 11.1–11.5_

  - [x] 13.4 Write integration test for full production flow
    - Create `tests/Integration/ManufacturingProductionFlowTest.php`
    - Test end-to-end: create recipe → verify item created → execute production order → verify stock changes → reverse → verify stock restored
    - Test transaction rollback on simulated failure
    - _Requirements: 5.5–5.8, 10.1–10.4_

  - [x] 13.5 Write integration test for cascading recalculation flow
    - Create `tests/Integration/ManufacturingCascadingFlowTest.php`
    - Test end-to-end: create recipe → update component cost_price via PriceUpdater → verify manufactured item cost_price updated → verify cost change flag set → verify auto-markup applied if configured
    - _Requirements: 6.5–6.8, 11.5_

  - [x] 13.6 Write integration test for tenant isolation
    - Create `tests/Integration/ManufacturingTenantIsolationTest.php`
    - Test: create recipe in tenant A, query from tenant B context, verify no results returned (Property 13 example-based)
    - _Requirements: 8.1, 8.2, 8.3_

- [x] 14. Final checkpoint - All tests pass
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP
- Each task references specific requirements for traceability
- Checkpoints ensure incremental validation
- Property tests validate universal correctness properties from the design document
- Unit tests validate specific examples and edge cases
- The implementation follows the established project patterns: Controller → Service → Repository, PDO prepared statements, AuthContext for tenant/business scoping, AuditService for logging
- All API calls from frontend use the existing `apiFetch` utility
- Frontend modals follow the existing componentManager pattern (mount/unmount lifecycle)
