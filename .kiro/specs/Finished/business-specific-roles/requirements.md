# Requirements Document

## Introduction

This feature transforms the roles system from tenant-scoped to business-scoped. Currently, roles are shared across all businesses within a tenant and the "Business Owner" role is identified by the fragile hardcoded `role_id = 1`. This feature introduces a `type` column to identify system roles semantically, adds `business_id` scoping to the roles table, auto-creates a SYSTEM_OWNER role per business, and supports tenant-level TEMPLATE roles that can be cloned into individual businesses. This depends on the "Business Types & Module Activation" spec being completed first.

## Glossary

- **Role_Service**: The backend service responsible for all role CRUD operations, now scoped by business_id instead of tenant_id
- **Role_Repository**: The data access layer for the roles table and related junction tables
- **Auth_Middleware**: The middleware that resolves user identity, business context, role, and permissions for each authenticated request
- **Business_Service**: The service responsible for business creation and management, extended to auto-create the SYSTEM_OWNER role
- **SYSTEM_OWNER_Role**: A system-managed role automatically created per business that always grants all permissions and cannot be edited or deleted
- **CUSTOM_Role**: A user-created role scoped to a single business with explicitly assigned permissions
- **TEMPLATE_Role**: A tenant-level role definition that serves as a blueprint and can be cloned into any business under that tenant
- **Role_Type**: An ENUM column on the roles table with values SYSTEM_OWNER, CUSTOM, and TEMPLATE that identifies the nature of a role
- **Roles_Management_Page**: The existing frontend page for listing, creating, editing, and deleting roles, modified to operate per-business

## Requirements

### Requirement 1: Role Type Column and Business Scoping

**User Story:** As a system architect, I want roles to have a type column and business_id scoping, so that the system can distinguish system roles from custom roles without relying on hardcoded IDs.

#### Acceptance Criteria

1. THE Role_Repository SHALL store roles in the `roles` table with an additional `type` column of ENUM('SYSTEM_OWNER', 'CUSTOM', 'TEMPLATE') NOT NULL DEFAULT 'CUSTOM'
2. THE Role_Repository SHALL store roles with an additional `business_id` column (INT, nullable, FOREIGN KEY to businesses.id) where NULL indicates a tenant-level TEMPLATE role
3. WHEN a role has type SYSTEM_OWNER or CUSTOM, THE Role_Repository SHALL require a non-null business_id value
4. WHEN a role has type TEMPLATE, THE Role_Repository SHALL require a null business_id and a non-null tenant_id value
5. THE roles table SHALL have a unique constraint on (business_id, name) to prevent duplicate role names within the same business
6. THE roles table SHALL have a unique constraint on (tenant_id, name) WHERE type = 'TEMPLATE' to prevent duplicate template names within the same tenant

### Requirement 2: SYSTEM_OWNER Role Behavior

**User Story:** As a business owner, I want a system-managed owner role that always has full permissions, so that business owners always retain complete access without manual permission assignment.

#### Acceptance Criteria

1. WHEN the Auth_Middleware resolves permissions for a user whose role has type SYSTEM_OWNER, THE Auth_Middleware SHALL return all permissions from the permissions table without consulting the role_permissions junction table
2. THE Role_Service SHALL reject any request to update a role with type SYSTEM_OWNER and return an error message "Cannot modify the system owner role"
3. THE Role_Service SHALL reject any request to delete a role with type SYSTEM_OWNER and return an error message "Cannot delete the system owner role"
4. THE SYSTEM_OWNER_Role SHALL have the name "Business Owner" when auto-created
5. WHEN a business has a SYSTEM_OWNER role, THE Role_Repository SHALL enforce that exactly one role with type SYSTEM_OWNER exists per business_id

### Requirement 3: Automatic SYSTEM_OWNER Role Creation on Business Creation

**User Story:** As a business owner, I want a SYSTEM_OWNER role to be automatically created when I create a new business, so that I immediately have full access without manual setup.

#### Acceptance Criteria

1. WHEN a new business is created, THE Business_Service SHALL create a role with name "Business Owner", type SYSTEM_OWNER, and business_id set to the new business ID within the same database transaction
2. WHEN a new business is created, THE Business_Service SHALL assign the SYSTEM_OWNER role of that business to the user who created the business via the user_roles table within the same database transaction
3. IF the SYSTEM_OWNER role creation fails during business creation, THEN THE Business_Service SHALL roll back the entire business creation transaction including the business record itself
4. WHEN the SYSTEM_OWNER role is created, THE Business_Service SHALL set the tenant_id on the role to match the tenant_id of the new business

### Requirement 4: CUSTOM Role Management

**User Story:** As a business owner, I want to create, edit, and delete custom roles for my business, so that I can define granular access levels for my team members.

#### Acceptance Criteria

1. WHEN a CUSTOM role is created, THE Role_Service SHALL accept a name (2-50 characters), optional description (max 255 characters), business_id, and an array of permission IDs
2. WHEN a CUSTOM role is created, THE Role_Service SHALL set the type to CUSTOM and the business_id to the current business context
3. IF a role name already exists within the same business_id, THEN THE Role_Service SHALL reject the creation and return an error message "A role with this name already exists in this business"
4. WHEN a CUSTOM role is updated, THE Role_Service SHALL validate that the role belongs to the current business context and has type CUSTOM before applying changes
5. WHEN a CUSTOM role is deleted, THE Role_Service SHALL verify that no users are currently assigned to that role in the user_roles table and reject deletion with a count of assigned users if any exist
6. WHEN a CUSTOM role is deleted, THE Role_Service SHALL remove all associated role_permissions records within the same transaction
7. THE Role_Service SHALL validate that all provided permission IDs exist in the permissions table before creating or updating a CUSTOM role

### Requirement 5: TEMPLATE Role Management

**User Story:** As a tenant administrator, I want to define template roles at the tenant level, so that business owners can quickly set up standard roles by cloning from templates.

#### Acceptance Criteria

1. WHEN a TEMPLATE role is created, THE Role_Service SHALL store it with type TEMPLATE, business_id as NULL, and tenant_id set to the current tenant
2. WHEN a TEMPLATE role is created, THE Role_Service SHALL accept a name (2-50 characters), optional description (max 255 characters), and an array of permission IDs
3. IF a template role name already exists within the same tenant_id, THEN THE Role_Service SHALL reject the creation and return an error message "A template role with this name already exists"
4. WHEN a business owner requests to clone a TEMPLATE role, THE Role_Service SHALL create a new CUSTOM role in the target business with the same name, description, and permission assignments as the template
5. IF a CUSTOM role with the same name already exists in the target business during cloning, THEN THE Role_Service SHALL append a numeric suffix to the cloned role name (e.g., "Accountant (2)")
6. WHEN a TEMPLATE role is cloned, THE Role_Service SHALL create an independent copy such that subsequent changes to the template do not affect the cloned CUSTOM role

### Requirement 6: Role Service Business Scoping

**User Story:** As a developer, I want all role operations scoped by business_id, so that roles from one business are never visible or accessible from another business.

#### Acceptance Criteria

1. WHEN listing roles, THE Role_Service SHALL return only roles where business_id matches the current business context, plus TEMPLATE roles where tenant_id matches the current tenant
2. WHEN retrieving a single role by ID, THE Role_Service SHALL verify that the role's business_id matches the current business context before returning data
3. IF a role is requested that belongs to a different business_id, THEN THE Role_Service SHALL return null as if the role does not exist
4. THE Role_Service SHALL accept business_id as a required parameter for all role listing and retrieval methods, replacing the current tenant_id parameter
5. WHEN listing roles for a business, THE Role_Service SHALL return TEMPLATE roles in a separate group from business-specific roles to distinguish them in the response

### Requirement 7: API Endpoint Business Scoping

**User Story:** As a frontend developer, I want the roles API to work per-business using the existing X-Business-Id header, so that the roles management page shows only relevant roles.

#### Acceptance Criteria

1. WHEN a GET /roles request is received, THE System SHALL return roles scoped to the business_id from the authenticated AuthContext
2. WHEN a POST /roles request is received, THE System SHALL create the role with business_id from the authenticated AuthContext
3. WHEN a PUT /roles/{id} request is received, THE System SHALL verify the role belongs to the current business before allowing modification
4. WHEN a DELETE /roles/{id} request is received, THE System SHALL verify the role belongs to the current business before allowing deletion
5. WHEN a POST /roles/clone/{templateId} request is received, THE System SHALL clone the specified TEMPLATE role into the current business
6. IF a role operation targets a role that does not belong to the current business, THEN THE System SHALL return HTTP 404 with message "Role not found"

### Requirement 8: Permission Resolution Update

**User Story:** As a system architect, I want permission resolution to use the role type column instead of hardcoded role_id = 1, so that the system correctly identifies business owners regardless of their role's auto-increment ID.

#### Acceptance Criteria

1. WHEN resolving permissions, THE Auth_Middleware SHALL query the role type from the roles table for the user's assigned role in the current business
2. WHEN the role type is SYSTEM_OWNER, THE Auth_Middleware SHALL return all permission codes from the permissions table
3. WHEN the role type is CUSTOM, THE Auth_Middleware SHALL return only permission codes linked via the role_permissions junction table
4. THE Auth_Middleware SHALL remove all references to hardcoded role_id = 1 for determining business owner status
5. THE UserRepository getPermissionsForRole method SHALL accept a role type parameter or resolve the type internally, replacing the `if ($role_id === 1)` check with a type-based check

### Requirement 9: Migration of Existing Data

**User Story:** As a system administrator, I want existing roles migrated to the new schema without data loss, so that current users retain their access after the upgrade.

#### Acceptance Criteria

1. WHEN the migration executes, THE System SHALL add the `type` column to the roles table as ENUM('SYSTEM_OWNER', 'CUSTOM', 'TEMPLATE') NOT NULL DEFAULT 'CUSTOM'
2. WHEN the migration executes, THE System SHALL add the `business_id` column to the roles table as INT nullable with a FOREIGN KEY to businesses.id
3. WHEN the migration executes, THE System SHALL update the role with id = 1 to have type = 'SYSTEM_OWNER'
4. WHEN the migration executes, THE System SHALL update all roles with id != 1 to have type = 'CUSTOM'
5. WHEN the migration executes, THE System SHALL assign business_id to each existing role by finding the most common business_id in user_roles for that role_id
6. IF an existing role has no entries in user_roles, THEN THE System SHALL assign it to the first business in the tenant (by lowest businesses.id)
7. WHEN the migration executes for tenants with multiple businesses, THE System SHALL duplicate the existing SYSTEM_OWNER role (id=1) into each business that has users referencing role_id = 1, creating a new SYSTEM_OWNER role per business and updating user_roles to point to the new business-specific role
8. WHEN the migration completes, THE System SHALL verify that every role has a non-null business_id (except TEMPLATE roles) and then alter the column to add a NOT NULL constraint for SYSTEM_OWNER and CUSTOM types via a CHECK constraint
9. THE migration SHALL execute within a single database transaction, rolling back all changes if any step fails
10. THE migration SHALL use idempotent operations (IF NOT EXISTS, INSERT IGNORE) to be safe for re-execution

### Requirement 10: Roles Management Page Business Scoping

**User Story:** As a business owner, I want the roles management page to show only my business's roles, so that I manage access for my team without seeing other businesses' roles.

#### Acceptance Criteria

1. WHEN the Roles_Management_Page loads, THE System SHALL fetch roles from GET /roles which returns only roles for the current business context
2. THE Roles_Management_Page SHALL display CUSTOM roles in an editable list with options to edit permissions and delete
3. THE Roles_Management_Page SHALL display the SYSTEM_OWNER role as read-only with a visual indicator that it cannot be modified
4. THE Roles_Management_Page SHALL display available TEMPLATE roles in a separate section with a "Clone to Business" action button
5. WHEN a user clicks "Clone to Business" on a TEMPLATE role, THE Roles_Management_Page SHALL call POST /roles/clone/{templateId} and refresh the roles list upon success
6. THE Roles_Management_Page SHALL require the permission "roles.view" to access and "roles.create", "roles.edit", "roles.delete" for respective operations
7. IF a role creation or update fails due to a duplicate name, THEN THE Roles_Management_Page SHALL display the error message from the API response without refreshing the page

### Requirement 11: Backward Compatibility During Transition

**User Story:** As a system administrator, I want the migration to maintain backward compatibility, so that the system continues to function during the rollout period.

#### Acceptance Criteria

1. WHEN the migration adds the business_id column, THE System SHALL set a DEFAULT value that allows existing queries without business_id filters to continue functioning until code is updated
2. THE migration SHALL preserve all existing foreign key relationships in user_roles (user_id, business_id, role_id) without modification to the user_roles table structure
3. WHEN the new code is deployed, THE System SHALL use the type column for SYSTEM_OWNER detection in all permission resolution paths, eliminating all role_id = 1 checks
4. THE System SHALL maintain the existing role_permissions junction table structure without modification, continuing to use it for CUSTOM role permission storage
5. WHEN the fetchAuthContext method in UserRepository builds the roles CTE, THE System SHALL replace the `WHERE ur.role_id = 1` cross-join logic with a type-based check that cross-joins all permissions when the role type is SYSTEM_OWNER

