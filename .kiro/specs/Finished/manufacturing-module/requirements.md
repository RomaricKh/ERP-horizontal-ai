# Requirements Document

## Introduction

This feature implements a manufacturing module that enables businesses to define recipes which simultaneously create manufactured items. The recipe form is the primary interface for creating manufactured items — saving a recipe automatically creates an item with type "Manufactured". The module supports real-time cost calculation during recipe creation, cascading price recalculation when raw material costs change, and margin protection notifications. Production orders atomically deduct raw materials and add finished products to stock. The module integrates with the existing stock management system via `ItemService.adjustStock()` and follows the established multi-tenant architecture.

## Glossary

- **Manufacturing_Module**: The system component responsible for managing recipes, executing production orders, tracking manufacturing costs, and cascading price recalculations
- **Recipe**: A reusable definition that specifies a manufactured item name, which component items and their quantities are required, and how many units the recipe produces (yield quantity). Saving a Recipe automatically creates a Manufactured_Item.
- **Recipe_Item**: A single line within a Recipe specifying one component item (raw material) and the quantity required per batch yield
- **Manufactured_Item**: An item with type "Manufactured" that is automatically created when a Recipe is saved. Its cost_price is derived from the Recipe's calculated cost per unit.
- **Component_Item**: An item consumed as input during a Production_Order (typically a raw material with type "Raw Material" or "Product")
- **Yield_Quantity**: The number of units of the Manufactured_Item that one batch of the Recipe produces
- **Production_Order**: A record representing the execution of a Recipe to manufacture a specified quantity of a finished product
- **Production_Order_Item**: A line within a Production_Order recording the actual quantity of a component item consumed during production
- **Production_Quantity**: The number of units of the Manufactured_Item to be manufactured in a Production_Order
- **Required_Quantity**: The quantity of a Component_Item needed for a Production_Order, calculated as (Recipe_Item quantity / Yield_Quantity) multiplied by Production_Quantity
- **Manufacturing_Cost**: The total cost of producing a batch, calculated as the sum of each Component_Item cost_price multiplied by its Required_Quantity
- **Unit_Manufacturing_Cost**: The Manufacturing_Cost divided by the Production_Quantity, representing the cost to produce one unit of the Manufactured_Item
- **Cascading_Recalculation**: The automatic process of updating all affected Manufactured_Item cost prices when a Component_Item cost_price changes
- **Cost_Change_Flag**: A marker on a Manufactured_Item indicating that its cost_price has changed due to a Cascading_Recalculation and the sale price should be reviewed

## Requirements

### Requirement 1: Recipe Creation and Manufactured Item Auto-Creation

**User Story:** As a business owner, I want to create a recipe that automatically creates a manufactured item, so that I can define my products and their material requirements in a single step.

#### Acceptance Criteria

1. THE Manufacturing_Module SHALL provide a recipe form that includes: manufactured item name, a table of Component_Items with quantities, and a Yield_Quantity field
2. WHEN a Recipe is saved, THE Manufacturing_Module SHALL automatically create an item with type "Manufactured" using the manufactured item name from the recipe form
3. WHEN a Recipe is saved, THE Manufacturing_Module SHALL set the Manufactured_Item cost_price to the calculated Unit_Manufacturing_Cost based on current Component_Item cost prices
4. WHEN a Recipe is created, THE Manufacturing_Module SHALL validate that each Component_Item exists and belongs to the current tenant and business
5. WHEN a Recipe is created, THE Manufacturing_Module SHALL validate that each Recipe_Item quantity is greater than zero
6. WHEN a Recipe is created, THE Manufacturing_Module SHALL validate that the Yield_Quantity is greater than zero
7. WHEN a Recipe is created, THE Manufacturing_Module SHALL validate that the manufactured item name is not empty
8. THE Manufacturing_Module SHALL store each Recipe scoped to a tenant_id and business_id
9. WHEN a Recipe is created, THE Manufacturing_Module SHALL record the action in the audit log
10. THE Manufacturing_Module SHALL allow the user to set a sale_price for the Manufactured_Item during recipe creation
11. WHEN a Recipe is saved, THE Manufacturing_Module SHALL validate that no Component_Item is the same as the Manufactured_Item being created

### Requirement 2: Recipe Editing

**User Story:** As a business owner, I want to edit existing recipes when material requirements change, so that my recipes stay up to date and the manufactured item's cost is recalculated.

#### Acceptance Criteria

1. WHEN a Recipe is updated, THE Manufacturing_Module SHALL allow modifying the manufactured item name, adding Recipe_Items, removing Recipe_Items, changing Recipe_Item quantities, and changing the Yield_Quantity
2. WHEN a Recipe is updated, THE Manufacturing_Module SHALL validate that at least one Recipe_Item remains after the update
3. WHEN a Recipe is updated, THE Manufacturing_Module SHALL validate that all modified Recipe_Item quantities are greater than zero
4. WHEN a Recipe is updated, THE Manufacturing_Module SHALL validate that no Component_Item is the same as the Manufactured_Item
5. WHEN a Recipe is updated, THE Manufacturing_Module SHALL recalculate the Manufactured_Item cost_price based on the updated recipe and current Component_Item cost prices
6. WHEN a Recipe is updated, THE Manufacturing_Module SHALL record the changes in the audit log with before and after values

### Requirement 3: Recipe Deletion

**User Story:** As a business owner, I want to delete recipes that are no longer needed, so that my recipe list remains clean and the associated manufactured item is deactivated.

#### Acceptance Criteria

1. WHEN a Recipe deletion is requested, THE Manufacturing_Module SHALL delete the Recipe and all associated Recipe_Items
2. IF a Recipe has been used by one or more Production_Orders, THEN THE Manufacturing_Module SHALL reject the deletion and return a descriptive error message
3. WHEN a Recipe is deleted, THE Manufacturing_Module SHALL deactivate the associated Manufactured_Item by setting its is_active flag to false
4. WHEN a Recipe is deleted, THE Manufacturing_Module SHALL record the action in the audit log

### Requirement 4: Recipe and Manufactured Item Listing

**User Story:** As a business owner, I want to view all my items including manufactured items on the items page, and access recipe creation from there, so that I have a unified view of my inventory.

#### Acceptance Criteria

1. THE Manufacturing_Module SHALL display Manufactured_Items alongside raw materials and products on the items page with type "Manufactured"
2. THE Manufacturing_Module SHALL provide a "Create Recipe" button on the items page that opens the recipe form
3. WHEN a single Recipe is requested, THE Manufacturing_Module SHALL return the Recipe details including all Recipe_Items with their Component_Item names, quantities, units, and the Yield_Quantity
4. THE Manufacturing_Module SHALL return all active Recipes for the current business with their Manufactured_Item name, component count, and calculated cost per unit
5. WHEN the user clicks edit on a Manufactured_Item in the items list, THE Manufacturing_Module SHALL open the recipe form (not the regular item edit form) pre-populated with the associated Recipe data
6. WHEN the user clicks edit on a non-manufactured item (Raw Material or Product), THE items page SHALL open the regular item edit form as usual

### Requirement 5: Production Order Creation and Stock Adjustment

**User Story:** As a business owner, I want to execute a production order that automatically deducts raw materials and adds the finished product to stock, so that my inventory accurately reflects manufacturing activity.

#### Acceptance Criteria

1. WHEN a Production_Order is created, THE Manufacturing_Module SHALL require a Recipe reference and a Production_Quantity greater than zero
2. WHEN a Production_Order is created, THE Manufacturing_Module SHALL calculate the Required_Quantity for each Component_Item as (Recipe_Item quantity / Yield_Quantity) multiplied by the Production_Quantity
3. WHEN a Production_Order is created, THE Manufacturing_Module SHALL verify that sufficient stock exists for each Component_Item before executing any stock adjustments
4. IF any Component_Item has insufficient stock, THEN THE Manufacturing_Module SHALL reject the Production_Order and return a descriptive error identifying which items are short and by how much
5. WHEN a Production_Order is executed, THE Manufacturing_Module SHALL deduct each Component_Item from stock using ItemService.adjustStock with type OUT and a reference linking to the Production_Order
6. WHEN a Production_Order is executed, THE Manufacturing_Module SHALL add the Manufactured_Item to stock using ItemService.adjustStock with type IN and a reference linking to the Production_Order
7. THE Manufacturing_Module SHALL execute all stock adjustments for a Production_Order within a single database transaction
8. IF any stock adjustment fails during Production_Order execution, THEN THE Manufacturing_Module SHALL roll back the entire transaction including all prior adjustments
9. WHEN a Production_Order is created, THE Manufacturing_Module SHALL store the Production_Order with status COMPLETED, the production date, and all Production_Order_Items with their consumed quantities
10. WHEN a Production_Order is created, THE Manufacturing_Module SHALL record the action in the audit log

### Requirement 6: Manufacturing Cost Calculation and Cascading Recalculation

**User Story:** As a business owner, I want the system to automatically recalculate manufactured item costs when raw material prices change, so that I always know my true production costs and can adjust sale prices accordingly.

#### Acceptance Criteria

1. WHEN a Production_Order is created, THE Manufacturing_Module SHALL calculate the Manufacturing_Cost as the sum of each Component_Item cost_price multiplied by its Required_Quantity
2. WHEN a Production_Order is created, THE Manufacturing_Module SHALL calculate the Unit_Manufacturing_Cost as the Manufacturing_Cost divided by the Production_Quantity
3. THE Manufacturing_Module SHALL store the Manufacturing_Cost and Unit_Manufacturing_Cost on the Production_Order record
4. WHEN a Recipe is viewed, THE Manufacturing_Module SHALL display the estimated manufacturing cost per unit based on current Component_Item cost prices
5. WHEN a Component_Item cost_price changes, THE Manufacturing_Module SHALL identify all Recipes that use that Component_Item
6. WHEN a Component_Item cost_price changes, THE Manufacturing_Module SHALL recalculate the Unit_Manufacturing_Cost for each affected Recipe
7. WHEN a Component_Item cost_price changes, THE Manufacturing_Module SHALL update the Manufactured_Item cost_price to the newly calculated Unit_Manufacturing_Cost for each affected Recipe
8. WHEN a Cascading_Recalculation updates a Manufactured_Item cost_price, THE Manufacturing_Module SHALL set a Cost_Change_Flag on the affected Manufactured_Item

### Requirement 7: Production Order Listing and Viewing

**User Story:** As a business owner, I want to view my production history, so that I can track what was manufactured, when, and at what cost.

#### Acceptance Criteria

1. THE Manufacturing_Module SHALL return all Production_Orders for the current business ordered by production date descending
2. WHEN a single Production_Order is requested, THE Manufacturing_Module SHALL return the Production_Order details including the Recipe name, Manufactured_Item name, Production_Quantity, Manufacturing_Cost, Unit_Manufacturing_Cost, and all Production_Order_Items with their Component_Item names and consumed quantities
3. THE Manufacturing_Module SHALL support filtering Production_Orders by date range and by Manufactured_Item

### Requirement 8: Multi-Tenant Data Isolation

**User Story:** As a system administrator, I want manufacturing data to be isolated per tenant and business, so that businesses cannot access each other's recipes or production orders.

#### Acceptance Criteria

1. THE Manufacturing_Module SHALL scope all Recipe queries by tenant_id and business_id
2. THE Manufacturing_Module SHALL scope all Production_Order queries by tenant_id and business_id
3. THE Manufacturing_Module SHALL validate that all referenced Component_Items belong to the same tenant and business as the Recipe

### Requirement 9: Permission-Based Access Control

**User Story:** As a business owner, I want to control who can manage recipes and execute production orders, so that only authorized users can perform manufacturing operations.

#### Acceptance Criteria

1. THE Manufacturing_Module SHALL require the manufacturing.view permission to list and view Recipes and Production_Orders
2. THE Manufacturing_Module SHALL require the manufacturing.create permission to create Recipes and Production_Orders
3. THE Manufacturing_Module SHALL require the manufacturing.edit permission to update Recipes
4. THE Manufacturing_Module SHALL require the manufacturing.delete permission to delete Recipes
5. IF a user lacks the required permission, THEN THE Manufacturing_Module SHALL return a 403 Forbidden response

### Requirement 10: Production Order Reversal

**User Story:** As a business owner, I want to reverse a production order if it was created in error, so that my stock levels are corrected.

#### Acceptance Criteria

1. WHEN a Production_Order reversal is requested, THE Manufacturing_Module SHALL add back each Component_Item to stock using ItemService.adjustStock with type IN and a reference linking to the reversal
2. WHEN a Production_Order reversal is requested, THE Manufacturing_Module SHALL deduct the Manufactured_Item from stock using ItemService.adjustStock with type OUT and a reference linking to the reversal
3. THE Manufacturing_Module SHALL execute all reversal stock adjustments within a single database transaction
4. IF any reversal stock adjustment fails, THEN THE Manufacturing_Module SHALL roll back the entire reversal transaction
5. WHEN a Production_Order is reversed, THE Manufacturing_Module SHALL update the Production_Order status to REVERSED
6. IF a Production_Order is already in REVERSED status, THEN THE Manufacturing_Module SHALL reject the reversal request
7. WHEN a Production_Order is reversed, THE Manufacturing_Module SHALL record the action in the audit log

### Requirement 11: Margin Protection and Cost Change Notification

**User Story:** As a business owner, I want to be notified when manufactured item costs change due to raw material price updates, so that I can review and adjust my sale prices to maintain profitability.

#### Acceptance Criteria

1. WHEN a Cascading_Recalculation updates a Manufactured_Item cost_price, THE Manufacturing_Module SHALL display a visible indicator on the affected Manufactured_Item in the items list
2. THE Manufacturing_Module SHALL allow the user to acknowledge and dismiss the Cost_Change_Flag after reviewing the sale price
3. THE Manufacturing_Module SHALL display the previous cost_price alongside the new cost_price when a Cost_Change_Flag is active
4. WHERE the auto-markup feature is enabled, THE Manufacturing_Module SHALL allow configuring a percentage markup for a Manufactured_Item
5. WHERE the auto-markup feature is enabled and a Cascading_Recalculation occurs, THE Manufacturing_Module SHALL automatically update the Manufactured_Item sale_price based on the configured percentage markup applied to the new cost_price

### Requirement 12: Real-Time Cost Display in Recipe Form

**User Story:** As a business owner, I want to see the calculated cost per unit in real-time as I add or modify materials in the recipe form, so that I can make informed decisions about material choices and pricing before saving.

#### Acceptance Criteria

1. WHILE the recipe form is open, THE Manufacturing_Module SHALL display the calculated cost per unit based on the current Component_Items and quantities entered
2. WHEN a Component_Item is added to the recipe form, THE Manufacturing_Module SHALL immediately update the displayed cost per unit
3. WHEN a Component_Item is removed from the recipe form, THE Manufacturing_Module SHALL immediately update the displayed cost per unit
4. WHEN a Recipe_Item quantity is changed in the recipe form, THE Manufacturing_Module SHALL immediately update the displayed cost per unit
5. WHEN the Yield_Quantity is changed in the recipe form, THE Manufacturing_Module SHALL immediately update the displayed cost per unit
6. THE Manufacturing_Module SHALL calculate the displayed cost per unit as the sum of (Component_Item cost_price multiplied by quantity) divided by the Yield_Quantity
