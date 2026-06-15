# Implementation Plan: First Installation Flow

## Overview

This plan implements two related features: (1) a multi-step setup wizard for fresh installs (backend API + frontend page), and (2) refactoring the 4 Tenant module pages to use the established `openModal()` + `registerPageActions()` pattern. Tasks are ordered backend-first, then frontend, then modal refactoring per page.

## Tasks

- [x] 1. Implement SetupGuard service
  - [x] 1.1 Create `src/Services/SetupGuard.php`
    - Implement `isFreshInstall(): bool` method that queries `SELECT COUNT(*) FROM tenants` and returns true when count is zero
    - Accept PDO instance via constructor
    - Cache result within the same request (private property) to avoid repeated queries per Requirement 1.4
    - _Requirements: 1.1, 1.2, 1.3, 1.4_

  - [x] 1.2 Write property test for SetupGuard
    - **Property 1: Setup Guard Correctness**
    - For any non-negative integer count returned by the tenants table, the SetupGuard SHALL return true if and only if the count equals zero
    - Create `tests/Property/SetupGuardPropertyTest.php`
    - Use PHPUnit data providers to generate random non-negative integers, mock PDO to return them, verify guard returns correct boolean
    - Minimum 100 iterations
    - **Validates: Requirements 1.1, 1.2**

- [ ] 2. Implement SetupService
  - [x] 2.1 Create `src/Services/SetupService.php`
    - Accept `$jwtSecret` and `PDO` via constructor
    - Implement `initialize(array $data): array` method
    - Begin transaction, execute `SELECT COUNT(*) FROM tenants FOR UPDATE` for locking
    - If count > 0 after lock: rollback, return `['success' => false, 'status' => 409, 'message' => '...']`
    - Create tenant (INSERT into tenants), create user (INSERT into users with bcrypt hash), create tenant_owner link (INSERT into tenant_owners)
    - On success: commit, generate JWT with user_id, tenant_id, is_tenant_owner=true, return `['success' => true, 'data' => [...]]`
    - On exception: rollback, return `['success' => false, 'status' => 500, 'message' => '...']`
    - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5, 5.6, 5.7, 6.1, 6.2, 6.3, 13.1, 13.2, 13.3_

  - [x] 2.2 Write property test for password hash round-trip
    - **Property 6: Password Hash Round-Trip**
    - For any password string of 8 or more characters provided during setup, `password_verify(password, stored_hash)` SHALL return true
    - Create `tests/Property/SetupServicePropertyTest.php`
    - Generate random strings ≥ 8 chars, hash with PASSWORD_BCRYPT, verify round-trip
    - Minimum 100 iterations
    - **Validates: Requirements 6.1, 6.3**

  - [ ] 2.3 Write property test for security lock
    - **Property 4: Security Lock Rejects When Configured**
    - For any valid setup payload, when the tenants table contains one or more rows, the initialize method SHALL return a failure result with status 409 and not modify the database
    - Add to `tests/Property/SetupServicePropertyTest.php`
    - Generate random valid payloads, mock PDO to return count > 0 on FOR UPDATE query, verify 409 response
    - Minimum 100 iterations
    - **Validates: Requirements 3.1, 13.2**

- [ ] 3. Implement SetupController
  - [ ] 3.1 Create `src/Controllers/SetupController.php`
    - Extend `BaseController`, do NOT call `$this->auth()`
    - Constructor: instantiate SetupGuard and SetupService with PDO and jwt_secret
    - Implement `status()`: call guard, return `{ success: true, data: { setup_required: bool } }`
    - Implement `initialize()`: guard check (403 if not fresh), validate payload, call service, return result
    - Implement `validateSetupPayload(?array $input): array` — check all required fields, username ≥ 3 chars, email format, password ≥ 8 chars
    - _Requirements: 2.1, 2.2, 2.4, 3.1, 3.2, 3.3, 3.4, 3.5, 4.1, 4.2, 4.3, 4.4, 4.5, 4.6_

  - [ ] 3.2 Write property tests for validation
    - **Property 2: Validation Rejects Incomplete Payloads**
    - For any setup payload where at least one required field is missing or empty, the controller SHALL return validation errors identifying the missing field
    - **Property 3: Validation Rejects Invalid Field Formats**
    - For any username < 3 chars, any invalid email, any password < 8 chars, the controller SHALL return validation errors for the corresponding field
    - Create `tests/Property/SetupControllerPropertyTest.php`
    - Generate payloads with random fields removed/invalid, verify correct error responses
    - Minimum 100 iterations per property
    - **Validates: Requirements 4.1, 4.2, 4.3, 4.4, 4.5, 4.6**

- [ ] 4. Register setup routes and wire backend
  - [ ] 4.1 Create `public/api/routes/setup.php`
    - Register `GET /setup/status` → SetupController::status() with auth=false, no permissions
    - Register `POST /setup/initialize` → SetupController::initialize() with auth=false, no permissions
    - _Requirements: 2.1, 2.2, 2.3, 2.5_

  - [ ] 4.2 Include setup routes in `public/api/index.php`
    - Add `require __DIR__ . '/routes/setup.php';` before other route files to ensure setup routes are registered first
    - _Requirements: 2.3, 2.5_

- [ ] 5. Checkpoint — Backend setup flow complete
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 6. Implement frontend setup page
  - [ ] 6.1 Create `public/html/Auth/setup.html`
    - Two-step wizard layout with step indicator (Step 1 / Step 2)
    - Step 1 form: tenant_name, username, email, password, confirm_password, first_name, last_name
    - Step 2 form: business_name, currency select (USD, EUR, GBP, LBP, SAR, AED, EGP, JOD), business_type select (General, Retail, Wholesale, Manufacturing, Service)
    - Inline validation error containers per field
    - Success message area for Step 3 (completion)
    - _Requirements: 9.4, 9.5, 9.7, 11.1, 11.2, 11.4, 12.1, 12.2_

  - [ ] 6.2 Create `public/assets/js/pages/Auth/setup.js`
    - Export `render(container)`, `mount()`, `unmount()` following standard page pattern
    - `render`: fetch and inject `html/Auth/setup.html`
    - `mount`: wire Step 1 form submit → POST /setup/initialize → store JWT → show Step 2
    - Wire Step 2 form submit → POST /tenant/businesses (with JWT) → show success → redirect to #/dashboard after 2 seconds
    - Display inline validation errors from 422 responses
    - Display general error messages from 500/409 responses
    - Pre-select "General" for business type and "USD" for currency
    - _Requirements: 7.1, 7.2, 7.3, 7.4, 7.5, 8.1, 8.2, 8.3, 9.3, 9.5, 9.6, 9.7, 9.8, 9.9, 11.2, 11.3, 12.2, 12.3_

  - [ ] 6.3 Add `#/setup` route to `router.config.js`
    - Add `{path: '#/setup', auth: false, permission: '', load: () => import('../pages/Auth/setup.js')}` before the login route
    - _Requirements: 9.1, 9.2_

  - [ ] 6.4 Add auto-redirect logic to login page
    - In `public/assets/js/pages/Auth/login.js` mount(), call GET /setup/status
    - If `setup_required === true` and current route is `#/login`, redirect to `#/setup`
    - In `setup.js` mount(), call GET /setup/status — if `setup_required === false`, redirect to `#/login`
    - On network error, stay on current page (no redirect)
    - Only check on unauthenticated routes
    - _Requirements: 10.1, 10.2, 10.3, 10.4, 10.5_

  - [ ] 6.5 Write frontend property test for redirect logic
    - Create `tests/first-installation-flow/redirectLogic.property.test.js`
    - Use fast-check to generate all combinations of (setup_required: bool, currentRoute: '#/login' | '#/setup')
    - Verify redirect target is correct for each combination
    - Minimum 100 iterations
    - **Validates: Requirements 10.2, 10.3, 10.4**

- [ ] 7. Checkpoint — Full setup flow end-to-end
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 8. Refactor Tenant Businesses page — modal integration
  - [ ] 8.1 Create `public/html/Tenant/businessForm.modal.html`
    - Modal template with form fields: business_name, business_type_id (select), initial_owner_user_id (select)
    - Error display area within modal
    - _Requirements: 14.3_

  - [ ] 8.2 Create `public/assets/js/pages/Tenant/businessForm.modal.js`
    - Export `mount({ modalRoot, data })` and `unmount()`
    - On mount: fetch business types from GET /tenant/business-types, populate select
    - On submit: POST /tenant/businesses, on success dispatch `tenant-businesses.updated` event and close modal
    - On error: display error message within modal
    - _Requirements: 14.4, 14.5, 14.6, 14.7_

  - [ ] 8.3 Refactor `public/assets/js/pages/Tenant/businesses.js`
    - Import `registerPageActions`, `openModal`, `closeModalsInContainer`, `MODAL_SCOPE` from modals.js
    - `render()`: load HTML from `html/Tenant/businesses.html` (already does this)
    - `mount()`: call `registerPageActions()` with `tenant-businesses.create` action that calls `openModal()` with PAGE scope, template path, controller path
    - Listen for `tenant-businesses.updated` event to refresh list
    - `unmount()`: call unregister function and `closeModalsInContainer()`
    - _Requirements: 14.1, 14.2, 14.3, 14.8, 18.1, 18.2, 18.3, 18.6_

  - [ ] 8.4 Update `public/html/Tenant/businesses.html`
    - Add `data-action="tenant-businesses.create"` attribute to the create business button
    - Remove any inline modal markup (if present) — modal content is now in the separate modal template
    - _Requirements: 14.2_

- [ ] 9. Refactor Tenant Owners page — modal integration
  - [ ] 9.1 Create `public/html/Tenant/ownerDesignate.modal.html`
    - Modal template with user selection field (select or search input)
    - Error display area within modal
    - _Requirements: 15.3_

  - [ ] 9.2 Create `public/assets/js/pages/Tenant/ownerDesignate.modal.js`
    - Export `mount({ modalRoot, data })` and `unmount()`
    - On mount: fetch tenant users from GET /tenant/users to populate user select
    - On submit: POST /tenant/owners with user_id, on success dispatch `tenant-owners.updated` event and close modal
    - On error: display error message within modal
    - _Requirements: 15.4, 15.5, 15.6_

  - [ ] 9.3 Refactor `public/assets/js/pages/Tenant/owners.js`
    - Import `registerPageActions`, `openModal`, `closeModalsInContainer`, `MODAL_SCOPE`, `confirm` from modals.js
    - `render()`: load HTML from `html/Tenant/owners.html` (already does this)
    - `mount()`: call `registerPageActions()` with:
      - `tenant-owners.designate`: opens designate modal via `openModal()`
      - `tenant-owners.revoke`: shows confirm dialog, on confirm sends DELETE /tenant/owners/{id}
    - Listen for `tenant-owners.updated` event to refresh list
    - `unmount()`: call unregister function and `closeModalsInContainer()`
    - _Requirements: 15.1, 15.2, 15.3, 15.7, 15.8, 18.1, 18.2, 18.3, 18.6_

  - [ ] 9.4 Update `public/html/Tenant/owners.html`
    - Add `data-action="tenant-owners.designate"` to the designate button
    - Add `data-action="tenant-owners.revoke"` to each revoke button in the table rows
    - Remove any inline modal markup
    - _Requirements: 15.2, 15.7_

- [ ] 10. Refactor Tenant Users page — modal integration
  - [ ] 10.1 Create `public/html/Tenant/userForm.modal.html`
    - Modal template with form fields: username, email, first_name, last_name, password, business assignment (select), role assignment (select)
    - Error display area within modal
    - _Requirements: 16.3_

  - [ ] 10.2 Create `public/assets/js/pages/Tenant/userForm.modal.js`
    - Export `mount({ modalRoot, data })` and `unmount()`
    - On mount: fetch businesses from GET /tenant/businesses and roles from GET /api/roles, populate selects
    - On submit: POST /tenant/users with form payload, on success dispatch `tenant-users.updated` event and close modal
    - On error: display error message within modal
    - _Requirements: 16.4, 16.5, 16.6, 16.7_

  - [ ] 10.3 Refactor `public/assets/js/pages/Tenant/users.js`
    - Import `registerPageActions`, `openModal`, `closeModalsInContainer`, `MODAL_SCOPE` from modals.js
    - `render()`: load HTML from `html/Tenant/users.html` via fetch (replace current inline HTML rendering)
    - `mount()`: call `registerPageActions()` with `tenant-users.create` action that calls `openModal()` with PAGE scope, template path, controller path
    - Listen for `tenant-users.updated` event to refresh list
    - `unmount()`: call unregister function and `closeModalsInContainer()`
    - _Requirements: 16.1, 16.2, 16.3, 16.8, 18.1, 18.2, 18.3, 18.6_

  - [ ] 10.4 Create `public/html/Tenant/users.html`
    - HTML template with page header, "Create User" button with `data-action="tenant-users.create"`, and users table
    - Replace the inline HTML that was previously in the JS file
    - _Requirements: 16.1, 16.2_

- [ ] 11. Refactor Tenant Business Types page — modal integration
  - [ ] 11.1 Create `public/html/Tenant/businessTypeForm.modal.html`
    - Modal template with form fields: name, description, module_codes (multi-select or checkboxes)
    - Dynamic title area (Create vs Edit)
    - Error display area within modal
    - _Requirements: 17.2, 17.3, 17.4_

  - [ ] 11.2 Create `public/assets/js/pages/Tenant/businessTypeForm.modal.js`
    - Export `mount({ modalRoot, data })` and `unmount()`
    - On mount: if `data.id` is empty → create mode (clear fields, set title "Create Business Type"); if `data.id` is set → edit mode (fetch from GET /tenant/business-types/{id}, populate fields, set title "Edit Business Type")
    - On submit: create mode → POST /tenant/business-types; edit mode → PUT /tenant/business-types/{id}
    - On success: dispatch `tenant-business-types.updated` event and close modal
    - On error: display error message within modal
    - _Requirements: 17.4, 17.5, 17.6, 17.7, 17.8, 17.9_

  - [ ] 11.3 Refactor `public/assets/js/pages/Tenant/business-types.js`
    - Import `registerPageActions`, `openModal`, `closeModalsInContainer`, `MODAL_SCOPE`, `confirm` from modals.js
    - `render()`: load HTML from `html/Tenant/business-types.html` via fetch (replace current inline HTML rendering)
    - `mount()`: call `registerPageActions()` with:
      - `tenant-business-types.create`: opens modal via `openModal()` with `data: { id: '' }`
      - `tenant-business-types.edit`: opens modal via `openModal()` with `data: { id: typeId }` (extract from button's data attribute)
      - `tenant-business-types.delete`: shows confirm dialog, on confirm sends DELETE /tenant/business-types/{id}
    - Listen for `tenant-business-types.updated` event to refresh list
    - `unmount()`: call unregister function and `closeModalsInContainer()`
    - _Requirements: 17.1, 17.2, 17.3, 17.10, 17.11, 18.1, 18.2, 18.3, 18.6_

  - [ ] 11.4 Create `public/html/Tenant/business-types.html`
    - HTML template with page header, "Add Type" button with `data-action="tenant-business-types.create"`, and business types table
    - Table row action buttons with `data-action="tenant-business-types.edit"` and `data-action="tenant-business-types.delete"` (with data-id attributes)
    - Replace the inline HTML that was previously in the JS file
    - _Requirements: 17.1, 17.2, 17.3, 17.10_

- [ ] 12. Final checkpoint — All features integrated
  - Ensure all tests pass, ask the user if questions arise.
  - Verify setup flow works end-to-end: fresh install → Step 1 → Step 2 → dashboard
  - Verify all 4 Tenant pages open modals correctly via `openModal()` pattern

## Task Dependency Graph

```json
{
  "waves": [
    {
      "name": "Wave 1 - Backend Services",
      "tasks": ["1.1", "1.2"],
      "description": "SetupGuard service and its property test"
    },
    {
      "name": "Wave 2 - Backend Orchestration",
      "tasks": ["2.1", "2.2", "2.3"],
      "description": "SetupService with transaction logic and property tests"
    },
    {
      "name": "Wave 3 - Backend Controller & Routes",
      "tasks": ["3.1", "3.2", "4.1", "4.2"],
      "description": "SetupController, validation property tests, and route registration"
    },
    {
      "name": "Wave 4 - Frontend Setup Page",
      "tasks": ["6.1", "6.2", "6.3", "6.4", "6.5"],
      "description": "Setup wizard HTML, JS page module, router config, and redirect logic"
    },
    {
      "name": "Wave 5 - Tenant Businesses Modal",
      "tasks": ["8.1", "8.2", "8.3", "8.4"],
      "description": "Business form modal template, controller, page refactor, and HTML update"
    },
    {
      "name": "Wave 6 - Tenant Owners Modal",
      "tasks": ["9.1", "9.2", "9.3", "9.4"],
      "description": "Owner designate modal template, controller, page refactor, and HTML update"
    },
    {
      "name": "Wave 7 - Tenant Users Modal",
      "tasks": ["10.1", "10.2", "10.3", "10.4"],
      "description": "User form modal template, controller, page refactor, and HTML template"
    },
    {
      "name": "Wave 8 - Tenant Business Types Modal",
      "tasks": ["11.1", "11.2", "11.3", "11.4"],
      "description": "Business type form modal template, controller, page refactor, and HTML template"
    }
  ]
}
```

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP
- Each task references specific requirements for traceability
- Checkpoints ensure incremental validation
- Property tests validate universal correctness properties (Properties 1–6 from design)
- The existing `Users/users.js` serves as the reference implementation for the modal pattern
- Step 2 of setup reuses the existing POST /tenant/businesses endpoint — no new backend work needed for business creation
