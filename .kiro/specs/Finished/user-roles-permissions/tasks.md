# Implementation Plan: User Roles & Permissions Management

## Overview

Extend the existing roles infrastructure into a full CRUD management interface. The implementation builds incrementally on the existing `RoleRepository`, `RoleService`, and `RolesController` — adding new methods, endpoints, routes with permission guards, a permission seeding migration, and frontend pages for managing roles and permissions.

## Tasks

- [ ] 1. Extend RoleRepository with CRUD and permission methods
  - [x] 1.1 Add `findByIdAndTenant`, `findAllPermissions`, `findPermissionsByRoleId` methods
    - `findByIdAndTenant(int $id, int $tenantId): ?array` — fetch single role scoped to tenant
    - `findAllPermissions(): array` — fetch all rows from `permissions` table
    - `findPermissionsByRoleId(int $roleId): array` — return ALL permissions for Business Owner (id=1), otherwise join through `role_permissions`
    - _Requirements: 2.1, 2.2, 2.3, 6.3_

  - [x] 1.2 Add `existsByNameAndTenant`, `countUsersWithRole` query methods
    - `existsByNameAndTenant(string $name, int $tenantId, ?int $excludeId = null): bool` — check duplicate names, excluding a given role ID for updates
    - `countUsersWithRole(int $roleId): int` — count rows in `user_roles` where `role_id` matches
    - _Requirements: 3.6, 4.4, 5.3_

  - [x] 1.3 Add `insert`, `updateRole`, `deleteRole`, `syncPermissions` mutation methods
    - `insert(int $tenantId, string $name, ?string $description): int` — insert role, return new ID
    - `updateRole(int $id, string $name, ?string $description): void` — update name and description
    - `deleteRole(int $id): void` — delete role row
    - `syncPermissions(int $roleId, array $permissionIds): void` — delete existing `role_permissions` for role, then insert new set (within a transaction)
    - _Requirements: 3.1, 4.2, 5.1_

- [ ] 2. Extend RoleService with validation and business logic
  - [x] 2.1 Add `getById` and `getAllPermissions` read methods
    - `getById(int $id, int $tenantId): ?array` — fetch role via repository, attach permissions array
    - `getAllPermissions(): array` — delegate to repository
    - _Requirements: 2.1, 2.2, 3.4_

  - [x] 2.2 Add `create` method with validation and audit logging
    - Validate name length (2–50 chars trimmed), description length (≤255 chars)
    - Check duplicate name via `existsByNameAndTenant`
    - Validate all permission IDs exist in the permissions table
    - Call repository `insert` then `syncPermissions`
    - Log creation via `AuditService`
    - _Requirements: 3.1, 3.2, 3.3, 3.5, 3.6, 3.7_

  - [x] 2.3 Add `update` method with Business Owner protection and validation
    - Reject if `id === 1` (Business Owner) — throw exception
    - Validate name length, description length, duplicate name (excluding current ID)
    - Validate permission IDs
    - Call repository `updateRole` then `syncPermissions`
    - Log update via `AuditService`
    - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 6.1_

  - [x] 2.4 Add `delete` method with in-use check and Business Owner protection
    - Reject if `id === 1` (Business Owner) — throw exception
    - Check `countUsersWithRole` — reject if > 0 with user count in error
    - Call repository `deleteRole` (which cascades `role_permissions` removal)
    - Log deletion via `AuditService`
    - _Requirements: 5.1, 5.2, 5.3, 5.4, 6.2_

  - [x] 2.5 Write property test: Business Owner Immutability (Property 7)
    - **Property 7: Business Owner Immutability**
    - Generate random update/delete payloads targeting role id=1, verify rejection
    - **Validates: Requirements 4.3, 5.2, 6.1, 6.2, 6.4**

  - [x] 2.6 Write property test: Name and Description Validation (Property 5)
    - **Property 5: Name and Description Validation**
    - Generate random strings, verify acceptance iff trimmed length is 2–50 for name, ≤255 for description
    - **Validates: Requirements 3.2, 3.3**

  - [x] 2.7 Write property test: Name Uniqueness Within Tenant (Property 6)
    - **Property 6: Name Uniqueness Within Tenant**
    - Generate random names, create a role, attempt to create/update another with same name in same tenant, verify rejection
    - **Validates: Requirements 3.6, 4.4**

- [x] 3. Checkpoint - Ensure repository and service tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 4. Extend RolesController with new endpoints
  - [x] 4.1 Add `show(int $id)` method
    - Call `$this->service->getById($id, $ctx->tenant_id)`
    - Return 404 if not found, otherwise return role with permissions
    - _Requirements: 2.1, 2.2, 2.3_

  - [x] 4.2 Add `permissions()` method
    - Call `$this->service->getAllPermissions()`
    - Return flat list of all permissions
    - _Requirements: 3.4, 7.1_

  - [x] 4.3 Add `store()` method
    - Parse input body (`name` required, `description` optional, `permission_ids` optional array)
    - Call `$this->service->create(...)`, catch validation exceptions and return 422
    - Return 201 with success message on creation
    - _Requirements: 3.1, 3.2, 3.3, 3.5, 3.6, 3.7_

  - [x] 4.4 Add `update(int $id)` method
    - Parse input body, call `$this->service->update(...)`
    - Catch Business Owner exception → 403, validation exceptions → 422
    - Return 200 with success message
    - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 6.4_

  - [x] 4.5 Add `delete(int $id)` method
    - Call `$this->service->delete(...)`
    - Catch Business Owner exception → 403, in-use exception → 422
    - Return 200 with success message
    - _Requirements: 5.1, 5.2, 5.3, 5.4, 6.4_

  - [x] 4.6 Update `index()` to include `is_system` flag
    - Add `is_system => ($role['id'] === 1)` to each role in the response
    - _Requirements: 1.3, 1.4_

- [ ] 5. Update API routes with permission guards
  - [x] 5.1 Rewrite `public/api/routes/roles.php` with all role endpoints and permission guards
    - `GET /roles/permissions` → `permissions()` with `['roles.view']` (register BEFORE `/{id}`)
    - `GET /roles` → `index()` with `['roles.view']`
    - `POST /roles` → `store()` with `['roles.create']`
    - `GET /roles/{id}` → `show($id)` with `['roles.view']`
    - `PUT /roles/{id}` → `update($id)` with `['roles.edit']`
    - `DELETE /roles/{id}` → `delete($id)` with `['roles.delete']`
    - _Requirements: 8.1, 8.2, 8.3, 8.4, 8.5_

  - [x] 5.2 Write property test: Permission Enforcement (Property 10)
    - **Property 10: Permission Enforcement**
    - Generate users with/without `roles.*` permissions, verify 403 for unauthorized access
    - **Validates: Requirements 8.1, 8.2, 8.3, 8.4, 8.5**

- [ ] 6. Seed roles permissions migration
  - [x] 6.1 Create `database/migrations/seed_roles_permissions.sql`
    - Insert `roles.view`, `roles.create`, `roles.edit`, `roles.delete` into `permissions` table
    - Grant all `roles.*` permissions to Business Owner (role_id = 1) via `role_permissions`
    - Use `INSERT IGNORE` to be idempotent
    - _Requirements: 8.1, 8.2, 8.3, 8.4, 6.3_

- [x] 7. Checkpoint - Ensure backend is complete and all tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 8. Frontend: Roles list page
  - [x] 8.1 Create `public/html/Roles/roles.html` template
    - Table layout with columns: Role Name, Description, Actions
    - Action buttons for edit and delete (disabled for Business Owner)
    - Create Role button
    - _Requirements: 1.1, 1.2, 1.3, 1.4_

  - [x] 8.2 Create `public/assets/js/pages/Roles/roles.js` page controller
    - Fetch roles via `GET /roles` using `apiFetch`
    - Render table using `createDataTable` pattern
    - Mark Business Owner row with visual indicator and disable edit/delete buttons
    - Wire create/edit/delete page actions to open modals
    - Listen for `roles.updated` event to refresh list
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 3.7, 4.5, 5.4_

- [ ] 9. Frontend: Role form modal (create/edit)
  - [x] 9.1 Create `public/html/Roles/roleForm.html` modal template
    - Name input field, description textarea
    - Permissions section with grouped checkboxes
    - Select-all toggle per permission group
    - Save and Cancel buttons
    - _Requirements: 3.4, 7.1, 7.2, 7.3_

  - [x] 9.2 Create `public/assets/js/pages/Roles/roleForm.modal.js` controller
    - Fetch all permissions via `GET /roles/permissions`
    - Group permissions by module prefix (text before first dot)
    - Map module prefixes to human-readable labels (static label map)
    - Render grouped checkboxes with select-all toggles per group
    - On create: `POST /roles` with name, description, selected permission_ids
    - On edit: fetch role via `GET /roles/{id}`, populate form, `PUT /roles/{id}` on save
    - Display success/error messages, dispatch `roles.updated` event on success
    - _Requirements: 3.1, 3.4, 3.7, 4.1, 4.5, 7.1, 7.2, 7.3, 7.4_

  - [x] 9.3 Write property test: Permission Grouping by Module Prefix (Property 2)
    - **Property 2: Permission Grouping by Module Prefix**
    - Generate random permission codes with dot-notation, verify grouping produces correct partitions
    - **Validates: Requirements 2.1, 7.1**

- [ ] 10. Frontend: Delete role confirmation modal
  - [x] 10.1 Implement delete role flow with confirmation dialog
    - Use existing `confirm()` modal utility for confirmation dialog
    - On confirm: `DELETE /roles/{id}`, handle 403 (Business Owner) and 422 (in-use) errors
    - Display error messages with user count for in-use roles
    - Dispatch `roles.updated` event on successful deletion
    - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5_

- [x] 11. Checkpoint - Ensure frontend integration works end-to-end
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 12. Property-based tests: Data integrity properties
  - [x] 12.1 Write property test: Tenant Isolation (Property 1)
    - **Property 1: Tenant Isolation**
    - Generate random tenant IDs, create roles in different tenants, verify list returns only matching tenant's roles
    - **Validates: Requirements 1.1**

  - [x] 12.2 Write property test: Business Owner Permission Completeness (Property 3)
    - **Property 3: Business Owner Permission Completeness**
    - Generate random permission sets in the permissions table, verify Business Owner role always returns the complete set
    - **Validates: Requirements 2.3, 6.3**

  - [x] 12.3 Write property test: Role Data Round-Trip (Property 4)
    - **Property 4: Role Data Round-Trip**
    - Generate random valid role names (2–50 chars), descriptions (≤255 chars), and permission ID subsets; create/update then read back; verify data matches
    - **Validates: Requirements 3.1, 4.1, 4.2**

  - [x] 12.4 Write property test: Deletion Cascade (Property 8)
    - **Property 8: Deletion Cascade**
    - Generate roles with random permission assignments, delete them, verify role and role_permissions rows are removed
    - **Validates: Requirements 5.1**

  - [x] 12.5 Write property test: In-Use Deletion Protection (Property 9)
    - **Property 9: In-Use Deletion Protection**
    - Generate roles assigned to N random users (N > 0), attempt deletion, verify rejection with correct user count
    - **Validates: Requirements 5.3**

- [x] 13. Final checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP
- Each task references specific requirements for traceability
- Checkpoints ensure incremental validation
- Property tests validate universal correctness properties from the design document
- The implementation extends existing classes rather than replacing them
- All property tests use PHPUnit with custom random generators (100+ iterations per property)
- Test files go in `tests/Property/` following the existing project convention
