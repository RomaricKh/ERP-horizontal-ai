# Design Document: User Notifications

## Overview

The User Notifications feature adds a real-time notification system to the ERP application, enabling both system-generated and user-to-user notifications. The system follows the existing Controller → Service → Repository architecture and integrates with the current JWT authentication, multi-business scoping, and permission model.

Key design decisions:
- **Polling over WebSockets**: The existing infrastructure uses simple HTTP endpoints with JWT auth. Polling at 30-second intervals keeps the architecture consistent and avoids introducing WebSocket infrastructure.
- **Soft-delete with retention**: Notifications use soft-delete (deleted_at timestamp) with a 90-day retention policy, matching the audit-trail philosophy of the existing system.
- **Preference-based filtering at creation time**: Notifications are skipped at creation time (not delivery time) to avoid storing unwanted records.
- **Single table design**: All notification types share one `notifications` table with a `type` discriminator column, keeping queries simple and the schema flat.

## Architecture

```mermaid
graph TD
    subgraph Frontend [JavaScript SPA]
        NC[NotificationCenter Component]
        NP[Notifications Page]
        NPS[NotificationPollingService]
        NC --> NPS
        NP --> NPS
    end

    subgraph Backend [PHP API]
        NCtrl[NotificationController]
        NSvc[NotificationService]
        NRepo[NotificationRepository]
        NPrefRepo[NotificationPreferenceRepository]
    end

    subgraph Existing Services
        POSvc[PurchaseOrderService]
        PaySvc[PaymentService]
        SalSvc[SalaryService]
        MfgSvc[ManufacturingService]
        ItemSvc[ItemService]
        InvSvc[SalesInvoiceService]
    end

    subgraph Database
        NTable[(notifications)]
        NPrefTable[(notification_preferences)]
    end

    NPS -->|GET /notifications/poll| NCtrl
    NC -->|PATCH /notifications/read| NCtrl
    NP -->|GET /notifications| NCtrl
    NP -->|POST /notifications/send| NCtrl

    NCtrl --> NSvc
    NSvc --> NRepo
    NSvc --> NPrefRepo
    NRepo --> NTable
    NPrefRepo --> NPrefTable

    POSvc -->|notify()| NSvc
    PaySvc -->|notify()| NSvc
    SalSvc -->|notify()| NSvc
    MfgSvc -->|notify()| NSvc
    ItemSvc -->|notify()| NSvc
    InvSvc -->|notify()| NSvc
```

### Request Flow

1. **System event occurs** (e.g., PO status change) → existing service calls `NotificationService::notify()`
2. **NotificationService** checks recipient preferences, creates notification records
3. **Frontend polls** every 30s via `GET /notifications/poll?since={timestamp}`
4. **Badge updates** immediately when new unread notifications are returned
5. **User interactions** (read, delete, send) go through standard REST endpoints

## Components and Interfaces

### Backend Components

#### NotificationController (`src/Controllers/NotificationController.php`)

Extends `BaseController`. Handles all notification HTTP endpoints.

| Method | Route | Permission | Description |
|--------|-------|-----------|-------------|
| `poll()` | GET /notifications/poll | notifications.view | Returns notifications since timestamp |
| `index()` | GET /notifications | notifications.view | Paginated list with filters |
| `unreadCount()` | GET /notifications/unread-count | notifications.view | Returns unread count |
| `markAsRead()` | PATCH /notifications/{id}/read | notifications.view | Mark single as read |
| `markAllAsRead()` | PATCH /notifications/read-all | notifications.view | Mark all as read |
| `markBatchAsRead()` | PATCH /notifications/read-batch | notifications.view | Mark visible batch as read |
| `send()` | POST /notifications/send | notifications.send | Send user-to-user notification |
| `delete()` | DELETE /notifications/{id} | notifications.view | Soft-delete single |
| `clearAllRead()` | DELETE /notifications/read | notifications.view | Soft-delete all read |
| `preferences()` | GET /notifications/preferences | notifications.view | Get user preferences |
| `updatePreferences()` | PUT /notifications/preferences | notifications.view | Update preferences |

#### NotificationService (`src/Services/NotificationService.php`)

Core business logic. Instantiated with `PDO $db` like other services.

```php
class NotificationService {
    public function __construct(PDO $db);

    // Public API for other services
    public function notify(
        string $type,
        string $title,
        string $body,
        int|array $recipientIds,
        ?string $entityType = null,
        ?int $entityId = null,
        ?int $senderId = null
    ): void;

    // Controller-facing methods
    public function pollSince(int $userId, int $businessId, string $since): array;
    public function getAll(int $userId, int $businessId, array $filters, int $page, int $perPage): array;
    public function getUnreadCount(int $userId, int $businessId): int;
    public function markAsRead(int $notificationId, int $userId, int $businessId): void;
    public function markAllAsRead(int $userId, int $businessId): void;
    public function markBatchAsRead(array $ids, int $userId, int $businessId): void;
    public function sendUserMessage(int $senderId, int $recipientId, string $title, string $body, int $businessId): void;
    public function softDelete(int $notificationId, int $userId, int $businessId): void;
    public function clearAllRead(int $userId, int $businessId): void;
    public function getPreferences(int $userId, int $businessId): array;
    public function updatePreferences(int $userId, int $businessId, array $preferences): void;
    public function cleanup(int $retentionDays = 90): int;
}
```

#### NotificationRepository (`src/Repository/NotificationRepository.php`)

Data access layer for the `notifications` table.

```php
class NotificationRepository {
    public function __construct(PDO $db);

    public function create(array $data): int;
    public function findSince(int $userId, int $businessId, string $since): array;
    public function findAll(int $userId, int $businessId, array $filters, int $offset, int $limit): array;
    public function countAll(int $userId, int $businessId, array $filters): int;
    public function countUnread(int $userId, int $businessId): int;
    public function findById(int $id, int $userId, int $businessId): ?array;
    public function markAsRead(int $id, int $userId, int $businessId): void;
    public function markAllAsRead(int $userId, int $businessId): void;
    public function markBatchAsRead(array $ids, int $userId, int $businessId): void;
    public function softDelete(int $id, int $userId, int $businessId): void;
    public function softDeleteAllRead(int $userId, int $businessId): void;
    public function permanentDeleteOlderThan(int $days): int;
}
```

#### NotificationPreferenceRepository (`src/Repository/NotificationPreferenceRepository.php`)

Data access for the `notification_preferences` table.

```php
class NotificationPreferenceRepository {
    public function __construct(PDO $db);

    public function getForUser(int $userId, int $businessId): array;
    public function upsert(int $userId, int $businessId, string $type, bool $enabled): void;
    public function isTypeEnabled(int $userId, int $businessId, string $type): bool;
}
```

### Frontend Components

#### NotificationPollingService (`public/assets/js/services/NotificationPollingService.js`)

Singleton service managing the polling lifecycle.

```javascript
class NotificationPollingService {
    constructor(intervalMs = 30000);

    start();           // Begin polling
    stop();            // Stop polling
    pause();           // Pause (tab hidden)
    resume();          // Resume (tab visible) with immediate fetch
    onUpdate(callback); // Register listener for new notifications
    getUnreadCount();  // Current cached unread count
}
```

Integrates with existing `tab:activated` / `tab:deactivated` events from `app.js`.

#### NotificationCenter (`public/assets/js/components/NotificationCenter.js`)

Bell icon dropdown component rendered in the top navigation bar.

- Renders bell icon with unread badge
- Shows dropdown with 10 most recent notifications
- Auto-marks visible notifications as read after 3 seconds
- Provides "Mark all as read" and "View all" actions
- Handles click-to-navigate for entity references

#### Notifications Page (`public/assets/js/pages/Notifications/notifications.js`)

Full page at `#/notifications` following the existing page pattern (render/mount/unmount lifecycle).

- Paginated list (20 per page)
- Filter by type and read status
- Send notification form
- Preferences panel
- Delete and clear actions

## Data Models

### notifications table

```sql
CREATE TABLE notifications (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type          ENUM('PO_STATUS_CHANGE', 'INVOICE_DUE', 'STOCK_LOW', 'PAYMENT_RECEIVED', 'USER_MESSAGE', 'SYSTEM_ALERT') NOT NULL,
    title         VARCHAR(255) NOT NULL,
    body          TEXT NOT NULL,
    sender_id     INT UNSIGNED NULL,
    recipient_id  INT UNSIGNED NOT NULL,
    business_id   INT UNSIGNED NOT NULL,
    entity_type   VARCHAR(100) NULL,
    entity_id     INT UNSIGNED NULL,
    is_read       TINYINT(1) NOT NULL DEFAULT 0,
    read_at       DATETIME NULL,
    deleted_at    DATETIME NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_recipient_business (recipient_id, business_id, deleted_at, created_at),
    INDEX idx_recipient_unread (recipient_id, business_id, is_read, deleted_at),
    INDEX idx_poll (recipient_id, business_id, created_at, deleted_at),
    INDEX idx_cleanup (deleted_at),

    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### notification_preferences table

```sql
CREATE TABLE notification_preferences (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    business_id  INT UNSIGNED NOT NULL,
    type         ENUM('PO_STATUS_CHANGE', 'INVOICE_DUE', 'STOCK_LOW', 'PAYMENT_RECEIVED', 'USER_MESSAGE', 'SYSTEM_ALERT') NOT NULL,
    enabled      TINYINT(1) NOT NULL DEFAULT 1,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_user_business_type (user_id, business_id, type),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Entity Reference Mapping

| entity_type | Navigation Target |
|-------------|-------------------|
| purchase_orders | #/purchase-orders (with PO highlighted) |
| sales_invoices | #/sales-invoices |
| items | #/items |
| payments | #/payments |
| collections | #/collections |
| salaries | #/salaries |
| production_orders | #/production-orders |

### API Response Shapes

**Poll response** (`GET /notifications/poll?since=2024-01-01T00:00:00Z`):
```json
{
    "success": true,
    "data": {
        "notifications": [
            {
                "id": 42,
                "type": "PO_STATUS_CHANGE",
                "title": "PO Approved",
                "body": "Purchase Order PO-2024-015 has been approved",
                "sender_name": null,
                "entity_type": "purchase_orders",
                "entity_id": 15,
                "is_read": false,
                "created_at": "2024-01-15T10:30:00Z"
            }
        ],
        "unread_count": 5
    }
}
```

**Paginated list response** (`GET /notifications?page=1&per_page=20`):
```json
{
    "success": true,
    "data": {
        "notifications": [...],
        "pagination": {
            "page": 1,
            "per_page": 20,
            "total": 87,
            "total_pages": 5
        }
    }
}
```

## Correctness Properties

*A property is a characteristic or behavior that should hold true across all valid executions of a system — essentially, a formal statement about what the system should do. Properties serve as the bridge between human-readable specifications and machine-verifiable correctness guarantees.*

### Property 1: Permission-based recipient resolution

*For any* notification event targeting users with a specific permission (e.g., "purchaseOrders.approve", "items.edit", "finance.view", "manufacturing.view"), the set of recipients SHALL equal exactly the set of users who hold that permission in the same business — no more, no fewer.

**Validates: Requirements 1.1, 1.3, 1.4, 10.4**

### Property 2: Invoice due date window

*For any* sales invoice with a due date, the system SHALL create an INVOICE_DUE notification for the creator if and only if the due date is within 3 days of the current date (inclusive). Invoices with due dates more than 3 days away or already past SHALL NOT trigger a notification.

**Validates: Requirements 1.2**

### Property 3: User message data preservation

*For any* valid user-to-user message with sender_id, recipient_id, title, and body, the created notification SHALL preserve the sender_id as the authenticated user's ID and the body content exactly as provided.

**Validates: Requirements 1.5, 6.4**

### Property 4: Since-timestamp filtering

*For any* set of notifications and any "since" timestamp parameter, the polling endpoint SHALL return only notifications with created_at strictly greater than the "since" value. No notification with created_at ≤ since SHALL appear in the results.

**Validates: Requirements 2.5**

### Property 5: Dropdown ordering and limiting

*For any* set of N notifications belonging to a user, the notification dropdown SHALL display at most 10 notifications, and those 10 SHALL be the most recent by created_at, ordered descending (newest first).

**Validates: Requirements 3.2**

### Property 6: Body preview truncation

*For any* notification with a body of length L, the displayed body preview SHALL be the first min(L, 100) characters of the body. If L > 100, the preview SHALL be exactly 100 characters followed by an ellipsis indicator.

**Validates: Requirements 3.3**

### Property 7: Entity reference navigation mapping

*For any* notification with a non-null entity_type, clicking that notification SHALL produce a navigation URL that corresponds to the correct SPA route for that entity type according to the entity reference mapping table.

**Validates: Requirements 3.4**

### Property 8: Pagination correctness

*For any* total count of notifications T and page size P (default 20), requesting page N SHALL return at most P notifications, and total_pages SHALL equal ceil(T / P). The union of all pages SHALL equal the complete set with no duplicates or omissions.

**Validates: Requirements 4.2**

### Property 9: Type filter correctness

*For any* set of notifications with mixed types and any selected type filter, the returned results SHALL contain only notifications whose type matches the filter. No notification of a different type SHALL appear.

**Validates: Requirements 4.3**

### Property 10: Read status filter correctness

*For any* set of notifications with mixed read statuses and any selected read/unread filter, the returned results SHALL contain only notifications matching that read status.

**Validates: Requirements 4.4**

### Property 11: Mark as read sets timestamp

*For any* unread notification (is_read = false, read_at = null), marking it as read SHALL set is_read to true and read_at to a non-null timestamp representing the time of the operation.

**Validates: Requirements 5.2, 5.4**

### Property 12: Mark as read idempotence

*For any* notification that is already read (is_read = true, read_at = T), marking it as read again SHALL NOT change the read_at timestamp. The value of read_at SHALL remain T.

**Validates: Requirements 5.5**

### Property 13: Mark all as read completeness

*For any* set of notifications belonging to a user in a business, after executing "mark all as read", every notification in that set SHALL have is_read = true. No unread notification SHALL remain.

**Validates: Requirements 5.3**

### Property 14: Same-business validation

*For any* sender and recipient pair, the user-to-user send operation SHALL succeed if and only if both users belong to the same business. If they belong to different businesses (or recipient doesn't exist), the operation SHALL fail with a 400 response.

**Validates: Requirements 6.2, 6.3**

### Property 15: Preference round-trip

*For any* user, business, notification type, and enabled/disabled value, storing a preference and then retrieving it SHALL return the same enabled/disabled value that was stored.

**Validates: Requirements 7.1**

### Property 16: Preference-based notification filtering

*For any* user who has disabled a notification type in their preferences, the notify() method SHALL NOT create a notification of that type for that user. The notification SHALL be skipped entirely.

**Validates: Requirements 7.2**

### Property 17: USER_MESSAGE bypasses preferences

*For any* user preference configuration — even if USER_MESSAGE is explicitly disabled — notifications of type USER_MESSAGE SHALL always be created and delivered to the recipient.

**Validates: Requirements 7.5**

### Property 18: Soft-delete exclusion from queries

*For any* notification that has been soft-deleted (deleted_at is non-null), that notification SHALL NOT appear in any list, poll, or count query results for the user.

**Validates: Requirements 8.1**

### Property 19: Clear all read targets only read notifications

*For any* set of notifications belonging to a user, executing "clear all read" SHALL soft-delete all notifications where is_read = true, and SHALL NOT modify any notification where is_read = false.

**Validates: Requirements 8.2**

### Property 20: Cleanup removes only expired records

*For any* set of soft-deleted notifications, the cleanup mechanism SHALL permanently remove only those with deleted_at older than 90 days. Soft-deleted notifications with deleted_at within 90 days SHALL remain in the database.

**Validates: Requirements 8.4**

### Property 21: Access isolation

*For any* authenticated user and business context, all notification queries SHALL return only notifications where recipient_id matches the user's ID AND business_id matches the current business. Attempting to access a notification belonging to a different user or business SHALL result in a 403 Forbidden response.

**Validates: Requirements 9.2, 9.3**

### Property 22: notify() accepts single and array recipients

*For any* call to NotificationService::notify() with either a single recipient_id (int) or an array of recipient_ids, the method SHALL create one notification per recipient. The count of created notifications SHALL equal the count of (preference-eligible) recipients provided.

**Validates: Requirements 10.2**

### Property 23: Error isolation in notification creation

*For any* error that occurs during notification creation within NotificationService::notify(), the error SHALL be caught and logged, and the calling operation SHALL complete successfully without interruption or exception propagation.

**Validates: Requirements 10.5**

## Error Handling

### Backend Error Strategy

| Scenario | HTTP Status | Response |
|----------|-------------|----------|
| Missing/invalid JWT | 401 | `{"success": false, "message": "..."}` |
| Missing permission | 403 | `{"success": false, "message": "Missing permission: notifications.view"}` |
| Notification not found or not owned | 403 | `{"success": false, "message": "Forbidden"}` |
| Invalid recipient (cross-business) | 400 | `{"success": false, "message": "Recipient not found in your business"}` |
| Missing required fields | 400 | `{"success": false, "message": "Missing field: title"}` |
| Internal error in notify() | Logged, not propagated | Calling service continues normally |
| Database error in controller | 500 | `{"success": false, "message": "Internal server error"}` |

### Error Handling Patterns

1. **NotificationService::notify()** wraps all logic in try/catch. Errors are logged via `error_log()` and never thrown to the caller. This ensures notification failures don't break business operations.

2. **NotificationController** methods use try/catch with appropriate HTTP status codes, following the same pattern as existing controllers (e.g., `PurchaseOrdersController`).

3. **Frontend polling** silently handles network errors — failed polls are retried on the next interval without user-visible errors. Console warnings are logged for debugging.

4. **Preference checks** default to "enabled" if no preference record exists (fail-open for notifications, not fail-closed).

## Testing Strategy

### Property-Based Tests (Vitest + fast-check)

The project already has `fast-check` (v4.1.1) and `vitest` (v3.2.1) configured. Property-based tests will validate the correctness properties defined above.

**Configuration:**
- Minimum 100 iterations per property test
- Each test tagged with: `Feature: user-notifications, Property {N}: {title}`
- Tests target pure logic functions extracted from services (recipient resolution, filtering, preference checking, pagination math, timestamp comparisons)

**Test file structure:**
```
tests/
  properties/
    notifications.property.test.js
```

**Key testable functions (pure logic extracted for PBT):**
- `resolveRecipientsByPermission(users, permission)` — Property 1
- `isWithinDueDateWindow(dueDate, currentDate, windowDays)` — Property 2
- `filterSince(notifications, sinceTimestamp)` — Property 4
- `getTopN(notifications, n)` — Property 5
- `truncateBody(body, maxLength)` — Property 6
- `mapEntityToRoute(entityType, entityId)` — Property 7
- `paginate(items, page, perPage)` — Property 8
- `filterByType(notifications, type)` — Property 9
- `filterByReadStatus(notifications, isRead)` — Property 10
- `shouldDeliverNotification(type, preferences)` — Properties 16, 17
- `isExpiredForCleanup(deletedAt, retentionDays)` — Property 20

### Unit Tests (PHPUnit)

**Backend unit tests** for:
- NotificationService method behavior (create, read, delete flows)
- NotificationRepository query correctness
- NotificationPreferenceRepository CRUD
- Controller input validation and response formatting
- Access control enforcement (403 scenarios)
- Error isolation in notify() method

**Frontend unit tests** (Vitest) for:
- NotificationPollingService start/stop/pause/resume lifecycle
- NotificationCenter rendering and interaction
- Badge count updates
- Auto-mark-as-read timer behavior

### Integration Tests

- End-to-end notification creation from PO status change
- Polling endpoint with real database queries
- Preference filtering with database state
- Cleanup mechanism with time-based records
- Cross-business access denial

