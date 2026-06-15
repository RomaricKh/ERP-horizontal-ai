<?php

namespace Tests\Unit;

use App\Services\NotificationService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for notification send + read flow.
 *
 * Validates: REQ-012 — send a notification, verify it appears in the
 * notifications list; mark as read, verify state persists.
 */
class NotificationReadFlowTest extends TestCase
{
    private PDO $db;
    private NotificationService $service;

    private const SENDER_ID = 1;
    private const RECIPIENT_ID = 2;
    private const BUSINESS_ID = 100;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        // Register NOW() function for SQLite compatibility with MySQL queries
        $this->db->sqliteCreateFunction('NOW', function () {
            return date('Y-m-d H:i:s');
        }, 0);

        $this->createSchema();
        $this->service = new NotificationService($this->db);
    }

    private function createSchema(): void
    {
        $this->db->exec("
            CREATE TABLE notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL,
                title TEXT NOT NULL,
                body TEXT NOT NULL,
                sender_id INTEGER NULL,
                recipient_id INTEGER NOT NULL,
                business_id INTEGER NOT NULL,
                entity_type TEXT NULL,
                entity_id INTEGER NULL,
                is_read INTEGER NOT NULL DEFAULT 0,
                read_at TEXT NULL,
                deleted_at TEXT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");

        $this->db->exec("
            CREATE TABLE notification_preferences (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                business_id INTEGER NOT NULL,
                type TEXT NOT NULL,
                enabled INTEGER NOT NULL DEFAULT 1,
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                UNIQUE(user_id, business_id, type)
            )
        ");

        $this->db->exec("
            CREATE TABLE user_roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                business_id INTEGER NOT NULL,
                role_id INTEGER NOT NULL DEFAULT 1
            )
        ");
    }

    /**
     * Test full notification lifecycle: send → appears in list → mark read → state persists.
     *
     * Validates: REQ-012 task 12.6
     */
    public function testSendNotificationAppearsInListAndMarkAsReadPersists(): void
    {
        // Arrange: assign recipient to a business
        $stmt = $this->db->prepare("
            INSERT INTO user_roles (user_id, business_id, role_id)
            VALUES (:user_id, :business_id, 1)
        ");
        $stmt->execute([
            'user_id'     => self::RECIPIENT_ID,
            'business_id' => self::BUSINESS_ID,
        ]);

        // Act: send a notification
        $this->service->notify(
            'STOCK_LOW',
            'Low Stock: Widget A',
            'Widget A stock is below minimum threshold (5 remaining)',
            self::RECIPIENT_ID,
            'items',
            42,
            self::SENDER_ID
        );

        // Assert: notification appears in the notifications list (getAll)
        $result = $this->service->getAll(self::RECIPIENT_ID, self::BUSINESS_ID, [], 1, 20);
        $notifications = $result['notifications'];

        $this->assertCount(1, $notifications);
        $this->assertSame('STOCK_LOW', $notifications[0]['type']);
        $this->assertSame('Low Stock: Widget A', $notifications[0]['title']);
        $this->assertSame('Widget A stock is below minimum threshold (5 remaining)', $notifications[0]['body']);
        $this->assertEquals(0, $notifications[0]['is_read']);
        $this->assertNull($notifications[0]['read_at']);

        // Assert: unread count is 1
        $unreadCount = $this->service->getUnreadCount(self::RECIPIENT_ID, self::BUSINESS_ID);
        $this->assertSame(1, $unreadCount);

        // Act: mark the notification as read
        $notificationId = (int) $notifications[0]['id'];
        $this->service->markAsRead($notificationId, self::RECIPIENT_ID, self::BUSINESS_ID);

        // Assert: read state persists — notification is now read
        $result = $this->service->getAll(self::RECIPIENT_ID, self::BUSINESS_ID, [], 1, 20);
        $notifications = $result['notifications'];

        $this->assertCount(1, $notifications);
        $this->assertEquals(1, $notifications[0]['is_read']);
        $this->assertNotNull($notifications[0]['read_at']);

        // Assert: unread count is now 0
        $unreadCount = $this->service->getUnreadCount(self::RECIPIENT_ID, self::BUSINESS_ID);
        $this->assertSame(0, $unreadCount);
    }

    /**
     * Test that notification preferences block delivery when type is disabled.
     *
     * Validates: REQ-012 task 12.5
     */
    public function testNotificationBlockedWhenPreferenceDisabled(): void
    {
        // Arrange: assign recipient to a business
        $stmt = $this->db->prepare("
            INSERT INTO user_roles (user_id, business_id, role_id)
            VALUES (:user_id, :business_id, 1)
        ");
        $stmt->execute([
            'user_id'     => self::RECIPIENT_ID,
            'business_id' => self::BUSINESS_ID,
        ]);

        // Disable STOCK_LOW notifications for this user
        $stmt = $this->db->prepare("
            INSERT INTO notification_preferences (user_id, business_id, type, enabled)
            VALUES (:user_id, :business_id, :type, :enabled)
        ");
        $stmt->execute([
            'user_id'     => self::RECIPIENT_ID,
            'business_id' => self::BUSINESS_ID,
            'type'        => 'STOCK_LOW',
            'enabled'     => 0,
        ]);

        // Act: attempt to send a notification
        $this->service->notify(
            'STOCK_LOW',
            'Low Stock: Widget B',
            'Widget B stock is below minimum',
            self::RECIPIENT_ID,
            'items',
            43,
            self::SENDER_ID
        );

        // Assert: notification was NOT created (preference blocked it)
        $result = $this->service->getAll(self::RECIPIENT_ID, self::BUSINESS_ID, [], 1, 20);
        $this->assertCount(0, $result['notifications']);
        $this->assertSame(0, $this->service->getUnreadCount(self::RECIPIENT_ID, self::BUSINESS_ID));
    }

    /**
     * Test that marking as read is idempotent — calling it twice doesn't error.
     *
     * Validates: REQ-012 task 12.6
     */
    public function testMarkAsReadIsIdempotent(): void
    {
        // Arrange: assign recipient and send notification
        $stmt = $this->db->prepare("
            INSERT INTO user_roles (user_id, business_id, role_id)
            VALUES (:user_id, :business_id, 1)
        ");
        $stmt->execute([
            'user_id'     => self::RECIPIENT_ID,
            'business_id' => self::BUSINESS_ID,
        ]);

        $this->service->notify(
            'SYSTEM_ALERT',
            'System Update',
            'System maintenance scheduled',
            self::RECIPIENT_ID,
            null,
            null,
            null
        );

        $result = $this->service->getAll(self::RECIPIENT_ID, self::BUSINESS_ID, [], 1, 20);
        $notificationId = (int) $result['notifications'][0]['id'];

        // Act: mark as read twice
        $this->service->markAsRead($notificationId, self::RECIPIENT_ID, self::BUSINESS_ID);
        $firstReadResult = $this->service->getAll(self::RECIPIENT_ID, self::BUSINESS_ID, [], 1, 20);
        $firstReadAt = $firstReadResult['notifications'][0]['read_at'];

        $this->service->markAsRead($notificationId, self::RECIPIENT_ID, self::BUSINESS_ID);
        $secondReadResult = $this->service->getAll(self::RECIPIENT_ID, self::BUSINESS_ID, [], 1, 20);
        $secondReadAt = $secondReadResult['notifications'][0]['read_at'];

        // Assert: read_at doesn't change on second call
        $this->assertSame($firstReadAt, $secondReadAt);
        $this->assertEquals(1, $secondReadResult['notifications'][0]['is_read']);
    }
}
