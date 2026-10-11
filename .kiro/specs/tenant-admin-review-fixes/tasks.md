# Implementation Plan: Tenant Admin Review Fixes

## Overview

This plan implements a series of targeted fixes to the tenant management module: shared guard extraction, repository extraction, service consolidation, validation improvements, data corrections, pagination standardization, a new endpoint, frontend error handling, and client-side validation. Each task builds incrementally on the previous, ending with integration wiring.

## Tasks

- [x] 1. Extract shared tenant guard and fix MODULE_TABLE_MAP
  - [x] 1.1 Create shared tenant guard file at `public/api/routes/_guards.php`
    - Create `_guards.php` that returns a closure checking `AuthContext::isTenantOwner()` and returning HTTP 403 JSON on failure
    - Update `public/api/routes/tenant.php` to import the guard via `$tenantGuard = require __DIR__ . '/_guards.php';` replacing the inline closure
    - Update `public/api/routes/businesses.php` to import the guard via `$businessTenantGuard = require __DIR__ . '/_guards.php';` replacing the inline closure
    - _Requirements: 13.1, 13.2, 13.3, 13.4_

  - [x] 1.2 Fix MODULE_TABLE_MAP entries in `src/Services/BusinessTypeService.php`
    - Change `'payments' => ['payments']` to `'payments' => ['payment_slips']`
    - Change `'collections' => ['collections']` to `'collections' => ['collection_slips']`
    - Verify `'salaries' => ['salaries']` remains unchanged
    - _Requirements: 6.1, 6.2, 6.3_

  - [x] 1.3 Write unit test for MODULE_TABLE_MAP correctness
    - Create `tests/Unit/BusinessTypeServiceTableMapTest.php`
    - Assert 'payments' maps to ['payment_slips'], 'collections' maps to ['collection_slips'], 'salaries' maps to ['salaries']
    - _Requirements: 6.1, 6.2, 6.3_

  - [x] 1.4 Write property test for shared tenant guard rejection
    - **Property 7: Shared tenant guard rejection**
    - Create `tests/Property/SharedTenantGuardPropertyTest.php`
    - For any request context where isTenantOwner() returns false, verify HTTP 403 with correct JSON body
    - **Validates: Requirements 13.4**

- [x] 2. Repository extraction and active business filtering
  - [x] 2.1 Add `listOwnersForTenant()` method to `src/Repository/TenantOwnerRepository.php`
    - Extract the owner listing SQL currently inline in `TenantOwnerController::listOwners()`
    - Method signature: `public function listOwnersForTenant(int $tenantId): array`
    - Returns array of owners with user_id, username, full_name, email, is_active, designated_at
    - _Requirements: 4.1, 4.3_

  - [x] 2.2 Add `getSystemTypesWithModules()` method to `src/Repository/BusinessTypeRepository.php`
    - Extract the system types SQL currently inline in `TenantBusinessController::listSystemBusinessTypes()`
    - Method signature: `public function getSystemTypesWithModules(): array`
    - Returns system types with their associated modules
    - _Requirements: 4.2, 4.4_

  - [x] 2.3 Refactor `TenantOwnerController::listOwners()` to use the new repository method
    - Inject/use `TenantOwnerRepository` and call `listOwnersForTenant()`
    - Remove all raw SQL from the controller method
    - _Requirements: 4.1, 4.3_

  - [x] 2.4 Refactor `TenantBusinessController::listSystemBusinessTypes()` to use the new repository method
    - Call `$this->businessTypeService` or a repository method instead of inline SQL
    - Remove all raw SQL from the controller method
    - _Requirements: 4.2, 4.4_

  - [x] 2.5 Add `is_active = 1` filter to `BusinessRepository::getAllForCurrentUser()`
    - Add `WHERE b.is_active = 1` to the query
    - _Requirements: 12.1, 12.2_

  - [x] 2.6 Include `tenant_id` in `BusinessRepository::getBusinessByID()` SELECT clause
    - Add `b.tenant_id` to the SELECT statement so callers can verify tenant scoping
    - _Requirements: 2.1, 2.3_

  - [x] 2.7 Write property test for active business filtering
    - **Property 6: Active business filtering**
    - Create `tests/Property/ActiveBusinessFilterPropertyTest.php`
    - For any mix of active/inactive businesses, verify only active ones are returned by `getAllForCurrentUser()`
    - **Validates: Requirements 12.1, 12.2**

- [x] 3. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [x] 4. Service consolidation and validation
  - [x] 4.1 Add `designateFromController()` method to `src/Services/TenantOwnerService.php`
    - Implement the consolidated designation flow: email validation, password validation (>= 8 chars), user lookup/creation, duplicate check, max-owner enforcement, business-role removal with audit, role assignment, audit logging
    - Add private static method `validatePassword(string $password): void` that throws `InvalidArgumentException` if < 8 chars
    - Include email validation via `filter_var($email, FILTER_VALIDATE_EMAIL)`
    - Write audit log entry for business-role deletion within the same transaction
    - _Requirements: 1.1, 1.3, 3.1, 3.2, 5.1, 5.2, 9.1, 9.2_

  - [x] 4.2 Refactor `TenantOwnerController::designateOwner()` to delegate to service
    - Replace inline business logic with a call to `$this->tenantOwnerService->designateFromController()`
    - Controller retains only input parsing (`$this->input()`) and response formatting
    - Wire up `TenantOwnerService` as a dependency in the constructor
    - _Requirements: 5.1, 5.2_

  - [x] 4.3 Add email and password validation to `UserService::createWithMultipleBusinesses()`
    - Add `filter_var($data['email'], FILTER_VALIDATE_EMAIL)` check at the top
    - Add password length check (>= 8 chars) for new users
    - Throw `InvalidArgumentException` with appropriate messages on failure
    - _Requirements: 1.2, 9.3, 9.4_

  - [x] 4.4 Write property test for password length validation
    - **Property 1: Password length validation**
    - Create `tests/Property/PasswordValidationPropertyTest.php`
    - For any string < 8 chars, validatePassword() throws; for any string >= 8 chars, it does not throw
    - **Validates: Requirements 1.1, 1.2, 1.3**

  - [x] 4.5 Write property test for email format validation
    - **Property 5: Email format validation**
    - Create `tests/Property/EmailValidationPropertyTest.php`
    - For any string that fails filter_var FILTER_VALIDATE_EMAIL, designation rejects; for any string that passes, it does not reject on email grounds
    - **Validates: Requirements 9.1, 9.2, 9.3, 9.4**

- [x] 5. Tenant scoping, pagination, and new endpoint
  - [x] 5.1 Add tenant scoping guard to `TenantBusinessController::viewBusiness()`
    - After fetching business, verify `(int)$business['tenant_id'] === AuthContext::tenantId()`
    - Return HTTP 403 with "Access denied: business does not belong to your tenant" if mismatch
    - _Requirements: 2.1, 2.2_

  - [x] 5.2 Add tenant scoping guard to `TenantBusinessController::updateBusiness()`
    - Same tenant membership check before applying any changes
    - Return HTTP 403 with appropriate message if mismatch
    - _Requirements: 2.3, 2.4_

  - [x] 5.3 Standardize pagination envelope in `TenantOwnerService::getBusinessSummaries()`
    - Change return keys from `businesses` to `data`, from `page` to `current_page`
    - Add `last_page` computed as `max(1, (int) ceil($total / $perPage))`
    - Final shape: `{data, total, current_page, last_page, per_page}`
    - _Requirements: 7.1_

  - [x] 5.4 Add `viewTenant()` method to `TenantCoreController` and register route
    - Create `viewTenant(int $tenantId)` method that checks ownership and returns tenant record
    - Register `GET /tenant/tenants/{id}` route in `public/api/routes/tenant.php` with the shared guard
    - Return HTTP 403 if user does not own the tenant, HTTP 404 if tenant not found
    - _Requirements: 8.1, 8.2, 8.3_

  - [x] 5.5 Fix `createTenant()` input whitelist to include 'description'
    - Update `$this->input(['name'])` to `$this->input(['name', 'description'])` in `TenantCoreController::createTenant()`
    - Ensure description remains optional (handled as nullable)
    - _Requirements: 11.1_

  - [x] 5.6 Write property test for tenant scoping
    - **Property 2: Tenant scoping rejects cross-tenant business access**
    - Create `tests/Property/TenantScopingPropertyTest.php`
    - For any business where tenant_id != authenticated tenant, verify 403 response
    - **Validates: Requirements 2.1, 2.2, 2.3, 2.4**

  - [x] 5.7 Write property test for pagination envelope structure
    - **Property 3: Pagination envelope structure**
    - Create `tests/Property/PaginationEnvelopePropertyTest.php`
    - For any valid page/perPage values, verify envelope contains exactly {data, total, current_page, last_page, per_page} with correct computation
    - **Validates: Requirements 7.1**

  - [x] 5.8 Write property test for single tenant fetch ownership guard
    - **Property 4: Single tenant fetch ownership guard**
    - Create `tests/Property/TenantFetchOwnershipPropertyTest.php`
    - For any tenant/user combination, viewTenant returns data iff user owns tenant, else 403
    - **Validates: Requirements 8.2, 8.3**

- [x] 6. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [x] 7. Frontend fixes
  - [x] 7.1 Update `fetchBusinesses()` in `public/assets/js/pages/Tenant/businesses.js`
    - Read paginated data from `data.data?.data || []` (Pagination_Envelope format)
    - Add try/catch error handling: on network error or non-success response, call a `showError()` helper
    - Add `showError(message)` function that renders an alert div in the page container
    - _Requirements: 7.2, 10.1, 10.2_

  - [x] 7.2 Update `fetchAndFill()` in `public/assets/js/pages/Tenant/tenantForm.modal.js`
    - Change from fetching full tenant list to calling `GET /api/tenant/tenants/${id}`
    - Read tenant from `json.data?.tenant` instead of filtering array
    - _Requirements: 8.4_

  - [x] 7.3 Add client-side email validation to tenant owner designation form
    - Set input type to "email" on the email field in the owner designation HTML template
    - Add pre-submit regex validation (`/^[^\s@]+@[^\s@]+\.[^\s@]+$/`) in the modal controller JS
    - Display inline validation message on failure before sending request
    - _Requirements: 14.1, 14.2_

  - [x] 7.4 Write JS unit tests for frontend changes
    - Create `tests/frontend/businesses.test.js`: test that fetchBusinesses reads from `data.data`, test error feedback on failed fetch
    - Create `tests/frontend/tenantForm.test.js`: test that fetchAndFill calls `/api/tenant/tenants/{id}`
    - Create `tests/frontend/emailValidation.test.js`: test client-side email pattern against valid/invalid inputs
    - _Requirements: 7.2, 8.4, 10.1, 14.1, 14.2_

- [x] 8. Final checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP
- Each task references specific requirements for traceability
- Checkpoints ensure incremental validation
- Property tests validate universal correctness properties from the design document
- Unit tests validate specific examples and edge cases
- The shared guard (`_guards.php`) is created first since multiple subsequent tasks depend on it
- Repository extraction (task 2) unblocks the service consolidation (task 4) which depends on clean separation
- Frontend tasks (task 7) are independent of backend tasks and can be done after all backend work is stable

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1", "1.2", "2.1", "2.2", "2.5", "2.6"] },
    { "id": 1, "tasks": ["1.3", "1.4", "2.3", "2.4", "2.7"] },
    { "id": 2, "tasks": ["4.1", "4.3", "5.1", "5.2", "5.3", "5.4", "5.5"] },
    { "id": 3, "tasks": ["4.2", "4.4", "4.5", "5.6", "5.7", "5.8"] },
    { "id": 4, "tasks": ["7.1", "7.2", "7.3"] },
    { "id": 5, "tasks": ["7.4"] }
  ]
}
```
