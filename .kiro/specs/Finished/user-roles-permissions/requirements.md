# Requirements Document

## Introduction

This feature adds a Roles & Permissions management interface to the ERP system. Users with the appropriate permission can create custom roles, assign permissions to those roles, edit existing roles, and delete roles that are no longer needed. The built-in Business_Owner role is protected and always retains all permissions.

## Glossary

- **Role_Manager**: The UI page and associated backend logic responsible for listing, creating, editing, and deleting roles
- **Role**: A named grouping of permissions scoped to a tenant, stored in the `roles` table
- **Permission**: A discrete access right identified by a dot-notation code (e.g. `po.create`), stored in the `permissions` table
- **Business_Owner_Role**: The system-protected role (role_id = 1) that always holds all permissions and cannot be modified or deleted
- **Tenant**: The organizational boundary that scopes roles; derived from the current business context
- **Permission_Group**: A logical grouping of permissions by module (e.g. Purchase Orders, Expenses) used for display purposes in the UI

## Requirements

### Requirement 1: List Roles

**User Story:** As a user with the `roles.view` permission, I want to see all roles for my tenant, so that I can understand the current role structure.

#### Acceptance Criteria

1. WHEN the user navigates to the Roles page, THE Role_Manager SHALL display a table of all roles belonging to the current tenant
2. THE Role_Manager SHALL display the role name and description for each role in the list
3. THE Role_Manager SHALL visually indicate which role is the Business_Owner_Role
4. WHEN the roles list is displayed, THE Role_Manager SHALL disable edit and delete actions for the Business_Owner_Role

### Requirement 2: View Role Permissions

**User Story:** As a user with the `roles.view` permission, I want to view the permissions assigned to a role, so that I can understand what access a role grants.

#### Acceptance Criteria

1. WHEN the user selects a role to view, THE Role_Manager SHALL display all permissions assigned to that role grouped by Permission_Group
2. THE Role_Manager SHALL display each permission with its human-readable name and code
3. WHEN the user views the Business_Owner_Role, THE Role_Manager SHALL show all permissions as selected and indicate they cannot be changed

### Requirement 3: Create Role

**User Story:** As a user with the `roles.create` permission, I want to create a new role with a name, description, and selected permissions, so that I can define custom access levels for my team.

#### Acceptance Criteria

1. WHEN the user submits a valid role creation form, THE Role_Manager SHALL create a new role scoped to the current tenant
2. THE Role_Manager SHALL require a role name between 2 and 50 characters
3. THE Role_Manager SHALL accept an optional description up to 255 characters
4. THE Role_Manager SHALL display all available permissions grouped by Permission_Group with checkboxes for selection
5. WHEN the user submits the form without selecting any permissions, THE Role_Manager SHALL create the role with zero permissions
6. IF a role with the same name already exists for the tenant, THEN THE Role_Manager SHALL reject the creation and display a duplicate name error
7. WHEN a role is created successfully, THE Role_Manager SHALL refresh the roles list and display a success message

### Requirement 4: Edit Role

**User Story:** As a user with the `roles.edit` permission, I want to modify an existing role's name, description, and permissions, so that I can adjust access levels as business needs change.

#### Acceptance Criteria

1. WHEN the user opens a role for editing, THE Role_Manager SHALL populate the form with the current role name, description, and selected permissions
2. WHEN the user submits valid changes, THE Role_Manager SHALL update the role name, description, and permission assignments
3. IF the user attempts to edit the Business_Owner_Role, THEN THE Role_Manager SHALL reject the request and display a protected role error
4. IF a role name conflict exists with another role in the same tenant, THEN THE Role_Manager SHALL reject the update and display a duplicate name error
5. WHEN a role is updated successfully, THE Role_Manager SHALL refresh the roles list and display a success message

### Requirement 5: Delete Role

**User Story:** As a user with the `roles.delete` permission, I want to delete a role that is no longer needed, so that I can keep the role list clean and relevant.

#### Acceptance Criteria

1. WHEN the user confirms deletion of a role, THE Role_Manager SHALL remove the role and its permission assignments
2. IF the user attempts to delete the Business_Owner_Role, THEN THE Role_Manager SHALL reject the request and display a protected role error
3. IF the role is currently assigned to one or more users, THEN THE Role_Manager SHALL reject the deletion and display an in-use error indicating the number of affected users
4. WHEN a role is deleted successfully, THE Role_Manager SHALL refresh the roles list and display a success message
5. THE Role_Manager SHALL require a confirmation dialog before executing the deletion

### Requirement 6: Business Owner Protection

**User Story:** As a system administrator, I want the Business_Owner_Role to always retain all permissions and remain unmodifiable, so that there is always a role with full access.

#### Acceptance Criteria

1. THE Role_Manager SHALL prevent any modification to the Business_Owner_Role name, description, or permissions through the API
2. THE Role_Manager SHALL prevent deletion of the Business_Owner_Role through the API
3. WHEN a new permission is added to the system, THE Role_Manager SHALL ensure the Business_Owner_Role automatically includes the new permission
4. THE Role_Manager SHALL return a 403 status code when an edit or delete request targets the Business_Owner_Role

### Requirement 7: Permission Grouping Display

**User Story:** As a user managing roles, I want permissions displayed in logical groups by module, so that I can quickly find and select relevant permissions.

#### Acceptance Criteria

1. THE Role_Manager SHALL group permissions by their module prefix (the portion before the dot in the permission code)
2. THE Role_Manager SHALL display a "select all" toggle for each Permission_Group
3. WHEN the user toggles "select all" for a Permission_Group, THE Role_Manager SHALL select or deselect all permissions within that group
4. THE Role_Manager SHALL display Permission_Group names using human-readable labels (e.g. "Purchase Orders" for the `po` prefix)

### Requirement 8: Roles Management Permission

**User Story:** As a system designer, I want role management gated behind dedicated permissions, so that only authorized users can modify the role structure.

#### Acceptance Criteria

1. THE Role_Manager SHALL require the `roles.view` permission to access the roles list and view role details
2. THE Role_Manager SHALL require the `roles.create` permission to create new roles
3. THE Role_Manager SHALL require the `roles.edit` permission to modify existing roles
4. THE Role_Manager SHALL require the `roles.delete` permission to remove roles
5. WHEN a user without the required permission attempts a roles management action, THE Role_Manager SHALL return a 403 status code
