# Requirements Document

## Introduction

This feature introduces customer-specific items to the multi-tenant ERP system. Currently, items are scoped to either a tenant (shared across all businesses) or a specific business. This feature adds a customer dimension: items can be global (available to all customers) or specific to a single customer. This is useful for businesses like printing companies where certain products (e.g., a custom wedding card or branded sticker label) are unique to a particular customer, while raw materials remain globally available.

## Glossary

- **Item**: A product or material record in the `items` table, identified by id, belonging to a tenant and optionally scoped to a business
- **Customer**: A payee record in the `people` table that has the "Customer" payee type
- **Global_Item**: An item with `customer_id` set to NULL, available to all customers within its business/tenant scope
- **Customer_Specific_Item**: An item with `customer_id` set to a valid customer ID, available only when that customer is the context
- **Items_List_Page**: The frontend page that displays all items for the current business, accessible via the items module
- **Sales_Invoice_Form**: The frontend form used to create or edit a sales invoice, which includes item selection
- **Customer_Insights_View**: The frontend view that displays detailed information about a specific customer, including their associated items
- **Item_Visibility_Service**: The backend service logic responsible for filtering items based on customer context

## Requirements

### Requirement 1: Database Schema Extension

**User Story:** As a developer, I want a `customer_id` column on the items table, so that items can be optionally associated with a specific customer.

#### Acceptance Criteria

1. THE System SHALL add a nullable `customer_id` column (INT, DEFAULT NULL) to the `items` table
2. THE System SHALL add a foreign key constraint on `items.customer_id` referencing `people.id`
3. THE System SHALL add an index on `items.customer_id` to optimize queries filtering by customer
4. WHEN the migration executes, THE System SHALL set `customer_id` to NULL for all existing items so that all current items remain global
5. IF the migration fails at any step, THEN THE System SHALL roll back all changes made during that migration execution

### Requirement 2: Item Creation with Customer Scope

**User Story:** As a user, I want to optionally assign an item to a specific customer when creating it, so that I can track customer-specific products separately from global items.

#### Acceptance Criteria

1. WHEN an item is created, THE System SHALL accept an optional `customer_id` field in the creation payload
2. WHEN an item is created with a valid `customer_id` that exists in the `people` table and belongs to the same tenant, THE System SHALL store that `customer_id` on the item record
3. WHEN an item is created without a `customer_id` or with `customer_id` set to null, THE System SHALL create the item as a Global_Item (customer_id = NULL)
4. IF an item is created with a `customer_id` that does not exist in the `people` table or does not belong to the same tenant, THEN THE System SHALL reject the request with an error response indicating the customer is invalid
5. IF an item is created with a `customer_id` referencing a payee that does not have the "Customer" payee type, THEN THE System SHALL reject the request with an error response indicating the payee is not a customer

### Requirement 3: Item Update with Customer Scope

**User Story:** As a user, I want to change or remove the customer assignment on an existing item, so that I can reassign items between customers or make them global.

#### Acceptance Criteria

1. WHEN an item is updated, THE System SHALL accept an optional `customer_id` field in the update payload
2. WHEN an item is updated with a valid `customer_id`, THE System SHALL update the item's `customer_id` to the provided value
3. WHEN an item is updated with `customer_id` explicitly set to null, THE System SHALL set the item's `customer_id` to NULL, making it a Global_Item
4. IF an item is updated with a `customer_id` that does not exist in the `people` table or does not belong to the same tenant, THEN THE System SHALL reject the request with an error response indicating the customer is invalid
5. IF an item is updated with a `customer_id` referencing a payee that does not have the "Customer" payee type, THEN THE System SHALL reject the request with an error response indicating the payee is not a customer
6. WHEN the `customer_id` field is not present in the update payload, THE System SHALL leave the existing `customer_id` value unchanged

### Requirement 4: Item Listing with Customer Indicator

**User Story:** As a user, I want to see all items on the items list page with a clear indication of which items are customer-specific, so that I can distinguish between global and customer-specific items at a glance.

#### Acceptance Criteria

1. WHEN items are listed on the Items_List_Page, THE System SHALL return all items for the current business regardless of their `customer_id` value
2. WHEN items are listed, THE System SHALL include the `customer_id` and the associated customer name in the response payload for each item
3. WHEN an item has a non-null `customer_id`, THE Items_List_Page SHALL display the customer name as a visual indicator alongside the item
4. WHEN an item has a null `customer_id`, THE Items_List_Page SHALL display the item without a customer indicator, signifying it is a Global_Item

### Requirement 5: Item Filtering for Sales Invoice

**User Story:** As a user, I want the item selection in the sales invoice form to show only global items and items specific to the selected customer, so that I only see relevant items when creating an invoice.

#### Acceptance Criteria

1. WHEN a customer is selected on the Sales_Invoice_Form, THE System SHALL provide an API endpoint that returns items filtered to include only Global_Items and Customer_Specific_Items belonging to that customer
2. WHEN the item filter API is called with a valid `customer_id` parameter, THE Item_Visibility_Service SHALL return items where `customer_id` IS NULL OR `customer_id` equals the provided customer ID
3. WHEN the item filter API is called without a `customer_id` parameter, THE Item_Visibility_Service SHALL return only Global_Items (where `customer_id` IS NULL)
4. WHEN the customer selection changes on the Sales_Invoice_Form, THE Sales_Invoice_Form SHALL re-fetch the filtered item list for the newly selected customer and update the item dropdown options
5. IF the provided `customer_id` does not exist or does not belong to the same tenant, THEN THE System SHALL return only Global_Items without raising an error

### Requirement 6: Customer Item Catalog

**User Story:** As a user, I want to view all items specific to a customer from the customer insights view, so that I can quickly see what custom products belong to that customer.

#### Acceptance Criteria

1. THE System SHALL provide an API endpoint that returns all Customer_Specific_Items for a given customer ID
2. WHEN the customer items API is called with a valid customer ID, THE System SHALL return all items where `customer_id` equals the provided ID, filtered by the current tenant and business scope
3. THE Customer_Insights_View SHALL display a section listing all Customer_Specific_Items for the viewed customer
4. WHEN a customer has no Customer_Specific_Items, THE Customer_Insights_View SHALL display an empty state message indicating no customer-specific items exist
5. THE Customer_Insights_View items section SHALL display each item's name, SKU, type, and sale price

### Requirement 7: Customer Dropdown in Item Form

**User Story:** As a user, I want a customer dropdown in the item creation and edit forms, so that I can easily assign or change the customer for an item.

#### Acceptance Criteria

1. THE item creation form SHALL include an optional customer dropdown field
2. THE item edit form SHALL include an optional customer dropdown field, pre-populated with the item's current customer assignment
3. WHEN the customer dropdown is rendered, THE System SHALL populate it with all payees that have the "Customer" payee type within the current tenant
4. WHEN no customer is selected in the dropdown, THE System SHALL treat the item as a Global_Item (customer_id = NULL)
5. THE customer dropdown SHALL support search/filter functionality to allow users to find customers by name

### Requirement 8: Data Integrity and Cascading Behavior

**User Story:** As a developer, I want proper data integrity rules for the customer-item relationship, so that the system handles customer deletion gracefully.

#### Acceptance Criteria

1. IF a customer (payee) is deleted and has associated Customer_Specific_Items, THEN THE System SHALL set `customer_id` to NULL on all items previously associated with that customer (SET NULL on delete)
2. THE System SHALL enforce referential integrity between `items.customer_id` and `people.id` via a foreign key constraint with ON DELETE SET NULL
3. WHEN a customer is deleted and their items become Global_Items, THE System SHALL not notify the user or require confirmation for the item scope change
