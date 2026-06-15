# Requirements Document

## Introduction

This feature introduces a Business Types and Module Activation system for the multi-tenant ERP. Each business is assigned a type (Retail, Manufacturing, Wholesale, Service, General) that determines which ERP modules are accessible. The system filters the sidebar menu, API routes, and permissions based on active modules, while allowing per-business overrides for flexibility. Existing businesses receive the "General" type (all modules active) to maintain backward compatibility.

## Glossary

- **Module_Registry**: The database table and in-memory registry that defines all available ERP modules with their unique codes, display names, and route prefixes
- **Business_Type**: A predefined classification (General, Retail, Wholesale, Manufacturing, Service) that determines the default set of active modules for a business
- **Module_Activation_Service**: The backend service responsible for resolving which modules are active for a given business by combining type defaults with per-business overrides
- **Module_Override**: A per-business record that activates or deactivates a specific module beyond the defaults defined by the business type
- **Module_Gate_Middleware**: The middleware layer that intercepts API requests and verifies the requested module is active for the current business before processing
- **Sidebar_Renderer**: The frontend component responsible for rendering navigation menu items filtered by active modules
- **Modules_Settings_Page**: The frontend page where business owners view active modules and toggle optional overrides

## Requirements

### Requirement 1: Module Registry

**User Story:** As a system administrator, I want a central registry of all ERP modules, so that the system can reference modules consistently across business types, routes, and permissions.

#### Acceptance Criteria

1. THE Module_Registry SHALL define each module with a unique code (maximum 50 characters), display name (maximum 150 characters), icon identifier (maximum 100 characters), route prefix (maximum 100 characters), and associated permission prefixes stored as a JSON array of strings
2. WHEN the application initializes, THE Module_Registry SHALL contain entries for all existing modules: auth, users, businesses, people, items, stock, exchange_rates, accounts, expenses, purchase_orders, sales_invoices, payments, collections, salaries, dashboard, activity_log, settings, manufacturing, notifications, roles
3. THE Module_Registry SHALL store module definitions in a `modules` database table with columns: id (INT AUTO_INCREMENT PRIMARY KEY), code (VARCHAR(50) UNIQUE NOT NULL), name (VARCHAR(150) NOT NULL), icon (VARCHAR(100)), route_prefix (VARCHAR(100) NOT NULL), permission_prefixes (JSON NOT NULL), sort_order (INT NOT NULL DEFAULT 0), created_at (DATETIME NOT NULL)
4. WHEN a module code is queried that does not exist in the registry, THE Module_Registry SHALL return null without throwing an exception
5. THE Module_Registry SHALL order modules by the sort_order column in ascending order, where lower values appear first
6. IF an attempt is made to insert a module with a code that already exists in the registry, THEN THE Module_Registry SHALL reject the insertion and return an error indicating a duplicate module code

### Requirement 2: Business Type Definitions

**User Story:** As a system administrator, I want predefined business types with default module sets, so that new businesses get appropriate module access based on their industry.

#### Acceptance Criteria

1. THE Business_Type SHALL be stored in a `business_types` database table with columns: id, code (VARCHAR unique), name, description, is_default (BOOLEAN), created_at
2. THE Business_Type table SHALL contain exactly five predefined types: general, retail, wholesale, manufacturing, service
3. THE Business_Type table SHALL have exactly one row with is_default = 1, and that row SHALL have code "general"
4. WHEN no business type is specified during business creation, THE System SHALL assign the default business type "general"
5. IF an invalid business_type_id is provided during business creation, THEN THE System SHALL reject the request with an error response indicating the business type does not exist
6. THE System SHALL store the mapping between business types and their default modules in a `business_type_modules` junction table with columns: id, business_type_id, module_id, created_at
7. WHEN the business type is "general", THE System SHALL map all modules in the Module_Registry as active
8. WHEN the business type is "retail", THE System SHALL map these modules as active: items, stock, sales_invoices, purchase_orders, accounts, payments, collections, expenses, people, dashboard, settings, exchange_rates, activity_log, notifications, roles, auth, users, businesses
9. WHEN the business type is "wholesale", THE System SHALL map these modules as active: items, stock, sales_invoices, purchase_orders, accounts, payments, collections, expenses, people, dashboard, settings, exchange_rates, activity_log, notifications, roles, auth, users, businesses
10. WHEN the business type is "manufacturing", THE System SHALL map these modules as active: items, stock, sales_invoices, purchase_orders, accounts, payments, collections, expenses, people, dashboard, settings, exchange_rates, activity_log, notifications, roles, auth, users, businesses, manufacturing
11. WHEN the business type is "service", THE System SHALL map these modules as active: sales_invoices, accounts, payments, collections, expenses, people, dashboard, settings, exchange_rates, activity_log, notifications, roles, auth, users, businesses

### Requirement 3: Business Type Assignment

**User Story:** As a business owner, I want to select a business type when creating a business, so that only relevant modules are activated from the start.

#### Acceptance Criteria

1. WHEN a business is created, THE System SHALL accept an optional business_type_id field in the creation payload
2. WHEN a business is created with a business_type_id that exists in the business_types table, THE System SHALL store that business_type_id on the businesses table
3. WHEN a business is created without a business_type_id or with a null business_type_id, THE System SHALL assign the default business type (general)
4. IF a business is created with a business_type_id that does not exist in the business_types table, THEN THE System SHALL reject the request and return an error response indicating the business type is invalid
5. THE businesses table SHALL include a new column: business_type_id (INT, FOREIGN KEY to business_types.id, NOT NULL, DEFAULT referencing the "general" type id)
6. WHEN the migration runs, THE System SHALL set business_type_id to the "general" type id for all existing businesses that have no type assigned

### Requirement 4: Business Type Change

**User Story:** As a business owner, I want to change my business type, so that I can adjust module access as my business evolves.

#### Acceptance Criteria

1. WHEN a business type change is requested, THE System SHALL accept the new business_type_id via the business update API endpoint and validate that the provided business_type_id exists in the business_types table
2. IF the provided business_type_id does not exist in the business_types table, THEN THE System SHALL reject the request with an error response indicating an invalid business type
3. WHEN a business type change would deactivate modules that contain one or more records in their associated database tables, THE System SHALL return a warning response listing each affected module code and its record count without applying the change
4. WHEN a business type change is confirmed (force flag set to true), THE System SHALL update the business_type_id regardless of existing data in deactivated modules
5. WHEN a business type change is confirmed, THE System SHALL remove module overrides that are redundant under the new type defaults: activate overrides for modules already included in the new type defaults, and deactivate overrides for modules already excluded from the new type defaults
6. WHEN a business type change is confirmed, THE System SHALL execute the type update, override cleanup, and audit log within a single database transaction, rolling back all changes if any step fails
7. WHEN a business type is changed, THE System SHALL log the change via the AuditService with event type "BUSINESS_TYPE_CHANGE" including the previous and new business_type_id values

### Requirement 5: Per-Business Module Overrides

**User Story:** As a business owner, I want to activate or deactivate specific modules beyond my business type defaults, so that I have flexibility to customize my module set.

#### Acceptance Criteria

1. THE System SHALL store per-business module overrides in a `business_module_overrides` table with columns: id, business_id, module_id, action ENUM('activate', 'deactivate'), created_at, created_by
2. WHEN a module override with action "activate" exists, THE Module_Activation_Service SHALL treat that module as active for the business regardless of type defaults
3. WHEN a module override with action "deactivate" exists, THE Module_Activation_Service SHALL treat that module as inactive for the business regardless of type defaults
4. IF a deactivation override is requested for a core module (auth, users, businesses), THEN THE System SHALL reject the request and return an error response indicating that core modules cannot be deactivated
5. WHEN a deactivation override is requested for a module that contains existing data, THE System SHALL return a response with a warning flag and the total record count across all database tables associated with that module, without creating the override record
6. WHEN a deactivation override is confirmed (force flag set to true), THE System SHALL create the override record regardless of existing data
7. IF an override is requested for a business_id and module_id combination that already has an existing override, THEN THE System SHALL replace the existing override record with the new action
8. IF an override is requested for a module_id that does not exist in the Module_Registry, THEN THE System SHALL reject the request and return an error response indicating the module is invalid

### Requirement 6: Module Activation Resolution

**User Story:** As a developer, I want a single service that resolves active modules for any business, so that all system components use consistent module state.

#### Acceptance Criteria

1. THE Module_Activation_Service SHALL resolve active modules by combining the business type default modules with any per-business overrides
2. WHEN resolving active modules, THE Module_Activation_Service SHALL first load all module codes from the business type mapping, then apply activate overrides (add modules), then apply deactivate overrides (remove modules)
3. THE Module_Activation_Service SHALL cache the resolved module list per businessId for the duration of a single HTTP request to avoid repeated database queries
4. THE Module_Activation_Service SHALL expose a method `isModuleActive(string $moduleCode, int $businessId): bool` for use by middleware and other services
5. THE Module_Activation_Service SHALL expose a method `getActiveModules(int $businessId): array` that returns a flat array of active module code strings for a business
6. IF the provided businessId does not correspond to an existing business, THEN THE Module_Activation_Service SHALL return an empty array from `getActiveModules` and return false from `isModuleActive`
7. IF the provided moduleCode does not exist in the Module_Registry, THEN THE Module_Activation_Service SHALL return false from `isModuleActive` without throwing an exception

### Requirement 7: API Route Filtering via Middleware

**User Story:** As a system architect, I want API routes to be blocked when their module is inactive for the current business, so that deactivated features are inaccessible at the API level.

#### Acceptance Criteria

1. WHEN an authenticated API request is received, THE Module_Gate_Middleware SHALL extract the first URI path segment and match it against the Module_Registry route_prefix field to determine the module code
2. IF the determined module is not active for the current business (as resolved by Module_Activation_Service), THEN THE Module_Gate_Middleware SHALL reject the request with HTTP 403 and a JSON response body containing success as false and message "Module not available for this business"
3. IF the determined module is active for the current business, THEN THE Module_Gate_Middleware SHALL allow the request to proceed to the route handler without modification
4. THE Module_Gate_Middleware SHALL skip module checking for routes whose route prefix maps to core modules (auth, users, businesses) that are always active regardless of business type
5. IF a route's first URI path segment does not match any route_prefix in the Module_Registry, THEN THE Module_Gate_Middleware SHALL allow the request to proceed without blocking
6. THE Module_Gate_Middleware SHALL execute after AuthMiddleware resolves the authenticated user and current business context, but before permission checking occurs in the request lifecycle
7. THE Module_Gate_Middleware SHALL resolve the current business ID from the authenticated user's AuthContext established by the AuthMiddleware

### Requirement 8: Permission Filtering by Active Modules

**User Story:** As a system architect, I want permissions to be filtered by active modules, so that users cannot exercise permissions for deactivated modules even if their role grants them.

#### Acceptance Criteria

1. WHEN the AuthMiddleware resolves permissions for a user, THE System SHALL use the Module_Activation_Service to determine active modules for the current business and filter out permissions belonging to inactive modules before storing them in AuthContext
2. THE System SHALL determine module ownership of a permission by checking whether the permission code starts with any prefix listed in the Module_Registry permission_prefixes field for that module
3. IF a permission code does not match any module prefix in the Module_Registry, THEN THE System SHALL include that permission in the resolved set (fail-open for unmapped permissions)
4. WHEN a module is reactivated, THE System SHALL include that module's permissions in the resolved set on the next permission resolution request, based on the user's current role assignments at that time
5. THE System SHALL resolve permission filtering on every authenticated request using the per-request cached module list from the Module_Activation_Service, ensuring module state changes take effect without requiring session invalidation

### Requirement 9: Frontend Sidebar Filtering

**User Story:** As a user, I want the sidebar menu to show only modules that are active for my current business, so that I have a clean interface without irrelevant options.

#### Acceptance Criteria

1. WHEN the frontend loads the authenticated user context, THE System SHALL include the list of active module codes as an array field in the API response payload
2. WHEN the Sidebar_Renderer builds the navigation menu, THE Sidebar_Renderer SHALL not render menu items whose module code is not present in the active modules list
3. WHEN a menu item is not associated with any module code, THE Sidebar_Renderer SHALL render it unconditionally (always visible)
4. WHEN a user navigates directly to a URL for an inactive module, THE System SHALL display a "Module not available" message in the main content area instead of the page content, and SHALL not redirect the user away from the URL
5. IF the active modules list is missing or empty in the API response, THEN THE Sidebar_Renderer SHALL render only menu items belonging to core modules (auth, users, businesses)
6. WHEN a module override or business type change API call returns successfully, THE Sidebar_Renderer SHALL re-fetch the active modules list and update the navigation menu within 2 seconds without requiring a full page reload

### Requirement 10: Modules Settings Page

**User Story:** As a business owner, I want a settings page to view my active modules and toggle optional ones, so that I can manage module access without changing my business type.

#### Acceptance Criteria

1. THE Modules_Settings_Page SHALL display all modules from the Module_Registry grouped by category (core, finance, operations, other) using a category field defined in the Module_Registry
2. THE Modules_Settings_Page SHALL indicate for each module whether it is active by type default, active by override, inactive by type default, or inactive by override using a distinct visual status label for each state
3. THE Modules_Settings_Page SHALL display core modules (auth, users, businesses) as non-toggleable and SHALL allow toggling all other modules between active and inactive states via override creation
4. WHEN a user attempts to deactivate a module with existing data, THE Modules_Settings_Page SHALL display a confirmation dialog showing the module name and record count, and SHALL not create the override unless the user explicitly confirms
5. THE Modules_Settings_Page SHALL require the permission "settings.modules" to access
6. WHEN a module toggle is saved successfully, THE Modules_Settings_Page SHALL refresh the sidebar within 2 seconds to reflect the change without requiring a full page reload
7. IF a module toggle save fails due to a server error, THEN THE Modules_Settings_Page SHALL display an error message indicating the failure reason and SHALL revert the toggle to its previous state

### Requirement 11: Data Integrity on Module Deactivation

**User Story:** As a business owner, I want assurance that deactivating a module does not delete my data, so that I can reactivate it later and recover my records.

#### Acceptance Criteria

1. WHEN a module is deactivated, THE System SHALL retain all existing data for that module in the database without modification
2. WHEN a previously deactivated module is reactivated, THE System SHALL restore read and write access to all previously stored data for that module, such that all API operations available before deactivation function identically
3. THE System SHALL prevent deletion of database records solely due to module deactivation
4. WHILE a module is inactive, THE Module_Gate_Middleware SHALL reject API requests that create, update, or delete records for that module with HTTP 403 and message "Module not available for this business"
5. WHILE a module is inactive, THE System SHALL preserve referential integrity by retaining foreign key references from active modules to the deactivated module's records without modification
6. WHILE a module is inactive, THE System SHALL allow read-only access to the deactivated module's data when requested by an active module that holds a foreign key reference to it

### Requirement 12: Migration and Backward Compatibility

**User Story:** As a system administrator, I want the migration to be non-breaking, so that existing businesses continue to function without manual intervention.

#### Acceptance Criteria

1. WHEN the migration executes, THE System SHALL create the modules, business_types, business_type_modules, and business_module_overrides tables using IF NOT EXISTS to ensure the migration is safe to re-run
2. WHEN the migration executes, THE System SHALL seed the modules table with all 20 existing module definitions (auth, users, businesses, people, items, stock, exchange_rates, accounts, expenses, purchase_orders, sales_invoices, payments, collections, salaries, dashboard, activity_log, settings, manufacturing, notifications, roles)
3. WHEN the migration executes, THE System SHALL seed the business_types table with the five predefined types (general, retail, wholesale, manufacturing, service) and populate the business_type_modules junction table with their default module mappings
4. WHEN the migration executes, THE System SHALL add the business_type_id column to the businesses table as INT NOT NULL with a default value referencing the "general" type id
5. WHEN the migration executes, THE System SHALL update all existing businesses to have business_type_id set to the "general" type so that all currently available modules remain active for those businesses
6. IF the migration fails at any step, THEN THE System SHALL roll back all changes made during that migration execution by wrapping the entire migration in a single database transaction
7. WHEN the migration completes successfully, THE System SHALL leave all existing columns, indexes, and foreign keys on the businesses table unchanged so that existing queries continue to function without modification
8. WHEN the migration seeds module data, THE System SHALL use INSERT IGNORE or equivalent idempotent insert logic to prevent duplicate entry errors on repeated execution
