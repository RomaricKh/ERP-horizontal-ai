# Implementation Plan: Business Types & Module Activation

## Overview

This plan implements a module activation system that controls ERP feature access per business. The implementation follows a dependency-driven order: database schema first, then repositories, services, middleware, controllers, and finally frontend. Each business gets a type (General, Retail, Wholesale, Manufacturing, Service) determining default module access, with per-business overrides for flexibility.

## Tasks

- [x] 1. Database migration and seed data
  - [x] 1.1 Create the migration SQL file with all new tables and seed data
    - Create `database/migrations/add_business_types_module_activation.sql`
    - Create `modules` table with columns: id, code, name, icon, route_prefix, permission_prefixes (JSON), category (ENUM), sort_order, created_at
    - Create `business_types` table with columns: id, code, name, description, is_default, created_at
    - Create `business_type_modules` junction table with foreign keys to business_types and modules
    - Create `business_module_overrides` table with columns: id, business_id, module_id, action ENUM('activate','deactivate'), created_at, created_by
    - ALTER `businesses` table to add `business_type_id` column (INT NOT NULL DEFAULT 1, FK to business_types)
    - Seed all 20 module definitions with correct route_prefix, permission_prefixes, category, and sort_order
    - Seed 5 business types (general, retail, wholesale, manufacturing, service)
    - Seed business_type_modules junction with correct mappings per type
    - Seed `settings.modules` permission and assign to business_owner role
    - Use INSERT IGNORE and IF NOT EXISTS for idempotent re-runs
    - Update all existing businesses to business_type_id = 1 (general)
    - _Requirements: 1.1, 1.2, 1.3, 2.1, 2.2, 2.3, 2.6, 2.7, 2.8, 2.9, 2.10, 2.11, 3.5, 3.6, 12.1, 12.2, 12.3, 12.4, 12.5, 12.7, 12.8_

- [x] 2. Repository layer
  - [x] 2.1 Implement ModuleRepository
    - Create `src/Repository/ModuleRepository.php`
    - Implement `getAll()`: returns all modules ordered by sort_order ASC
    - Implement `getByCode(string $code): ?array`: returns module or null
    - Implement `getById(int $id): ?array`: returns module or null
    - Implement `getModulesForBusinessType(int $businessTypeId): array`: joins business_type_modules
    - Implement `getRoutePrefixMap(): array`: returns [route_prefix => module_code] mapping
    - Implement `getPermissionPrefixMap(): array`: returns [module_code => [prefixes]] mapping
    - _Requirements: 1.1, 1.3, 1.4, 1.5_

  - [x] 2.2 Implement BusinessTypeRepository
    - Create `src/Repository/BusinessTypeRepository.php`
    - Implement `getAll(): array`: returns all business types
    - Implement `getById(int $id): ?array`: returns type or null
    - Implement `getByCode(string $code): ?array`: returns type or null
    - Implement `getDefault(): array`: returns the row with is_default = 1
    - Implement `getModuleCodesForType(int $businessTypeId): array`: returns flat array of module codes
    - _Requirements: 2.1, 2.2, 2.3, 2.4_

  - [x] 2.3 Implement BusinessModuleOverrideRepository
    - Create `src/Repository/BusinessModuleOverrideRepository.php`
    - Implement `getOverridesForBusiness(int $businessId): array`: returns [module_code => action] map
    - Implement `upsert(int $businessId, int $moduleId, string $action, int $createdBy): void`: INSERT ON DUPLICATE KEY UPDATE
    - Implement `delete(int $businessId, int $moduleId): void`
    - Implement `deleteRedundantOverrides(int $businessId, array $typeModuleIds): int`: removes activate overrides for modules in type defaults and deactivate overrides for modules not in type defaults
    - Implement `exists(int $businessId, int $moduleId): bool`
    - _Requirements: 5.1, 5.7_

  - [ ]* 2.4 Write unit tests for repositories
    - Test ModuleRepository: getAll ordering, getByCode returns null for non-existent, getRoutePrefixMap correctness
    - Test BusinessTypeRepository: getDefault returns general, getById returns null for invalid
    - Test BusinessModuleOverrideRepository: upsert replaces existing, delete removes record
    - _Requirements: 1.4, 1.5, 1.6_

- [x] 3. Checkpoint - Ensure repository layer tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [x] 4. Service layer
  - [x] 4.1 Implement ModuleActivationService
    - Create `src/Services/ModuleActivationService.php`
    - Implement `getActiveModules(int $businessId): array`: resolves (type_defaults ∪ activate_overrides) \ deactivate_overrides with per-request static cache
    - Implement `isModuleActive(string $moduleCode, int $businessId): bool`: checks against cached active modules, returns false for non-existent business/module
    - Implement `filterPermissionsByActiveModules(array $permissions, int $businessId): array`: keeps permissions belonging to active modules + unmapped permissions (fail-open)
    - Implement `clearCache(): void`: resets static cache
    - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.5, 6.6, 6.7, 8.1, 8.2, 8.3_

  - [ ]* 4.2 Write property test for ModuleActivationService - Resolution Set Algebra
    - **Property 1: Module Resolution Set Algebra**
    - For any business with type defaults D, activate overrides A, deactivate overrides R: resolved modules = (D ∪ A) \ R
    - Use randomized module sets as data provider with 100+ iterations
    - **Validates: Requirements 6.1, 6.2, 5.2, 5.3**

  - [ ]* 4.3 Write property test for ModuleActivationService - Permission Filtering Completeness
    - **Property 3: Permission Filtering Completeness**
    - For any permission set and active module set: filtered set contains exactly permissions matching active module prefixes + unmatched permissions
    - Use randomized permission arrays and module sets with 100+ iterations
    - **Validates: Requirements 8.1, 8.2, 8.3**

  - [x] 4.4 Implement BusinessTypeService
    - Create `src/Services/BusinessTypeService.php`
    - Implement `getAllTypes(): array`: returns all types with module codes
    - Implement `getTypeById(int $id): ?array`: validates type existence
    - Implement `changeBusinessType(int $businessId, int $newTypeId, bool $force, int $userId): array`: handles warning/force flow, transaction, override cleanup, audit logging
    - Implement `getAffectedModulesOnTypeChange(int $businessId, int $newTypeId): array`: returns modules with data that would be deactivated
    - Implement `getModuleRecordCount(string $moduleCode, int $businessId): int`: counts records in module's tables for a business
    - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 4.7, 5.4, 5.5, 5.6_

  - [ ]* 4.5 Write property test for BusinessTypeService - Core Module Protection
    - **Property 7: Core Module Protection**
    - For any of the core modules (auth, users, businesses): a deactivation override request is always rejected
    - **Validates: Requirements 5.4**

  - [ ]* 4.6 Write property test for BusinessTypeService - Redundant Override Cleanup
    - **Property 9: Redundant Override Cleanup on Type Change**
    - After type change from T1 to T2: activate overrides for modules in T2 defaults are removed, deactivate overrides for modules not in T2 defaults are removed
    - **Validates: Requirements 4.5**

  - [ ]* 4.7 Write property test for BusinessTypeService - Data Warning Before Deactivation
    - **Property 11: Data Warning Before Deactivation**
    - For any module with N > 0 records: deactivation without force=true returns warning with module code and count N
    - **Validates: Requirements 4.3, 5.5**

- [x] 5. Checkpoint - Ensure service layer tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [x] 6. Middleware layer
  - [x] 6.1 Implement ModuleGateMiddleware
    - Create `src/Middleware/ModuleGateMiddleware.php`
    - Implement `handle(string $uri): void`: extracts first URI segment, maps to module code via routePrefixMap, allows core modules and unrecognized prefixes, blocks inactive modules with 403
    - Implement `extractRoutePrefix(string $uri): string`: parses first path segment
    - Load routePrefixMap from ModuleRepository on construction
    - Define CORE_MODULES constant: ['auth', 'users', 'businesses']
    - _Requirements: 7.1, 7.2, 7.3, 7.4, 7.5, 7.6, 7.7, 11.4_

  - [ ]* 6.2 Write property test for ModuleGateMiddleware
    - **Property 2: Module Gate Middleware Correctness**
    - For any URI: request allowed iff first segment doesn't match any route_prefix, OR matched module is core, OR matched module is active
    - **Validates: Requirements 7.1, 7.2, 7.3, 7.4, 7.5**

  - [x] 6.3 Modify AuthMiddleware to filter permissions by active modules
    - In `src/Middleware/AuthMiddleware.php`, after resolving permissions from role
    - Instantiate ModuleActivationService and call `filterPermissionsByActiveModules($permissions, $businessId)`
    - Store filtered permissions in AuthContext
    - _Requirements: 8.1, 8.2, 8.3, 8.4, 8.5_

  - [x] 6.4 Modify Router to invoke ModuleGateMiddleware after AuthMiddleware
    - In `src/Support/Router.php` `dispatch()` method
    - After `AuthMiddleware->handle()`, instantiate `ModuleGateMiddleware` and call `handle($uri)`
    - Import `App\Config\Database` for the PDO connection
    - _Requirements: 7.6_

- [x] 7. Checkpoint - Ensure middleware tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [x] 8. Controller and route layer
  - [x] 8.1 Implement ModulesController
    - Create `src/Controllers/ModulesController.php` extending BaseController
    - Implement `index()`: GET /settings/modules — returns all modules with activation status (type_default, override_activate, override_deactivate, inactive) for current business
    - Implement `toggleOverride()`: POST /settings/modules/override — validates module_code, rejects core module deactivation, checks data warning, creates/replaces override
    - Implement `removeOverride(string $moduleCode)`: DELETE /settings/modules/override/{code} — removes override for current business
    - _Requirements: 10.1, 10.2, 10.3, 10.4, 10.5, 5.4, 5.5, 5.6, 5.7, 5.8_

  - [x] 8.2 Implement BusinessTypesController
    - Create `src/Controllers/BusinessTypesController.php` extending BaseController
    - Implement `index()`: GET /business-types — returns all business types with their module codes
    - _Requirements: 2.1, 2.2_

  - [x] 8.3 Modify BusinessesController for business type support
    - In `store()`: pass `business_type_id` from request body to BusinessService::create()
    - In `update()`: pass `business_type_id` and `force` from request body to handle type changes
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 4.1, 4.2, 4.3, 4.4_

  - [x] 8.4 Modify AuthController to include active_modules in /auth/me response
    - In the `me()` method, instantiate ModuleActivationService
    - Call `getActiveModules($businessId)` and include result as `active_modules` in response payload
    - _Requirements: 9.1_

  - [x] 8.5 Register new routes in settings.php and index.php
    - Append module management routes to `public/api/routes/settings.php`: GET /settings/modules, POST /settings/modules/override, DELETE /settings/modules/override/{code}
    - Add business-types route to `public/api/routes/businesses.php`: GET /business-types
    - _Requirements: 10.5_

  - [ ]* 8.6 Write unit tests for ModulesController and BusinessTypesController
    - Test index returns correct module statuses
    - Test toggleOverride rejects core module deactivation
    - Test toggleOverride returns warning for modules with data
    - Test removeOverride removes the override
    - Test BusinessTypesController returns all types
    - _Requirements: 5.4, 5.5, 10.1, 10.2, 10.3_

- [x] 9. Checkpoint - Ensure controller and route tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [x] 10. Modify BusinessService for type assignment
  - [x] 10.1 Update BusinessService::create() to handle business_type_id
    - Accept optional `business_type_id` in data array
    - If null/missing, resolve default type via BusinessTypeRepository::getDefault()
    - If provided, validate via BusinessTypeRepository::getById() — throw InvalidArgumentException if not found
    - Pass resolved type_id to BusinessRepository::create()
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 2.4, 2.5_

  - [x] 10.2 Update BusinessService::update() to delegate type changes
    - If `business_type_id` is present in data, delegate to BusinessTypeService::changeBusinessType()
    - Pass `force` flag from data
    - Return warning response if type change returns warning
    - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 4.7_

  - [x] 10.3 Update BusinessRepository::create() to include business_type_id column
    - Add `business_type_id` to the INSERT statement
    - Add parameter binding for business_type_id
    - _Requirements: 3.5_

  - [ ]* 10.4 Write property test for default type assignment
    - **Property 5: Default Type Assignment**
    - For any business creation without business_type_id: the created business has business_type_id = general type id
    - **Validates: Requirements 2.4, 3.2, 3.3**

  - [ ]* 10.5 Write property test for type ID validation
    - **Property 6: Type ID Validation**
    - For any integer not in business_types.id: creation/change request is rejected with error
    - **Validates: Requirements 2.5, 3.4, 4.1, 4.2**

- [x] 11. Checkpoint - Ensure business service tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [x] 12. Frontend - Module service and sidebar filtering
  - [x] 12.1 Create ModuleService.js
    - Create `public/assets/js/services/ModuleService.js`
    - Implement `fetchActiveModules()`: fetches from /settings/modules endpoint
    - Implement `getActiveModules()`: returns cached module list
    - Implement `setActiveModules(modules)`: sets cache from /auth/me response
    - Implement `isModuleActive(moduleCode)`: checks if module is in active list, returns true if no moduleCode
    - Implement `invalidateModuleCache()`: clears cache for re-fetch
    - _Requirements: 9.1, 9.2, 9.3_

  - [x] 12.2 Add module field to appShell.config.js menu items
    - Add `module` property to each menu item matching the module code from the registry
    - Items without a module field remain always visible
    - _Requirements: 9.2, 9.3_

  - [x] 12.3 Modify appShell.js renderMenu() to filter by active modules
    - Import `isModuleActive` from ModuleService.js
    - In renderMenu(), skip items where `isModuleActive(entry.module)` returns false
    - For groups, filter children by both permission and module activation
    - _Requirements: 9.2, 9.3, 9.5_

  - [x] 12.4 Update auth.js to store active_modules from /auth/me response
    - After successful /auth/me call, pass `active_modules` array to `setActiveModules()`
    - _Requirements: 9.1_

  - [x] 12.5 Implement "Module not available" message for inactive module direct navigation
    - When router navigates to a page whose module is inactive, display a "Module not available" message in the content area
    - Do not redirect the user away from the URL
    - _Requirements: 9.4_

  - [ ]* 12.6 Write property test for sidebar filtering (JS unit test)
    - **Property 4: Sidebar Filtering Consistency**
    - For any menu item config and active module set: item rendered iff no module field OR module in active set
    - **Validates: Requirements 9.2, 9.3**

- [x] 13. Frontend - Modules Settings Page
  - [x] 13.1 Create modules settings page (modules.js)
    - Create `public/assets/js/pages/Settings/modules.js` following render/mount/unmount pattern
    - Display all modules grouped by category (core, operations, finance, other)
    - Show status labels: "Active (type default)", "Active (override)", "Inactive (type default)", "Inactive (override)"
    - Core modules (auth, users, businesses) shown as non-toggleable
    - Toggle switches for non-core modules to create activate/deactivate overrides
    - _Requirements: 10.1, 10.2, 10.3_

  - [x] 13.2 Implement confirmation dialog for deactivation with data
    - When toggling a module to inactive, call API to check for existing data
    - If warning returned, show confirmation dialog with module name and record count
    - Only send force=true on user confirmation
    - _Requirements: 10.4_

  - [x] 13.3 Implement sidebar refresh after module toggle
    - After successful override save, call `invalidateModuleCache()` then `fetchActiveModules()`
    - Re-render sidebar menu within 2 seconds
    - On error, revert toggle to previous state and show error message
    - _Requirements: 10.6, 10.7, 9.6_

  - [x] 13.4 Register modules settings page route in SPA router
    - Add route for /settings/modules in the SPA router configuration
    - Wire navigation from settings menu
    - _Requirements: 10.5_

- [x] 14. Final checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP
- Each task references specific requirements for traceability
- Checkpoints ensure incremental validation
- Property tests validate universal correctness properties from the design document
- Unit tests validate specific examples and edge cases
- The migration (task 1.1) must run before any other task since all backend code depends on the schema
- PHP 7.4 compatibility: use `@dataProvider` with randomized generators instead of dedicated PBT libraries
- Frontend uses Vanilla JS ES6 modules with the existing render/mount/unmount SPA pattern

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1"] },
    { "id": 1, "tasks": ["2.1", "2.2", "2.3"] },
    { "id": 2, "tasks": ["2.4", "4.1", "4.4"] },
    { "id": 3, "tasks": ["4.2", "4.3", "4.5", "4.6", "4.7", "6.1"] },
    { "id": 4, "tasks": ["6.2", "6.3", "6.4"] },
    { "id": 5, "tasks": ["8.1", "8.2", "8.3", "8.4", "8.5"] },
    { "id": 6, "tasks": ["8.6", "10.1", "10.2", "10.3"] },
    { "id": 7, "tasks": ["10.4", "10.5", "12.1", "12.2"] },
    { "id": 8, "tasks": ["12.3", "12.4", "12.5", "12.6"] },
    { "id": 9, "tasks": ["13.1", "13.2"] },
    { "id": 10, "tasks": ["13.3", "13.4"] }
  ]
}
```
