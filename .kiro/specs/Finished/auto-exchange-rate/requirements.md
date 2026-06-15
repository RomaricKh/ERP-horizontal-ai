# Requirements Document

## Introduction

This feature automates the population of exchange rate fields across all financial document forms in the ERP system. Instead of requiring users to manually type the exchange rate on every payment, collection, expense, salary, purchase order, and sales invoice, the system will automatically look up the most recent rate from the exchange rates table based on the selected currency and the business's base currency. The base currency is defined in the business table's currency field.

## Glossary

- **Exchange_Rate_Table**: The database table (`exchange_rates`) where administrators define currency exchange rates per business, including currency code, rate date, and rate value.
- **Base_Currency**: The currency defined in the `currency` field of the business table for the currently active business. All base-currency (BC) calculations use this currency.
- **Foreign_Currency**: Any currency selected on a financial document that differs from the Base_Currency.
- **Financial_Document_Form**: Any form modal used to create or edit a financial document, including: Payment, Collection, Expense, Salary, Purchase Order, and Sales Invoice forms.
- **Rate_Lookup_Service**: The backend service (`ExchangeRateService`) responsible for retrieving the most recent exchange rate for a given currency and business.
- **Auto_Populate**: The action of automatically filling the exchange rate input field with a value retrieved from the Exchange_Rate_Table without manual user input.
- **Currency_Select**: The currency dropdown or input field present on each Financial_Document_Form.

## Requirements

### Requirement 1: Auto-populate exchange rate on currency selection

**User Story:** As a user creating a financial document, I want the exchange rate field to be automatically filled from the exchange rate table when I select a currency, so that I do not have to manually look up and type the rate every time.

#### Acceptance Criteria

1. WHEN a user selects a Foreign_Currency in the Currency_Select on any Financial_Document_Form, THE Rate_Lookup_Service SHALL retrieve the most recent rate for that currency from the Exchange_Rate_Table for the current business.
2. WHEN the Rate_Lookup_Service returns a rate, THE Financial_Document_Form SHALL Auto_Populate the exchange rate input field with the retrieved rate value.
3. WHEN the selected currency equals the Base_Currency, THE Financial_Document_Form SHALL set the exchange rate field to 1.
4. IF no rate exists in the Exchange_Rate_Table for the selected Foreign_Currency, THEN THE Financial_Document_Form SHALL leave the exchange rate field empty and display a warning message indicating that no rate was found.

### Requirement 2: Base currency determined from business table

**User Story:** As a business owner, I want the system to use my business's defined currency as the base currency for all exchange rate lookups, so that conversions are always relative to my operating currency.

#### Acceptance Criteria

1. THE Rate_Lookup_Service SHALL use the currency field from the business table of the currently active business as the Base_Currency for all exchange rate lookups.
2. WHEN a Financial_Document_Form is opened, THE Financial_Document_Form SHALL identify the Base_Currency from the current business context before performing any rate lookup.

### Requirement 3: Backend API endpoint for rate lookup

**User Story:** As a frontend developer, I want a dedicated API endpoint that returns the latest exchange rate for a given currency, so that all financial forms can retrieve rates consistently.

#### Acceptance Criteria

1. THE Rate_Lookup_Service SHALL expose an API endpoint that accepts a currency code parameter and returns the most recent exchange rate for the current business.
2. WHEN the API endpoint receives a valid currency code, THE Rate_Lookup_Service SHALL return the rate value and rate date from the Exchange_Rate_Table.
3. WHEN the API endpoint receives a currency code that matches the Base_Currency, THE Rate_Lookup_Service SHALL return a rate of 1 without querying the Exchange_Rate_Table.
4. IF the API endpoint receives an empty or invalid currency code, THEN THE Rate_Lookup_Service SHALL return an error response with a descriptive message.

### Requirement 4: Apply auto-population to all financial document types

**User Story:** As a user, I want the exchange rate auto-population to work consistently across all payment screens, so that I have a uniform experience regardless of the document type.

#### Acceptance Criteria

1. WHEN a currency is selected on the Payment form, THE Payment form SHALL Auto_Populate the exchange rate from the Exchange_Rate_Table.
2. WHEN a currency is selected on the Collection form, THE Collection form SHALL Auto_Populate the exchange rate from the Exchange_Rate_Table.
3. WHEN a currency is selected on the Expense form, THE Expense form SHALL Auto_Populate the exchange rate from the Exchange_Rate_Table.
4. WHEN a currency is selected on the Salary form, THE Salary form SHALL Auto_Populate the exchange rate from the Exchange_Rate_Table.
5. WHEN a currency is selected on the Purchase Order form, THE Purchase Order form SHALL Auto_Populate the exchange rate from the Exchange_Rate_Table.
6. WHEN a currency is selected on the Sales Invoice form, THE Sales Invoice form SHALL Auto_Populate the exchange rate from the Exchange_Rate_Table.

### Requirement 5: User override of auto-populated rate

**User Story:** As a user, I want to be able to manually override the auto-populated exchange rate if needed, so that I can handle exceptional cases where the stored rate does not apply.

#### Acceptance Criteria

1. WHILE the exchange rate field is Auto_Populated, THE Financial_Document_Form SHALL keep the exchange rate field editable so the user can modify the value.
2. WHEN a user manually changes the auto-populated exchange rate, THE Financial_Document_Form SHALL use the user-entered value for all subsequent calculations on that document.
3. WHEN a user changes the currency selection after manually editing the exchange rate, THE Financial_Document_Form SHALL Auto_Populate the exchange rate field with the new currency's rate, replacing the previous manual entry.

### Requirement 6: Recalculate base-currency amounts on auto-population

**User Story:** As a user, I want the base-currency amounts to recalculate automatically when the exchange rate is auto-populated, so that the totals are always accurate.

#### Acceptance Criteria

1. WHEN the exchange rate field is Auto_Populated, THE Financial_Document_Form SHALL trigger a recalculation of all base-currency amount fields using the new rate.
2. WHEN the exchange rate field is Auto_Populated on a line-item form (Purchase Order, Sales Invoice), THE Financial_Document_Form SHALL recalculate all line-item base-currency unit prices and the grand total in base currency.

### Requirement 7: Backend validation uses exchange rate table as fallback

**User Story:** As a system administrator, I want the backend to validate and apply the exchange rate from the table when no rate is provided in the request, so that documents are never saved without a valid rate.

#### Acceptance Criteria

1. WHEN a financial document is submitted without an exchange rate value, THE Rate_Lookup_Service SHALL look up the latest rate from the Exchange_Rate_Table and apply it before saving.
2. IF a financial document is submitted without an exchange rate and no rate exists in the Exchange_Rate_Table for the specified currency, THEN THE Rate_Lookup_Service SHALL reject the request with an error message indicating that an exchange rate is required.
3. WHEN a financial document is submitted with an exchange rate value provided by the user, THE Rate_Lookup_Service SHALL accept and use the user-provided rate without overriding it.
