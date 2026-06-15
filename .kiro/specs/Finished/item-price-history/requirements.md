# Requirements Document

## Introduction

This feature extends the existing ERP item pricing infrastructure to provide comprehensive price change tracking and multi-supplier pricing intelligence. It builds upon the existing `item_prices` table and `PriceUpdater` service to add granular change history with source attribution, and introduces a new `supplier_item_prices` table that automatically aggregates supplier pricing data from purchase orders. Together, these capabilities give users full visibility into price evolution and supplier cost comparisons.

## Glossary

- **Price_History_Service**: The backend service responsible for recording, retrieving, and managing item price change history records
- **Supplier_Pricing_Service**: The backend service responsible for tracking, aggregating, and retrieving per-supplier pricing data for items
- **Item_Price_History_Table**: The `item_price_history` database table that stores individual price change events with source attribution
- **Supplier_Item_Prices_Table**: The `supplier_item_prices` database table that stores aggregated pricing statistics per supplier-item combination
- **Price_History_UI**: The frontend modal component that displays chronological price change history for a specific item
- **Supplier_Comparison_UI**: The frontend component that displays supplier pricing comparison for a specific item
- **Source_Type**: An enumeration indicating the origin of a price change: INITIAL, PURCHASE_ORDER, MANUAL_EDIT, or MANUFACTURING
- **Price_Type**: An enumeration indicating which price changed: COST or SALE
- **Preferred_Supplier**: A user-designated supplier marked as the preferred source for a specific item

## Requirements

### Requirement 1: Record Initial Price History on Item Creation

**User Story:** As a business owner, I want the system to automatically record the initial cost and sale prices when an item is created, so that I have a complete price history from the item's inception.

#### Acceptance Criteria

1. WHEN a new item is created with a non-null cost_price, THE Price_History_Service SHALL insert a record into the Item_Price_History_Table with price_type COST, old_price NULL, new_price equal to the cost_price, source_type INITIAL, and source_id NULL
2. WHEN a new item is created with a non-null sale_price, THE Price_History_Service SHALL insert a record into the Item_Price_History_Table with price_type SALE, old_price NULL, new_price equal to the sale_price, source_type INITIAL, and source_id NULL
3. WHEN a new item is created with both cost_price and sale_price as NULL, THE Price_History_Service SHALL not insert any price history records
4. THE Item_Price_History_Table SHALL store item_id, tenant_id, price_type, old_price, new_price, effective_date, source_type, source_id, and created_at for each record

### Requirement 2: Record Price Changes from Purchase Order Receive

**User Story:** As a procurement manager, I want price changes triggered by PO receipts to be recorded with a reference to the originating PO, so that I can trace why a cost price changed.

#### Acceptance Criteria

1. WHEN a purchase order is received and the PO line unit_price_bc differs from the item current cost_price, THE Price_History_Service SHALL insert a record with price_type COST, old_price equal to the previous cost_price, new_price equal to the PO line unit_price_bc, source_type PURCHASE_ORDER, and source_id equal to the purchase order ID
2. WHEN a purchase order is received and the PO line unit_price_bc equals the item current cost_price, THE Price_History_Service SHALL not insert a price history record for that item
3. THE Price_History_Service SHALL use the PO date as the effective_date for purchase-order-sourced price changes

### Requirement 3: Record Price Changes from Manual Edits

**User Story:** As a business owner, I want manual price edits to be tracked in the price history, so that I can distinguish between automatic and manual price adjustments.

#### Acceptance Criteria

1. WHEN a user updates an item cost_price through the item edit form and the new value differs from the current value, THE Price_History_Service SHALL insert a record with price_type COST, old_price equal to the previous cost_price, new_price equal to the submitted cost_price, source_type MANUAL_EDIT, and source_id NULL
2. WHEN a user updates an item sale_price through the item edit form and the new value differs from the current value, THE Price_History_Service SHALL insert a record with price_type SALE, old_price equal to the previous sale_price, new_price equal to the submitted sale_price, source_type MANUAL_EDIT, and source_id NULL
3. THE Price_History_Service SHALL use the current date as the effective_date for manual edit price changes

### Requirement 4: Record Price Changes from Manufacturing Cost Recalculation

**User Story:** As a production manager, I want cost changes triggered by manufacturing recipe recalculations to be tracked, so that I can understand how component price changes cascade to manufactured items.

#### Acceptance Criteria

1. WHEN a manufacturing cost recalculation updates an item cost_price, THE Price_History_Service SHALL insert a record with price_type COST, source_type MANUFACTURING, and source_id NULL
2. THE Price_History_Service SHALL record the old_price and new_price for manufacturing-sourced cost changes

### Requirement 5: Display Item Price History

**User Story:** As a business owner, I want to view the complete price history of any item in a chronological list, so that I can analyze price trends and identify the source of each change.

#### Acceptance Criteria

1. WHEN a user opens the price history view for an item, THE Price_History_UI SHALL display all price change records for that item ordered by effective_date descending and created_at descending
2. THE Price_History_UI SHALL display for each record: the price_type, old_price, new_price, effective_date, source_type, and source reference details
3. WHEN a price history record has source_type PURCHASE_ORDER, THE Price_History_UI SHALL display the PO number as a clickable reference
4. IF no price history records exist for the item, THEN THE Price_History_UI SHALL display an informational message indicating no price history is available
5. THE Price_History_UI SHALL be accessible from the item detail view via a dedicated button or tab

### Requirement 6: Populate Supplier Item Prices on PO Receive

**User Story:** As a procurement manager, I want the system to automatically track which suppliers provide which items and at what prices, so that I can compare supplier costs without manual data entry.

#### Acceptance Criteria

1. WHEN a purchase order is received, THE Supplier_Pricing_Service SHALL create or update a record in the Supplier_Item_Prices_Table for each line item where the PO has a supplier_id
2. THE Supplier_Item_Prices_Table SHALL store supplier_id, item_id, tenant_id, last_price, avg_price, min_price, max_price, last_purchase_date, purchase_count, and is_preferred for each supplier-item combination
3. WHEN a new supplier-item combination is encountered, THE Supplier_Pricing_Service SHALL initialize last_price, avg_price, min_price, and max_price to the PO line unit_price_bc, set purchase_count to 1, and set is_preferred to false
4. WHEN an existing supplier-item combination is updated, THE Supplier_Pricing_Service SHALL update last_price to the current PO line unit_price_bc, recalculate avg_price using the running average formula, update min_price and max_price if the new price is outside the current range, increment purchase_count by 1, and update last_purchase_date
5. WHEN a purchase order has no supplier_id, THE Supplier_Pricing_Service SHALL not create or update any supplier item price records

### Requirement 7: Display Supplier Price Comparison

**User Story:** As a procurement manager, I want to see which suppliers offer a specific item and compare their pricing statistics, so that I can make informed purchasing decisions.

#### Acceptance Criteria

1. WHEN a user opens the supplier comparison view for an item, THE Supplier_Comparison_UI SHALL display all suppliers that have previously provided that item, ordered by last_purchase_date descending
2. THE Supplier_Comparison_UI SHALL display for each supplier: supplier name, last_price, avg_price, min_price, max_price, last_purchase_date, purchase_count, and preferred status
3. THE Supplier_Comparison_UI SHALL highlight the supplier with the lowest last_price among all listed suppliers
4. IF no supplier pricing data exists for the item, THEN THE Supplier_Comparison_UI SHALL display an informational message indicating no supplier data is available
5. THE Supplier_Comparison_UI SHALL be accessible from the item detail view and from the PO line item selection context

### Requirement 8: Manage Preferred Supplier

**User Story:** As a procurement manager, I want to mark a supplier as preferred for a specific item, so that my team knows which supplier to prioritize when reordering.

#### Acceptance Criteria

1. WHEN a user marks a supplier as preferred for an item, THE Supplier_Pricing_Service SHALL set is_preferred to true for that supplier-item combination and set is_preferred to false for all other suppliers of the same item within the same tenant
2. WHEN a user removes the preferred status from a supplier, THE Supplier_Pricing_Service SHALL set is_preferred to false for that supplier-item combination
3. THE Supplier_Comparison_UI SHALL visually distinguish the preferred supplier from other suppliers
4. THE Supplier_Pricing_Service SHALL enforce that at most one supplier is marked as preferred per item per tenant

### Requirement 9: Tenant Data Isolation

**User Story:** As a system administrator, I want all price history and supplier pricing data to be isolated per tenant, so that businesses cannot access each other's pricing information.

#### Acceptance Criteria

1. THE Price_History_Service SHALL include tenant_id in all Item_Price_History_Table queries and enforce that users can only access records belonging to their own tenant
2. THE Supplier_Pricing_Service SHALL include tenant_id in all Supplier_Item_Prices_Table queries and enforce that users can only access records belonging to their own tenant
3. THE Item_Price_History_Table SHALL include a tenant_id column with a NOT NULL constraint
4. THE Supplier_Item_Prices_Table SHALL include a tenant_id column with a NOT NULL constraint
