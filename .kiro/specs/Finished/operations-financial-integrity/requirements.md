# Requirements Document

## Introduction

This specification corrects and enforces proper document lifecycle flows and stock/financial integrity across the Purchase Order, Production, Sales Invoice, and Payment/Collection modules in the ERP system. The changes address six areas: simplifying the PO lifecycle with computed payment status, enforcing material availability checks on production orders, validating stock on sales invoice line items, restructuring the sales invoice lifecycle around delivery-based stock movement, enabling account-level customer/supplier settlement, and fixing a confirmed balance_fc data integrity bug.

## Glossary

- **PO_Module**: The backend subsystem (PurchaseOrderService, PurchaseOrderRepository, API routes) responsible for managing purchase order lifecycle and status transitions.
- **Production_Module**: The backend subsystem (ManufacturingService, ManufacturingRepository) responsible for recipe management and production order execution.
- **Invoice_Module**: The backend subsystem (SalesInvoiceService, SalesInvoiceRepository, API routes) responsible for managing sales invoice lifecycle and status transitions.
- **Payment_Module**: The backend subsystem (PaymentService, PaymentRepository) responsible for creating and managing outgoing payment slips that deduct from business accounts.
- **Collection_Module**: The backend subsystem (CollectionService, CollectionRepository) responsible for creating and managing incoming collection slips that add to business accounts.
- **Settlement_Engine**: The logic component responsible for evaluating payee account balances after payment/collection creation and auto-marking linked documents as settled.
- **Balance_Recalculator**: A utility that recomputes account balance and balance_fc columns from scratch by replaying all payment and collection slip transactions.
- **Payment_Status**: A computed field on purchase orders and sales invoices derived from linked payment/collection slips, with values UNPAID, PARTIALLY_PAID, or PAID.
- **Stock_Validator**: The logic component that checks available item stock against requested quantities before allowing operations to proceed.
- **Payee_Balance**: The running balance tracked per person/entity (balance column in people table), representing net amounts owed to or by that entity.
- **Account_Balance_BC**: The business-currency balance of a business account (balance column in business_accounts table).
- **Account_Balance_FC**: The foreign-currency balance of a business account (balance_fc column in business_accounts table).

## Requirements

### Requirement 1: Purchase Order Lifecycle Simplification

**User Story:** As a warehouse manager, I want the purchase order to reach its terminal state at RECEIVED (when goods arrive and stock increases), so that the PO lifecycle reflects physical goods movement without a redundant CLOSED status.

#### Acceptance Criteria

1. WHEN a purchase order status is DRAFT, THE PO_Module SHALL allow transition only to APPROVED or CANCELLED.
2. WHEN a purchase order status is APPROVED, THE PO_Module SHALL allow transition only to RECEIVED or CANCELLED.
3. WHEN a purchase order transitions to RECEIVED, THE PO_Module SHALL execute stock IN for each line item and increase the supplier's payee balance.
4. THE PO_Module SHALL treat RECEIVED as a terminal state and reject any further status transitions from RECEIVED except CANCELLED.
5. THE PO_Module SHALL remove the CLOSED status from the set of valid purchase order statuses.
6. IF a user attempts to transition a RECEIVED purchase order to CLOSED, THEN THE PO_Module SHALL reject the request with the error message "CLOSED status is no longer valid; RECEIVED is the terminal state."

### Requirement 2: Purchase Order Computed Payment Status

**User Story:** As an accountant, I want to see the payment status of each purchase order derived from actual payment slips linked to the supplier, so that I can track outstanding payables without manual status changes.

#### Acceptance Criteria

1. WHEN a purchase order is in RECEIVED status, THE PO_Module SHALL compute a Payment_Status field based on payment slips linked to the supplier (payee_id matching the PO's supplier_id).
2. WHILE the sum of payment slip amounts for the supplier equals or exceeds the PO total_amount_bc, THE PO_Module SHALL report Payment_Status as PAID.
3. WHILE the sum of payment slip amounts for the supplier is greater than zero but less than the PO total_amount_bc, THE PO_Module SHALL report Payment_Status as PARTIALLY_PAID.
4. WHILE no payment slips exist for the supplier, THE PO_Module SHALL report Payment_Status as UNPAID.
5. THE PO_Module SHALL include the computed Payment_Status in all purchase order list and detail API responses.
6. THE PO_Module SHALL not allow manual modification of the Payment_Status field.

### Requirement 3: Purchase Order Payment Shortcut

**User Story:** As an accountant, I want a shortcut button on a received purchase order to create a payment slip pre-filled with the supplier, amount, and PO reference, so that I can quickly record payments against purchase orders.

#### Acceptance Criteria

1. WHEN a user triggers the payment shortcut on a RECEIVED purchase order, THE PO_Module SHALL pre-fill the payment slip form with the supplier as payee, the PO total_amount_bc as amount, and the PO number as reference.
2. THE PO_Module SHALL set the reference_type to "PURCHASE_ORDER" and reference_id to the PO ID on the pre-filled payment slip.
3. WHILE a purchase order is not in RECEIVED status, THE PO_Module SHALL hide or disable the payment shortcut button.
4. THE PO_Module SHALL allow the user to modify the pre-filled amount before submitting the payment slip (to support partial payments).

### Requirement 4: Production Order Material Availability Check

**User Story:** As a production manager, I want the system to block production order confirmation when raw materials are insufficient, so that I do not start production runs that cannot be completed.

#### Acceptance Criteria

1. WHEN a user submits a production order for execution, THE Production_Module SHALL verify that current stock of each component item is greater than or equal to the required quantity (recipe_quantity divided by yield_quantity, multiplied by production_quantity).
2. IF any component item has insufficient stock, THEN THE Production_Module SHALL reject the production order with an error message listing each deficient material, its required quantity, and its available stock.
3. THE Production_Module SHALL perform the stock sufficiency check atomically within the same transaction as stock deduction to prevent race conditions.
4. THE Production_Module SHALL format the insufficient stock error as: "Insufficient stock for: [item_name] (need [required], have [available])" for each deficient component, separated by commas.

### Requirement 5: Sales Invoice Stock Validation on Line Items

**User Story:** As a sales clerk, I want the system to prevent me from adding invoice line items that exceed available stock, so that I do not promise goods the warehouse cannot fulfill.

#### Acceptance Criteria

1. WHEN a user adds a line item to a sales invoice, THE Invoice_Module SHALL verify that the requested quantity does not exceed the available stock for that item.
2. WHEN a user edits the quantity of an existing line item on a sales invoice, THE Invoice_Module SHALL verify that the new quantity does not exceed the available stock for that item.
3. IF the requested quantity exceeds available stock, THEN THE Invoice_Module SHALL reject the operation with the error message "Insufficient stock for [item_name]: available [available_qty], requested [requested_qty]".
4. THE Invoice_Module SHALL enforce stock validation at all invoice statuses including DRAFT.
5. THE Invoice_Module SHALL check stock availability against the current stock level at the time of the add/edit operation.

### Requirement 6: Sales Invoice Lifecycle Restructuring

**User Story:** As a warehouse manager, I want stock to be deducted when goods are delivered (not when payment is received), so that the inventory reflects physical reality and delivery is tracked as a distinct step.

#### Acceptance Criteria

1. WHEN a sales invoice status is DRAFT, THE Invoice_Module SHALL allow transition only to DELIVERED or CANCELLED.
2. WHEN a sales invoice transitions to DELIVERED, THE Invoice_Module SHALL execute stock OUT for each line item.
3. WHEN a sales invoice transitions to DELIVERED, THE Invoice_Module SHALL increase the customer's payee balance by the invoice total_amount_bc (customer now owes for delivered goods).
4. THE Invoice_Module SHALL remove the APPROVED and PAID statuses from the set of manually-settable invoice statuses.
5. THE Invoice_Module SHALL not allow direct manual transition to PAID status; payment status is determined exclusively by collection slips.
6. IF a user attempts to manually set an invoice status to PAID, THEN THE Invoice_Module SHALL reject the request with the error message "Payment status is computed from collection slips and cannot be set manually."

### Requirement 7: Sales Invoice Computed Payment Status

**User Story:** As an accountant, I want the payment status of each delivered invoice to be automatically derived from collection slips, so that I have an accurate real-time view of receivables.

#### Acceptance Criteria

1. WHEN a sales invoice is in DELIVERED status, THE Invoice_Module SHALL compute a Payment_Status field based on collection slips where reference_type is "SALES_INVOICE" and reference_id matches the invoice ID.
2. WHILE the sum of collection slip amounts (amount_bc) for the invoice equals or exceeds the invoice total_amount_bc, THE Invoice_Module SHALL report Payment_Status as PAID.
3. WHILE the sum of collection slip amounts for the invoice is greater than zero but less than the invoice total_amount_bc, THE Invoice_Module SHALL report Payment_Status as PARTIALLY_PAID.
4. WHILE no collection slips reference the invoice, THE Invoice_Module SHALL report Payment_Status as UNPAID.
5. THE Invoice_Module SHALL include the computed Payment_Status in all sales invoice list and detail API responses.
6. WHEN a sales invoice Payment_Status is PAID, THE Collection_Module SHALL reject creation of additional collection slips referencing that invoice with the error message "Invoice is already fully paid."

### Requirement 8: Collection Slip Invoice Reference

**User Story:** As an accountant, I want to create collection slips that reference specific sales invoices, so that payments are tracked against the correct receivables.

#### Acceptance Criteria

1. WHEN a user creates a collection slip with reference_type "SALES_INVOICE", THE Collection_Module SHALL validate that the referenced invoice exists and belongs to the current business.
2. WHEN a user creates a collection slip with reference_type "SALES_INVOICE", THE Collection_Module SHALL validate that the referenced invoice is in DELIVERED status.
3. IF the referenced invoice does not exist or is not in DELIVERED status, THEN THE Collection_Module SHALL reject the collection slip with a descriptive error message.
4. THE Collection_Module SHALL allow collection slip amounts that are less than the invoice total (partial payment).
5. THE Collection_Module SHALL allow multiple collection slips to reference the same invoice until the invoice is fully paid.

### Requirement 9: Account-Level Customer Settlement

**User Story:** As an accountant, I want a single large collection from a customer to automatically settle all their outstanding invoices when their account balance reaches zero, so that I do not need to manually mark each invoice as paid.

#### Acceptance Criteria

1. WHEN a collection slip is created for a customer (payee), THE Settlement_Engine SHALL evaluate the customer's updated payee balance after the collection is applied.
2. WHEN the customer's payee balance reaches zero or below after a collection, THE Settlement_Engine SHALL identify all DELIVERED invoices for that customer with Payment_Status UNPAID or PARTIALLY_PAID.
3. WHEN the customer's payee balance reaches zero or below, THE Settlement_Engine SHALL mark all identified invoices as settled (Payment_Status = PAID) by recording the settlement event.
4. THE Settlement_Engine SHALL execute the balance evaluation and invoice settlement within the same database transaction as the collection slip creation.
5. THE Settlement_Engine SHALL log the automatic settlement action via the AuditService, recording which invoices were settled and the triggering collection slip ID.

### Requirement 10: Account-Level Supplier Settlement

**User Story:** As an accountant, I want a single large payment to a supplier to automatically settle all their outstanding purchase orders when the supplier's account balance reaches zero, so that I do not need to manually track each PO's payment status.

#### Acceptance Criteria

1. WHEN a payment slip is created for a supplier (payee), THE Settlement_Engine SHALL evaluate the supplier's updated payee balance after the payment is applied.
2. WHEN the supplier's payee balance reaches zero or above after a payment, THE Settlement_Engine SHALL identify all RECEIVED purchase orders for that supplier with Payment_Status UNPAID or PARTIALLY_PAID.
3. WHEN the supplier's payee balance reaches zero or above, THE Settlement_Engine SHALL mark all identified purchase orders as settled (Payment_Status = PAID) by recording the settlement event.
4. THE Settlement_Engine SHALL execute the balance evaluation and PO settlement within the same database transaction as the payment slip creation.
5. THE Settlement_Engine SHALL log the automatic settlement action via the AuditService, recording which purchase orders were settled and the triggering payment slip ID.

### Requirement 11: Account Balance Recalculation Migration

**User Story:** As a system administrator, I want to run a one-time migration that recomputes all account balances from transaction history, so that the confirmed balance_fc discrepancy (caused by the column being added after existing transactions) is corrected.

#### Acceptance Criteria

1. WHEN the balance recalculation migration is executed, THE Balance_Recalculator SHALL set both balance and balance_fc to zero for every account in the business_accounts table.
2. THE Balance_Recalculator SHALL replay all payment slips in chronological order, subtracting amount_bc from balance and amount_fc from balance_fc for each payment's account.
3. THE Balance_Recalculator SHALL replay all collection slips in chronological order, adding amount_bc to balance and amount_fc to balance_fc for each collection's account.
4. THE Balance_Recalculator SHALL execute the entire recalculation within a single database transaction to ensure atomicity.
5. IF any error occurs during recalculation, THEN THE Balance_Recalculator SHALL roll back all changes and report the error.
6. THE Balance_Recalculator SHALL log the recalculation action via the AuditService, recording the before and after balances for each affected account.

### Requirement 12: Reusable Balance Recalculation Utility

**User Story:** As a system administrator, I want a reusable utility to recalculate account balances on demand, so that I can verify and correct data integrity issues in the future without writing custom scripts.

#### Acceptance Criteria

1. THE Balance_Recalculator SHALL be implemented as a callable service method (not only a migration script) that can be invoked via an API endpoint.
2. THE Balance_Recalculator SHALL accept an optional account_id parameter to recalculate a single account, or recalculate all accounts when no parameter is provided.
3. WHEN invoked for a single account, THE Balance_Recalculator SHALL recompute only that account's balance and balance_fc from its linked payment and collection slips.
4. THE Balance_Recalculator SHALL return a report listing each account processed with its previous balance, new balance, previous balance_fc, and new balance_fc.
5. THE Balance_Recalculator SHALL require administrative permission to execute.
6. THE Balance_Recalculator SHALL be idempotent: running the recalculation multiple times SHALL produce the same final balances.

### Requirement 13: Cancellation Guard for Settled Documents

**User Story:** As a business owner, I want the system to prevent cancellation of purchase orders and invoices that have associated payments or collections, so that financial records remain consistent.

#### Acceptance Criteria

1. IF a user attempts to cancel a RECEIVED purchase order that has Payment_Status PARTIALLY_PAID or PAID, THEN THE PO_Module SHALL reject the cancellation with the error message "Cannot cancel: purchase order has associated payments. Reverse payments first."
2. IF a user attempts to cancel a DELIVERED sales invoice that has Payment_Status PARTIALLY_PAID or PAID, THEN THE Invoice_Module SHALL reject the cancellation with the error message "Cannot cancel: invoice has associated collections. Reverse collections first."
3. WHEN a RECEIVED purchase order with Payment_Status UNPAID is cancelled, THE PO_Module SHALL reverse the stock IN for each line item and reverse the supplier's payee balance increase.
4. WHEN a DELIVERED sales invoice with Payment_Status UNPAID is cancelled, THE Invoice_Module SHALL reverse the stock OUT for each line item and reverse the customer's payee balance increase.

### Requirement 14: Payment Slip Supplier Reference Tracking

**User Story:** As an accountant, I want payment slips to optionally reference a specific purchase order, so that I can trace which payments relate to which purchases even though settlement happens at the account level.

#### Acceptance Criteria

1. WHEN a payment slip is created with reference_type "PURCHASE_ORDER", THE Payment_Module SHALL validate that the referenced purchase order exists, belongs to the current business, and is in RECEIVED status.
2. IF the referenced purchase order does not exist or is not in RECEIVED status, THEN THE Payment_Module SHALL reject the payment slip with a descriptive error message.
3. THE Payment_Module SHALL allow payment slips without a reference_id (general payments to a supplier).
4. THE Payment_Module SHALL allow multiple payment slips to reference the same purchase order.

### Requirement 15: DRAFT Document Deletion with Line Item Cleanup

**User Story:** As a user, I want deleting a DRAFT purchase order or sales invoice to also remove its associated line items, so that no orphaned records remain in the database.

#### Acceptance Criteria

1. WHEN a DRAFT purchase order is deleted, THE PO_Module SHALL delete all associated records from the po_details table within the same transaction before deleting the purchase order header.
2. WHEN a DRAFT sales invoice is deleted, THE Invoice_Module SHALL delete all associated records from the si_details table within the same transaction before deleting the sales invoice header.
3. THE PO_Module SHALL only allow deletion of purchase orders in DRAFT status; attempts to delete a non-DRAFT purchase order SHALL be rejected with the error message "Only DRAFT purchase orders can be deleted."
4. THE Invoice_Module SHALL only allow deletion of sales invoices in DRAFT status; attempts to delete a non-DRAFT sales invoice SHALL be rejected with the error message "Only DRAFT sales invoices can be deleted."
5. THE PO_Module and Invoice_Module SHALL execute the line item deletion and header deletion atomically within a single database transaction.
6. IF any error occurs during deletion, THEN the entire operation SHALL be rolled back and no records SHALL be removed.
