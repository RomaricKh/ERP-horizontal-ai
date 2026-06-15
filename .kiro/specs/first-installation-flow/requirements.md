# Requirements Document

## Introduction

This feature covers two related areas of the multi-tenant ERP system:

1. **Multi-Step First Installation Flow** — When the system is deployed to a fresh environment with no existing tenants, a guided multi-step setup wizard walks the initial administrator through creating the first tenant + owner user (Step 1), then logging in automatically and creating the first business (Step 2), before being redirected to the dashboard (Step 3). The setup endpoints operate outside the JWT-based auth system and are permanently disabled once the first tenant exists.

2. **Tenant Module Modal Fixes** — The Tenant module pages (businesses, owners, users, business-types) have broken modals. The HTML templates already define modal markup, but the JS page files either render their own inline HTML instead of loading the templates, or never wire up the modal trigger buttons using the project's established `openModal` + `registerPageActions` pattern. These pages must be refactored to follow the working pattern established in `Users/users.js`.

## Glossary

- **Setup_Guard**: The service responsible for detecting whether the system is in a fresh-install state by checking if zero tenants exist in the database
- **Setup_Controller**: The API controller that handles setup wizard requests, operating independently of the AuthMiddleware
- **Setup_Service**: The backend service that orchestrates the creation of the first tenant and owner user within a single transaction
- **Setup_Page**: The frontend page (outside the authenticated SPA shell) that presents the multi-step setup wizard to the initial administrator
- **Fresh_Install_State**: The system state where zero rows exist in the tenants table, indicating no prior configuration has occurred
- **Tenant**: The top-level organizational entity in the multi-tenant hierarchy that owns businesses and users
- **Tenant_Owner**: The first user created during setup who has full administrative privileges over the tenant
- **Business_Owner_Role**: The role with id 1 that grants all permissions to the user within a business
- **Modal_Controller**: A JavaScript module that exports `mount({ modalRoot, data })` and `unmount()` functions to handle form logic within a modal opened via `openModal()`
- **Page_Action**: A named action string (e.g., `tenant-businesses.create`) declared on HTML button elements via `data-action` attributes and handled by `registerPageActions()`

## Requirements

### Requirement 1: Fresh Install Detection

**User Story:** As the system, I want to detect when no tenants exist in the database, so that I can enable the setup wizard for first-time configuration.

#### Acceptance Criteria

1. THE Setup_Guard SHALL determine the system is in Fresh_Install_State by executing a count query against the tenants table and verifying the result is zero
2. THE Setup_Guard SHALL return a boolean value indicating whether the system is in Fresh_Install_State
3. THE Setup_Guard SHALL execute the tenant count query using a single SELECT COUNT(*) statement against the tenants table without joins or subqueries
4. WHEN the Setup_Guard determines the system is not in Fresh_Install_State, THE Setup_Guard SHALL cache the result for the duration of the current HTTP request to avoid repeated database queries

### Requirement 2: Setup API Endpoint Registration

**User Story:** As a system architect, I want setup endpoints registered outside the authenticated router, so that they are accessible without JWT tokens.

#### Acceptance Criteria

1. THE System SHALL expose a POST /setup/initialize endpoint that accepts the tenant and owner user payload (Step 1)
2. THE System SHALL expose a GET /setup/status endpoint that returns whether the system is in Fresh_Install_State
3. THE Setup_Controller SHALL be registered in the Router with authRequired set to false, bypassing AuthMiddleware entirely
4. THE Setup_Controller SHALL not depend on AuthContext, JWT tokens, or any authentication-related classes for the initialize endpoint
5. WHEN the Router dispatches a request to a setup endpoint, THE Router SHALL skip both AuthMiddleware and ModuleGateMiddleware processing for that request

### Requirement 3: Setup Endpoint Security Lock

**User Story:** As a system administrator, I want setup endpoints to be permanently disabled after initial configuration, so that they cannot be exploited to create unauthorized tenants.

#### Acceptance Criteria

1. WHEN a request is received on the POST /setup/initialize endpoint and the system is not in Fresh_Install_State, THE Setup_Controller SHALL reject the request with HTTP 403 and a JSON response containing success as false and message "Setup is not available. System is already configured."
2. WHEN a request is received on the GET /setup/status endpoint and the system is not in Fresh_Install_State, THE Setup_Controller SHALL return HTTP 200 with a JSON response containing setup_required as false
3. WHEN a request is received on the GET /setup/status endpoint and the system is in Fresh_Install_State, THE Setup_Controller SHALL return HTTP 200 with a JSON response containing setup_required as true
4. THE Setup_Controller SHALL invoke the Setup_Guard as the first operation before processing any setup request payload
5. THE Setup_Guard SHALL perform the fresh-install check on every request to setup endpoints without relying on cached state from previous requests

### Requirement 4: Setup Step 1 — Tenant and Owner Creation Payload Validation

**User Story:** As the initial administrator, I want clear validation on my tenant and user input, so that I can correct errors before the system is configured.

#### Acceptance Criteria

1. WHEN a POST /setup/initialize request is received, THE Setup_Controller SHALL validate the presence of required fields: tenant_name, username, email, password, first_name, and last_name
2. IF the tenant_name field is missing or empty, THEN THE Setup_Controller SHALL reject the request with HTTP 422 and a JSON response listing tenant_name as a required field
3. IF the username field is missing, empty, or shorter than 3 characters, THEN THE Setup_Controller SHALL reject the request with HTTP 422 and a JSON response listing username validation errors
4. IF the email field is missing or does not match a valid email format, THEN THE Setup_Controller SHALL reject the request with HTTP 422 and a JSON response listing email validation errors
5. IF the password field is missing or shorter than 8 characters, THEN THE Setup_Controller SHALL reject the request with HTTP 422 and a JSON response listing password validation errors
6. IF the first_name or last_name field is missing or empty, THEN THE Setup_Controller SHALL reject the request with HTTP 422 and a JSON response listing the field as required

### Requirement 5: Setup Step 1 — Tenant and Owner Creation Execution

**User Story:** As the initial administrator, I want to create the first tenant and my owner account in one step, so that I can proceed to configure my business.

#### Acceptance Criteria

1. WHEN a valid POST /setup/initialize request is received and the system is in Fresh_Install_State, THE Setup_Service SHALL create the tenant and owner user within a single database transaction
2. THE Setup_Service SHALL create the tenant record first by inserting a row into the tenants table with the provided tenant_name
3. THE Setup_Service SHALL create the user record second by inserting a row into the users table with the provided username, email, first_name, last_name, a bcrypt hash of the provided password, and the tenant_id from the created tenant
4. THE Setup_Service SHALL designate the created user as a Tenant_Owner by inserting a row into the tenant_owners table linking the user_id and tenant_id
5. IF any step within the transaction fails, THEN THE Setup_Service SHALL roll back all changes and return HTTP 500 with a JSON response containing success as false and a descriptive error message
6. WHEN the transaction completes successfully, THE Setup_Service SHALL return HTTP 201 with a JSON response containing success as true, the created tenant_id, user_id, and a valid JWT token for the created user
7. THE Setup_Service SHALL generate a JWT token for the created user so the frontend can immediately authenticate as the Tenant_Owner without a separate login step

### Requirement 6: Setup Password Security

**User Story:** As a security-conscious administrator, I want my initial password stored securely, so that it cannot be recovered from the database.

#### Acceptance Criteria

1. THE Setup_Service SHALL hash the provided password using PHP password_hash with the PASSWORD_BCRYPT algorithm before storing it in the users table
2. THE Setup_Service SHALL not store, log, or return the plain-text password in any response or log output
3. THE Setup_Service SHALL validate that the password is at least 8 characters before hashing

### Requirement 7: Setup Step 2 — First Business Creation

**User Story:** As the newly created Tenant_Owner, I want to create my first business after logging in, so that I can start using the ERP system.

#### Acceptance Criteria

1. WHEN the Tenant_Owner is authenticated and no businesses exist for the tenant, THE System SHALL present a business creation form as Step 2 of the setup flow
2. THE Setup_Page SHALL collect the following fields for business creation: business_name, currency, and business_type
3. WHEN the business creation form is submitted, THE Setup_Page SHALL send a POST request to /tenant/businesses with the form data using the JWT token obtained from Step 1
4. THE System SHALL use the existing POST /tenant/businesses endpoint (already implemented in tenant.php routes) to create the business
5. WHEN the business is created successfully, THE Setup_Service SHALL assign the Business_Owner_Role (role_id 1) to the Tenant_Owner for the created business
6. WHEN the business is created successfully, THE Setup_Service SHALL create a default account for the business by inserting a row into the accounts table with the business name and currency

### Requirement 8: Setup Step 3 — Completion and Redirect

**User Story:** As the initial administrator, I want to be redirected to the dashboard after completing setup, so that I can start using the system immediately.

#### Acceptance Criteria

1. WHEN the first business is created successfully in Step 2, THE Setup_Page SHALL display a success message indicating setup is complete
2. WHEN setup is complete, THE Setup_Page SHALL redirect the user to the #/dashboard route after 2 seconds
3. THE System SHALL store the JWT token from Step 1 in the standard auth storage location so the authenticated app shell loads correctly after redirect

### Requirement 9: Frontend Setup Page — Multi-Step Wizard

**User Story:** As the initial administrator, I want a dedicated multi-step setup page outside the login flow, so that I can configure the system step by step without needing existing credentials.

#### Acceptance Criteria

1. THE Setup_Page SHALL be accessible at the hash route #/setup within the SPA
2. THE Setup_Page SHALL be registered in the router configuration with auth set to false and no permission requirement
3. THE Setup_Page SHALL render outside the authenticated app shell (sidebar and topbar are not displayed)
4. THE Setup_Page SHALL display a step indicator showing the current step: Step 1 "Create Organization & Owner", Step 2 "Create First Business"
5. WHEN Step 1 is active, THE Setup_Page SHALL render a form with fields: tenant name, username, email, password, password confirmation, first name, and last name
6. WHEN Step 1 form is submitted successfully, THE Setup_Page SHALL store the returned JWT token and advance to Step 2 without a page reload
7. WHEN Step 2 is active, THE Setup_Page SHALL render a form with fields: business name, currency selection, and business type selection
8. IF the POST /setup/initialize response returns a validation error, THEN THE Setup_Page SHALL display the validation errors inline next to the corresponding form fields
9. IF the POST /tenant/businesses response returns an error, THEN THE Setup_Page SHALL display the error message and allow the user to retry

### Requirement 10: Frontend Auto-Redirect Logic

**User Story:** As a user, I want to be automatically redirected between setup and login pages based on system state, so that I always reach the correct page.

#### Acceptance Criteria

1. WHEN the SPA initializes or navigates to #/login, THE System SHALL call GET /setup/status to check if setup is required
2. IF the GET /setup/status response indicates setup_required is true and the current route is #/login, THEN THE System SHALL redirect the user to #/setup
3. IF the GET /setup/status response indicates setup_required is false and the current route is #/setup, THEN THE System SHALL redirect the user to #/login
4. WHEN the GET /setup/status request fails due to a network error, THE System SHALL remain on the current page and display no redirect
5. THE System SHALL call GET /setup/status only for unauthenticated routes (#/login and #/setup) to avoid unnecessary API calls on authenticated pages

### Requirement 11: Business Type Selection During Setup

**User Story:** As the initial administrator, I want to select a business type during setup Step 2, so that my business starts with the correct module configuration.

#### Acceptance Criteria

1. THE Setup_Page SHALL display a business type selection field in Step 2 with options: General, Retail, Wholesale, Manufacturing, and Service
2. THE Setup_Page SHALL pre-select "General" as the default business type
3. WHEN the business creation form is submitted with a business_type value, THE System SHALL store the corresponding business_type_id on the created business record
4. THE Setup_Page SHALL display a brief description for each business type option to help the administrator make an informed selection

### Requirement 12: Currency Selection During Setup

**User Story:** As the initial administrator, I want to select my business currency during setup Step 2, so that financial records use the correct base currency from the start.

#### Acceptance Criteria

1. THE Setup_Page SHALL display a currency selection field in Step 2 with common currency options (USD, EUR, GBP, LBP, SAR, AED, EGP, JOD)
2. THE Setup_Page SHALL pre-select "USD" as the default currency
3. WHEN the business creation form is submitted, THE System SHALL use the selected currency value when creating the business record and the default account

### Requirement 13: Setup Idempotency Protection

**User Story:** As the system, I want to prevent race conditions during setup, so that concurrent requests cannot create duplicate tenants.

#### Acceptance Criteria

1. WHEN the Setup_Service begins the initialization transaction, THE Setup_Service SHALL acquire a database-level lock by executing SELECT COUNT(*) FROM tenants FOR UPDATE within the transaction before proceeding with inserts
2. IF the locked count query returns a value greater than zero, THEN THE Setup_Service SHALL roll back the transaction and return HTTP 409 with a JSON response containing success as false and message "Setup has already been completed by another request"
3. THE Setup_Service SHALL rely on the database transaction isolation to prevent two concurrent setup requests from both succeeding

### Requirement 14: Tenant Businesses Page — Modal Integration

**User Story:** As a Tenant Owner, I want the "Create Business" button on the Tenant Businesses page to open a properly functioning modal, so that I can create new businesses without page navigation.

#### Acceptance Criteria

1. THE Tenant Businesses page SHALL load its HTML template from `html/Tenant/businesses.html` using a fetch call in the render function
2. THE Tenant Businesses page SHALL register a Page_Action named `tenant-businesses.create` that opens the Create Business modal using `openModal()`
3. WHEN the `tenant-businesses.create` action is triggered, THE System SHALL open a modal with scope PAGE, loading the modal template from a dedicated HTML file and a Modal_Controller from a dedicated JS module
4. THE Modal_Controller for Create Business SHALL fetch available business types from GET /tenant/business-types and populate the business type select field
5. WHEN the Create Business form is submitted, THE Modal_Controller SHALL send a POST request to /tenant/businesses with the form payload (business_name, business_type_id, initial_owner_user_id)
6. WHEN the POST /tenant/businesses response returns success, THE Modal_Controller SHALL close the modal and dispatch a custom event to refresh the businesses list
7. IF the POST /tenant/businesses response returns an error, THEN THE Modal_Controller SHALL display the error message within the modal without closing it
8. THE Tenant Businesses page SHALL call `closeModalsInContainer()` during unmount to clean up any open modals

### Requirement 15: Tenant Owners Page — Modal Integration

**User Story:** As a Tenant Owner, I want the "Designate Owner" button on the Tenant Owners page to open a properly functioning modal, so that I can designate new tenant owners.

#### Acceptance Criteria

1. THE Tenant Owners page SHALL load its HTML template from `html/Tenant/owners.html` using a fetch call in the render function
2. THE Tenant Owners page SHALL register a Page_Action named `tenant-owners.designate` that opens the Designate Owner modal using `openModal()`
3. WHEN the `tenant-owners.designate` action is triggered, THE System SHALL open a modal with scope PAGE, loading the modal template from a dedicated HTML file and a Modal_Controller from a dedicated JS module
4. WHEN the Designate Owner form is submitted, THE Modal_Controller SHALL send a POST request to /tenant/owners with the user_id payload
5. WHEN the POST /tenant/owners response returns success, THE Modal_Controller SHALL close the modal and dispatch a custom event to refresh the owners list
6. IF the POST /tenant/owners response returns an error, THEN THE Modal_Controller SHALL display the error message within the modal without closing it
7. THE Tenant Owners page SHALL register a Page_Action named `tenant-owners.revoke` that confirms and sends a DELETE request to /tenant/owners/{id}
8. THE Tenant Owners page SHALL call `closeModalsInContainer()` during unmount to clean up any open modals

### Requirement 16: Tenant Users Page — Modal Integration

**User Story:** As a Tenant Owner, I want the "Create User" button on the Tenant Users page to open a properly functioning modal, so that I can create new users across the tenant.

#### Acceptance Criteria

1. THE Tenant Users page SHALL load its HTML template from `html/Tenant/users.html` using a fetch call in the render function
2. THE Tenant Users page SHALL register a Page_Action named `tenant-users.create` that opens the Create User modal using `openModal()`
3. WHEN the `tenant-users.create` action is triggered, THE System SHALL open a modal with scope PAGE, loading the modal template from a dedicated HTML file and a Modal_Controller from a dedicated JS module
4. THE Modal_Controller for Create User SHALL fetch available businesses from GET /tenant/businesses and roles from GET /api/roles to populate the assignment dropdowns
5. WHEN the Create User form is submitted, THE Modal_Controller SHALL send a POST request to /tenant/users with the form payload (username, email, name, password, business assignments with roles)
6. WHEN the POST /tenant/users response returns success, THE Modal_Controller SHALL close the modal and dispatch a custom event to refresh the users list
7. IF the POST /tenant/users response returns an error, THEN THE Modal_Controller SHALL display the error message within the modal without closing it
8. THE Tenant Users page SHALL call `closeModalsInContainer()` during unmount to clean up any open modals

### Requirement 17: Tenant Business Types Page — Modal Integration

**User Story:** As a Tenant Owner, I want the "Create Type" and "Edit" buttons on the Tenant Business Types page to open a properly functioning modal, so that I can manage custom business types.

#### Acceptance Criteria

1. THE Tenant Business Types page SHALL load its HTML template from `html/Tenant/business-types.html` using a fetch call in the render function
2. THE Tenant Business Types page SHALL register a Page_Action named `tenant-business-types.create` that opens the Business Type modal in create mode using `openModal()`
3. THE Tenant Business Types page SHALL register a Page_Action named `tenant-business-types.edit` that opens the Business Type modal in edit mode, passing the business type ID as data
4. WHEN the Business Type modal opens in create mode, THE Modal_Controller SHALL set the modal title to "Create Business Type" and clear all form fields
5. WHEN the Business Type modal opens in edit mode, THE Modal_Controller SHALL fetch the business type details from GET /tenant/business-types and populate the form fields
6. WHEN the Business Type form is submitted in create mode, THE Modal_Controller SHALL send a POST request to /tenant/business-types with the form payload (name, description, module_codes)
7. WHEN the Business Type form is submitted in edit mode, THE Modal_Controller SHALL send a PUT request to /tenant/business-types/{id} with the form payload
8. WHEN the save response returns success, THE Modal_Controller SHALL close the modal and dispatch a custom event to refresh the business types list
9. IF the save response returns an error, THEN THE Modal_Controller SHALL display the error message within the modal without closing it
10. THE Tenant Business Types page SHALL register a Page_Action named `tenant-business-types.delete` that confirms and sends a DELETE request to /tenant/business-types/{id}
11. THE Tenant Business Types page SHALL call `closeModalsInContainer()` during unmount to clean up any open modals

### Requirement 18: Tenant Page Modal Pattern Consistency

**User Story:** As a developer, I want all Tenant module pages to follow the same modal pattern as the Users module, so that the codebase is consistent and maintainable.

#### Acceptance Criteria

1. WHEN a Tenant page is rendered, THE page module SHALL load its HTML template via fetch and set container.innerHTML to the fetched HTML (matching the pattern in Users/users.js)
2. WHEN a Tenant page is mounted, THE page module SHALL call `registerPageActions()` to wire up all button actions and store the returned unregister function
3. WHEN a Tenant page is unmounted, THE page module SHALL call the unregister function and call `closeModalsInContainer()` to clean up
4. THE Modal_Controller files for Tenant pages SHALL be placed in `public/assets/js/pages/Tenant/` following the naming convention `{feature}.modal.js`
5. THE modal HTML templates for Tenant pages SHALL be placed in `public/html/Tenant/` as separate files dedicated to modal content (e.g., `businessForm.modal.html`)
6. WHEN opening a modal, THE page module SHALL use `openModal()` with scope `MODAL_SCOPE.PAGE`, a template path, a controller path, data object, and options object
