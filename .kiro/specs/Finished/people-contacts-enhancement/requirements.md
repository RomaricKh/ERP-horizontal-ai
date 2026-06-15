# Requirements Document

## Introduction

This feature enhances the People & Contacts module in the multi-tenant ERP system. It addresses four key improvements: filtering the people table to show only typed payees (reducing clutter), enforcing phone number uniqueness within a tenant to prevent duplicates, adding a multi-select type filter to the people table, and providing a statement-of-account view showing balance activity (debits/credits) for a specific time period per payee.

## Glossary

- **People_List_Service**: The backend service responsible for retrieving and filtering the list of people/payees for display in the current business context
- **Phone_Uniqueness_Checker**: The backend component that validates phone number uniqueness within a tenant and identifies existing people with matching phone numbers
- **Type_Filter**: The frontend multi-select dropdown component that allows users to filter the people table by one or more payee types simultaneously
- **Statement_Service**: The backend service responsible for aggregating all debit and credit transactions for a specific payee within a given date range to produce a statement of account
- **People_Table**: The frontend DataTables-based table that displays the list of people/payees for the current business
- **Link_Prompt**: The frontend dialog that appears when a phone number match is detected, offering the user the option to link the existing person to the current business instead of creating a duplicate
- **Statement_View**: The frontend component that displays the balance activity overview (debits and credits) for a selected payee within a specified date range

## Requirements

### Requirement 1: Hide Untyped People from the People Table

**User Story:** As a business user, I want the people table to show only people who have a payee record with at least one type assigned in the current business, so that the list is not cluttered with irrelevant contacts.

#### Acceptance Criteria

1. WHEN the People_Table is loaded, THE People_List_Service SHALL return only people who have a payee record linked to the current business AND have at least one payee type assigned via the business_payees_types junction table
2. WHEN a person has a payee record in the current business but no payee types assigned, THE People_List_Service SHALL exclude that person from the list response
3. WHEN a person has no payee record in the current business, THE People_List_Service SHALL exclude that person from the list response
4. WHEN a payee type is removed from a person and that person has no remaining payee types in the current business, THE People_Table SHALL no longer display that person after the next data refresh
5. WHEN a payee type is assigned to a person who previously had no types in the current business, THE People_Table SHALL display that person after the next data refresh

### Requirement 2: Mandatory Phone Number with Tenant-Level Uniqueness Check

**User Story:** As a business user, I want the system to require a phone number when adding a new person and check for duplicates within the tenant, so that I avoid creating duplicate contact records.

#### Acceptance Criteria

1. WHEN a new person is created, THE People_List_Service SHALL require the phone field to be non-empty and reject the request with a validation error if phone is missing or blank
2. WHEN a new person is created with a phone number, THE Phone_Uniqueness_Checker SHALL search the people table for any existing person with the same phone number within the same tenant
3. WHEN the Phone_Uniqueness_Checker finds an existing person with the same phone number in the tenant, THE System SHALL return a response containing the existing person's id, name, and phone number along with a flag indicating a duplicate was found, without creating a new person record
4. WHEN the frontend receives a duplicate-found response, THE Link_Prompt SHALL display a dialog showing the existing person's name and phone number and offer two options: link the existing person to the current business, or cancel the operation
5. WHEN the user confirms linking via the Link_Prompt, THE System SHALL create a payee record for the existing person in the current business (if one does not already exist) and assign the specified payee types
6. WHEN the user cancels the Link_Prompt, THE System SHALL not create any new person or payee record and SHALL return the user to the person creation form
7. WHEN a person's phone number is updated, THE Phone_Uniqueness_Checker SHALL validate that no other person in the same tenant already has that phone number, and SHALL reject the update with a validation error if a conflict exists
8. THE Phone_Uniqueness_Checker SHALL compare phone numbers after stripping all non-digit characters to prevent duplicates caused by formatting differences (spaces, dashes, parentheses)

### Requirement 3: Multi-Select Filter by Payee Type

**User Story:** As a business user, I want to filter the people table by one or more payee types simultaneously, so that I can quickly find contacts of a specific category.

#### Acceptance Criteria

1. THE People_Table SHALL display a multi-select dropdown filter above the table containing all payee types defined for the current business
2. WHEN one or more payee types are selected in the Type_Filter, THE People_Table SHALL display only people who have at least one of the selected payee types assigned in the current business
3. WHEN no payee types are selected in the Type_Filter (default state), THE People_Table SHALL display all typed people without type-based filtering (showing all people who pass the Requirement 1 criteria)
4. WHEN the available payee types change (type created or deleted), THE Type_Filter SHALL update its options list without requiring a full page reload
5. THE Type_Filter SHALL perform filtering on the client side using the payee_types data already present in the table response, without making additional API calls for each filter change
6. WHEN multiple payee types are selected, THE Type_Filter SHALL use OR logic: a person is shown if they have any one of the selected types

### Requirement 4: Balance Activity Statement (Statement of Account)

**User Story:** As a business user, I want to view a statement of account for a specific payee showing all debits and credits within a date range, so that I can review their financial activity and verify balances.

#### Acceptance Criteria

1. WHEN a user requests a balance activity statement for a payee, THE Statement_Service SHALL accept a payee_id, a start_date, and an end_date as parameters
2. THE Statement_Service SHALL aggregate all debit transactions (expenses, purchase orders) and all credit transactions (payment slips, collection slips, sales invoices) for the specified payee within the date range (inclusive of both start and end dates)
3. THE Statement_Service SHALL return each transaction with: date, type (expense, purchase_order, sales_invoice, payment_slip, collection_slip), reference number, description, debit amount, credit amount, and running balance
4. THE Statement_Service SHALL sort transactions by date in ascending order, with transactions on the same date sorted by creation time
5. THE Statement_Service SHALL calculate the opening balance as the payee's net balance of all transactions dated before the start_date
6. THE Statement_Service SHALL calculate the closing balance as the opening balance plus all debits minus all credits within the date range
7. IF the specified payee_id does not exist or does not belong to the current business, THEN THE Statement_Service SHALL reject the request with an error indicating the payee was not found
8. WHEN the Statement_View is opened, THE Statement_View SHALL default the date range to the current calendar month (first day of month to today)
9. THE Statement_View SHALL display the opening balance, a table of transactions with running balance, and the closing balance
10. THE Statement_View SHALL provide an option to export the statement data as a downloadable file
