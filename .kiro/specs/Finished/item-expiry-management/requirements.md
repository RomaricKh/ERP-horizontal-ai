# Requirements Document

## Introduction

This feature adds expiry date management to the multi-tenant ERP system. Items can be flagged as requiring expiry tracking, enabling batch-level stock management with expiry dates. Stock inflows (via PO receive) create batch records, stock outflows consume batches using FEFO (First Expired, First Out) logic, and the system provides expiry alerts and write-off capabilities. The feature integrates with the existing stock management flow (upsertStock, insertStockTransaction) and the PO receive process without breaking current behavior for items that do not require expiry tracking.

## Glossary

- **Batch_Service**: The backend service responsible for creating, querying, and consuming item batches with expiry tracking using FEFO logic
- **Item_Batch**: A record in the `item_batches` table representing a specific quantity of an item with a known expiry date, linked to a business and optionally to a source purchase order
- **FEFO_Logic**: First Expired, First Out — the algorithm that selects batches with the earliest expiry date first when consuming stock
- **Expiry_Alert_Service**: The backend service that identifies batches approaching or past their expiry date based on a configurable threshold
- **Batch_Write_Off**: The process of marking an expired or unusable batch as written off, reducing the aggregate stock quantity accordingly
- **Item_Service**: The existing backend service responsible for stock adjustments (IN, OUT, ADJ) via the adjustStock method
- **PO_Receive_Flow**: The existing process in PurchaseOrderService.updateStatus that triggers stock IN for each PO line item when status transitions to RECEIVED
- **Stock_Aggregate**: The existing `stock` table record that holds the total quantity of an item per business, updated via upsertStock

## Requirements

### Requirement 1: Item Expiry Flag

**User Story:** As a warehouse manager, I want to flag specific items as requiring expiry tracking, so that only relevant items incur the overhead of batch management.

#### Acceptance Criteria

1. THE System SHALL add a `has_expiry` column (TINYINT(1) NOT NULL DEFAULT 0) to the `items` table
2. WHEN an item is created or updated, THE System SHALL accept an optional `has_expiry` boolean field in the request payload
3. WHEN the migration executes, THE System SHALL set `has_expiry` to 0 for all existing items so that current behavior remains unchanged
4. WHEN an item with `has_expiry` = 1 is returned by the API, THE System SHALL include the `has_expiry` field in the response payload
5. WHEN an item with `has_expiry` = 0 is returned by the API, THE System SHALL include the `has_expiry` field in the response payload with value 0
6. IF a user attempts to change `has_expiry` from 1 to 0 on an item that has active batches with quantity greater than zero, THEN THE System SHALL reject the request with an error indicating that active batches must be resolved before disabling expiry tracking

### Requirement 2: Item Batches Table

**User Story:** As a system architect, I want a dedicated table to track individual batches with expiry dates, so that the system can manage stock at the batch level for expiry-tracked items.

#### Acceptance Criteria

1. THE System SHALL create an `item_batches` table with columns: id (INT AUTO_INCREMENT PRIMARY KEY), item_id (INT NOT NULL, FOREIGN KEY to items.id), business_id (INT NOT NULL, FOREIGN KEY to businesses.id), batch_number (VARCHAR(100) NOT NULL), quantity (DECIMAL(15,4) NOT NULL DEFAULT 0), manufacturing_date (DATE NULL), expiry_date (DATE NOT NULL), purchase_order_id (INT NULL, FOREIGN KEY to purchase_orders.id), status (ENUM('active','expired','written_off') NOT NULL DEFAULT 'active'), created_at (DATETIME NOT NULL), updated_at (DATETIME NULL)
2. THE System SHALL enforce a UNIQUE constraint on the combination of item_id, business_id, and batch_number to prevent duplicate batch numbers for the same item within a business
3. THE System SHALL add an INDEX on (item_id, business_id, status, expiry_date) to optimize FEFO queries
4. WHEN a batch_number is not provided during batch creation, THE Batch_Service SHALL auto-generate a batch number using the format "BTH-{YYYYMMDD}-{sequential_number}" where the sequential number is zero-padded to 4 digits and increments per item per business per day
5. THE System SHALL store quantity values with up to 4 decimal places to support fractional units (e.g., kilograms, liters)

### Requirement 3: Stock IN with Batch Creation

**User Story:** As a warehouse manager, I want batch records created automatically when receiving a purchase order for expiry-tracked items, so that each stock inflow is traceable to a specific batch with an expiry date.

#### Acceptance Criteria

1. WHEN a purchase order transitions to RECEIVED status and a line item has `has_expiry` = 1, THE PO_Receive_Flow SHALL require batch information (at minimum an expiry_date) for that line item
2. WHEN batch information is provided for an expiry-tracked line item during PO receive, THE Batch_Service SHALL create a new Item_Batch record with the provided expiry_date, optional manufacturing_date, optional batch_number, the line item quantity, and the purchase_order_id
3. WHEN a PO is received with expiry-tracked items, THE System SHALL continue to call the existing adjustStock method with type "IN" to maintain the Stock_Aggregate, in addition to creating the batch record
4. IF a purchase order contains expiry-tracked items and the batch information (expiry_date) is missing from the request payload, THEN THE System SHALL reject the receive operation with an error listing each item that requires batch information
5. WHEN batch information is provided for a line item with `has_expiry` = 0, THE System SHALL ignore the batch information and process the stock IN without creating a batch record
6. WHEN a manual stock adjustment of type "IN" is performed on an expiry-tracked item, THE System SHALL require batch information (expiry_date at minimum) and create a corresponding batch record

### Requirement 4: Stock OUT with FEFO Logic

**User Story:** As a warehouse manager, I want stock consumption to automatically deduct from the batch expiring soonest, so that older stock is used first and waste is minimized.

#### Acceptance Criteria

1. WHEN a stock OUT operation is performed on an item with `has_expiry` = 1, THE Batch_Service SHALL deduct the requested quantity from active batches ordered by expiry_date ascending (earliest expiry first)
2. WHEN the requested OUT quantity exceeds a single batch's available quantity, THE Batch_Service SHALL deplete that batch to zero and continue deducting from the next batch in FEFO order until the full quantity is consumed
3. WHEN a batch quantity reaches zero after a stock OUT operation, THE Batch_Service SHALL retain the batch record with quantity 0 for audit purposes without deleting it
4. IF the total available quantity across all active batches for an item is less than the requested OUT quantity, THEN THE Batch_Service SHALL reject the operation with an error indicating insufficient batch stock and specifying the available total
5. WHEN a stock OUT operation is performed on an item with `has_expiry` = 0, THE System SHALL process the deduction using only the Stock_Aggregate without involving the Batch_Service
6. WHEN a stock OUT operation consumes from multiple batches, THE Batch_Service SHALL record the deduction details (batch_id, quantity_deducted) in a `batch_transactions` table for traceability
7. THE `batch_transactions` table SHALL have columns: id (INT AUTO_INCREMENT PRIMARY KEY), batch_id (INT NOT NULL, FOREIGN KEY to item_batches.id), transaction_type (ENUM('in','out','write_off') NOT NULL), quantity (DECIMAL(15,4) NOT NULL), reference (VARCHAR(255) NULL), notes (TEXT NULL), created_at (DATETIME NOT NULL)

### Requirement 5: Expiry Alerts

**User Story:** As a warehouse manager, I want to be notified when item batches are approaching their expiry date, so that I can take action to sell, use, or write off stock before it expires.

#### Acceptance Criteria

1. THE Expiry_Alert_Service SHALL identify batches where the expiry_date is within a configurable threshold number of days from the current date and the batch status is "active" and quantity is greater than zero
2. THE System SHALL store the expiry alert threshold as a business-level setting with a default value of 30 days
3. WHEN the expiry alert check is triggered, THE Expiry_Alert_Service SHALL return batches grouped into two categories: "expiring_soon" (within threshold but not yet expired) and "expired" (expiry_date is before the current date)
4. THE System SHALL expose an API endpoint GET /items/batches/expiry-alerts that returns the categorized batch list for the current business
5. WHEN one or more batches are found in the "expired" or "expiring_soon" categories, THE System SHALL include the item name, batch number, quantity, and expiry_date for each batch in the response
6. THE System SHALL expose an API endpoint PUT /settings/expiry-threshold that accepts a threshold_days integer value (minimum 1, maximum 365) and stores it as the business-level expiry alert threshold

### Requirement 6: Batch List View

**User Story:** As a warehouse manager, I want to view all active batches for an expiry-tracked item, so that I can see current stock distribution across batches and their expiry dates.

#### Acceptance Criteria

1. THE System SHALL expose an API endpoint GET /items/{id}/batches that returns all batches for the specified item in the current business
2. WHEN the batch list is requested, THE System SHALL return each batch with: id, batch_number, quantity, manufacturing_date, expiry_date, status, purchase_order_id, and created_at
3. THE System SHALL order the batch list by expiry_date ascending (soonest expiry first) by default
4. IF the specified item does not have `has_expiry` = 1, THEN THE System SHALL return an empty array with a 200 status code
5. THE System SHALL support an optional query parameter `status` (values: active, expired, written_off) to filter the batch list
6. THE System SHALL include a computed field `days_until_expiry` for each batch calculated as the difference between expiry_date and the current date (negative values indicate days past expiry)

### Requirement 7: Expired Stock Write-Off

**User Story:** As a warehouse manager, I want to write off expired batches, so that the stock quantity accurately reflects usable inventory and the write-off is recorded for accounting purposes.

#### Acceptance Criteria

1. THE System SHALL expose an API endpoint POST /items/batches/{batchId}/write-off that marks a batch as written off
2. WHEN a write-off is requested, THE Batch_Service SHALL change the batch status from "active" or "expired" to "written_off"
3. WHEN a write-off is performed, THE System SHALL reduce the Stock_Aggregate quantity for the item by the batch's remaining quantity using the existing adjustStock method with type "OUT"
4. WHEN a write-off is performed, THE Batch_Service SHALL record a batch_transaction with transaction_type "write_off" and the quantity written off
5. IF a write-off is requested for a batch that already has status "written_off", THEN THE System SHALL reject the request with an error indicating the batch has already been written off
6. WHEN a write-off is performed, THE System SHALL record a stock_transaction with type "OUT", reference "WRITE-OFF-BTH-{batch_number}", and notes indicating the reason is expiry write-off
7. THE System SHALL require the permission "stock.adjust" to perform a batch write-off

### Requirement 8: Batch Quantity Consistency

**User Story:** As a system architect, I want the sum of active batch quantities to always equal the Stock_Aggregate quantity for expiry-tracked items, so that data integrity is maintained across the two tracking mechanisms.

#### Acceptance Criteria

1. WHEN a stock IN operation creates a batch for an expiry-tracked item, THE System SHALL ensure the Stock_Aggregate increases by the same quantity as the new batch
2. WHEN a stock OUT operation deducts from batches for an expiry-tracked item, THE System SHALL ensure the Stock_Aggregate decreases by the same total quantity deducted across all affected batches
3. WHEN a write-off is performed, THE System SHALL ensure the Stock_Aggregate decreases by the exact quantity of the written-off batch
4. IF a discrepancy is detected between the sum of active batch quantities and the Stock_Aggregate for an expiry-tracked item, THEN THE System SHALL log a warning with the item_id, business_id, batch_sum, and aggregate_quantity for investigation
5. THE System SHALL perform batch and aggregate stock updates within the same database transaction to prevent partial updates

### Requirement 9: Migration and Backward Compatibility

**User Story:** As a system administrator, I want the migration to be non-breaking, so that existing items and stock operations continue to function without modification.

#### Acceptance Criteria

1. WHEN the migration executes, THE System SHALL add the `has_expiry` column to the `items` table with DEFAULT 0 so that all existing items remain non-expiry-tracked
2. WHEN the migration executes, THE System SHALL create the `item_batches` table using IF NOT EXISTS to ensure the migration is safe to re-run
3. WHEN the migration executes, THE System SHALL create the `batch_transactions` table using IF NOT EXISTS to ensure the migration is safe to re-run
4. WHEN the migration completes, THE System SHALL leave all existing columns, indexes, and foreign keys on the `items`, `stock`, and `stock_transactions` tables unchanged
5. WHEN a stock operation is performed on an item with `has_expiry` = 0, THE System SHALL execute the existing adjustStock logic without any batch-related processing, maintaining identical behavior to the current system
6. IF the migration fails at any step, THEN THE System SHALL roll back all changes made during that migration execution by wrapping the entire migration in a single database transaction

### Requirement 10: PO Receive Payload Extension

**User Story:** As a developer, I want the PO receive API to accept batch information per line item, so that the frontend can submit expiry dates when receiving purchase orders for expiry-tracked items.

#### Acceptance Criteria

1. WHEN the POST /purchase-orders/{id}/status endpoint is called with status "RECEIVED", THE System SHALL accept an optional `batch_info` array in the request payload
2. THE `batch_info` array SHALL contain objects with fields: item_id (INT, required), expiry_date (DATE string, required), manufacturing_date (DATE string, optional), batch_number (VARCHAR, optional)
3. WHEN `batch_info` is provided, THE System SHALL match each batch_info entry to the corresponding PO line item by item_id
4. IF a PO line item has `has_expiry` = 1 and no matching entry exists in the `batch_info` array, THEN THE System SHALL reject the receive operation with HTTP 422 and an error listing the items missing batch information
5. IF a PO line item has `has_expiry` = 0, THE System SHALL process that line item using the existing stock IN logic regardless of whether batch_info is provided for it
6. WHEN the expiry_date provided in batch_info is in the past (before the current date), THE System SHALL accept the value without rejection to allow receiving stock that is near expiry but still usable
7. IF the expiry_date field is missing or not a valid date format (YYYY-MM-DD), THEN THE System SHALL reject the request with HTTP 422 and an error indicating the invalid expiry_date for the specific item

### Requirement 11: Expiry Status Transition

**User Story:** As a system architect, I want batches to automatically transition to "expired" status when their expiry date passes, so that the system accurately reflects which stock is past its usable date.

#### Acceptance Criteria

1. WHEN the Expiry_Alert_Service checks for expiring batches, THE System SHALL update the status of all active batches whose expiry_date is strictly before the current date to "expired"
2. WHILE a batch has status "expired", THE Batch_Service SHALL still include that batch in FEFO deduction if no active batches are available, allowing the business to decide whether to use or write off expired stock
3. WHEN a batch status transitions from "active" to "expired", THE System SHALL not modify the batch quantity or the Stock_Aggregate
4. THE System SHALL not automatically write off expired batches; write-off requires an explicit user action via the write-off endpoint
