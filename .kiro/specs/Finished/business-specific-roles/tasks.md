# Implementation Plan: Business-Specific Roles

## Overview

This plan transforms the roles system from tenant-scoped to business-scoped by adding a `type` ENUM column and `business_id` foreign key to the roles table, replacing all hardcoded `role_id = 1` checks with type-based logic, auto-creating SYSTEM_OWNER roles on business creation, and supporting TEMPLATE role cloning. Implementation proceeds from database migration → repository layer → service layer → controller/routes → frontend integration.

## Tasks

- [x] 1. Database migration for role type and business scoping
  - [x] 1.1 Create the migration SQL file `database/migrations/add_role_type_and_business_scoping.sql`
    - Add `type` ENUM('SYSTEM_OWNER', 'CUSTOM', 'TEMPLATE') NOT NULL DEFAULT 'CUSTOM' column to `roles` table
    - Add `business_id` INT NULL column with FOREIGN KEY to `businesses(id)` ON DELETE CASCADE
    - Add CHECK constraint enforcing: SYSTEM_OWNER/CUSTOM require non-null business_id, TEMPLATE requires null business_id
    - Add unique index `uq_roles_business_name` on (business_id, name)
    - Update existing role with id=1 to type='SYSTEM_OWNER'
    - Update all other existing roles to type='CUSTOM'
    - Assign business_id to each existing role based on most common business_id in user_roles
    - For roles with no user_roles entries, assign to the first business in the tenant (lowest businesses.id)
    - For multi-business tenants: duplicate SYSTEM_OWNER role into each business that has users referencing role_id=1, create new per-business SYSTEM_OWNER roles, update user_roles references
    - Wrap all operations in a single transaction with rollback on failure
    - Use idempotent operations (IF NOT EXISTS, conditional checks) for safe re-execution
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 9.1, 9.2, 9.3, 9.4, 9.5, 9.6, 9.7, 9.8, 9.9, 9.10, 11.1, 11.2_

- [x] 2. Update RoleRepository for business-scoped queries
  - [x] 2.1 Add new business-scoped query methods to `src/Repository/RoleRepository.php`
    - Add `findAllByBusiness(int $businessId): array` — replaces `findAllByTenant`, queries roles WHERE business_id = :businessId
    - Add `findTemplatesByTenant(int $tenantId): array` — queries roles WHERE type='TEMPLATE' AND tenant_id = :tenantId
    - Add `findByIdAndBusiness(int $id, int $businessId): ?array` — fetches role verifying business ownership
    - Add `existsByNameAndBusiness(string $name, int $businessId, ?int $excludeId = null): bool`
    - Add `existsTemplateByNameAndTenant(string $name, int $tenantId, ?int $excludeId = null): bool`
    - Add `insertWithType(int $tenantId, ?int $businessId, string $name, ?string $description, string $type): int`
    - Add `findRoleType(int $roleId): ?string` — SELECT type FROM roles WHERE id = :roleId
    - Add `countSystemOwnerRoles(int $businessId): int`
    - Add `countUsersWithRoleInBusiness(int $roleId, int $businessId): int`
    - _Requirements: 1.2, 1.3, 1.4, 1.5, 1.6, 6.1, 6.2, 6.4_

  - [x] 2.2 Update `findPermissionsByRoleId` to use type-based check instead of `$roleId === 1`
    - Query role type via `findRoleType($roleId)`
    - If type is 'SYSTEM_OWNER', return all permissions
    - Otherwise, return permissions via role_permissions join
    - Remove the hardcoded `if ($roleId === 1)` check
    - _Requirements: 8.1, 8.2, 8.3, 8.4_

  - [ ]* 2.3 Write property tests for RoleRepository type-based permission resolution
    - **Property 2: SYSTEM_OWNER grants all permissions**
    - **Validates: Requirements 2.1, 8.2, 8.3**

- [x] 3. Update UserRepository for type-based permission resolution
  - [x] 3.1 Update `getPermissionsForRole` in `src/Repository/UserRepository.php`
    - Replace `if ($role_id === 1)` with a query to get role type from roles table
    - If type = 'SYSTEM_OWNER', return all permission codes
    - If type = 'CUSTOM', return permission codes via role_permissions join
    - _Requirements: 8.1, 8.2, 8.3, 8.4, 8.5_

  - [x] 3.2 Update `fetchAuthContext` CTE in `src/Repository/UserRepository.php`
    - In the `role_permissions_cte`, replace `WHERE ur.role_id = 1` with a JOIN to roles table checking `r.type = 'SYSTEM_OWNER'`
    - Ensure the CROSS JOIN of all permissions triggers on type='SYSTEM_OWNER' instead of role_id=1
    - _Requirements: 8.4, 11.5_

  - [ ]* 3.3 Write property tests for UserRepository permission resolution
    - **Property 2: SYSTEM_OWNER grants all permissions (UserRepository path)**
    - **Validates: Requirements 8.2, 8.3, 11.5**

- [x] 4. Checkpoint - Ensure permission resolution works
  - Ensure all tests pass, ask the user if questions arise.

- [x] 5. Update RoleService for business-scoped operations
  - [x] 5.1 Refactor `RoleService` to accept business_id and implement type-based guards
    - Change `getAllForTenant` to `getAllForBusiness(int $businessId, int $tenantId): array` — returns business roles + tenant templates in separate groups
    - Change `getById(int $id, int $tenantId)` to `getById(int $id, int $businessId): ?array` — verify business ownership
    - Change `create` to accept `int $businessId, int $tenantId, string $name, ?string $description, array $permissionIds` — sets type='CUSTOM' and business_id
    - Change `update` to accept `int $id, int $businessId, ...` — verify role belongs to business AND type='CUSTOM' before allowing changes
    - Change `delete` to accept `int $id, int $businessId` — verify role belongs to business AND type='CUSTOM', check user count with `countUsersWithRoleInBusiness`
    - Replace `if ($id === 1)` checks with type-based checks using `findRoleType()`
    - Update duplicate name check to use `existsByNameAndBusiness` instead of `existsByNameAndTenant`
    - Update error messages: "Cannot modify the system owner role" and "Cannot delete the system owner role"
    - _Requirements: 2.2, 2.3, 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 4.7, 6.1, 6.2, 6.3, 6.4, 6.5_

  - [ ]* 5.2 Write property tests for SYSTEM_OWNER immutability
    - **Property 3: SYSTEM_OWNER immutability**
    - **Validates: Requirements 2.2, 2.3**

  - [ ]* 5.3 Write property tests for role name uniqueness within business
    - **Property 6: Role name uniqueness within business**
    - **Validates: Requirements 1.5, 4.3**

  - [ ]* 5.4 Write property tests for role name validation
    - **Property 8: Role name validation**
    - **Validates: Requirements 4.1, 5.2**

  - [ ]* 5.5 Write property tests for permission ID validation
    - **Property 9: Permission ID validation**
    - **Validates: Requirements 4.7**

  - [ ]* 5.6 Write property tests for deletion blocked when users assigned
    - **Property 10: Deletion blocked when users assigned**
    - **Validates: Requirements 4.5**

- [x] 6. Implement SYSTEM_OWNER role creation in BusinessService
  - [x] 6.1 Add `createSystemOwnerRole` method to `RoleService`
    - Create method `createSystemOwnerRole(int $businessId, int $tenantId): int`
    - Insert role with name "Business Owner", type 'SYSTEM_OWNER', business_id, tenant_id
    - Verify no existing SYSTEM_OWNER for this business before creating
    - _Requirements: 2.4, 2.5, 3.1, 3.4_

  - [x] 6.2 Extend `BusinessService::create()` to auto-create SYSTEM_OWNER role and assign to creator
    - After creating the business record and default account, call `RoleService::createSystemOwnerRole()`
    - Assign the new SYSTEM_OWNER role to the creating user via `UserRepository::assignRole()`
    - All within the existing transaction — if role creation or assignment fails, entire business creation rolls back
    - _Requirements: 3.1, 3.2, 3.3, 3.4_

  - [ ]* 6.3 Write property tests for business creation producing assigned SYSTEM_OWNER
    - **Property 5: Business creation produces assigned SYSTEM_OWNER**
    - **Validates: Requirements 3.1, 3.2, 3.4**

  - [ ]* 6.4 Write property tests for exactly one SYSTEM_OWNER per business
    - **Property 4: Exactly one SYSTEM_OWNER per business**
    - **Validates: Requirements 2.5**

- [x] 7. Implement TEMPLATE role management and cloning
  - [x] 7.1 Add TEMPLATE role CRUD methods to `RoleService`
    - Add `createTemplate(int $tenantId, string $name, ?string $description, array $permissionIds): int`
    - Add `updateTemplate(int $id, int $tenantId, string $name, ?string $description, array $permissionIds): void`
    - Add `deleteTemplate(int $id, int $tenantId): void`
    - Validate name uniqueness within tenant using `existsTemplateByNameAndTenant`
    - Apply same name length (2-50) and description length (≤255) validations
    - Validate permission IDs exist before creating/updating
    - _Requirements: 5.1, 5.2, 5.3_

  - [x] 7.2 Add `cloneTemplate` method to `RoleService`
    - Add `cloneTemplate(int $templateId, int $businessId, int $tenantId): int`
    - Verify the source role exists and has type='TEMPLATE' and matching tenant_id
    - If a CUSTOM role with the same name exists in the target business, append numeric suffix "(2)", "(3)", etc.
    - Create independent CUSTOM role copy with same name/description/permissions
    - Execute within a single transaction (read template + insert role + sync permissions)
    - _Requirements: 5.4, 5.5, 5.6_

  - [ ]* 7.3 Write property tests for clone preserving template data
    - **Property 12: Clone preserves template data**
    - **Validates: Requirements 5.4, 5.6**

  - [ ]* 7.4 Write property tests for clone name conflict resolution
    - **Property 13: Clone name conflict resolution**
    - **Validates: Requirements 5.5**

  - [ ]* 7.5 Write property tests for template name uniqueness within tenant
    - **Property 7: Template name uniqueness within tenant**
    - **Validates: Requirements 1.6, 5.3**

- [x] 8. Checkpoint - Ensure service layer tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [x] 9. Update RolesController and API routes for business scoping
  - [x] 9.1 Refactor `RolesController` to use business_id from AuthContext
    - Update `index()` to call `getAllForBusiness($ctx->business_id, $ctx->tenant_id)` instead of `getAllForTenant($ctx->tenant_id)`
    - Remove the `array_map` that sets `is_system` based on `$role['id'] === 1` — use type field instead
    - Update `show()` to call `getById($id, $ctx->business_id)` — returns null if role doesn't belong to business
    - Update `store()` to pass `$ctx->business_id` to `create()`
    - Update `update()` to pass `$ctx->business_id` — service handles type check internally
    - Update `delete()` to pass `$ctx->business_id` — service handles type check internally
    - Return HTTP 404 "Role not found" when role doesn't belong to current business
    - _Requirements: 7.1, 7.2, 7.3, 7.4, 7.6_

  - [x] 9.2 Add `clone` endpoint to `RolesController` and register route
    - Add `clone(int $templateId): void` method to RolesController
    - Call `$this->service->cloneTemplate($templateId, $ctx->business_id, $ctx->tenant_id)`
    - Register route: `POST /roles/clone/{id}` in `public/api/routes/roles.php` with permission `['roles.create']`
    - Place the static route BEFORE the `/{id}` dynamic routes to avoid routing conflicts
    - _Requirements: 7.5_

  - [ ]* 9.3 Write property tests for role listing scoped to business and tenant templates
    - **Property 14: Role listing scoped to business and tenant templates**
    - **Validates: Requirements 6.1**

  - [ ]* 9.4 Write property tests for cross-business role retrieval returns null
    - **Property 15: Cross-business role retrieval returns null**
    - **Validates: Requirements 6.2, 6.3**

- [x] 10. Update Roles Management Page frontend
  - [x] 10.1 Update the roles management page to work with business-scoped API
    - Update the GET /roles call — response now includes business roles and templates in separate groups
    - Display CUSTOM roles in an editable list with edit/delete actions
    - Display the SYSTEM_OWNER role as read-only with a visual indicator (e.g., lock icon, disabled buttons)
    - Display TEMPLATE roles in a separate section with a "Clone to Business" action button
    - On "Clone to Business" click, call POST /roles/clone/{templateId} and refresh the list on success
    - Handle duplicate name errors from API (display inline error without page refresh)
    - Ensure permission checks: require 'roles.view' to access, 'roles.create'/'roles.edit'/'roles.delete' for respective operations
    - _Requirements: 10.1, 10.2, 10.3, 10.4, 10.5, 10.6, 10.7_

- [ ] 11. Implement role type scoping invariant validation
  - [ ]* 11.1 Write property tests for role type scoping invariant
    - **Property 1: Role type scoping invariant**
    - **Validates: Requirements 1.3, 1.4**

  - [ ]* 11.2 Write property tests for deletion removes associated permissions
    - **Property 11: Deletion removes associated permissions**
    - **Validates: Requirements 4.6**

- [x] 12. Final checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP
- Each task references specific requirements for traceability
- Checkpoints ensure incremental validation
- Property tests validate universal correctness properties using PHPUnit with custom DataProvider generators (100+ randomized iterations per property)
- Unit tests validate specific examples and edge cases
- The migration (task 1.1) should be tested on a copy of the database before running on production
- All existing `role_id = 1` checks must be eliminated — tasks 2.2, 3.1, 3.2, and 5.1 collectively handle this
- The frontend task (10.1) depends on all backend tasks being complete

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1"] },
    { "id": 1, "tasks": ["2.1", "2.2"] },
    { "id": 2, "tasks": ["2.3", "3.1", "3.2"] },
    { "id": 3, "tasks": ["3.3", "5.1"] },
    { "id": 4, "tasks": ["5.2", "5.3", "5.4", "5.5", "5.6", "6.1"] },
    { "id": 5, "tasks": ["6.2", "7.1"] },
    { "id": 6, "tasks": ["6.3", "6.4", "7.2"] },
    { "id": 7, "tasks": ["7.3", "7.4", "7.5", "9.1"] },
    { "id": 8, "tasks": ["9.2"] },
    { "id": 9, "tasks": ["9.3", "9.4", "10.1"] },
    { "id": 10, "tasks": ["11.1", "11.2"] }
  ]
}
```
