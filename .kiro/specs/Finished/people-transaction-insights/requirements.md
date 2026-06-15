# Requirements Document

## Introduction

This feature adds contextual transaction insight actions to the People module. Based on a person's payee type (Supplier, Customer, or other types like Employee/Partner), an action button in the people table opens a modal displaying aggregated transaction data. Suppliers see purchase history by item, Customers see sales history by item, and other types see a summary of their system usage across expenses, salaries, payments, and collections. All views are read-only and scoped to the current business.

## Glossary

- **Insights_Modal**: The Bootstrap 5 modal dialog that displays transaction insight data for a selected person, opened from the people table action column
- **Supplier_Insights_Service**: The backend service responsible for aggregating purchase order data for a given supplier payee
- **Customer_Insights_Service**: The backend service responsible for aggregating sales invoice data for a given customer payee
- **General_Insights_Service**: The backend service responsible for aggregating usage data (expenses, salaries, payments, collections) for payees that are not exclusively Supplier or Customer type
- **Insights_Controller**: The API controller that handles insight data requests and delegates to the appropriate service based on payee type
- **Insights_Repository**: The repository layer responsible for executing aggregation queries against purchase_orders, sales_invoices, expenses, salaries, payment_slips, and collection_slips tables
- **Payee_Type**: A classification assigned to a payee via the business_payees_types junction table, referencing the payee_types table (e.g., Supplier, Customer, Employee, Partner)

## Requirements

### Requirement 1: Supplier Purchase Insights API

**User Story:** As a business user, I want to see which items I usually purchase from a supplier and at what prices, so that I can make informed purchasing decisions.

#### Acceptance Criteria

1. WHEN a GET request is made to `/people/{payeeId}/insights/supplier`, THE Insights_Controller SHALL return a JSON response containing an array of item purchase records for that supplier within the current business
2. THE Insights_Repository SHALL aggregate data from purchase_orders and po_details tables, grouping by item_id, and SHALL return for each item: item name, total quantity purchased, average unit price (in foreign currency), last purchase price (unit_price_fc from the most recent PO detail), last purchase date, and total number of distinct purchase orders containing that item
3. WHEN the supplier payee has no purchase order records in the current business, THE Insights_Controller SHALL return a successful response with an empty items array
4. THE Insights_Repository SHALL filter purchase order data by the supplier_id matching the provided payee_id and the business_id matching the current business context
5. THE Insights_Repository SHALL order the results by total quantity purchased in descending order
6. IF the provided payeeId does not exist or does not belong to the current business, THEN THE Insights_Controller SHALL return HTTP 404 with a JSON response containing success as false and message "Payee not found"

### Requirement 2: Customer Sales Insights API

**User Story:** As a business user, I want to see which items a customer usually buys and at what prices, so that I can understand customer purchasing patterns.

#### Acceptance Criteria

1. WHEN a GET request is made to `/people/{payeeId}/insights/customer`, THE Insights_Controller SHALL return a JSON response containing an array of item sales records for that customer within the current business
2. THE Insights_Repository SHALL aggregate data from sales_invoices and si_details tables, grouping by item_id, and SHALL return for each item: item name, total quantity sold, average unit price (in foreign currency), last sale price (unit_price_fc from the most recent SI detail), last sale date, and total number of distinct sales invoices containing that item
3. WHEN the customer payee has no sales invoice records in the current business, THE Insights_Controller SHALL return a successful response with an empty items array
4. THE Insights_Repository SHALL filter sales invoice data by the customer_id matching the provided payee_id and the business_id matching the current business context
5. THE Insights_Repository SHALL order the results by total quantity sold in descending order
6. IF the provided payeeId does not exist or does not belong to the current business, THEN THE Insights_Controller SHALL return HTTP 404 with a JSON response containing success as false and message "Payee not found"

### Requirement 3: General Usage Insights API

**User Story:** As a business user, I want to see where a person (employee, partner, etc.) appears in the system, so that I can understand their overall financial relationship with the business.

#### Acceptance Criteria

1. WHEN a GET request is made to `/people/{payeeId}/insights/general`, THE Insights_Controller SHALL return a JSON response containing usage summary sections for expenses, salaries, payments, and collections
2. THE Insights_Repository SHALL return for the expenses section: total number of expense records, total amount, and a breakdown by expense_category_id showing category name, count, and total amount per category
3. THE Insights_Repository SHALL return for the salaries section: total number of salary records, total gross amount, and total net amount
4. THE Insights_Repository SHALL return for the payments section: total number of payment_slips records and total amount paid
5. THE Insights_Repository SHALL return for the collections section: total number of collection_slips records and total amount collected
6. WHEN the payee has no records in any of the four sections, THE Insights_Controller SHALL return a successful response with all section counts and totals as zero
7. THE Insights_Repository SHALL filter all data by the payee_id matching the provided payeeId and the business_id matching the current business context
8. IF the provided payeeId does not exist or does not belong to the current business, THEN THE Insights_Controller SHALL return HTTP 404 with a JSON response containing success as false and message "Payee not found"

### Requirement 4: Insights Action Button Visibility

**User Story:** As a business user, I want to see the appropriate insight action button based on a person's payee type, so that I can access relevant transaction data with one click.

#### Acceptance Criteria

1. WHEN the people table renders the action column for a person with payee type "Supplier", THE System SHALL display a "Purchase Insights" action button
2. WHEN the people table renders the action column for a person with payee type "Customer", THE System SHALL display a "Sales Insights" action button
3. WHEN the people table renders the action column for a person with a payee type that is neither "Supplier" nor "Customer", THE System SHALL display a "Usage Insights" action button
4. WHEN a person has both "Supplier" and "Customer" payee types assigned, THE System SHALL display both "Purchase Insights" and "Sales Insights" action buttons
5. WHEN a person has no payee record in the current business (payee_id is null), THE System SHALL not display any insight action button for that person
6. WHEN a person has a payee record but no payee types assigned, THE System SHALL display the "Usage Insights" action button as the default

### Requirement 5: Supplier Insights Modal Display

**User Story:** As a business user, I want to view supplier purchase data in a clear tabular format inside a modal, so that I can quickly scan purchasing patterns.

#### Acceptance Criteria

1. WHEN the "Purchase Insights" action button is clicked, THE Insights_Modal SHALL open and display a loading indicator while fetching data from the supplier insights API endpoint
2. WHEN data is loaded successfully, THE Insights_Modal SHALL display a DataTable with columns: Item Name, Total Qty Purchased, Average Unit Price, Last Purchase Price, Last Purchase Date, Number of POs
3. THE Insights_Modal SHALL display the supplier name in the modal header title
4. THE Insights_Modal SHALL format numeric values with appropriate decimal places (quantities as integers or up to 2 decimals, prices up to 2 decimals)
5. THE Insights_Modal SHALL format dates in a locale-appropriate format consistent with the application date format
6. IF the API request fails, THEN THE Insights_Modal SHALL display an error message inside the modal body and SHALL not close the modal
7. WHEN the items array is empty, THE Insights_Modal SHALL display a message "No purchase records found for this supplier"

### Requirement 6: Customer Insights Modal Display

**User Story:** As a business user, I want to view customer sales data in a clear tabular format inside a modal, so that I can quickly scan customer buying patterns.

#### Acceptance Criteria

1. WHEN the "Sales Insights" action button is clicked, THE Insights_Modal SHALL open and display a loading indicator while fetching data from the customer insights API endpoint
2. WHEN data is loaded successfully, THE Insights_Modal SHALL display a DataTable with columns: Item Name, Total Qty Sold, Average Unit Price, Last Sale Price, Last Sale Date, Number of Invoices
3. THE Insights_Modal SHALL display the customer name in the modal header title
4. THE Insights_Modal SHALL format numeric values with appropriate decimal places (quantities as integers or up to 2 decimals, prices up to 2 decimals)
5. THE Insights_Modal SHALL format dates in a locale-appropriate format consistent with the application date format
6. IF the API request fails, THEN THE Insights_Modal SHALL display an error message inside the modal body and SHALL not close the modal
7. WHEN the items array is empty, THE Insights_Modal SHALL display a message "No sales records found for this customer"

### Requirement 7: General Usage Insights Modal Display

**User Story:** As a business user, I want to view a person's system usage summary in a modal, so that I can understand their financial footprint across the business.

#### Acceptance Criteria

1. WHEN the "Usage Insights" action button is clicked, THE Insights_Modal SHALL open and display a loading indicator while fetching data from the general insights API endpoint
2. WHEN data is loaded successfully, THE Insights_Modal SHALL display four summary cards or sections: Expenses, Salaries, Payments, and Collections
3. THE Insights_Modal SHALL display for the Expenses section: total count, total amount, and a sub-table showing breakdown by expense category (category name, count, total amount)
4. THE Insights_Modal SHALL display for the Salaries section: total count, total gross amount, and total net amount
5. THE Insights_Modal SHALL display for the Payments section: total count and total amount
6. THE Insights_Modal SHALL display for the Collections section: total count and total amount
7. THE Insights_Modal SHALL display the person name in the modal header title
8. IF the API request fails, THEN THE Insights_Modal SHALL display an error message inside the modal body and SHALL not close the modal
9. WHEN all sections have zero counts, THE Insights_Modal SHALL display a message "No transaction records found for this person"

### Requirement 8: Permission and Access Control

**User Story:** As a system administrator, I want insight endpoints to respect existing permissions, so that only authorized users can view transaction data.

#### Acceptance Criteria

1. THE Insights_Controller SHALL require the "people.view" permission for all three insight endpoints (supplier, customer, general)
2. THE Insights_Controller SHALL validate that the authenticated user belongs to the same tenant as the requested payee
3. THE Insights_Controller SHALL scope all data queries to the current business_id from the authenticated user context
4. IF the authenticated user does not have the "people.view" permission, THEN THE System SHALL return HTTP 403 with a JSON response containing success as false and message "Forbidden"
5. THE System SHALL not require any new permissions beyond the existing "people.view" permission to access insight data

### Requirement 9: Multi-Tenancy Data Isolation

**User Story:** As a system architect, I want insight queries to enforce tenant and business isolation, so that no cross-tenant or cross-business data leakage occurs.

#### Acceptance Criteria

1. THE Insights_Repository SHALL include business_id in all WHERE clauses for purchase_orders, sales_invoices, expenses, salaries, payment_slips, and collection_slips queries
2. THE Insights_Repository SHALL validate that the requested payee_id belongs to the current business by checking the payees table for a matching business_id before executing aggregation queries
3. IF a payee_id belongs to a different business than the authenticated user's current business, THEN THE Insights_Controller SHALL return HTTP 404 with message "Payee not found" without revealing the existence of the payee in another business
4. THE Insights_Repository SHALL use parameterized PDO prepared statements for all query parameters to prevent SQL injection
