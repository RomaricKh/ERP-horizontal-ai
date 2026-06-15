# Requirements Document

## Introduction

This feature ensures that the payee balance field provides a complete financial picture of what is owed to or from each person connected to a business. Currently, only payments and collections update the payee balance. This feature extends balance tracking so that creating, approving, editing, or cancelling Purchase Orders, Sales Invoices, Expenses, and Salaries also adjusts the payee balance accordingly. All balance changes must be atomic and consistent with the business account balance.

## Glossary

- **Balance_Tracker**: The system component responsible for adjusting payee and account balances in response to financial document lifecycle events
- **Payee**: A record in the `payees` table linking a person to a business, holding the running balance in business currency
- **Payee_Balance**: The `balance` field on the payees table representing the net amount owed (positive = business owes the payee, or payee owes the business depending on context)
- **Business_Account**: A record in `business_accounts` representing a financial account with a running balance
- **Amount_BC**: The monetary amount converted to business currency using the exchange rate
- **Purchase_Order**: A document representing goods ordered from a supplier
- **Sales_Invoice**: A document representing goods or services sold to a customer
- **Expense**: A record representing a cost incurred by the business owed to a payee
- **Salary**: A record representing employee compensation owed by the business
- **Payment_Slip**: A record of money paid out from a business account to a payee
- **Collection_Slip**: A record of money received into a business account from a payee
- **Status_Transition**: A change in the lifecycle status of a document (e.g., DRAFT → APPROVED)
- **Balance_Delta**: The signed amount added to or subtracted from a balance during an update

## Requirements

### Requirement 1: Purchase Order Balance Update on Status Change

**User Story:** As a business owner, I want the supplier's payee balance to increase when a Purchase Order is received, so that I can see how much I owe the supplier.

#### Acceptance Criteria

1. WHEN a Purchase_Order transitions to RECEIVED status, THE Balance_Tracker SHALL increase the supplier Payee_Balance by the Purchase_Order total_amount_bc
2. WHEN a Purchase_Order transitions to CANCELLED status from RECEIVED, THE Balance_Tracker SHALL decrease the supplier Payee_Balance by the Purchase_Order total_amount_bc
3. WHEN a Purchase_Order transitions to CANCELLED status from APPROVED, THE Balance_Tracker SHALL NOT modify the supplier Payee_Balance
4. IF a Purchase_Order has no supplier_id assigned, THEN THE Balance_Tracker SHALL skip the Payee_Balance update without error

### Requirement 2: Sales Invoice Balance Update on Status Change

**User Story:** As a business owner, I want the customer's payee balance to increase when a Sales Invoice is approved, so that I can track how much the customer owes me.

#### Acceptance Criteria

1. WHEN a Sales_Invoice transitions to APPROVED status, THE Balance_Tracker SHALL increase the customer Payee_Balance by the Sales_Invoice total_amount_bc
2. WHEN a Sales_Invoice transitions to CANCELLED status from APPROVED, THE Balance_Tracker SHALL decrease the customer Payee_Balance by the Sales_Invoice total_amount_bc
3. WHEN a Sales_Invoice transitions to PAID status, THE Balance_Tracker SHALL decrease the customer Payee_Balance by the Sales_Invoice total_amount_bc via the generated Collection_Slip
4. IF a Sales_Invoice has no customer_id assigned, THEN THE Balance_Tracker SHALL skip the Payee_Balance update without error

### Requirement 3: Expense Balance Update on Lifecycle Events

**User Story:** As a business owner, I want the payee balance to increase when an expense is created, so that I can see how much I owe the person who incurred the expense.

#### Acceptance Criteria

1. WHEN an Expense is created with a payee_id, THE Balance_Tracker SHALL increase the Payee_Balance by the Expense amount_bc
2. WHEN an Expense is updated and the amount_bc changes, THE Balance_Tracker SHALL adjust the Payee_Balance by the difference between the new amount_bc and the old amount_bc
3. WHEN an Expense is updated and the payee_id changes from one payee to another, THE Balance_Tracker SHALL decrease the old Payee_Balance by the old amount_bc and increase the new Payee_Balance by the new amount_bc
4. WHEN an Expense is deleted, THE Balance_Tracker SHALL decrease the Payee_Balance by the Expense amount_bc
5. IF an Expense has no payee_id assigned, THEN THE Balance_Tracker SHALL skip the Payee_Balance update without error

### Requirement 4: Salary Balance Update on Lifecycle Events

**User Story:** As a business owner, I want the employee's payee balance to increase when a salary record is created, so that I can track how much I owe the employee.

#### Acceptance Criteria

1. WHEN a Salary is created with a payee_id, THE Balance_Tracker SHALL increase the Payee_Balance by the Salary net_amount_bc
2. WHEN a Salary is updated and the net_amount_bc changes, THE Balance_Tracker SHALL adjust the Payee_Balance by the difference between the new net_amount_bc and the old net_amount_bc
3. WHEN a Salary is updated and the payee_id changes from one payee to another, THE Balance_Tracker SHALL decrease the old Payee_Balance by the old net_amount_bc and increase the new Payee_Balance by the new net_amount_bc
4. WHEN a Salary is deleted, THE Balance_Tracker SHALL decrease the Payee_Balance by the Salary net_amount_bc
5. IF a Salary has no payee_id assigned, THEN THE Balance_Tracker SHALL skip the Payee_Balance update without error

### Requirement 5: Atomicity of Balance Updates

**User Story:** As a business owner, I want balance updates to be all-or-nothing, so that my financial data remains consistent even if an error occurs mid-operation.

#### Acceptance Criteria

1. THE Balance_Tracker SHALL execute all Payee_Balance modifications within the same database transaction as the originating document operation
2. IF any part of a balance update fails, THEN THE Balance_Tracker SHALL roll back the entire transaction including the originating document change
3. THE Balance_Tracker SHALL use atomic SQL operations (balance = balance + delta) to prevent race conditions on concurrent updates

### Requirement 6: Payment and Collection Balance Preservation

**User Story:** As a business owner, I want existing payment and collection balance behavior to remain unchanged, so that my current financial workflows continue to work correctly.

#### Acceptance Criteria

1. WHEN a Payment_Slip is created, THE Balance_Tracker SHALL decrease the Payee_Balance by the Payment_Slip amount_bc
2. WHEN a Payment_Slip is deleted, THE Balance_Tracker SHALL increase the Payee_Balance by the Payment_Slip amount_bc
3. WHEN a Collection_Slip is created, THE Balance_Tracker SHALL decrease the Payee_Balance by the Collection_Slip amount_bc
4. WHEN a Collection_Slip is deleted, THE Balance_Tracker SHALL increase the Payee_Balance by the Collection_Slip amount_bc
5. WHEN a Payment_Slip is created, THE Balance_Tracker SHALL decrease the Business_Account balance by the Payment_Slip amount_bc
6. WHEN a Collection_Slip is created, THE Balance_Tracker SHALL increase the Business_Account balance by the Collection_Slip amount_bc

### Requirement 7: Balance Calculation Uses Business Currency

**User Story:** As a business owner operating in multiple currencies, I want all balance updates to use the business currency amount, so that balances are consistent and comparable.

#### Acceptance Criteria

1. THE Balance_Tracker SHALL use amount_bc (amount in business currency) for all Payee_Balance adjustments
2. THE Balance_Tracker SHALL compute amount_bc as amount_fc multiplied by exchange_rate, rounded to 2 decimal places
3. FOR Salary records, THE Balance_Tracker SHALL use net_amount_bc for Payee_Balance adjustments

### Requirement 8: Cancellation Reversal for Documents with Balance Impact

**User Story:** As a business owner, I want cancelling a document to reverse its balance impact, so that the payee balance accurately reflects only active obligations.

#### Acceptance Criteria

1. WHEN a Purchase_Order in RECEIVED status transitions to CANCELLED, THE Balance_Tracker SHALL decrease the supplier Payee_Balance by the Purchase_Order total_amount_bc
2. WHEN a Sales_Invoice in APPROVED status transitions to CANCELLED, THE Balance_Tracker SHALL decrease the customer Payee_Balance by the Sales_Invoice total_amount_bc
3. THE Balance_Tracker SHALL only reverse balance for documents that previously caused a balance increase (RECEIVED for POs, APPROVED for Sales Invoices)

### Requirement 9: Edit Handling for Amount Changes

**User Story:** As a business owner, I want editing a document's amount to adjust the payee balance by the difference, so that the balance always reflects the current obligation.

#### Acceptance Criteria

1. WHEN an Expense amount_bc is updated, THE Balance_Tracker SHALL adjust the Payee_Balance by the delta (new_amount_bc minus old_amount_bc)
2. WHEN a Salary net_amount_bc is updated, THE Balance_Tracker SHALL adjust the Payee_Balance by the delta (new_net_amount_bc minus old_net_amount_bc)
3. THE Balance_Tracker SHALL compute the delta before applying the update to ensure accuracy based on the stored previous value

### Requirement 10: Audit Trail for Balance Changes

**User Story:** As a business owner, I want all balance changes to be logged in the audit trail, so that I can trace the history of any balance adjustment.

#### Acceptance Criteria

1. WHEN the Balance_Tracker modifies a Payee_Balance, THE Balance_Tracker SHALL record the change in the audit log with the originating document type, document id, and Balance_Delta
2. THE Balance_Tracker SHALL include both the before and after balance context in the audit log entry
