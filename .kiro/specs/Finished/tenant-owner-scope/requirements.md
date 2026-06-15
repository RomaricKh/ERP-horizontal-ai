# Requirements Document

## Introduction

This feature introduces a Tenant Owner scope — a hierarchical authority layer above the existing business-level access in the ERP system. Currently, users operate within individual businesses with business-scoped roles (SYSTEM_OWNER, CUSTOM, TEMPLATE). This feature adds a tenant-level owner who has unrestricted access across all businesses under the tenant, can manage business types, create businesses, allocate business-specific roles to users, monitor all operations, and export consolidated or per-business reports. The Tenant Owner operates as a super-administrator for the entire tenant without needing individual business-level role assignments.

## Glossary

- **Tenant_Owner**: A user designated as the owner of a tenant, possessing unrestricted access to all businesses, users, roles, and data within that tenant
- **Tenant_Owner_Service**: The backend service responsible for managing tenant ownership, tenant-wide operations, and cross-business data aggregation
- **Tenant_Scope**: The authorization scope that grants a Tenant Owner full access across all businesses under the tenant without requiring per-business role assignments
- **Auth_Middleware**: The existing middleware that resolves user identity, business context, role, and permissions — extended to recognize Tenant Owner status
- **Business_Service**: The existing service for business creation and management, extended to support Tenant Owner operations
- **Business_Type_Service**: The existing service for managing business types, extended to allow Tenant Owner modification of type definitions
- **Role_Service**: The existing service for role CRUD operations, extended to allow Tenant Owner to manage roles across all businesses in the tenant
- **Report_Service**: A new service responsible for generating and exporting consolidated tenant-level and per-business reports
- **Tenant_Dashboard**: A new frontend page providing the Tenant Owner with a consolidated view across all businesses under the tenant
- **Module_Activation_Service**: The existing service that resolves active modules per business, used by the Tenant Owner when creating businesses with specific types

## Requirements

### Requirement 1: Tenant Owner Designation

**User Story:** As a system administrator, I want to designate a user as the Tenant Owner, so that one user has full authority over all businesses and operations within the tenant.

#### Acceptance Criteria

1. THE System SHALL store tenant ownership in a `tenant_owners` table with columns: id (INT AUTO_INCREMENT PRIMARY KEY), tenant_id (INT NOT NULL, FOREIGN KEY to tenants), user_id (INT NOT NULL, FOREIGN KEY to users), created_at (DATETIME NOT NULL), created_by (INT NULL)
2. THE System SHALL enforce a unique constraint on (tenant_id, user_id) in the tenant_owners table to prevent duplicate ownership records
3. WHEN a new tenant is created, THE System SHALL designate the creating user as the Tenant Owner by inserting a record into the tenant_owners table
4. THE System SHALL allow a maximum of 10 Tenant Owners per tenant to support delegation of authority
5. IF a user is designated as Tenant Owner, THEN THE System SHALL grant that user full access to all businesses under the tenant, including all permission codes, without requiring individual business-level role assignments
6. WHEN a Tenant Owner designation is created or removed, THE Tenant_Owner_Service SHALL log the change via the AuditService with event type "TENANT_OWNER_CHANGE", recording the affected user_id and the acting user_id
7. IF a Tenant Owner designation request specifies a user_id that does not exist or does not belong to the same tenant, THEN THE System SHALL reject the request with an error message indicating the user is invalid for tenant owner designation
8. WHEN a request is made to remove a Tenant Owner designation, THE System SHALL reject the removal if it would leave the tenant with zero Tenant Owners, returning an error message indicating at least one Tenant Owner must remain
9. THE System SHALL restrict Tenant Owner designation and removal operations to users who are themselves Tenant Owners of the same tenant

### Requirement 2: Tenant Owner Authentication and Authorization

**User Story:** As a Tenant Owner, I want the system to recognize my elevated scope automatically, so that I can access any business under my tenant without switching roles.

#### Acceptance Criteria

1. WHEN the Auth_Middleware resolves a user's identity, THE Auth_Middleware SHALL query the tenant_owners table by user_id to determine whether the user is a Tenant Owner, and if so, resolve the tenant_id from the tenant_owners record
2. WHEN a user is identified as a Tenant Owner, THE Auth_Middleware SHALL set a `is_tenant_owner` flag to true in the AuthContext object and set the `role_id` to null
3. WHEN a Tenant Owner sends a request with an X-Business-Id header, THE Auth_Middleware SHALL verify that the business belongs to the Tenant Owner's tenant by comparing the business's tenant_id with the Tenant Owner's tenant_id, and allow access without checking the user_roles table
4. WHEN a Tenant Owner sends a request without an X-Business-Id header, THE Auth_Middleware SHALL operate in tenant-wide scope, set the AuthContext business_id to null, and resolve the tenant_id from the tenant_owners table
5. WHEN a user is identified as a Tenant Owner, THE Auth_Middleware SHALL return all permission codes from the permissions table regardless of role assignments, bypassing module-based permission filtering
6. IF a Tenant Owner attempts to access a business that does not belong to the Tenant Owner's tenant, THEN THE Auth_Middleware SHALL reject the request with HTTP 403 and message "Business does not belong to your tenant"
7. IF a non-Tenant-Owner user sends a request without an X-Business-Id header, THEN THE Auth_Middleware SHALL reject the request with HTTP 403 and a message indicating that business context is required
8. IF a user holds Tenant Owner designation in more than one tenant, THEN THE Auth_Middleware SHALL resolve the tenant from the X-Business-Id header when provided, or use the first tenant_owners record (ordered by id ascending) when no X-Business-Id header is present

### Requirement 3: Tenant Owner Business Management

**User Story:** As a Tenant Owner, I want to create businesses under my tenant and assign each a specific business type, so that each business gets the appropriate module set from creation.

#### Acceptance Criteria

1. WHEN a Tenant Owner creates a business, THE Business_Service SHALL require a business name (1-255 characters) and a business_type_id field, and SHALL validate the business_type_id against the business_types table
2. IF the provided business_type_id does not exist in the business_types table, THEN THE Business_Service SHALL reject the request with an error message indicating the business type is invalid
3. WHEN a Tenant Owner creates a business, THE Business_Service SHALL create the business under the Tenant Owner's tenant_id with the specified business_type_id
4. WHEN a Tenant Owner creates a business, THE Business_Service SHALL create the SYSTEM_OWNER role for that business but SHALL NOT automatically assign it to the Tenant Owner (the Tenant Owner already has full access via tenant scope)
5. WHEN a Tenant Owner creates a business, THE Business_Service SHALL accept an optional initial_owner_user_id field specifying a user to receive the SYSTEM_OWNER role assignment for that business
6. IF the specified initial_owner_user_id does not exist or does not belong to the tenant, THEN THE Business_Service SHALL reject the request with an error message indicating the user is invalid for business owner assignment
7. WHEN a Tenant Owner requests to change the business_type_id of a business under the tenant, THE Business_Service SHALL invoke the existing business type change flow, which returns data-loss warnings listing modules that will be deactivated before requiring confirmation via a force flag

### Requirement 4: Tenant Owner Business Type Management

**User Story:** As a Tenant Owner, I want to modify the business types available under my tenant, so that I can customize the types of businesses my organization supports.

#### Acceptance Criteria

1. WHEN a Tenant Owner requests to create a custom business type, THE Business_Type_Service SHALL accept a name (2-100 characters), description (max 500 characters), and an array of 1 to 20 module codes to activate
2. WHEN a Tenant Owner creates a custom business type, THE Business_Type_Service SHALL store it with a reference to the tenant_id, distinguishing it from system-wide predefined types
3. IF a Tenant Owner attempts to create or update a custom business type with a name that already exists within the same tenant, THEN THE Business_Type_Service SHALL reject the request with an error message indicating the name is already in use
4. WHEN a Tenant Owner requests to update a custom business type's module set, THE Business_Type_Service SHALL validate all provided module codes against the Module_Registry
5. IF a Tenant Owner attempts to create or update a custom business type with one or more module codes that do not exist in the Module_Registry, THEN THE Business_Type_Service SHALL reject the request with an error message indicating the invalid module codes
6. IF a Tenant Owner attempts to modify a system-wide predefined business type (general, retail, wholesale, manufacturing, service), THEN THE Business_Type_Service SHALL reject the request with an error message "Cannot modify system-defined business types"
7. WHEN a Tenant Owner deletes a custom business type, THE Business_Type_Service SHALL verify no businesses are currently assigned to that type and reject deletion with a count of assigned businesses if any exist
8. WHEN listing business types, THE Business_Type_Service SHALL return both system-wide predefined types and tenant-specific custom types for the Tenant Owner's tenant, ordered by system-wide types first followed by custom types sorted by creation date ascending

### Requirement 5: Tenant Owner Role Management Across Businesses

**User Story:** As a Tenant Owner, I want to create and allocate business-specific roles to users across all my businesses, so that I can centrally manage access control for my entire organization.

#### Acceptance Criteria

1. WHEN a Tenant Owner requests to manage roles, THE Role_Service SHALL allow create, read, update, and delete operations on roles in any business under the Tenant Owner's tenant without requiring a business-level role assignment
2. WHEN a Tenant Owner creates a role for a specific business, THE Role_Service SHALL scope the role to that business_id and set the type to CUSTOM
3. WHEN a Tenant Owner assigns a role to a user for a specific business, THE User_Service SHALL replace the user's existing role assignment for that business in the user_roles table, or create a new user_roles record if the user has no prior assignment in that business
4. WHEN a Tenant Owner assigns a role to a user, THE User_Service SHALL validate that the target user exists and that the target business belongs to the Tenant Owner's tenant
5. IF the target user does not exist or the target business does not belong to the Tenant Owner's tenant, THEN THE User_Service SHALL reject the role assignment request with an error message indicating the validation failure
6. IF the Tenant Owner assigns a role that belongs to a different business than the target business, THEN THE Role_Service SHALL reject the request with an error message "Role does not belong to the target business"
7. WHEN a Tenant Owner creates a TEMPLATE role, THE Role_Service SHALL store it at the tenant level (business_id NULL, tenant_id set) and return it in role listings for all businesses under the tenant so it is available for cloning via the existing clone endpoint

### Requirement 6: Tenant Owner Cross-Business Access

**User Story:** As a Tenant Owner, I want to access and operate within any business under my tenant, so that I can monitor operations and intervene when necessary.

#### Acceptance Criteria

1. WHEN a Tenant Owner sets the X-Business-Id header to any business under the tenant, THE System SHALL grant full access to all endpoints for that business as if the user held the SYSTEM_OWNER role
2. WHEN a Tenant Owner accesses a business, THE Module_Activation_Service SHALL not filter the Tenant Owner's permissions by active modules, granting visibility into all data regardless of module activation state
3. THE System SHALL include the Tenant Owner in the business user list only when the Tenant Owner has an explicit user_roles record for that business
4. WHEN a Tenant Owner performs write operations on a business, THE AuditService SHALL log the action with the Tenant Owner's user_id and a flag indicating the action was performed via tenant scope
5. THE System SHALL allow the Tenant Owner to switch between businesses by changing the X-Business-Id header without re-authentication

### Requirement 7: Tenant-Level Monitoring Dashboard

**User Story:** As a Tenant Owner, I want a consolidated dashboard showing key metrics across all my businesses, so that I can monitor the health of my entire organization at a glance.

#### Acceptance Criteria

1. WHEN a Tenant Owner accesses the Tenant Dashboard, THE Tenant_Owner_Service SHALL aggregate key metrics across all businesses under the tenant including: total revenue, total expenses, total outstanding receivables, total outstanding payables, and user count per business, converting all monetary amounts to the tenant's base currency using the latest exchange rates from the exchange_rates table
2. THE Tenant Dashboard SHALL display a summary card for each business showing: business name, business type, number of active users, and key financial totals (revenue, expenses, net) in the tenant's base currency
3. WHEN a Tenant Owner selects a specific business from the Tenant Dashboard, THE System SHALL navigate to that business context by setting the X-Business-Id header to the selected business
4. THE Tenant Dashboard SHALL accept a date range defined by a start date and end date (default: first day of current month to current date), with a maximum span of 12 months, and provide aggregation period options of daily, weekly, monthly, and yearly
5. IF a non-Tenant-Owner user attempts to access tenant dashboard endpoints, THEN THE System SHALL reject the request with HTTP 403
6. WHEN a business has no transactions for the selected date range, THE Tenant Dashboard SHALL display zero values for that business rather than omitting it from the view
7. IF the provided date range is invalid (end date before start date, span exceeds 12 months, or dates are not in YYYY-MM-DD format), THEN THE Tenant Dashboard SHALL reject the request with an error message indicating the validation failure
8. IF aggregation fails for one or more businesses due to data unavailability, THEN THE Tenant_Owner_Service SHALL return successful results for the remaining businesses and indicate which businesses encountered errors

### Requirement 8: Tenant-Level and Per-Business Report Export

**User Story:** As a Tenant Owner, I want to export reports either across the entire tenant or for a specific business, so that I can produce consolidated financial statements and per-business analysis.

#### Acceptance Criteria

1. WHEN a Tenant Owner requests a tenant-level report, THE Report_Service SHALL aggregate data across all businesses under the tenant for the specified date range (start_date and end_date, maximum span of 5 years) and report type
2. WHEN a Tenant Owner requests a per-business report, THE Report_Service SHALL generate the report scoped to the specified business_id, validating that the business belongs to the Tenant Owner's tenant
3. THE Report_Service SHALL support the following report types: profit-and-loss, balance-sheet, cash-flow, accounts-receivable-aging, accounts-payable-aging, and user-activity-summary
4. THE Report_Service SHALL export reports in PDF and CSV formats, determined by a required format parameter in the request
5. WHEN generating a tenant-level report, THE Report_Service SHALL include a breakdown section showing each business's contribution to the aggregated totals
6. WHEN generating a tenant-level report, THE Report_Service SHALL convert all amounts to the tenant's base currency using the most recent exchange rate per currency pair from the exchange_rates table
7. IF a Tenant Owner requests a report for a business that does not belong to the tenant, THEN THE Report_Service SHALL reject the request with HTTP 403 and message "Business does not belong to your tenant"
8. THE Report_Service SHALL require the user to be a verified Tenant Owner for tenant-level reports
9. THE Report_Service SHALL allow per-business report access to Business Owners who hold the "reports.export" permission for that business
10. IF the Report_Service cannot find an exchange rate for a currency pair required during tenant-level report generation, THEN THE Report_Service SHALL reject the request with an error message indicating which currency pair is missing from the exchange_rates table
11. IF a report request specifies a start_date that is after the end_date or a date range exceeding 5 years, THEN THE Report_Service SHALL reject the request with an error message indicating the invalid date range

### Requirement 9: Tenant Owner User Management

**User Story:** As a Tenant Owner, I want to manage users across all businesses in my tenant, so that I can onboard, reassign, and offboard team members centrally.

#### Acceptance Criteria

1. WHEN a Tenant Owner lists users at the tenant level, THE User_Service SHALL return all users who have at least one user_roles record in any business under the tenant, including their business assignments and roles, supporting pagination with default page size of 20 and maximum of 100
2. WHEN a Tenant Owner creates a new user, THE User_Service SHALL allow specifying one or more business assignments with role_id for each business in a single request, validating that each business belongs to the tenant and each role belongs to the specified business
3. WHEN a Tenant Owner reassigns a user from one business to another, THE User_Service SHALL remove the user_roles record from the source business and create a new record in the target business within a single transaction
4. IF a Tenant Owner attempts to reassign a user to a business where the user already has a role assignment, THEN THE User_Service SHALL reject the request with an error message indicating the user already exists in the target business
5. WHEN a Tenant Owner deactivates a user across all businesses, THE User_Service SHALL set is_active to 0 on all user_roles records for that user within the tenant
6. IF a Tenant Owner attempts to deactivate a user who is the sole SYSTEM_OWNER of any business, THEN THE User_Service SHALL reject the request with an error message listing the businesses that would be left without an owner
7. IF a Tenant Owner attempts to remove their own Tenant Owner designation and no other Tenant Owner exists for the tenant, THEN THE System SHALL reject the removal with an error message indicating at least one Tenant Owner must remain
8. WHEN a Tenant Owner views a specific user's profile, THE System SHALL display all business assignments, roles, and the most recent 100 activity log entries for that user across the entire tenant

### Requirement 10: Tenant Owner API Endpoints

**User Story:** As a frontend developer, I want dedicated API endpoints for Tenant Owner operations, so that the tenant management interface can be built with clear, purpose-specific routes.

#### Acceptance Criteria

1. THE System SHALL expose tenant owner endpoints under the route prefix `/tenant` requiring Tenant Owner verification on all routes, returning HTTP 403 if the requesting user is not a verified Tenant Owner
2. WHEN a GET /tenant/businesses request is received, THE System SHALL return all businesses under the Tenant Owner's tenant with their types, user counts, and active module counts, supporting optional `page` and `per_page` query parameters (default: page 1, per_page 20, maximum per_page 100) and returning pagination metadata (total, current_page, last_page)
3. WHEN a GET /tenant/users request is received, THE System SHALL return all users across the tenant with their business assignments and roles, supporting optional `page` and `per_page` query parameters (default: page 1, per_page 20, maximum per_page 100) and returning pagination metadata (total, current_page, last_page)
4. WHEN a GET /tenant/dashboard request is received with optional `start_date` and `end_date` query parameters, THE System SHALL return aggregated metrics across all businesses for the specified date range, defaulting to the current month if no date range is provided
5. WHEN a POST /tenant/reports request is received, THE System SHALL require a `report_type` parameter and an optional `business_id` parameter, generating a tenant-level report when business_id is absent or a per-business report when business_id is provided
6. IF a POST /tenant/reports request is received with a missing or unsupported report_type, THEN THE System SHALL return HTTP 422 with an error message indicating the valid report types
7. WHEN a GET /tenant/business-types request is received, THE System SHALL return all available business types (system-wide and tenant-specific custom types), indicating for each type whether it is system-defined or tenant-custom
8. WHEN a POST /tenant/business-types request is received, THE System SHALL create a new custom business type for the tenant using the validation rules defined in Requirement 4
9. WHEN a PUT /tenant/business-types/{id} request is received, THE System SHALL update the specified custom business type, returning HTTP 404 if the type does not exist under the tenant and HTTP 403 if the type is system-defined
10. WHEN a DELETE /tenant/business-types/{id} request is received, THE System SHALL delete the specified custom business type, returning HTTP 404 if the type does not exist under the tenant, HTTP 403 if the type is system-defined, and HTTP 409 with the count of assigned businesses if any businesses are currently using that type

### Requirement 11: Migration and Backward Compatibility

**User Story:** As a system administrator, I want the tenant owner feature to be added without breaking existing functionality, so that current users and businesses continue to operate normally.

#### Acceptance Criteria

1. WHEN the migration executes, THE System SHALL create the `tenant_owners` table with the schema defined in Requirement 1 using IF NOT EXISTS for idempotent execution
2. WHEN the migration executes, THE System SHALL identify the initial Tenant Owner for each existing tenant by selecting the user_id from the user_roles record associated with the SYSTEM_OWNER role in the tenant's earliest-created business (determined by the minimum businesses.created_at), and insert that user as the initial Tenant Owner using INSERT IGNORE to ensure idempotent re-execution
3. IF no user with a SYSTEM_OWNER role can be determined for a tenant's earliest business, THEN THE System SHALL designate the user with the lowest user_id who holds a SYSTEM_OWNER role in any business under that tenant
4. IF no user with a SYSTEM_OWNER role exists in any business under a tenant, THEN THE System SHALL skip Tenant Owner designation for that tenant and log a warning entry identifying the tenant_id that requires manual owner assignment
5. WHEN the migration executes, THE System SHALL add a `base_currency` column (VARCHAR(3), DEFAULT 'USD') to the tenants table using a column-existence check to ensure idempotent re-execution
6. THE migration SHALL execute within a single database transaction, rolling back all changes if any step fails
7. THE migration SHALL use idempotent operations (IF NOT EXISTS, INSERT IGNORE, column-existence checks) to be safe for re-execution without producing duplicate records or errors
8. WHEN the migration completes, THE System SHALL leave all existing user_roles, roles, businesses, and permissions records unchanged so that current business-level access continues to function without modification
9. THE System SHALL ensure that existing Business Owners (SYSTEM_OWNER role holders) retain their current access and functionality without requiring any role reassignment or permission changes after the Tenant Owner feature is deployed
