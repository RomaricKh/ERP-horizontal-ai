# Requirements Document

## Introduction

This feature introduces a Service Module for the multi-tenant ERP system, enabling businesses that sell services (consulting, design, development, maintenance, etc.) to manage service definitions and invoice them through the existing sales invoice system. Services are similar to items but without stock tracking. The module supports pure service businesses (where the "Service" business type excludes items/stock/purchase_orders/manufacturing) as well as mixed businesses that sell both products and services on the same invoice. The module integrates with the existing module activation system so it can be activated or deactivated per business.

## Glossary

- **Service_Module**: The ERP module (code: "services") that provides service definition management, service categories, and service-based invoicing capabilities
- **Service**: A sellable unit of work or expertise defined in the `services` table, characterized by a name, unit of measurement, and default rate, without stock tracking
- **Service_Category**: A classification grouping for services (analogous to item_types for items), stored in the `service_categories` table
- **Service_Repository**: The data access layer responsible for CRUD operations on the `services` and `service_categories` tables
- **Service_Service**: The backend service layer responsible for business logic around service and service category management
- **Services_Controller**: The API controller handling HTTP requests for service and service category endpoints
- **Invoice_Line**: A single row in the `si_details` table representing either an item or a service sold on a sales invoice
- **Module_Activation_Service**: The existing backend service that resolves which modules are active for a given business
- **Dashboard_Service**: The existing backend service responsible for computing and returning dashboard metrics

## Requirements

### Requirement 1: Services Table Schema

**User Story:** As a service business owner, I want a dedicated table to store my service definitions, so that I can manage services independently from physical items.

#### Acceptance Criteria

1. THE Service_Module SHALL store service definitions in a `services` database table with columns: id (INT AUTO_INCREMENT PRIMARY KEY), tenant_id (INT NOT NULL), business_id (INT NOT NULL), category_id (INT NULL, FOREIGN KEY to service_categories.id), name (VARCHAR(255) NOT NULL), description (TEXT NULL), unit (VARCHAR(50) NOT NULL), rate (DECIMAL(15,2) NOT NULL DEFAULT 0.00), is_active (TINYINT(1) NOT NULL DEFAULT 1), created_by (INT NOT NULL), created_at (DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)
2. THE services table SHALL enforce a composite index on (tenant_id, business_id) for efficient tenant-scoped queries
3. THE services table SHALL enforce a FOREIGN KEY on tenant_id referencing the tenants table and a FOREIGN KEY on business_id referencing the businesses table
4. WHEN a service is created without a category_id, THE Service_Service SHALL store the category_id as NULL

### Requirement 2: Service Categories

**User Story:** As a service business owner, I want to categorize my services (e.g., Consulting, Design, Development, Maintenance), so that I can organize and filter them effectively.

#### Acceptance Criteria

1. THE Service_Module SHALL store service categories in a `service_categories` database table with columns: id (INT AUTO_INCREMENT PRIMARY KEY), tenant_id (INT NOT NULL), business_id (INT NOT NULL), name (VARCHAR(150) NOT NULL), created_by (INT NOT NULL), created_at (DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)
2. THE service_categories table SHALL enforce a composite unique constraint on (tenant_id, business_id, name) to prevent duplicate category names within the same business
3. WHEN a service category is deleted that has one or more services referencing it, THE Service_Service SHALL reject the deletion and return an error indicating the category is in use
4. WHEN a service category is deleted that has zero services referencing it, THE Service_Service SHALL remove the category record from the database
5. THE Service_Service SHALL log all service category create, update, and delete operations via the AuditService with module code "SERVICES"

### Requirement 3: Service CRUD Operations

**User Story:** As a service business owner, I want to create, view, update, and deactivate services, so that I can maintain an accurate catalog of the services I offer.

#### Acceptance Criteria

1. WHEN a service is created, THE Service_Service SHALL validate that name is non-empty (after trimming), unit is non-empty (after trimming), and rate is a non-negative numeric value, rejecting the request with a descriptive error if any validation fails
2. WHEN a service is created with valid data, THE Service_Service SHALL insert the record with tenant_id and business_id from the authenticated user context and return the new service id
3. WHEN a service list is requested, THE Service_Service SHALL return all services for the current business scoped by tenant_id and business_id, including the category name joined from service_categories
4. WHEN a service is updated, THE Service_Service SHALL accept changes to name, description, unit, rate, category_id, and is_active fields, applying only fields that differ from the current values
5. WHEN a service is deactivated (is_active set to 0), THE Service_Service SHALL retain the service record in the database and exclude it from active service listings by default
6. THE Service_Service SHALL log all service create, update, and deactivate operations via the AuditService with module code "SERVICES"
7. WHEN a service list is requested with an include_inactive parameter set to true, THE Service_Service SHALL return both active and inactive services

### Requirement 4: Service Invoicing - Line Item Support

**User Story:** As a business owner, I want to add services as line items on sales invoices, so that I can bill clients for services rendered.

#### Acceptance Criteria

1. THE si_details table SHALL include a new nullable column: service_id (INT NULL, FOREIGN KEY to services.id)
2. THE si_details table SHALL enforce that each line has exactly one of item_id or service_id populated (one non-null, the other null), using a CHECK constraint: (item_id IS NOT NULL AND service_id IS NULL) OR (item_id IS NULL AND service_id IS NOT NULL)
3. WHEN a service line is added to a sales invoice, THE SalesInvoiceService SHALL accept service_id, quantity, unit_price_fc, and unit_price_bc in the line data and store the line with item_id as NULL
4. WHEN a service line is added to a sales invoice, THE SalesInvoiceService SHALL skip stock validation for that line since services have no stock tracking
5. WHEN a sales invoice status transitions to DELIVERED, THE SalesInvoiceService SHALL skip stock OUT adjustments for lines where service_id is populated and item_id is NULL
6. WHEN a DELIVERED sales invoice is cancelled, THE SalesInvoiceService SHALL skip stock IN reversal for lines where service_id is populated and item_id is NULL
7. THE SalesInvoiceService SHALL calculate line totals (total_price_fc, total_price_bc) for service lines using the same formula as item lines: quantity multiplied by unit price

### Requirement 5: Service-Based Business Invoice Form

**User Story:** As a service business owner (with "Service" business type), I want the sales invoice form to show services instead of items, so that my workflow matches my business model.

#### Acceptance Criteria

1. WHEN the sales invoice form loads for a business with the "Service" business type (items module inactive), THE Frontend SHALL display a service selector dropdown populated from the services API instead of the item selector
2. WHEN a service is selected in the invoice line form, THE Frontend SHALL auto-populate the unit_price_fc field with the service rate from the service definition
3. WHEN the sales invoice form loads for a business with both items and services modules active, THE Frontend SHALL display a line type toggle (Item or Service) allowing the user to choose which selector to use for each line
4. WHEN the line type is set to "Service", THE Frontend SHALL display the service selector and hide the item selector for that line
5. WHEN the line type is set to "Item", THE Frontend SHALL display the item selector and hide the service selector for that line
6. THE Frontend SHALL display the service unit (e.g., "hour", "session", "project") next to the quantity field when a service line is being added

### Requirement 6: Services API Endpoints

**User Story:** As a frontend developer, I want RESTful API endpoints for services and service categories, so that the SPA can perform all service management operations.

#### Acceptance Criteria

1. THE Services_Controller SHALL expose GET /services to list all active services for the current business, requiring the permission "services.view"
2. THE Services_Controller SHALL expose POST /services to create a new service, requiring the permission "services.create"
3. THE Services_Controller SHALL expose GET /services/{id} to retrieve a single service by id, requiring the permission "services.view"
4. THE Services_Controller SHALL expose PUT /services/{id} to update a service, requiring the permission "services.edit"
5. THE Services_Controller SHALL expose DELETE /services/{id} to deactivate a service (soft delete), requiring the permission "services.delete"
6. THE Services_Controller SHALL expose GET /services/categories to list all service categories for the current business, requiring the permission "services.view"
7. THE Services_Controller SHALL expose POST /services/categories to create a new service category, requiring the permission "services.create"
8. THE Services_Controller SHALL expose PUT /services/categories/{id} to update a service category, requiring the permission "services.edit"
9. THE Services_Controller SHALL expose DELETE /services/categories/{id} to delete a service category, requiring the permission "services.delete"
10. IF the services module is inactive for the current business, THEN THE Module_Gate_Middleware SHALL reject all /services requests with HTTP 403 and message "Module not available for this business"

### Requirement 7: Module Registration and Activation

**User Story:** As a system administrator, I want the services module registered in the module system, so that it integrates with business type defaults and per-business overrides.

#### Acceptance Criteria

1. WHEN the migration executes, THE System SHALL insert a new row into the modules table with code "services", name "Services", icon "fas fa-concierge-bell", route_prefix "services", permission_prefixes '["services."]', category "operations", and sort_order 21
2. WHEN the migration executes, THE System SHALL add the services module to the "service" business type default modules in the business_type_modules junction table
3. WHEN the migration executes, THE System SHALL add the services module to the "general" business type default modules in the business_type_modules junction table
4. WHEN a business has the "Service" business type, THE Module_Activation_Service SHALL include the services module as active by default
5. WHEN a business has the "Retail", "Wholesale", or "Manufacturing" business type, THE Module_Activation_Service SHALL not include the services module by default (activatable via override)
6. THE services module SHALL be activatable or deactivatable per business via the existing business_module_overrides mechanism

### Requirement 8: Service Revenue Dashboard Metrics

**User Story:** As a service business owner, I want to see service revenue metrics on my dashboard, so that I can track my service-based income at a glance.

#### Acceptance Criteria

1. WHEN the dashboard is loaded for a business with the services module active, THE Dashboard_Service SHALL return a service_revenue metric representing the total revenue from service lines on DELIVERED invoices for the current month
2. WHEN the dashboard is loaded for a business with the services module active, THE Dashboard_Service SHALL return a service_count metric representing the total number of distinct services that have been invoiced in the current month
3. WHEN the dashboard is loaded for a business with the services module inactive, THE Dashboard_Service SHALL omit service_revenue and service_count metrics from the response
4. THE Dashboard_Service SHALL compute service_revenue by summing total_price_bc from si_details lines where service_id is not null, joined to sales_invoices with status DELIVERED and invoice_date within the current calendar month
5. THE Frontend SHALL display service revenue metrics in a dashboard card only when the services module is active for the current business

### Requirement 9: Permissions Seeding

**User Story:** As a system administrator, I want service-related permissions seeded in the database, so that role-based access control applies to the services module.

#### Acceptance Criteria

1. WHEN the migration executes, THE System SHALL insert the following permissions into the permissions table: "services.view", "services.create", "services.edit", "services.delete"
2. WHEN the migration executes, THE System SHALL assign all four service permissions (services.view, services.create, services.edit, services.delete) to the business_owner role (role_id = 1)
3. THE Module_Gate_Middleware SHALL filter out services permissions for businesses where the services module is inactive, preventing users from exercising service permissions even if their role grants them

### Requirement 10: Migration and Backward Compatibility

**User Story:** As a system administrator, I want the migration to be non-breaking, so that existing invoices and businesses continue to function without modification.

#### Acceptance Criteria

1. WHEN the migration executes, THE System SHALL add the service_id column to si_details as INT NULL with no default value, preserving all existing rows with service_id as NULL (indicating item-based lines)
2. WHEN the migration executes, THE System SHALL create the services and service_categories tables using IF NOT EXISTS for idempotent re-runs
3. WHEN the migration executes, THE System SHALL use INSERT IGNORE for all seed data (module registration, permissions, business type module mappings) to prevent duplicate entry errors on repeated execution
4. WHEN the migration executes, THE System SHALL not modify or remove any existing columns, indexes, or foreign keys on the si_details, items, or sales_invoices tables
5. THE System SHALL ensure all existing sales invoices with item-based lines continue to function identically after the migration, with no changes to query results or behavior
6. WHEN the migration adds the CHECK constraint to si_details, THE System SHALL verify that all existing rows satisfy the constraint (all existing rows have item_id NOT NULL and service_id IS NULL) before applying it

### Requirement 11: Mixed Business Invoice Support

**User Story:** As a business owner who sells both products and services, I want to include both item lines and service lines on the same invoice, so that I can bill for a complete engagement in one document.

#### Acceptance Criteria

1. WHEN a sales invoice contains both item lines and service lines, THE SalesInvoiceService SHALL calculate the invoice totals (total_amount_fc, total_amount_bc) by summing all line totals regardless of whether the line references an item or a service
2. WHEN a mixed invoice transitions to DELIVERED status, THE SalesInvoiceService SHALL process stock OUT only for lines with item_id populated and skip lines with service_id populated
3. WHEN a mixed invoice is cancelled from DELIVERED status, THE SalesInvoiceService SHALL reverse stock IN only for lines with item_id populated and skip lines with service_id populated
4. THE SalesInvoiceRepository SHALL return invoice lines with both item details (item name, SKU) and service details (service name, unit) joined from their respective tables, using LEFT JOIN on both items and services tables
5. WHEN listing sales invoices, THE SalesInvoiceRepository SHALL include a line_type indicator ("item" or "service") for each line in the response to help the frontend render the appropriate display

