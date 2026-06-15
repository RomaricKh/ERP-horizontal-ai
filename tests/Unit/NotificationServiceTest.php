<?php

namespace Tests\Unit;

use App\Services\NotificationService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for NotificationService.
 *
 * Uses an in-memory SQLite database with notifications, notification_preferences,
 * and user_roles tables to verify service-layer business logic.
 *
 * Validates: Requirements 6.2, 6.3, 7.2, 7.5, 10.2, 10.5
 */
class NotificationServiceTest extends TestCase
{
    private PDO $db;
    private NotificationService $service;

    private const SENDER_ID = 1;
    private const RECIPIENT_ID = 2;
    private const BUSINESS_ID = 100;
    private const OTHER_BUSINESS_ID = 200;

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
                notification_type_id INTEGER NULL,
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
                notification_type_id INTEGER NULL,
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
     * Helper to assign a user to a business in user_roles.
     */
    private function assignUserToBusiness(int $userId, int $businessId): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO user_roles (user_id, business_id, role_id)
            VALUES (:user_id, :business_id, 1)
        ");
        $stmt->execute([
            'user_id'     => $userId,
            'business_id' => $businessId,
        ]);
    }

    /**
     * Helper to set a notification preference for a user.
     */
    private function setPreference(int $userId, int $businessId, string $type, bool $enabled): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO notification_preferences (user_id, business_id, type, enabled)
            VALUES (:user_id, :business_id, :type, :enabled)
        ");
        $stmt->execute([
            'user_id'     => $userId,
            'business_id' => $businessId,
            'type'        => $type,
            'enabled'     => $enabled ? 1 : 0,
        ]);
    }

    /**
     * Helper to count notifications in the database.
     */
    private function countNotifications(): int
    {
        $stmt = $this->db->query("SELECT COUNT(*) AS total FROM notifications");
        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    }

    /**
     * Helper to get all notifications from the database.
     */
    private function getAllNotifications(): array
    {
        $stmt = $this->db->query("SELECT * FROM notifications ORDER BY id ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Helper to insert a notification directly.
     */
    private function insertNotification(array $overrides = []): int
    {
        $data = array_merge([
            'type'         => 'SYSTEM_ALERT',
            'title'        => 'Test Notification',
            'body'         => 'Test body',
            'sender_id'    => null,
            'recipient_id' => self::RECIPIENT_ID,
            'business_id'  => self::BUSINESS_ID,
            'entity_type'  => null,
            'entity_id'    => null,
            'is_read'      => 0,
            'read_at'      => null,
            'deleted_at'   => null,
            'created_at'   => date('Y-m-d H:i:s'),
        ], $overrides);

        $stmt = $this->db->prepare("
            INSERT INTO notifications
              (type, title, body, sender_id, recipient_id, business_id,
               entity_type, entity_id, is_read, read_at, deleted_at, created_at)
            VALUES
              (:type, :title, :body, :sender_id, :recipient_id, :business_id,
               :entity_type, :entity_id, :is_read, :read_at, :deleted_at, :created_at)
        ");
        $stmt->execute($data);
        return (int) $this->db->lastInsertId();
    }

    // ─── notify() Tests ─────────────────────────────────────────────────────────

    /**
     * Test notify() with single recipient creates one notification.
     *
     * Validates: Requirements 10.2
     */
    public function testNotifyWithSingleRecipientCreatesOneNotification(): void
    {
        $this->assignUserToBusiness(self::RECIPIENT_ID, self::BUSINESS_ID);

        $this->service->notify(
            'SYSTEM_ALERT',
            'Test Title',
            'Test Body',
            self::RECIPIENT_ID,
            null,
            null,
            self::SENDER_ID
        );

        $this->assertSame(1, $this->countNotifications());

        $notifications = $this->getAllNotifications();
        $this->assertSame('SYSTEM_ALERT', $notifications[0]['type']);
        $this->assertSame('Test Title', $notifications[0]['title']);
        $this->assertSame('Test Body', $notifications[0]['body']);
        $this->assertEquals(self::RECIPIENT_ID, $notifications[0]['recipient_id']);
    }

    /**
     * Test notify() with array of recipients creates one per eligible recipient.
     *
     * Validates: Requirements 10.2
     */
    public function testNotifyWithArrayOfRecipientsCreatesOnePerEligible(): void
    {
        $recipientA = 10;
        $recipientB = 11;
        $recipientC = 12;

        $this->assignUserToBusiness($recipientA, self::BUSINESS_ID);
        $this->assignUserToBusiness($recipientB, self::BUSINESS_ID);
        $this->assignUserToBusiness($recipientC, self::BUSINESS_ID);

        $this->service->notify(
            'PO_STATUS_CHANGE',
            'PO Updated',
            'Purchase order was approved',
            [$recipientA, $recipientB, $recipientC],
            'purchase_orders',
            42,
            self::SENDER_ID
        );

        $this->assertSame(3, $this->countNotifications());

        $notifications = $this->getAllNotifications();
        $recipientIds = array_column($notifications, 'recipient_id');
        $this->assertContains((string) $recipientA, $recipientIds);
        $this->assertContains((string) $recipientB, $recipientIds);
        $this->assertContains((string) $recipientC, $recipientIds);
    }

    /**
     * Test notify() skips recipients with disabled preferences.
     *
     * Validates: Requirements 7.2
     */
    public function testNotifySkipsRecipientsWithDisabledPreferences(): void
    {
        $recipientEnabled = 20;
        $recipientDisabled = 21;

        $this->assignUserToBusiness($recipientEnabled, self::BUSINESS_ID);
        $this->assignUserToBusiness($recipientDisabled, self::BUSINESS_ID);

        // Disable PO_STATUS_CHANGE for recipientDisabled
        $this->setPreference($recipientDisabled, self::BUSINESS_ID, 'PO_STATUS_CHANGE', false);

        $this->service->notify(
            'PO_STATUS_CHANGE',
            'PO Updated',
            'Purchase order was approved',
            [$recipientEnabled, $recipientDisabled],
            'purchase_orders',
            42,
            self::SENDER_ID
        );

        $this->assertSame(1, $this->countNotifications());

        $notifications = $this->getAllNotifications();
        $this->assertEquals($recipientEnabled, $notifications[0]['recipient_id']);
    }

    /**
     * Test notify() always delivers USER_MESSAGE regardless of preferences.
     *
     * Validates: Requirements 7.5
     */
    public function testNotifyAlwaysDeliversUserMessageRegardlessOfPreferences(): void
    {
        $recipient = 30;
        $this->assignUserToBusiness($recipient, self::BUSINESS_ID);

        // Explicitly disable USER_MESSAGE in preferences
        $this->setPreference($recipient, self::BUSINESS_ID, 'USER_MESSAGE', false);

        $this->service->notify(
            'USER_MESSAGE',
            'Hello',
            'This is a direct message',
            $recipient,
            null,
            null,
            self::SENDER_ID
        );

        $this->assertSame(1, $this->countNotifications());

        $notifications = $this->getAllNotifications();
        $this->assertSame('USER_MESSAGE', $notifications[0]['type']);
        $this->assertEquals($recipient, $notifications[0]['recipient_id']);
    }

    /**
     * Test notify() catches exceptions and does not throw.
     *
     * Validates: Requirements 10.5
     */
    public function testNotifyCatchesExceptionsAndDoesNotThrow(): void
    {
        // Create a PDO that will fail on prepare for the notification insert
        // by dropping the notifications table after service construction
        $this->assignUserToBusiness(self::RECIPIENT_ID, self::BUSINESS_ID);
        $this->db->exec("DROP TABLE notifications");

        // This should NOT throw — errors are caught internally
        $this->service->notify(
            'SYSTEM_ALERT',
            'Test',
            'Body',
            self::RECIPIENT_ID,
            null,
            null,
            null
        );

        // If we reach here, the test passes — no exception was thrown
        $this->assertTrue(true);
    }

    // ─── sendUserMessage() Tests ────────────────────────────────────────────────

    /**
     * Test sendUserMessage() fails with InvalidArgumentException for cross-business recipient.
     *
     * Validates: Requirements 6.2, 6.3
     */
    public function testSendUserMessageFailsForCrossBusinessRecipient(): void
    {
        // Sender is in BUSINESS_ID, recipient is in OTHER_BUSINESS_ID
        $this->assignUserToBusiness(self::SENDER_ID, self::BUSINESS_ID);
        $this->assignUserToBusiness(self::RECIPIENT_ID, self::OTHER_BUSINESS_ID);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Recipient not found in your business');

        $this->service->sendUserMessage(
            self::SENDER_ID,
            self::RECIPIENT_ID,
            'Hello',
            'Cross-business message',
            self::BUSINESS_ID
        );
    }

    // ─── markAsRead() Tests ─────────────────────────────────────────────────────

    /**
     * Test markAsRead() does not update already-read notification timestamp.
     *
     * Validates: Requirements 5.5
     */
    public function testMarkAsReadDoesNotUpdateAlreadyReadTimestamp(): void
    {
        $originalReadAt = '2024-01-15 10:30:00';

        $id = $this->insertNotification([
            'is_read' => 1,
            'read_at' => $originalReadAt,
        ]);

        $this->service->markAsRead($id, self::RECIPIENT_ID, self::BUSINESS_ID);

        $stmt = $this->db->prepare("SELECT read_at FROM notifications WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertSame($originalReadAt, $row['read_at']);
    }

    // ─── markAllAsRead() Tests ──────────────────────────────────────────────────

    /**
     * Test markAllAsRead() marks all unread as read.
     *
     * Validates: Requirements 5.3
     */
    public function testMarkAllAsReadMarksAllUnreadAsRead(): void
    {
        // Insert multiple unread notifications
        $this->insertNotification(['is_read' => 0, 'read_at' => null]);
        $this->insertNotification(['is_read' => 0, 'read_at' => null]);
        $this->insertNotification(['is_read' => 0, 'read_at' => null]);

        // Insert one already-read notification
        $this->insertNotification(['is_read' => 1, 'read_at' => '2024-01-15 10:00:00']);

        $this->service->markAllAsRead(self::RECIPIENT_ID, self::BUSINESS_ID);

        // All should now be read
        $stmt = $this->db->query("SELECT COUNT(*) AS total FROM notifications WHERE is_read = 0");
        $unreadCount = (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        $this->assertSame(0, $unreadCount);

        // All should have read_at set
        $stmt = $this->db->query("SELECT COUNT(*) AS total FROM notifications WHERE read_at IS NOT NULL");
        $readCount = (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        $this->assertSame(4, $readCount);
    }

    // ─── clearAllRead() Tests ───────────────────────────────────────────────────

    /**
     * Test clearAllRead() only soft-deletes read notifications.
     *
     * Validates: Requirements 8.2
     */
    public function testClearAllReadOnlySoftDeletesReadNotifications(): void
    {
        // Unread notifications
        $unreadId1 = $this->insertNotification(['is_read' => 0, 'read_at' => null]);
        $unreadId2 = $this->insertNotification(['is_read' => 0, 'read_at' => null]);

        // Read notifications
        $readId1 = $this->insertNotification(['is_read' => 1, 'read_at' => '2024-01-15 10:00:00']);
        $readId2 = $this->insertNotification(['is_read' => 1, 'read_at' => '2024-01-15 11:00:00']);

        $this->service->clearAllRead(self::RECIPIENT_ID, self::BUSINESS_ID);

        // Unread notifications should NOT be soft-deleted
        $stmt = $this->db->prepare("SELECT deleted_at FROM notifications WHERE id = :id");

        $stmt->execute(['id' => $unreadId1]);
        $this->assertNull($stmt->fetch(PDO::FETCH_ASSOC)['deleted_at']);

        $stmt->execute(['id' => $unreadId2]);
        $this->assertNull($stmt->fetch(PDO::FETCH_ASSOC)['deleted_at']);

        // Read notifications SHOULD be soft-deleted
        $stmt->execute(['id' => $readId1]);
        $this->assertNotNull($stmt->fetch(PDO::FETCH_ASSOC)['deleted_at']);

        $stmt->execute(['id' => $readId2]);
        $this->assertNotNull($stmt->fetch(PDO::FETCH_ASSOC)['deleted_at']);
    }

    // ─── cleanup() Tests ────────────────────────────────────────────────────────

    /**
     * Test cleanup() removes only records older than retention period.
     * Uses a mock PDO since the method uses MySQL-specific INTERVAL syntax.
     *
     * Validates: Requirements 8.4
     */
    public function testCleanupRemovesOnlyRecordsOlderThanRetentionPeriod(): void
    {
        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->expects($this->once())
            ->method('bindValue')
            ->with('days', 90, PDO::PARAM_INT);
        $mockStmt->expects($this->once())
            ->method('execute')
            ->willReturn(true);
        $mockStmt->expects($this->once())
            ->method('rowCount')
            ->willReturn(5);

        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('deleted_at'))
            ->willReturn($mockStmt);

        $service = new NotificationService($mockPdo);
        $deletedCount = $service->cleanup(90);

        $this->assertSame(5, $deletedCount);
    }
}
