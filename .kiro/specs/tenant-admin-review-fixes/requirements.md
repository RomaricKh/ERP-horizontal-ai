# Requirements Document

## Introduction

This specification addresses code review findings in the tenant management and administration module of the ERP system. The fixes span server-side validation, tenant scoping, audit logging, architectural layering (moving raw SQL to repositories), deduplication of logic, data correctness (table name mapping), pagination standardization, a new single-tenant fetch endpoint, input validation, frontend error feedback, and shared guard extraction.

## Glossary

- **System**: The ERP backend application (PHP 8+, custom architecture)
- **TenantOwnerController**: Controller handling tenant owner designation and listing at `src/Controllers/Tenant/TenantOwnerController.php`
- **TenantBusinessController**: Controller handling tenant-scoped business CRUD at `src/Controllers/Tenant/TenantBusinessController.php`
- **TenantCoreController**: Controller handling tenant CRUD and dashboard at `src/Controllers/Tenant/TenantCoreController.php`
- **UserService**: Service layer for user lifecycle operations at `src/Services/UserService.php`
- **TenantOwnerService**: Service layer for tenant owner business logic at `src/Services/TenantOwnerService.php`
- **BusinessTypeService**: Service containing MODULE_TABLE_MAP at `src/Services/BusinessTypeService.php`
- **BusinessRepository**: Repository for business data access at `src/Repository/BusinessRepository.php`
- **TenantOwnerRepository**: Repository for tenant owner queries at `src/Repository/TenantOwnerRepository.php`
- **Tenant_Guard**: A shared closure that verifies `AuthContext::isTenantOwner()` and returns HTTP 403 on failure
- **Businesses_Page**: The frontend page at `public/assets/js/pages/Tenant/businesses.js`
- **TenantForm_Modal**: The frontend modal controller at `public/assets/js/pages/Tenant/tenantForm.modal.js`
- **Pagination_Envelope**: The standard response shape `{data, total, current_page, last_page, per_page}`
- **AuditService**: Service responsible for writing audit log entries

## Requirements

### Requirement 1: Server-Side Password Validation

**User Story:** As a system administrator, I want passwords validated on the server to enforce a minimum length policy, so that weak passwords cannot bypass client-side checks.

#### Acceptance Criteria

1. WHEN a new user is created via TenantOwnerController::designateOwner() with a password shorter than 8 characters, THE System SHALL reject the request with HTTP 422 and a message indicating the minimum length requirement.
2. WHEN a new user is created via UserService::createWithMultipleBusinesses() with a password shorter than 8 characters, THE System SHALL reject the request with HTTP 422 and a message indicating the minimum length requirement.
3. WHEN a password of 8 or more characters is provided, THE System SHALL accept the password without composition-rule validation.

### Requirement 2: Tenant Scoping on Business View and Update

**User Story:** As a tenant owner, I want business view and update operations to verify tenant membership, so that one tenant cannot access another tenant's businesses.

#### Acceptance Criteria

1. WHEN TenantBusinessController::viewBusiness() is called, THE System SHALL verify that the requested business belongs to the authenticated tenant owner's tenant before returning data.
2. IF the business does not belong to the authenticated tenant owner's tenant on viewBusiness(), THEN THE System SHALL return HTTP 403 with a message indicating access denial.
3. WHEN TenantBusinessController::updateBusiness() is called, THE System SHALL verify that the requested business belongs to the authenticated tenant owner's tenant before applying changes.
4. IF the business does not belong to the authenticated tenant owner's tenant on updateBusiness(), THEN THE System SHALL return HTTP 403 with a message indicating access denial.

### Requirement 3: Audit Logging for Role Deletion During Owner Designation

**User Story:** As a system auditor, I want all role deletions recorded in the audit log, so that no privilege changes go untracked.

#### Acceptance Criteria

1. WHEN TenantOwnerController::designateOwner() removes business-level roles from an existing user, THE System SHALL write an audit log entry recording the deletion with event type 'TENANT_OWNER_CHANGE', the affected user ID, the tenant ID, and the old role data.
2. THE System SHALL write the audit log entry within the same database transaction as the role deletion.

### Requirement 4: Move Raw SQL from Controllers to Repositories

**User Story:** As a developer, I want data access logic in repositories rather than controllers, so that the architecture follows the Controller → Service → Repository layering consistently.

#### Acceptance Criteria

1. THE System SHALL execute the tenant owner listing query (currently inline in TenantOwnerController::listOwners()) through TenantOwnerRepository or an equivalent repository method.
2. THE System SHALL execute the system business types query (currently inline in TenantBusinessController::listSystemBusinessTypes()) through a repository method.
3. THE TenantOwnerController SHALL contain no raw SQL statements after refactoring.
4. THE TenantBusinessController::listSystemBusinessTypes() SHALL contain no raw SQL statements after refactoring.

### Requirement 5: Consolidate Designation Logic into Service Layer

**User Story:** As a developer, I want the owner designation logic centralized in a single service method, so that all business rules (max-owner check, duplicate check, audit) are applied consistently.

#### Acceptance Criteria

1. WHEN TenantOwnerController::designateOwner() is invoked, THE System SHALL delegate all business logic (user lookup, duplicate check, max-owner enforcement, role assignment, audit logging) to TenantOwnerService or a consolidated service method.
2. THE TenantOwnerController::designateOwner() SHALL not contain inline business logic beyond input parsing and response formatting.

### Requirement 6: Correct MODULE_TABLE_MAP Entries

**User Story:** As a developer, I want MODULE_TABLE_MAP to reference actual database table names, so that module record counting produces correct results.

#### Acceptance Criteria

1. THE BusinessTypeService MODULE_TABLE_MAP SHALL map the 'payments' module key to the table name 'payment_slips' instead of 'payments'.
2. THE BusinessTypeService MODULE_TABLE_MAP SHALL map the 'collections' module key to the table name 'collection_slips' instead of 'collections'.
3. THE BusinessTypeService MODULE_TABLE_MAP SHALL retain the 'salaries' module key mapped to the table name 'salaries'.

### Requirement 7: Standardize Pagination Response Format

**User Story:** As a frontend developer, I want all paginated API responses to use the same envelope shape, so that the client can handle pagination uniformly.

#### Acceptance Criteria

1. WHEN TenantOwnerService::getBusinessSummaries() returns a paginated result, THE System SHALL use the Pagination_Envelope format with keys: data, total, current_page, last_page, per_page.
2. THE Businesses_Page fetchBusinesses() function SHALL read paginated business data from the 'data' key of the Pagination_Envelope.

### Requirement 8: Single Tenant Fetch Endpoint

**User Story:** As a frontend developer, I want a dedicated endpoint to fetch a single tenant by ID, so that the edit modal does not need to load the entire tenant list.

#### Acceptance Criteria

1. THE System SHALL expose a GET /tenant/tenants/{id} endpoint protected by the Tenant_Guard.
2. WHEN GET /tenant/tenants/{id} is called, THE System SHALL return the tenant record (id, name, description, is_active) for the specified ID if the authenticated user owns the tenant.
3. IF the authenticated user does not own the requested tenant, THEN THE System SHALL return HTTP 403.
4. THE TenantForm_Modal fetchAndFill() function SHALL call GET /tenant/tenants/{id} instead of fetching the full tenant list and filtering client-side.

### Requirement 9: Server-Side Email Validation

**User Story:** As a system administrator, I want email addresses validated on the server, so that malformed emails are rejected before persisting.

#### Acceptance Criteria

1. WHEN TenantOwnerController::designateOwner() receives an email value, THE System SHALL validate the email format using PHP filter_var with FILTER_VALIDATE_EMAIL.
2. IF the email fails format validation, THEN THE System SHALL reject the request with HTTP 422 and a message indicating an invalid email format.
3. WHEN UserService::createWithMultipleBusinesses() receives an email value, THE System SHALL validate the email format using PHP filter_var with FILTER_VALIDATE_EMAIL.
4. IF the email fails format validation in UserService::createWithMultipleBusinesses(), THEN THE System SHALL reject the request with HTTP 422 and a message indicating an invalid email format.

### Requirement 10: Frontend Error Feedback on Business Fetch Failure

**User Story:** As a tenant owner, I want to see an error message when the business list fails to load, so that I know the system encountered a problem.

#### Acceptance Criteria

1. IF Businesses_Page fetchBusinesses() receives a network error or non-success response, THEN THE Businesses_Page SHALL display a user-visible error notification or message indicating the failure.
2. THE Businesses_Page SHALL not silently swallow fetch errors without user feedback.

### Requirement 11: Include 'description' in createTenant Input Whitelist

**User Story:** As a tenant owner, I want to set a description when creating a tenant, so that the description field is persisted correctly.

#### Acceptance Criteria

1. WHEN TenantCoreController::createTenant() parses request input, THE System SHALL include 'description' in the input field whitelist passed to $this->input().

### Requirement 12: Filter Deactivated Businesses in getAllForCurrentUser

**User Story:** As a business user, I want my business list to exclude deactivated businesses, so that I only see businesses I can currently access.

#### Acceptance Criteria

1. WHEN BusinessRepository::getAllForCurrentUser() executes, THE System SHALL include a WHERE condition filtering to businesses where is_active = 1.
2. THE System SHALL not return deactivated businesses in the getAllForCurrentUser() result set.

### Requirement 13: Shared Tenant Guard Extraction

**User Story:** As a developer, I want the tenant owner guard closure defined in one shared file, so that tenant.php and businesses.php import the same logic without duplication.

#### Acceptance Criteria

1. THE System SHALL define the Tenant_Guard closure in a single shared file within the routes directory or a support file.
2. THE tenant.php route file SHALL import the shared Tenant_Guard instead of defining its own closure.
3. THE businesses.php route file SHALL import the shared Tenant_Guard instead of defining its own closure.
4. THE shared Tenant_Guard SHALL check AuthContext::isTenantOwner() and return HTTP 403 with a JSON error response when the check fails.

### Requirement 14: Client-Side Email Validation

**User Story:** As a user filling out forms that include email fields, I want immediate feedback on invalid email format, so that I can correct mistakes before submitting.

#### Acceptance Criteria

1. WHEN a form in the tenant owner designation flow includes an email field, THE System SHALL set the input type attribute to "email".
2. WHEN the form is submitted client-side with an email that does not match a basic email pattern, THE System SHALL display an inline validation message before sending the request.
