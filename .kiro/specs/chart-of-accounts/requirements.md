# Requirements Document

## Introduction

This feature introduces a full Chart of Accounts (COA) system into the ERP, replacing the current flat `business_accounts` table with a structured, numbered account hierarchy following standard accounting classification (Assets, Liabilities, Equity, Revenue, Expenses). Each account carries a code, description/guide, and classification. The system supports per-contact sub-accounts so that a person who is simultaneously a customer and supplier gets separate numbered accounts (e.g., 411-CustomerName for Accounts Receivable, 201-SupplierName for Accounts Payable). Cross-type netting allows settling balances across a contact's different account types.

## Glossary

- **COA**: Chart of Accounts — the complete numbered listing of all accounts used by a business to record financial transactions.
- **COA_Engine**: The backend subsystem responsible for managing the Chart of Accounts hierarchy, account creation, validation, and balance operations.
- **Account_Classification**: The top-level grouping of accounts: Assets (1xx), Liabilities (2xx), Equity (3xx), Revenue (4xx), Expenses (5xx).
- **Control_Account**: A parent-level numbered account (e.g., 411 Accounts Receivable) that aggregates sub-account balances.
- **Sub_Account**: A numbered account under a control account, linked to a specific contact and relationship type (e.g., 411-001 for Customer A's receivable).
- **Account_Code**: A numeric identifier following the standard COA numbering scheme (e.g., 101, 201, 411, 501).
- **Account_Guide**: A textual description explaining the purpose and usage of an account for end users.
- **Contact_Account**: A sub-account automatically created for a specific person (contact) under the relevant control account based on their payee type within a business.
- **Netting_Entry**: A journal entry that offsets balances between two sub-accounts belonging to the same contact across different relationship types (e.g., AR against AP).
- **Business**: An operating unit within a tenant; the COA is scoped per business.
- **Payee**: A person's financial identity within a specific business.
- **Person**: A contact entity that can hold multiple types (customer, supplier, employee, partner).
- **System_Account**: A predefined COA account seeded on business creation that cannot be deleted (but can be deactivated).
- **User_Account**: A COA account created by the user for custom tracking purposes.

## Requirements

### Requirement 1: Account Classification Hierarchy

**User Story:** As a business owner, I want my accounts organized into standard classification groups (Assets, Liabilities, Equity, Revenue, Expenses), so that financial reports follow accepted accounting standards and my auditor can navigate the books.

#### Acceptance Criteria

1. THE COA_Engine SHALL organize all accounts into exactly five top-level classifications: Assets, Liabilities, Equity, Revenue, and Expenses.
2. WHEN a new business is created, THE COA_Engine SHALL seed a default set of System_Accounts covering all five classifications with standard account codes, where each seeded account is marked as a system account that cannot be deleted or have its Account_Code or classification changed.
3. THE COA_Engine SHALL assign account codes following the standard numbering ranges: Assets (100–199), Liabilities (200–299), Equity (300–399), Revenue (400–499), Expenses (500–599).
4. IF a user attempts to create or update an account with an Account_Code that already exists within the same business, THEN THE COA_Engine SHALL reject the operation and return an error message indicating the code is already in use.
5. THE COA_Engine SHALL store an Account_Guide of up to 500 characters for each account explaining the account purpose.
6. IF a user attempts to create or update an account with an Account_Code outside the valid numbering range for its assigned classification, THEN THE COA_Engine SHALL reject the operation and return an error message indicating the code is not within the allowed range for that classification.

### Requirement 2: System-Seeded Default Accounts

**User Story:** As a business owner, I want a standard set of accounts created automatically when my business is set up, so that I have a working chart of accounts from day one without manual configuration.

#### Acceptance Criteria

1. WHEN a new business is created, THE COA_Engine SHALL atomically create the following minimum System_Accounts: 101 Cash, 102 Bank, 105 Inventory, 110 Prepaid Expenses, 201 Accounts Payable, 210 Accrued Liabilities, 301 Owner's Equity, 302 Retained Earnings, 401 Sales Revenue, 402 Service Revenue, 411 Accounts Receivable (Control), 501 Cost of Goods Sold, 502 Salaries Expense, 503 Rent Expense, 504 Utilities Expense, 505 Marketing Expense, 510 Depreciation Expense, 520 General & Administrative.
2. THE COA_Engine SHALL mark each seeded account with an is_system flag set to true and assign the Account_Classification corresponding to its code range.
3. WHILE an account is marked as is_system, THE COA_Engine SHALL reject any deletion request for that account and return an error message indicating the account is system-protected.
4. WHEN a user deactivates a System_Account, THE COA_Engine SHALL set the account to inactive regardless of its current balance or transaction history.
5. IF the seeding operation fails for any account during business creation, THEN THE COA_Engine SHALL roll back all seeded accounts for that business so that no partial set of System_Accounts exists.

### Requirement 3: User-Defined Custom Accounts

**User Story:** As an accountant, I want to create custom accounts within any classification group, so that I can tailor the chart of accounts to my business's specific needs.

#### Acceptance Criteria

1. WHEN a user creates a new account, THE COA_Engine SHALL require an account code (integer), account name (1 to 100 characters), classification (one of Assets, Liabilities, Equity, Revenue, Expenses), and account guide (1 to 500 characters).
2. IF a user provides an account code that falls outside the numeric range of the chosen classification (Assets: 100–199, Liabilities: 200–299, Equity: 300–399, Revenue: 400–499, Expenses: 500–599), THEN THE COA_Engine SHALL reject the creation and return an error indicating the valid range for the selected classification.
3. IF a user provides an account code that already exists within the business, THEN THE COA_Engine SHALL reject the creation and return an error indicating that the account code is already in use.
4. THE COA_Engine SHALL allow the user to specify whether the new account is a control account (parent) or a regular account.
5. WHEN a user creates a custom account, THE COA_Engine SHALL mark it with is_system = 0 to allow future deletion.
6. IF a user submits a create account request with any required field missing or blank, THEN THE COA_Engine SHALL reject the creation and return an error identifying the missing field(s).

### Requirement 4: Per-Contact Sub-Accounts

**User Story:** As a business owner, I want each contact to have a separate numbered sub-account for each relationship type they hold with my business (customer, supplier, employee, partner), so that I can track individual balances per relationship and satisfy auditor requirements for detailed account tracking.

#### Acceptance Criteria

1. WHEN a person is assigned a payee type within a business, THE COA_Engine SHALL create a Contact_Account under the control account mapped to that type: Customer → 411 Accounts Receivable, Supplier → 201 Accounts Payable, Employee → 502 Salaries Expense, Partner → 301 Owner's Equity.
2. THE COA_Engine SHALL generate sub-account codes using the pattern: {control_account_code}-{sequential_number} where the sequential number is zero-padded to 3 digits (001–999), scoped per control account per business (e.g., 411-001, 411-002).
3. WHEN a person holds multiple payee types (e.g., Customer AND Supplier), THE COA_Engine SHALL create one Contact_Account per type under the respective control accounts.
4. THE COA_Engine SHALL name each Contact_Account using the pattern: {person_name} – {payee_type_name} (e.g., "Acme Corp – Supplier") and link it to the payee record so that transactions posted to the contact are recorded in the correct sub-account.
5. IF a payee type is removed from a person and the corresponding Contact_Account has a non-zero balance or at least one posted transaction, THEN THE COA_Engine SHALL deactivate (not delete) the Contact_Account.
6. IF a payee type is removed from a person and the corresponding Contact_Account has zero balance and no posted transactions, THEN THE COA_Engine SHALL delete the Contact_Account and release its sequential number for future reuse.
7. IF a person is assigned a payee type whose mapped control account does not exist or is inactive in the business, THEN THE COA_Engine SHALL reject the Contact_Account creation and return an error indicating the required control account is unavailable.

### Requirement 5: Account Guide Descriptions

**User Story:** As a user, I want each account to have a description explaining what it is for, so that I can understand the purpose of each account without needing external accounting knowledge.

#### Acceptance Criteria

1. THE COA_Engine SHALL require a non-empty, whitespace-trimmed Account_Guide of 1 to 500 characters when creating any account (system or user-defined).
2. THE COA_Engine SHALL provide default Account_Guide text for all System_Accounts during seeding.
3. WHEN a user views the chart of accounts, THE COA_Engine SHALL return the Account_Guide alongside each account record.
4. WHEN a user edits the Account_Guide of any account (including System_Accounts), THE COA_Engine SHALL apply the same validation rules as creation (non-empty, whitespace-trimmed, 1 to 500 characters).
5. IF a user submits an Account_Guide that is empty or contains only whitespace, THEN THE COA_Engine SHALL reject the operation and return an error message indicating that a non-empty Account_Guide is required.

### Requirement 6: Cross-Type Netting

**User Story:** As a business owner, I want to offset a contact's receivable balance against their payable balance (or vice versa), so that I can settle mutual debts without requiring separate cash transactions.

#### Acceptance Criteria

1. WHEN a user initiates a netting operation, THE COA_Engine SHALL require the person (contact), source sub-account, target sub-account, and amount, where the amount is greater than zero.
2. IF the source and target sub-accounts do not both belong to the same person, THEN THE COA_Engine SHALL reject the operation and return an error message indicating the sub-accounts must belong to the same contact.
3. IF the source sub-account and target sub-account are the same account, THEN THE COA_Engine SHALL reject the operation and return an error message indicating that netting requires two distinct sub-accounts.
4. IF the netting amount exceeds the available balance on the source sub-account, THEN THE COA_Engine SHALL reject the operation and return an error message indicating insufficient balance.
5. WHEN a netting operation is executed, THE COA_Engine SHALL atomically decrease the source sub-account balance and increase the target sub-account balance by the netting amount, rolling back both changes if either fails.
6. WHEN a netting operation is executed, THE COA_Engine SHALL create an audit trail entry recording the netting with both sub-account references, amount, date, and user.
7. IF the source and target sub-accounts have different currencies, THEN THE COA_Engine SHALL require an exchange rate, deduct the specified amount from the source sub-account in its currency, and credit the target sub-account with the amount converted using the provided exchange rate.
8. IF either the source or target sub-account is inactive, THEN THE COA_Engine SHALL reject the netting operation and return an error message indicating that netting cannot be performed against inactive accounts.

### Requirement 7: Integration with Existing Financial Operations

**User Story:** As a system administrator, I want the COA system to integrate with existing payment, collection, expense, and invoice operations, so that all financial transactions are recorded against the proper accounts.

#### Acceptance Criteria

1. WHEN a collection slip is created, THE COA_Engine SHALL record a double-entry transaction debiting the Cash/Bank COA account (mapped from the collection slip's business_account) and crediting the customer's AR Contact_Account for the slip amount.
2. WHEN a payment slip is created, THE COA_Engine SHALL record a double-entry transaction debiting the supplier's AP Contact_Account and crediting the Cash/Bank COA account (mapped from the payment slip's business_account) for the slip amount.
3. WHEN a sales invoice status transitions to DELIVERED, THE COA_Engine SHALL record a double-entry transaction debiting the customer's AR Contact_Account and crediting the Revenue account (401) for the invoice total amount.
4. WHEN a purchase order status transitions to RECEIVED, THE COA_Engine SHALL record a double-entry transaction debiting the Inventory account (105) for item-based lines or the expense account linked to the expense category for service-based lines, and crediting the supplier's AP Contact_Account for the PO total amount.
5. WHEN an expense is recorded, THE COA_Engine SHALL record a double-entry transaction debiting the COA expense account mapped to the expense's expense_category and crediting the supplier's AP Contact_Account for the expense amount.
6. THE COA_Engine SHALL maintain backward compatibility with the existing `business_accounts` table by creating a one-to-one mapping between each business_account record and a corresponding COA account in the Assets classification, such that existing payment and collection slips continue to reference business_accounts while the COA_Engine uses the mapping to determine the COA debit/credit target.
7. IF a financial transaction references a payee that does not have a corresponding Contact_Account, THEN THE COA_Engine SHALL automatically create the Contact_Account under the appropriate control account before recording the transaction.
8. THE COA_Engine SHALL ensure that for every transaction recorded, the sum of all debit amounts equals the sum of all credit amounts.

### Requirement 8: COA Reporting and Display

**User Story:** As an accountant, I want to view the full chart of accounts in a hierarchical format with balances, so that I can verify the financial position of the business at a glance.

#### Acceptance Criteria

1. WHEN a user requests the chart of accounts listing, THE COA_Engine SHALL return accounts grouped by classification, showing control accounts with their sub-accounts nested underneath, sorted by account code within each classification.
2. THE COA_Engine SHALL return the current posted balance for each account, where a Control_Account's balance is the sum of its sub-account balances.
3. WHEN a user requests the trial balance view, THE COA_Engine SHALL return all accounts with their debit or credit balances such that total debits equal total credits across all accounts.
4. IF the trial balance total debits do not equal total credits, THEN THE COA_Engine SHALL return the trial balance with an out-of-balance indicator showing the difference amount.
5. WHEN a user filters the chart of accounts by classification, THE COA_Engine SHALL return only accounts within that classification, preserving the hierarchical structure.
6. WHEN a user searches accounts by code, name, or linked contact name, THE COA_Engine SHALL return all accounts where the search term matches as a case-insensitive substring of the account code, account name, or linked contact name.
7. WHEN a user requests the chart of accounts listing, THE COA_Engine SHALL include inactive accounts marked with their inactive status, and SHALL support an optional filter to exclude inactive accounts from the results.

### Requirement 9: Multi-Tenancy and Business Scoping

**User Story:** As a tenant owner with multiple businesses, I want each business to have its own independent chart of accounts, so that financial records remain separate and accurate per business.

#### Acceptance Criteria

1. THE COA_Engine SHALL scope all COA data to a single business (business_id) by including a business_id filter in every COA query and mutation operation.
2. WHEN a user accesses the COA, THE COA_Engine SHALL return only accounts belonging to the authenticated user's current business context.
3. IF a request references a COA account that does not belong to the authenticated user's current business, THEN THE COA_Engine SHALL reject the request and return an error message indicating the account was not found.
4. WHEN a new business is created within a tenant, THE COA_Engine SHALL seed a fresh set of default System_Accounts with zero balances, independent from other businesses in the same tenant.
5. IF a COA operation is attempted without a valid business context (missing or unresolved business_id), THEN THE COA_Engine SHALL reject the operation and return an error message indicating that a valid business context is required.

### Requirement 10: Account Lifecycle Management

**User Story:** As an accountant, I want to deactivate accounts that are no longer used and prevent posting to inactive accounts, so that the chart of accounts remains clean and only active accounts receive new transactions.

#### Acceptance Criteria

1. THE COA_Engine SHALL store an is_active flag on each account, defaulting to true upon account creation.
2. WHILE an account is inactive, THE COA_Engine SHALL reject any new transaction posting to that account and return an error indicating the account is inactive.
3. WHILE an account is inactive, THE COA_Engine SHALL retain the account and its historical balance and include it in reports that cover the period when the account was active.
4. IF a user attempts to deactivate an account that has a non-zero balance, THEN THE COA_Engine SHALL require explicit user confirmation before proceeding with deactivation.
5. IF a user attempts to delete a User_Account that has at least one posted transaction, THEN THE COA_Engine SHALL reject the deletion and return an error indicating that the account should be deactivated instead.
6. IF a user attempts to deactivate a Control_Account that has active sub-accounts, THEN THE COA_Engine SHALL reject the deactivation and return an error indicating that all sub-accounts must be deactivated first.
7. WHEN a user reactivates a previously deactivated account, THE COA_Engine SHALL set is_active to true and allow new transactions to be posted to that account.
