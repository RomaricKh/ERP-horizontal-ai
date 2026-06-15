# Requirements Document

## Introduction

This feature activates comprehensive activity logging across the ERP system so that all user actions are audited and viewable on the Activity Log page. The system already has an `AuditService`, `AuditRepository`, `ActivityLogController`, and a frontend Activity Log page in place. Most services already call `AuditService->log()` for CRUD operations. The goal is to ensure complete coverage of all auditable actions and enhance the Activity Log page for effective review and monitoring.

## Glossary

- **Audit_System**: The backend subsystem responsible for recording, storing, and retrieving audit log entries (composed of `AuditService`, `AuditRepository`, and `ActivityLogController`).
- **Activity_Log_Page**: The frontend page (`#/activityLog`) that displays audit log entries to authorized users.
- **Event_Type**: A classification label for an audit entry (e.g., CREATE, UPDATE, DELETE, LOGIN, LOGOUT, LOGIN_FAILED, SECURITY).
- **Audit_Entry**: A single record in the `audit_logs` table capturing who did what, when, and to which record.
- **Actor**: The authenticated user performing an action in the system.

## Requirements

### Requirement 1: Universal Action Auditing

**User Story:** As a business owner, I want every significant action in the system to be automatically recorded, so that I have a complete audit trail for accountability and compliance.

#### Acceptance Criteria

1. WHEN a user creates a record in any module (Businesses, Users, People, Items, Expenses, Salaries, Payments, Collections, Purchase Orders, Sales Invoices, Accounts, Exchange Rates, Manufacturing), THE Audit_System SHALL insert an Audit_Entry with event_type "CREATE", the table name, the new record ID, and the new values.
2. WHEN a user updates a record in any module, THE Audit_System SHALL insert an Audit_Entry with event_type "UPDATE", the table name, the record ID, the old values, and the new values.
3. WHEN a user deletes a record in any module, THE Audit_System SHALL insert an Audit_Entry with event_type "DELETE", the table name, the record ID, and the old values.
4. WHEN a user logs in successfully, THE Audit_System SHALL insert an Audit_Entry with event_type "LOGIN" and the user ID.
5. WHEN a user logs out, THE Audit_System SHALL insert an Audit_Entry with event_type "LOGOUT" and the user ID.
6. WHEN a login attempt fails, THE Audit_System SHALL insert an Audit_Entry with event_type "LOGIN_FAILED", the attempted username, and the IP address.
7. THE Audit_System SHALL record the Actor's user ID, tenant ID, business ID, IP address, and user agent for every Audit_Entry.

### Requirement 2: Audit Entry Integrity

**User Story:** As a system administrator, I want audit entries to be immutable and complete, so that the audit trail cannot be tampered with and remains reliable.

#### Acceptance Criteria

1. THE Audit_System SHALL store each Audit_Entry with a server-generated timestamp (created_at) that cannot be overridden by the client.
2. THE Audit_System SHALL store old_values and new_values as JSON-encoded strings preserving the original field names and values.
3. IF the database insert for an Audit_Entry fails, THEN THE Audit_System SHALL log the failure to the application error log without interrupting the original user action.

### Requirement 3: Activity Log Retrieval

**User Story:** As a business owner, I want to view all audit entries for my business in a paginated list, so that I can review recent activity without performance issues.

#### Acceptance Criteria

1. WHEN an authorized user requests the activity log, THE Audit_System SHALL return entries scoped to the user's current business only.
2. THE Audit_System SHALL return entries ordered by created_at descending (most recent first).
3. THE Audit_System SHALL support pagination with configurable page size (default 25 entries per page).
4. THE Audit_System SHALL include the actor's display name (from the users table) in each returned entry.

### Requirement 4: Activity Log Filtering

**User Story:** As a business owner, I want to filter the activity log by event type and date range, so that I can quickly find specific actions.

#### Acceptance Criteria

1. WHEN the user specifies an event_type filter, THE Audit_System SHALL return only entries matching that event_type.
2. WHEN the user specifies a "from" date, THE Audit_System SHALL return only entries with created_at on or after that date.
3. WHEN the user specifies a "to" date, THE Audit_System SHALL return only entries with created_at on or before that date.
4. WHEN no filters are specified, THE Audit_System SHALL return all entries for the business.

### Requirement 5: Activity Log Page Display

**User Story:** As a business owner, I want the Activity Log page to clearly present audit entries with key details visible at a glance, so that I can quickly understand what happened.

#### Acceptance Criteria

1. THE Activity_Log_Page SHALL display each entry with the following visible columns: Date, User, Event Type, Table, Action, Record ID, and a Details toggle button.
2. WHEN the user clicks the Details toggle for an entry, THE Activity_Log_Page SHALL expand an inline panel showing the old values and new values formatted as readable JSON.
3. THE Activity_Log_Page SHALL display event types with color-coded badges (CREATE=green, UPDATE=yellow, DELETE=red, LOGIN=blue, LOGOUT=gray).
4. THE Activity_Log_Page SHALL provide a filter bar with event type dropdown, from-date picker, and to-date picker.

### Requirement 6: Access Control

**User Story:** As a system administrator, I want only authorized users to access the activity log, so that sensitive audit data is protected.

#### Acceptance Criteria

1. THE Audit_System SHALL require the "activityLog.view" permission to access the GET /activity-log endpoint.
2. IF a user without the "activityLog.view" permission attempts to access the activity log, THEN THE Audit_System SHALL return a 403 Forbidden response.
3. THE Activity_Log_Page SHALL only be visible in the navigation menu to users who have the "activityLog.view" permission.

### Requirement 7: Settings-Based Audit Activation

**User Story:** As a business owner, I want to enable or disable activity logging from the settings page, so that I can control whether actions are being audited for my business.

#### Acceptance Criteria

1. THE Audit_System SHALL check a business-level setting ("audit_enabled") before writing an Audit_Entry.
2. WHILE the "audit_enabled" setting is set to true, THE Audit_System SHALL record all auditable actions.
3. WHILE the "audit_enabled" setting is set to false, THE Audit_System SHALL skip recording audit entries.
4. WHEN the "audit_enabled" setting is changed, THE Audit_System SHALL record that change as an Audit_Entry regardless of the previous setting value.
5. THE Audit_System SHALL default the "audit_enabled" setting to true for new businesses.
