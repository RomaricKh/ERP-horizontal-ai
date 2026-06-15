# Implementation Plan: User Notifications

## Overview

This plan implements a full notification system for the ERP application, covering database schema, backend services (PHP), frontend components (JavaScript SPA), and integration with existing modules. Tasks are ordered to build incrementally: schema first, then repository layer, service layer, controller, frontend services, UI components, and finally system event integration.

## Tasks

- [x] 1. Create database migration for notifications tables
  - [x] 1.1 Create migration file `database/migrations/add_notifications_tables.sql`
    - Define `notifications` table with columns: id, type (ENUM), title, body, sender_id, recipient_id, business_id, entity_type, entity_id, is_read, read_at, deleted_at, created_at
    - Define `notification_preferences` table with columns: id, user_id, business_id, type (ENUM), enabled, updated_at
    - Add all indexes: idx_recipient_business, idx_recipient_unread, idx_poll, idx_cleanup
    - Add foreign keys to users and businesses tables
    - Add unique constraint uq_user_business_type on notification_preferences
    - _Requirements: 1.6, 8.3_

  - [x] 1.2 Seed notification permissions into roles_permissions
    - Add "notifications.view" and "notifications.send" permissions
    - Assign "notifications.view" to all default roles
    - Assign "notifications.send" to appropriate roles
    - _Requirements: 9.4, 9.5_

- [x] 2. Implement NotificationRepository
  - [x] 2.1 Create `src/Repository/NotificationRepository.php`
    - Implement constructor accepting PDO instance
    - Implement `create(array $data): int` — inserts a notification record and returns the new ID
    - Implement `findSince(int $userId, int $businessId, string $since): array` — returns notifications with created_at > since, excluding soft-deleted
    - Implement `findAll(int $userId, int $businessId, array $filters, int $offset, int $limit): array` — paginated query with optional type and read status filters, excluding soft-deleted
    - Implement `countAll(int $userId, int $businessId, array $filters): int` — count matching the same filters
    - Implement `countUnread(int $userId, int $businessId): int` — count where is_read = 0 and deleted_at IS NULL
    - Implement `findById(int $id, int $userId, int $businessId): ?array` — returns notification only if it belongs to user+business
    - Implement `markAsRead(int $id, int $userId, int $businessId): void` — sets is_read=1 and read_at=NOW() only if currently unread
    - Implement `markAllAsRead(int $userId, int $businessId): void` — marks all unread as read for user+business
    - Implement `markBatchAsRead(array $ids, int $userId, int $businessId): void` — marks specific IDs as read
    - Implement `softDelete(int $id, int $userId, int $businessId): void` — sets deleted_at=NOW()
    - Implement `softDeleteAllRead(int $userId, int $businessId): void` — soft-deletes all read notifications
    - Implement `permanentDeleteOlderThan(int $days): int` — deletes records where deleted_at < NOW() - days
    - _Requirements: 1.6, 2.5, 4.2, 4.3, 4.4, 5.2, 5.3, 5.4, 5.5, 8.1, 8.2, 8.4, 9.2_

  - [x] 2.2 Write unit tests for NotificationRepository
    - Test create returns valid ID
    - Test findSince excludes soft-deleted and respects timestamp boundary
    - Test findAll with type and read status filters
    - Test markAsRead does not update read_at if already read (idempotence)
    - Test softDeleteAllRead only targets read notifications
    - Test permanentDeleteOlderThan respects retention boundary
    - Test findById returns null for wrong user/business
    - _Requirements: 2.5, 5.4, 5.5, 8.1, 8.2, 8.4, 9.2_

- [x] 3. Implement NotificationPreferenceRepository
  - [x] 3.1 Create `src/Repository/NotificationPreferenceRepository.php`
    - Implement constructor accepting PDO instance
    - Implement `getForUser(int $userId, int $businessId): array` — returns all preference records for user+business
    - Implement `upsert(int $userId, int $businessId, string $type, bool $enabled): void` — inserts or updates preference using ON DUPLICATE KEY UPDATE
    - Implement `isTypeEnabled(int $userId, int $businessId, string $type): bool` — returns true if no record exists (default enabled) or if record has enabled=1
    - _Requirements: 7.1, 7.2, 7.3_

  - [x] 3.2 Write unit tests for NotificationPreferenceRepository
    - Test getForUser returns empty array for new user (defaults)
    - Test upsert creates new record and updates existing
    - Test isTypeEnabled returns true when no record exists
    - Test isTypeEnabled returns correct value after upsert
    - _Requirements: 7.1, 7.3_

- [x] 4. Implement NotificationService
  - [x] 4.1 Create `src/Services/NotificationService.php`
    - Implement constructor accepting PDO, instantiating NotificationRepository and NotificationPreferenceRepository
    - Implement `notify(string $type, string $title, string $body, int|array $recipientIds, ?string $entityType, ?int $entityId, ?int $senderId): void`
      - Accept single int or array of recipient IDs
      - For each recipient, check preferences (skip if disabled, unless type is USER_MESSAGE)
      - Create notification record for each eligible recipient
      - Wrap entire method in try/catch, log errors, never throw
    - Implement `pollSince(int $userId, int $businessId, string $since): array` — delegates to repository, includes unread_count
    - Implement `getAll(int $userId, int $businessId, array $filters, int $page, int $perPage): array` — returns paginated results with pagination metadata
    - Implement `getUnreadCount(int $userId, int $businessId): int`
    - Implement `markAsRead(int $notificationId, int $userId, int $businessId): void` — verifies ownership then marks
    - Implement `markAllAsRead(int $userId, int $businessId): void`
    - Implement `markBatchAsRead(array $ids, int $userId, int $businessId): void`
    - Implement `sendUserMessage(int $senderId, int $recipientId, string $title, string $body, int $businessId): void` — validates same-business, creates USER_MESSAGE notification
    - Implement `softDelete(int $notificationId, int $userId, int $businessId): void` — verifies ownership then soft-deletes
    - Implement `clearAllRead(int $userId, int $businessId): void`
    - Implement `getPreferences(int $userId, int $businessId): array` — returns preferences with defaults for missing types
    - Implement `updatePreferences(int $userId, int $businessId, array $preferences): void` — upserts each type preference
    - Implement `cleanup(int $retentionDays = 90): int` — calls permanentDeleteOlderThan
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 5.2, 5.3, 5.5, 6.1, 6.2, 6.3, 6.4, 7.1, 7.2, 7.5, 8.1, 8.2, 8.4, 9.2, 9.3, 10.1, 10.2, 10.5_

  - [x] 4.2 Write unit tests for NotificationService
    - Test notify() with single recipient creates one notification
    - Test notify() with array of recipients creates one per eligible recipient
    - Test notify() skips recipients with disabled preferences
    - Test notify() always delivers USER_MESSAGE regardless of preferences
    - Test notify() catches exceptions and does not throw
    - Test sendUserMessage() fails with 400 for cross-business recipient
    - Test markAsRead() does not update already-read notification timestamp
    - Test markAllAsRead() marks all unread as read
    - Test clearAllRead() only soft-deletes read notifications
    - Test cleanup() removes only records older than retention period
    - _Requirements: 6.2, 6.3, 7.2, 7.5, 10.2, 10.5_

- [x] 5. Checkpoint - Ensure backend service layer tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [x] 6. Implement NotificationController
  - [x] 6.1 Create `src/Controllers/NotificationController.php`
    - Extend BaseController
    - Implement `poll()` — GET /notifications/poll, requires "notifications.view" permission, validates "since" query param, returns notifications and unread_count
    - Implement `index()` — GET /notifications, requires "notifications.view", accepts page, per_page, type, is_read query params, returns paginated list
    - Implement `unreadCount()` — GET /notifications/unread-count, requires "notifications.view", returns count
    - Implement `markAsRead()` — PATCH /notifications/{id}/read, requires "notifications.view", marks single notification
    - Implement `markAllAsRead()` — PATCH /notifications/read-all, requires "notifications.view"
    - Implement `markBatchAsRead()` — PATCH /notifications/read-batch, requires "notifications.view", accepts array of IDs
    - Implement `send()` — POST /notifications/send, requires "notifications.send", validates recipient_id, title, body
    - Implement `delete()` — DELETE /notifications/{id}, requires "notifications.view", soft-deletes
    - Implement `clearAllRead()` — DELETE /notifications/read, requires "notifications.view"
    - Implement `preferences()` — GET /notifications/preferences, requires "notifications.view"
    - Implement `updatePreferences()` — PUT /notifications/preferences, requires "notifications.view", validates preferences array
    - _Requirements: 2.5, 4.2, 4.3, 4.4, 5.2, 5.3, 6.1, 6.2, 6.3, 7.1, 7.4, 8.1, 8.2, 9.1, 9.4, 9.5_

  - [x] 6.2 Register notification routes in `public/api/routes/`
    - Add route definitions for all 11 notification endpoints
    - Map HTTP methods and paths to NotificationController methods
    - _Requirements: 6.1, 9.1_

  - [x] 6.3 Write unit tests for NotificationController
    - Test poll endpoint returns 401 without auth
    - Test poll endpoint returns 403 without notifications.view permission
    - Test send endpoint returns 403 without notifications.send permission
    - Test send endpoint returns 400 for missing required fields
    - Test send endpoint returns 400 for cross-business recipient
    - Test delete endpoint returns 403 for notification not owned by user
    - Test index endpoint returns correct pagination structure
    - _Requirements: 9.1, 9.3, 9.4, 9.5, 6.2, 6.3_

- [x] 7. Implement NotificationPollingService (Frontend)
  - [x] 7.1 Create `public/assets/js/services/NotificationPollingService.js`
    - Implement singleton class with configurable interval (default 30000ms)
    - Implement `start()` — begins setInterval polling, stores last poll timestamp
    - Implement `stop()` — clears interval
    - Implement `pause()` — stops polling when tab is hidden
    - Implement `resume()` — resumes polling with immediate fetch when tab becomes visible
    - Implement `onUpdate(callback)` — registers listener for new notification data
    - Implement `getUnreadCount()` — returns cached unread count
    - Use `apiFetch` for authenticated requests to GET /notifications/poll?since={timestamp}
    - Integrate with existing `document.addEventListener('visibilitychange', ...)` or app.js tab events
    - Handle network errors silently (log to console, retry on next interval)
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5_

  - [x] 7.2 Write unit tests for NotificationPollingService
    - Test start() initiates polling at configured interval
    - Test pause() stops polling
    - Test resume() triggers immediate fetch
    - Test onUpdate callbacks are invoked with new notifications
    - Test network errors are handled silently
    - _Requirements: 2.1, 2.3, 2.4_

- [x] 8. Implement NotificationCenter component (Frontend)
  - [x] 8.1 Create `public/assets/js/components/NotificationCenter.js`
    - Render bell icon in top navigation bar with unread badge count
    - Subscribe to NotificationPollingService updates to refresh badge
    - On bell click, toggle dropdown panel showing 10 most recent notifications (newest first)
    - Display each notification: title, body preview (first 100 chars + ellipsis if longer), sender name or "System", relative timestamp, unread indicator
    - Implement 3-second auto-mark-as-read timer when dropdown opens (calls PATCH /notifications/read-batch with visible IDs)
    - Cancel timer if dropdown closes before 3 seconds
    - On notification click with entity_type: navigate to mapped SPA route, close dropdown
    - Provide "Mark all as read" button calling PATCH /notifications/read-all
    - Provide "View all" link navigating to #/notifications
    - Update badge count after read operations
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 5.1, 5.2_

  - [x] 8.2 Integrate NotificationCenter into the main layout
    - Add NotificationCenter to the top navigation bar (alongside existing user dropdown)
    - Initialize NotificationPollingService on app startup (after auth)
    - Stop polling on logout
    - _Requirements: 3.1, 2.1_

  - [x] 8.3 Write unit tests for NotificationCenter
    - Test badge displays correct unread count
    - Test dropdown shows max 10 notifications ordered by newest first
    - Test body preview truncates at 100 characters with ellipsis
    - Test entity reference click navigates to correct route
    - Test auto-mark-as-read fires after 3 seconds
    - Test auto-mark-as-read cancels if dropdown closes early
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 5.1_

- [x] 9. Checkpoint - Ensure polling and notification center work end-to-end
  - Ensure all tests pass, ask the user if questions arise.

- [x] 10. Implement Notifications Page (Frontend)
  - [x] 10.1 Create `public/assets/js/pages/Notifications/notifications.js`
    - Follow existing page pattern (render/mount/unmount lifecycle)
    - Register route at #/notifications
    - Display paginated list of notifications (20 per page)
    - Implement type filter dropdown (PO_STATUS_CHANGE, INVOICE_DUE, STOCK_LOW, PAYMENT_RECEIVED, USER_MESSAGE, SYSTEM_ALERT)
    - Implement read status filter (All, Read, Unread)
    - Display each notification: type badge, title, full body, sender name, created_at timestamp, read status indicator
    - Implement pagination controls (previous/next, page numbers)
    - Implement "Send Notification" button opening a form modal with recipient selector, title, body fields
    - Implement "Clear all read" button calling DELETE /notifications/read
    - Implement individual delete button calling DELETE /notifications/{id}
    - Implement preferences panel with toggles for each notification type
    - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 6.5, 7.4, 8.1, 8.2_

  - [x] 10.2 Write unit tests for Notifications Page
    - Test pagination renders correct page controls
    - Test type filter sends correct query parameter
    - Test read status filter sends correct query parameter
    - Test send notification form validates required fields
    - Test preferences toggles reflect current state
    - _Requirements: 4.2, 4.3, 4.4, 6.5, 7.4_

- [x] 11. Implement system event integration
  - [x] 11.1 Integrate notifications into PurchaseOrderService
    - After PO creation or status change, call `NotificationService::notify()` with type "PO_STATUS_CHANGE"
    - Resolve recipients: all users with "purchaseOrders.approve" permission in the same business
    - Include entity_type "purchase_orders" and entity_id
    - _Requirements: 1.1, 10.1_

  - [x] 11.2 Integrate notifications into SalesInvoiceService for due date alerts
    - Implement due date check logic (within 3 days of current date)
    - Call `NotificationService::notify()` with type "INVOICE_DUE" for the invoice creator
    - Include entity_type "sales_invoices" and entity_id
    - _Requirements: 1.2_

  - [x] 11.3 Integrate notifications into ItemService for low stock alerts
    - After stock adjustment, check if quantity falls below minimum threshold
    - Call `NotificationService::notify()` with type "STOCK_LOW" for users with "items.edit" permission
    - Include entity_type "items" and entity_id
    - _Requirements: 1.3_

  - [x] 11.4 Integrate notifications into PaymentService and CollectionService
    - After payment/collection creation, call `NotificationService::notify()` with type "PAYMENT_RECEIVED"
    - Resolve recipients: all users with "finance.view" permission in the same business
    - Include entity_type "payments" or "collections" and entity_id
    - _Requirements: 1.4_

  - [x] 11.5 Integrate notifications into SalaryService
    - After salary record creation, call `NotificationService::notify()` with type "SYSTEM_ALERT" for the employee user (if linked)
    - _Requirements: 10.3_

  - [x] 11.6 Integrate notifications into ManufacturingService
    - When production order status changes to "completed", call `NotificationService::notify()` with type "SYSTEM_ALERT"
    - Resolve recipients: all users with "manufacturing.view" permission in the same business
    - Include entity_type "production_orders" and entity_id
    - _Requirements: 10.4_

  - [x] 11.7 Write integration tests for system event notifications
    - Test PO status change creates notifications for correct recipients
    - Test invoice due date notification is created within 3-day window
    - Test low stock notification triggers at threshold
    - Test payment notification reaches finance.view users
    - Test salary notification reaches linked employee
    - Test production order completion notification reaches manufacturing.view users
    - Test notification creation failure does not break calling service
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 10.3, 10.4, 10.5_

- [x] 12. Checkpoint - Ensure system integration tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [x] 13. Implement property-based tests for correctness properties
  - [x] 13.1 Write property test for permission-based recipient resolution
    - **Property 1: Permission-based recipient resolution**
    - Generate arbitrary user lists with random permission sets; verify resolved recipients exactly match users holding the target permission
    - **Validates: Requirements 1.1, 1.3, 1.4, 10.4**

  - [x] 13.2 Write property test for invoice due date window
    - **Property 2: Invoice due date window**
    - Generate arbitrary dates; verify notification is created iff due date is within 3 days (inclusive) of current date
    - **Validates: Requirements 1.2**

  - [x] 13.3 Write property test for user message data preservation
    - **Property 3: User message data preservation**
    - Generate arbitrary sender_id, recipient_id, title, body; verify created notification preserves all fields exactly
    - **Validates: Requirements 1.5, 6.4**

  - [x] 13.4 Write property test for since-timestamp filtering
    - **Property 4: Since-timestamp filtering**
    - Generate arbitrary notification lists with random timestamps and a random "since" value; verify only notifications with created_at > since are returned
    - **Validates: Requirements 2.5**

  - [x] 13.5 Write property test for dropdown ordering and limiting
    - **Property 5: Dropdown ordering and limiting**
    - Generate arbitrary notification lists of varying sizes; verify at most 10 returned, ordered by created_at descending, and they are the 10 most recent
    - **Validates: Requirements 3.2**

  - [x] 13.6 Write property test for body preview truncation
    - **Property 6: Body preview truncation**
    - Generate arbitrary strings of varying lengths; verify truncation at 100 chars with ellipsis when needed
    - **Validates: Requirements 3.3**

  - [x] 13.7 Write property test for entity reference navigation mapping
    - **Property 7: Entity reference navigation mapping**
    - For all valid entity_type values, verify the mapped route matches the entity reference mapping table
    - **Validates: Requirements 3.4**

  - [x] 13.8 Write property test for pagination correctness
    - **Property 8: Pagination correctness**
    - Generate arbitrary total counts and page sizes; verify page N returns at most P items and total_pages = ceil(T/P)
    - **Validates: Requirements 4.2**

  - [x] 13.9 Write property test for type filter correctness
    - **Property 9: Type filter correctness**
    - Generate arbitrary notification lists with mixed types and a random type filter; verify only matching type returned
    - **Validates: Requirements 4.3**

  - [x] 13.10 Write property test for read status filter correctness
    - **Property 10: Read status filter correctness**
    - Generate arbitrary notification lists with mixed read statuses and a filter; verify only matching status returned
    - **Validates: Requirements 4.4**

  - [x] 13.11 Write property test for mark as read sets timestamp
    - **Property 11: Mark as read sets timestamp**
    - Generate arbitrary unread notifications; verify marking as read sets is_read=true and read_at to non-null
    - **Validates: Requirements 5.2, 5.4**

  - [x] 13.12 Write property test for mark as read idempotence
    - **Property 12: Mark as read idempotence**
    - Generate arbitrary already-read notifications with a read_at timestamp; verify marking again does not change read_at
    - **Validates: Requirements 5.5**

  - [x] 13.13 Write property test for mark all as read completeness
    - **Property 13: Mark all as read completeness**
    - Generate arbitrary sets of notifications with mixed read statuses; verify after mark-all, all have is_read=true
    - **Validates: Requirements 5.3**

  - [x] 13.14 Write property test for same-business validation
    - **Property 14: Same-business validation**
    - Generate arbitrary sender/recipient pairs with same or different business_ids; verify send succeeds iff same business
    - **Validates: Requirements 6.2, 6.3**

  - [x] 13.15 Write property test for preference round-trip
    - **Property 15: Preference round-trip**
    - Generate arbitrary user, business, type, enabled values; verify store then retrieve returns same value
    - **Validates: Requirements 7.1**

  - [x] 13.16 Write property test for preference-based notification filtering
    - **Property 16: Preference-based notification filtering**
    - Generate arbitrary preference configurations; verify notify() skips disabled types
    - **Validates: Requirements 7.2**

  - [x] 13.17 Write property test for USER_MESSAGE bypasses preferences
    - **Property 17: USER_MESSAGE bypasses preferences**
    - Generate arbitrary preference configurations including USER_MESSAGE disabled; verify USER_MESSAGE is always delivered
    - **Validates: Requirements 7.5**

  - [x] 13.18 Write property test for soft-delete exclusion from queries
    - **Property 18: Soft-delete exclusion from queries**
    - Generate arbitrary notification lists with some soft-deleted; verify soft-deleted never appear in results
    - **Validates: Requirements 8.1**

  - [x] 13.19 Write property test for clear all read targets only read notifications
    - **Property 19: Clear all read targets only read notifications**
    - Generate arbitrary notification lists with mixed read statuses; verify clear-all-read only soft-deletes read ones
    - **Validates: Requirements 8.2**

  - [x] 13.20 Write property test for cleanup removes only expired records
    - **Property 20: Cleanup removes only expired records**
    - Generate arbitrary soft-deleted notifications with various deleted_at dates; verify only those older than 90 days are removed
    - **Validates: Requirements 8.4**

  - [x] 13.21 Write property test for access isolation
    - **Property 21: Access isolation**
    - Generate arbitrary notifications across multiple users/businesses; verify queries only return matching recipient_id + business_id
    - **Validates: Requirements 9.2, 9.3**

  - [x] 13.22 Write property test for notify() accepts single and array recipients
    - **Property 22: notify() accepts single and array recipients**
    - Generate arbitrary single int and array inputs; verify correct number of notifications created per eligible recipient
    - **Validates: Requirements 10.2**

  - [x] 13.23 Write property test for error isolation in notification creation
    - **Property 23: Error isolation in notification creation**
    - Simulate errors during notification creation; verify no exception propagates to caller
    - **Validates: Requirements 10.5**

- [x] 14. Final checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP
- Each task references specific requirements for traceability
- Checkpoints ensure incremental validation
- Property tests (task 13) validate the 23 universal correctness properties from the design using Vitest + fast-check
- Unit tests validate specific examples and edge cases using PHPUnit (backend) and Vitest (frontend)
- The `NotificationService::notify()` method is wrapped in try/catch to ensure notification failures never break business operations (Property 23)
- All repository queries scope to user_id + business_id to enforce access isolation (Property 21)
