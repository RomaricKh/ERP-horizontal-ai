# Requirements Document

## Introduction

When a Purchase Order transitions to RECEIVED status, the system should automatically update item cost prices based on the PO line unit prices and record price changes in the `item_prices` history table. This provides an accurate, auditable trail of cost price changes driven by actual procurement activity.

## Glossary

- **PO_Receiver**: The component within PurchaseOrderService responsible for processing the APPROVED → RECEIVED status transition and triggering side effects (stock adjustments, supplier balance updates, and now price updates).
- **Price_Updater**: The component responsible for comparing PO line prices against current item cost prices, updating the items table, and inserting records into the item_prices history table.
- **Item_Price_Record**: A row in the `item_prices` table representing a historical snapshot of an item's cost price at a given point in time.
- **PO_Line**: A row in the `po_details` table representing a single line item on a purchase order, containing item_id, quantity, unit_price_fc, and unit_price_bc.
- **Cost_Price_BC**: The item's cost price expressed in the business's base currency (derived from `unit_price_bc` on the PO line).
- **Business_Currency**: The base currency of the business, used for storing canonical cost prices on items.

## Requirements

### Requirement 1: Update Item Cost Price on PO Receipt

**User Story:** As a business owner, I want item cost prices to automatically update when a purchase order is received, so that my inventory valuations reflect the latest procurement costs without manual data entry.

#### Acceptance Criteria

1. WHEN a Purchase Order status changes to RECEIVED, THE Price_Updater SHALL compare each PO_Line's unit_price_bc against the corresponding item's current cost_price.
2. WHEN a PO_Line's unit_price_bc differs from the item's current cost_price, THE Price_Updater SHALL update the item's cost_price column to the PO_Line's unit_price_bc value.
3. WHEN a PO_Line's unit_price_bc equals the item's current cost_price, THE Price_Updater SHALL skip the update for that item.
4. WHEN an item's current cost_price is NULL, THE Price_Updater SHALL set the item's cost_price to the PO_Line's unit_price_bc value.
5. THE Price_Updater SHALL update the item's updated_at timestamp when modifying the cost_price.

### Requirement 2: Record Price History on Change

**User Story:** As a business owner, I want a historical record of every cost price change, so that I can track price trends and audit when and why prices changed.

#### Acceptance Criteria

1. WHEN the Price_Updater updates an item's cost_price, THE Price_Updater SHALL insert a new Item_Price_Record into the item_prices table.
2. THE Item_Price_Record SHALL contain the item_id, business_id, currency (Business_Currency), cost_price (the new price), sale_price (the item's current sale_price at time of change), valid_from (the PO date), and created_at (current timestamp).
3. WHEN a previous Item_Price_Record exists for the same item and business with a NULL valid_to, THE Price_Updater SHALL set that record's valid_to to the day before the new record's valid_from date.
4. IF the new record's valid_from date is equal to or earlier than the previous record's valid_from date, THEN THE Price_Updater SHALL set the previous record's valid_to to the new record's valid_from date (same day closure).

### Requirement 3: Transactional Integrity

**User Story:** As a system administrator, I want price updates to be atomic with the PO receipt process, so that partial failures do not leave the system in an inconsistent state.

#### Acceptance Criteria

1. THE Price_Updater SHALL execute all item cost_price updates and item_prices inserts within the same database transaction as the PO status change and stock adjustments.
2. IF any price update or history insert fails, THEN THE PO_Receiver SHALL roll back the entire transaction including the status change, stock adjustments, and supplier balance update.
3. THE Price_Updater SHALL process all PO lines in a single pass within the existing transaction (no nested transactions).

### Requirement 4: Multi-Line PO Handling

**User Story:** As a procurement manager, I want all line items on a PO to have their prices evaluated, so that a single PO receipt updates all affected items consistently.

#### Acceptance Criteria

1. WHEN a Purchase Order with multiple lines is received, THE Price_Updater SHALL evaluate each PO_Line independently for price changes.
2. WHEN multiple PO_Lines reference the same item_id, THE Price_Updater SHALL use the last PO_Line (by line order) as the final cost_price for that item.
3. THE Price_Updater SHALL insert only one Item_Price_Record per unique item_id per PO receipt, reflecting the final price applied.

### Requirement 5: Audit Trail for Price Changes

**User Story:** As an auditor, I want price changes triggered by PO receipt to be logged in the audit system, so that I can trace the source of every cost price modification.

#### Acceptance Criteria

1. WHEN the Price_Updater updates an item's cost_price, THE Price_Updater SHALL log an audit entry with module "ITEMS", table "items", action "PRICE_UPDATE", old value (previous cost_price), and new value (new cost_price).
2. THE audit entry SHALL include a reference to the Purchase Order ID and PO number that triggered the change.
3. WHEN no price changes occur for a PO receipt (all prices match), THE Price_Updater SHALL not create any audit entries for price updates.

### Requirement 6: Cancellation Does Not Revert Prices

**User Story:** As a business owner, I want price history to remain intact when a received PO is cancelled, so that the audit trail accurately reflects what happened chronologically.

#### Acceptance Criteria

1. WHEN a RECEIVED Purchase Order is cancelled, THE PO_Receiver SHALL NOT revert item cost prices to their previous values.
2. WHEN a RECEIVED Purchase Order is cancelled, THE PO_Receiver SHALL NOT modify or delete existing Item_Price_Records.
