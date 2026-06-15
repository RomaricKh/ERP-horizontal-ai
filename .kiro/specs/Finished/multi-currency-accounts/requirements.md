# Requirements Document

## Introduction

This feature enforces currency-aware account selection and balance tracking for businesses operating in multiple currencies. Currently, accounts have a currency field and payments/collections reference an account, but there is no validation ensuring the transaction currency matches the account currency. Additionally, account balances are stored only in business currency (amount_bc), which does not reflect the true native-currency position of each account. This feature adds server-side currency matching validation, UI-level account filtering by currency, native-currency balance tracking on accounts, and a consolidated multi-currency account overview.

## Glossary

- **Account_Selector**: The UI component responsible for presenting and filtering business accounts during payment and collection slip creation
- **Currency_Validator**: The server-side component that validates the transaction currency matches the selected account currency before persisting a payment or collection slip
- **Business_Account**: A record in `business_accounts` representing a financial account with a name, currency, and running balance
- **Native_Balance**: The account balance expressed in the account's own currency (e.g., an LBP account shows balance in LBP)
- **Business_Currency_Balance**: The account balance expressed in the business's base currency, computed using exchange rates
- **Payment_Slip**: A record of money paid out from a business account to a payee
- **Collection_Slip**: A record of money received into a business account from a payee
- **Transaction_Currency**: The currency specified on a payment slip or collection slip (the `currency` field)
- **Account_Currency**: The currency assigned to a business account at creation time
- **Consolidated_View**: A UI display showing all accounts across currencies with their native balances and business-currency equivalents
- **Exchange_Rate**: The conversion rate from a foreign currency to the business currency, stored per tenant/business/date

## Requirements

### Requirement 1: Currency Match Validation on Payment Creation

**User Story:** As a business owner, I want the system to reject payments where the transaction currency does not match the selected account's currency, so that money is always deducted from the correct currency account.

#### Acceptance Criteria

1. WHEN a Payment_Slip is created, THE Currency_Validator SHALL verify that the Transaction_Currency equals the Account_Currency of the selected Business_Account
2. IF the Transaction_Currency does not match the Account_Currency, THEN THE Currency_Validator SHALL reject the operation and return a descriptive error message indicating the mismatch
3. THE Currency_Validator SHALL perform the currency match check before any balance update or database insert occurs

### Requirement 2: Currency Match Validation on Collection Creation

**User Story:** As a business owner, I want the system to reject collections where the transaction currency does not match the selected account's currency, so that money is always deposited into the correct currency account.

#### Acceptance Criteria

1. WHEN a Collection_Slip is created, THE Currency_Validator SHALL verify that the Transaction_Currency equals the Account_Currency of the selected Business_Account
2. IF the Transaction_Currency does not match the Account_Currency, THEN THE Currency_Validator SHALL reject the operation and return a descriptive error message indicating the mismatch
3. THE Currency_Validator SHALL perform the currency match check before any balance update or database insert occurs

### Requirement 3: Account Filtering by Currency in Payment Form

**User Story:** As a user creating a payment, I want the account dropdown to show only accounts matching the selected transaction currency, so that I cannot accidentally choose a mismatched account.

#### Acceptance Criteria

1. WHEN the user selects a currency in the Payment_Slip form, THE Account_Selector SHALL display only Business_Accounts whose Account_Currency matches the selected currency
2. WHEN the user changes the currency selection, THE Account_Selector SHALL reset the previously selected account and re-filter the list to match the new currency
3. WHEN no currency is selected, THE Account_Selector SHALL display all available Business_Accounts with their currency indicated
4. IF no Business_Accounts exist for the selected currency, THEN THE Account_Selector SHALL display an empty list with a message indicating no accounts are available for that currency

### Requirement 4: Account Filtering by Currency in Collection Form

**User Story:** As a user creating a collection, I want the account dropdown to show only accounts matching the selected transaction currency, so that I cannot accidentally choose a mismatched account.

#### Acceptance Criteria

1. WHEN the user selects a currency in the Collection_Slip form, THE Account_Selector SHALL display only Business_Accounts whose Account_Currency matches the selected currency
2. WHEN the user changes the currency selection, THE Account_Selector SHALL reset the previously selected account and re-filter the list to match the new currency
3. WHEN no currency is selected, THE Account_Selector SHALL display all available Business_Accounts with their currency indicated
4. IF no Business_Accounts exist for the selected currency, THEN THE Account_Selector SHALL display an empty list with a message indicating no accounts are available for that currency

### Requirement 5: Native Currency Balance Tracking

**User Story:** As a business owner, I want each account's balance to be tracked in its native currency, so that I can see the actual amount held in each currency account without exchange rate distortion.

#### Acceptance Criteria

1. THE Business_Account SHALL maintain a Native_Balance field that stores the running balance in the Account_Currency
2. WHEN a Payment_Slip is created, THE Balance_Tracker SHALL decrease the Native_Balance of the selected Business_Account by the Payment_Slip amount_fc
3. WHEN a Payment_Slip is deleted, THE Balance_Tracker SHALL increase the Native_Balance of the selected Business_Account by the Payment_Slip amount_fc
4. WHEN a Collection_Slip is created, THE Balance_Tracker SHALL increase the Native_Balance of the selected Business_Account by the Collection_Slip amount_fc
5. WHEN a Collection_Slip is deleted, THE Balance_Tracker SHALL decrease the Native_Balance of the selected Business_Account by the Collection_Slip amount_fc
6. THE Balance_Tracker SHALL update the Native_Balance atomically within the same database transaction as the slip creation or deletion

### Requirement 6: Preserve Existing Business Currency Balance

**User Story:** As a business owner, I want the existing business-currency balance to continue working alongside the new native balance, so that reporting and payee balance logic remain unaffected.

#### Acceptance Criteria

1. WHEN a Payment_Slip is created, THE Balance_Tracker SHALL continue to decrease the Business_Account balance field by the Payment_Slip amount_bc
2. WHEN a Payment_Slip is deleted, THE Balance_Tracker SHALL continue to increase the Business_Account balance field by the Payment_Slip amount_bc
3. WHEN a Collection_Slip is created, THE Balance_Tracker SHALL continue to increase the Business_Account balance field by the Collection_Slip amount_bc
4. WHEN a Collection_Slip is deleted, THE Balance_Tracker SHALL continue to decrease the Business_Account balance field by the Collection_Slip amount_bc
5. THE Balance_Tracker SHALL update both the Native_Balance and the Business_Currency_Balance within the same atomic transaction

### Requirement 7: Consolidated Multi-Currency Account View

**User Story:** As a business owner, I want to see all my accounts listed with their native-currency balances and business-currency equivalents, so that I have a complete financial overview across currencies.

#### Acceptance Criteria

1. THE Consolidated_View SHALL display each Business_Account with its name, Account_Currency, and Native_Balance
2. THE Consolidated_View SHALL display the Business_Currency_Balance for each account
3. THE Consolidated_View SHALL group or visually distinguish accounts by their Account_Currency
4. THE Consolidated_View SHALL display a total balance across all accounts expressed in the business currency

### Requirement 8: Account Currency Immutability

**User Story:** As a business owner, I want the currency of an account to be fixed after creation, so that existing transactions and balances remain consistent.

#### Acceptance Criteria

1. WHEN a Business_Account is created, THE Account_Service SHALL require a valid currency value
2. WHEN a Business_Account update is requested, THE Account_Service SHALL reject any attempt to change the Account_Currency field
3. IF an update request includes a different currency value than the current Account_Currency, THEN THE Account_Service SHALL return an error indicating that account currency cannot be changed

### Requirement 9: Account List API Supports Currency Filtering

**User Story:** As a frontend developer, I want to request accounts filtered by currency from the API, so that the UI can efficiently load only relevant accounts for a given transaction currency.

#### Acceptance Criteria

1. WHEN the accounts list endpoint receives a currency query parameter, THE Account_Service SHALL return only Business_Accounts whose Account_Currency matches the provided value
2. WHEN the accounts list endpoint receives no currency query parameter, THE Account_Service SHALL return all Business_Accounts for the business
3. THE Account_Service SHALL perform case-insensitive comparison on the currency filter value

### Requirement 10: Backward Compatibility for Existing Transactions

**User Story:** As a business owner with existing payment and collection records, I want the new validation to apply only to new transactions, so that historical data remains intact and accessible.

#### Acceptance Criteria

1. THE Currency_Validator SHALL apply currency match validation only during the creation of new Payment_Slips and Collection_Slips
2. THE System SHALL continue to display and allow deletion of existing Payment_Slips and Collection_Slips regardless of whether their Transaction_Currency matches the linked Account_Currency
3. WHEN the Native_Balance field is introduced, THE System SHALL initialize it to zero for existing accounts (historical transactions are not retroactively recalculated)
