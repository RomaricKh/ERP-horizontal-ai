# Requirements Document

## Introduction

This feature introduces a comprehensive notifications system for the ERP application, enabling user-to-user and system-to-user communication for actionable events. The system covers typical ERP notification scenarios including approval workflows, due date reminders, inventory alerts, and direct user messages. Notifications are delivered in near-real-time via polling and are accessible through a dedicated notification center in the UI. The feature integrates with the existing activity log, permissions, and multi-business architecture.

## Glossary

- **Notification_System**: The backend subsystem responsible for creating, storing, delivering, and managing notifications (composed of `NotificationService`, `NotificationRepository`, and `NotificationController`).
- **Notification_Center**: The frontend UI component (bell icon dropdown and full notifications page) that displays notifications to the authenticated user.
- **Notification**: A single record representing a message directed at a specific recipient user, containing a type, title, body, and optional reference to a related entity.
- **Recipient**: The user who receives and views a Notification.
- **Sender**: The user or system process that triggers the creation of a Notification. System-generated notifications use a null sender.
- **Notification_Type**: A classification label for a Notification (e.g., APPROVAL_REQUIRED, INVOICE_DUE, STOCK_LOW, PO_STATUS_CHANGE, PAYMENT_RECEIVED, USER_MESSAGE, SYSTEM_ALERT).
- **Entity_Reference**: A combination of entity type (table name) and entity ID that links a Notification to a specific record in the system (e.g., purchase_orders:42).
- **Read_Status**: A boolean flag indicating whether the Recipient has marked or viewed the Notification.
- **Polling_Interval**: The time interval at which the frontend checks for new notifications (default 30 seconds).

## Requirements

### Requirement 1: Notification Creation

**User Story:** As an ERP system, I want to generate notifications when significant events occur, so that relevant users are informed of actions requiring their attention.

#### Acceptance Criteria

1. WHEN a purchase order is created or its status changes, THE Notification_System SHALL create a Notification of type "PO_STATUS_CHANGE" for all users with the "purchaseOrders.approve" permission in the same business.
2. WHEN a sales invoice due date is within 3 days, THE Notification_System SHALL create a Notification of type "INVOICE_DUE" for the user who created the invoice.
3. WHEN an item stock quantity falls below its minimum threshold, THE Notification_System SHALL create a Notification of type "STOCK_LOW" for all users with the "items.edit" permission in the same business.
4. WHEN a payment or collection is recorded, THE Notification_System SHALL create a Notification of type "PAYMENT_RECEIVED" for all users with the "finance.view" permission in the same business.
5. WHEN a user explicitly sends a message to another user, THE Notification_System SHALL create a Notification of type "USER_MESSAGE" with the sender's user ID and the message content.
6. THE Notification_System SHALL store each Notification with: id, type, title, body, sender_id (nullable), recipient_id, business_id, entity_type (nullable), entity_id (nullable), is_read (default false), created_at, and read_at (nullable).

### Requirement 2: Notification Delivery via Polling

**User Story:** As a user, I want to receive new notifications in near-real-time without refreshing the page, so that I can respond to important events promptly.

#### Acceptance Criteria

1. THE Notification_Center SHALL poll the server for new notifications at the configured Polling_Interval (default 30 seconds).
2. WHEN the poll response contains new unread notifications, THE Notification_Center SHALL update the notification badge count immediately.
3. WHEN the user's browser tab is not visible, THE Notification_Center SHALL pause polling to reduce server load.
4. WHEN the user's browser tab becomes visible again, THE Notification_Center SHALL resume polling immediately with a fresh request.
5. THE Notification_System SHALL support a "since" timestamp parameter on the polling endpoint to return only notifications created after that timestamp.

### Requirement 3: Notification Center UI

**User Story:** As a user, I want a notification center accessible from any page, so that I can quickly see and act on my notifications.

#### Acceptance Criteria

1. THE Notification_Center SHALL display a bell icon in the top navigation bar showing the count of unread notifications as a badge.
2. WHEN the user clicks the bell icon, THE Notification_Center SHALL display a dropdown panel listing the 10 most recent notifications ordered by created_at descending.
3. THE Notification_Center SHALL display each notification with: title, body preview (first 100 characters), sender name (or "System" for null sender), relative timestamp (e.g., "5 min ago"), and a visual indicator for unread status.
4. WHEN the user clicks a notification that has an Entity_Reference, THE Notification_Center SHALL navigate the user to the relevant page for that entity.
5. THE Notification_Center SHALL provide a "Mark all as read" button in the dropdown header.
6. THE Notification_Center SHALL provide a "View all" link that navigates to a full notifications page.

### Requirement 4: Full Notifications Page

**User Story:** As a user, I want a dedicated page to browse all my notifications with filtering options, so that I can review past notifications and find specific ones.

#### Acceptance Criteria

1. THE Notification_Center SHALL provide a full-page view at the route "#/notifications" displaying all notifications for the Recipient in a paginated list.
2. THE Notification_System SHALL support pagination with a default page size of 20 notifications per page.
3. WHEN the user filters by Notification_Type, THE Notification_System SHALL return only notifications matching that type.
4. WHEN the user filters by Read_Status, THE Notification_System SHALL return only notifications matching that status (read or unread).
5. THE Notification_Center SHALL display each notification on the full page with: type badge, title, full body, sender name, created_at timestamp, and read status.

### Requirement 5: Notification Read Management

**User Story:** As a user, I want to mark notifications as read individually or in bulk, so that I can track which notifications I have already reviewed.

#### Acceptance Criteria

1. WHEN the user opens the notification dropdown, THE Notification_System SHALL mark all visible notifications as read after 3 seconds of the dropdown being open.
2. WHEN the user clicks a specific notification, THE Notification_System SHALL mark that notification as read immediately.
3. WHEN the user clicks "Mark all as read", THE Notification_System SHALL mark all unread notifications for that user in the current business as read.
4. THE Notification_System SHALL set the read_at timestamp when a Notification is marked as read.
5. WHEN a notification is already read, THE Notification_System SHALL not update the read_at timestamp again.

### Requirement 6: User-to-User Notifications

**User Story:** As a user, I want to send a direct notification to another user in my business, so that I can communicate important information within the system.

#### Acceptance Criteria

1. THE Notification_System SHALL provide an API endpoint (POST /notifications/send) that accepts recipient_id, title, and body to create a USER_MESSAGE notification.
2. THE Notification_System SHALL validate that the sender and recipient belong to the same business.
3. IF the recipient_id does not exist or does not belong to the same business, THEN THE Notification_System SHALL return a 400 Bad Request response with a descriptive error message.
4. THE Notification_System SHALL set the sender_id to the authenticated user's ID for user-sent notifications.
5. THE Notification_Center SHALL provide a "Send Notification" action accessible from the notifications page that opens a form with recipient selector, title field, and body field.

### Requirement 7: Notification Preferences

**User Story:** As a user, I want to control which types of notifications I receive, so that I am not overwhelmed by irrelevant alerts.

#### Acceptance Criteria

1. THE Notification_System SHALL maintain per-user notification preferences storing enabled/disabled status for each Notification_Type.
2. WHEN a user disables a Notification_Type in their preferences, THE Notification_System SHALL skip creating notifications of that type for that user.
3. THE Notification_System SHALL default all Notification_Types to enabled for new users.
4. THE Notification_Center SHALL provide a preferences panel accessible from the notifications page where users can toggle each Notification_Type on or off.
5. THE Notification_System SHALL always deliver notifications of type "USER_MESSAGE" regardless of preference settings.

### Requirement 8: Notification Deletion and Retention

**User Story:** As a user, I want to delete notifications I no longer need, and as a system administrator, I want old notifications to be cleaned up automatically, so that storage is managed efficiently.

#### Acceptance Criteria

1. WHEN the user deletes a single notification, THE Notification_System SHALL soft-delete that notification (set a deleted_at timestamp) so it no longer appears in the user's notification list.
2. WHEN the user clicks "Clear all read", THE Notification_System SHALL soft-delete all read notifications for that user in the current business.
3. THE Notification_System SHALL retain soft-deleted notifications in the database for 90 days.
4. THE Notification_System SHALL provide a cleanup mechanism that permanently removes notifications older than 90 days from the soft-delete date.

### Requirement 9: Access Control

**User Story:** As a system administrator, I want notification access to be properly secured, so that users can only see and manage their own notifications.

#### Acceptance Criteria

1. THE Notification_System SHALL require authentication for all notification endpoints.
2. THE Notification_System SHALL scope all notification queries to the authenticated user's ID and current business_id.
3. IF a user attempts to access or modify a notification that does not belong to them, THEN THE Notification_System SHALL return a 403 Forbidden response.
4. THE Notification_System SHALL require the "notifications.send" permission to use the user-to-user send endpoint.
5. THE Notification_System SHALL require the "notifications.view" permission to access notification endpoints.

### Requirement 10: System Event Integration

**User Story:** As a system administrator, I want the notification system to integrate with existing ERP modules, so that notifications are triggered automatically from normal business operations.

#### Acceptance Criteria

1. THE Notification_System SHALL expose a `NotificationService::notify()` method that other services can call to create notifications programmatically.
2. THE Notification_System SHALL accept parameters: type, title, body, recipient_id (or recipient_ids array), entity_type, and entity_id.
3. WHEN a salary record is created, THE Notification_System SHALL create a Notification of type "SYSTEM_ALERT" for the employee user (if linked) informing them of the new salary entry.
4. WHEN a manufacturing production order status changes to "completed", THE Notification_System SHALL create a Notification of type "SYSTEM_ALERT" for all users with "manufacturing.view" permission in the same business.
5. IF the NotificationService encounters an error while creating a notification, THEN THE Notification_System SHALL log the error without interrupting the calling operation.
