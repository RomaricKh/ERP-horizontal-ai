<?php

namespace Tests\Unit;

use App\Repository\NotificationRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for NotificationRepository.
 *
 * Uses an in-memory SQLite database to verify the actual SQL query logic
 * for notification CRUD operations, filtering, soft-delete, and access isolation.
 *
 * Validates: Requirements 2.5, 5.4, 5.5, 8.1, 8.2, 8.4, 9.2
 */
class NotificationRepositoryTest extends TestCase
{
    private PDO $db;
    private NotificationRepository $repo;

    private const USER_ID = 1;
    private const BUSINESS_ID = 100;
    private const OTHER_USER_ID = 2;
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
        $this->repo = new NotificationRepository($this->db);
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
    }

    /**
     * Helper to insert a notification directly into the database.
     */
    private function insertNotification(array $overrides = []): int
    {
        $data = array_merge([
            'type'         => 'SYSTEM_ALERT',
            'title'        => 'Test Notification',
            'body'         => 'Test body content',
            'sender_id'    => null,
            'recipient_id' => self::USER_ID,
            'business_id'  => self::BUSINESS_ID,
            'entity_type'  => null,
            'entity_id'    => null,
            'is_read'      => 0,
            'read_at'      => null,
            'deleted_at'   => null,
            'created_at'   => '2024-01-15 10:00:00',
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

    // ─── create() Tests ─────────────────────────────────────────────────────────

    /**
     * Test create returns a valid positive integer ID.
     *
     * Validates: Requirements 2.5
     */
    public function testCreateReturnsValidId(): void
    {
        $id = $this->repo->create([
            'type'         => 'PO_STATUS_CHANGE',
            'title'        => 'PO Approved',
            'body'         => 'Purchase Order PO-2024-015 has been approved',
            'sender_id'    => null,
            'recipient_id' => self::USER_ID,
            'business_id'  => self::BUSINESS_ID,
            'entity_type'  => 'purchase_orders',
            'entity_id'    => 15,
        ]);

        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }

    /**
     * Test create returns incrementing IDs for multiple inserts.
     */
    public function testCreateReturnsIncrementingIds(): void
    {
        $id1 = $this->repo->create([
            'type'         => 'SYSTEM_ALERT',
            'title'        => 'First',
            'body'         => 'First notification',
            'recipient_id' => self::USER_ID,
            'business_id'  => self::BUSINESS_ID,
        ]);

        $id2 = $this->repo->create([
            'type'         => 'SYSTEM_ALERT',
            'title'        => 'Second',
            'body'         => 'Second notification',
            'recipient_id' => self::USER_ID,
            'business_id'  => self::BUSINESS_ID,
        ]);

        $this->assertGreaterThan($id1, $id2);
    }

    // ─── findSince() Tests ──────────────────────────────────────────────────────

    /**
     * Test findSince excludes soft-deleted notifications.
     *
     * Validates: Requirements 8.1
     */
    public function testFindSinceExcludesSoftDeleted(): void
    {
        // Active notification
        $this->insertNotification([
            'title'      => 'Active',
            'created_at' => '2024-01-15 12:00:00',
        ]);

        // Soft-deleted notification
        $this->insertNotification([
            'title'      => 'Deleted',
            'created_at' => '2024-01-15 13:00:00',
            'deleted_at' => '2024-01-16 10:00:00',
        ]);

        $results = $this->repo->findSince(self::USER_ID, self::BUSINESS_ID, '2024-01-15 00:00:00');

        $this->assertCount(1, $results);
        $this->assertSame('Active', $results[0]['title']);
    }

    /**
     * Test findSince respects timestamp boundary (only returns created_at > since).
     *
     * Validates: Requirements 2.5
     */
    public function testFindSinceRespectsTimestampBoundary(): void
    {
        // Before boundary
        $this->insertNotification([
            'title'      => 'Before',
            'created_at' => '2024-01-15 09:00:00',
        ]);

        // Exactly at boundary (should NOT be included, since uses >)
        $this->insertNotification([
            'title'      => 'AtBoundary',
            'created_at' => '2024-01-15 10:00:00',
        ]);

        // After boundary
        $this->insertNotification([
            'title'      => 'After',
            'created_at' => '2024-01-15 11:00:00',
        ]);

        $results = $this->repo->findSince(self::USER_ID, self::BUSINESS_ID, '2024-01-15 10:00:00');

        $this->assertCount(1, $results);
        $this->assertSame('After', $results[0]['title']);
    }

    // ─── findAll() Tests ────────────────────────────────────────────────────────

    /**
     * Test findAll with type filter returns only matching type.
     *
     * Validates: Requirements 9.2
     */
    public function testFindAllWithTypeFilter(): void
    {
        $this->insertNotification([
            'type'  => 'PO_STATUS_CHANGE',
            'title' => 'PO Changed',
        ]);

        $this->insertNotification([
            'type'  => 'INVOICE_DUE',
            'title' => 'Invoice Due',
        ]);

        $this->insertNotification([
            'type'  => 'PO_STATUS_CHANGE',
            'title' => 'Another PO',
        ]);

        $results = $this->repo->findAll(
            self::USER_ID,
            self::BUSINESS_ID,
            ['type' => 'PO_STATUS_CHANGE'],
            0,
            10
        );

        $this->assertCount(2, $results);
        foreach ($results as $row) {
            $this->assertSame('PO_STATUS_CHANGE', $row['type']);
        }
    }

    /**
     * Test findAll with read status filter returns only matching read status.
     *
     * Validates: Requirements 9.2
     */
    public function testFindAllWithReadStatusFilter(): void
    {
        $this->insertNotification([
            'title'   => 'Unread',
            'is_read' => 0,
        ]);

        $this->insertNotification([
            'title'   => 'Read',
            'is_read' => 1,
            'read_at' => '2024-01-15 11:00:00',
        ]);

        $this->insertNotification([
            'title'   => 'Also Unread',
            'is_read' => 0,
        ]);

        // Filter for unread only
        $results = $this->repo->findAll(
            self::USER_ID,
            self::BUSINESS_ID,
            ['is_read' => 0],
            0,
            10
        );

        $this->assertCount(2, $results);
        foreach ($results as $row) {
            $this->assertEquals(0, $row['is_read']);
        }

        // Filter for read only
        $results = $this->repo->findAll(
            self::USER_ID,
            self::BUSINESS_ID,
            ['is_read' => 1],
            0,
            10
        );

        $this->assertCount(1, $results);
        $this->assertEquals(1, $results[0]['is_read']);
    }

    /**
     * Test findAll excludes soft-deleted notifications.
     *
     * Validates: Requirements 8.1
     */
    public function testFindAllExcludesSoftDeleted(): void
    {
        $this->insertNotification([
            'title' => 'Active',
        ]);

        $this->insertNotification([
            'title'      => 'Deleted',
            'deleted_at' => '2024-01-16 10:00:00',
        ]);

        $results = $this->repo->findAll(self::USER_ID, self::BUSINESS_ID, [], 0, 10);

        $this->assertCount(1, $results);
        $this->assertSame('Active', $results[0]['title']);
    }

    // ─── markAsRead() Tests ─────────────────────────────────────────────────────

    /**
     * Test markAsRead does not update read_at if already read (idempotence).
     *
     * Validates: Requirements 5.5
     */
    public function testMarkAsReadIdempotence(): void
    {
        $originalReadAt = '2024-01-15 10:30:00';

        // Insert an already-read notification
        $id = $this->insertNotification([
            'is_read' => 1,
            'read_at' => $originalReadAt,
        ]);

        // Attempt to mark as read again
        $this->repo->markAsRead($id, self::USER_ID, self::BUSINESS_ID);

        // Verify read_at has not changed
        $stmt = $this->db->prepare("SELECT read_at FROM notifications WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertSame($originalReadAt, $row['read_at']);
    }

    /**
     * Test markAsRead sets is_read and read_at for unread notification.
     *
     * Validates: Requirements 5.4
     */
    public function testMarkAsReadSetsTimestampForUnread(): void
    {
        $id = $this->insertNotification([
            'is_read' => 0,
            'read_at' => null,
        ]);

        $this->repo->markAsRead($id, self::USER_ID, self::BUSINESS_ID);

        $stmt = $this->db->prepare("SELECT is_read, read_at FROM notifications WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertEquals(1, $row['is_read']);
        $this->assertNotNull($row['read_at']);
    }

    // ─── softDeleteAllRead() Tests ──────────────────────────────────────────────

    /**
     * Test softDeleteAllRead only targets read notifications.
     *
     * Validates: Requirements 8.2
     */
    public function testSoftDeleteAllReadOnlyTargetsReadNotifications(): void
    {
        // Unread notification
        $unreadId = $this->insertNotification([
            'title'   => 'Unread',
            'is_read' => 0,
        ]);

        // Read notification
        $readId = $this->insertNotification([
            'title'   => 'Read',
            'is_read' => 1,
            'read_at' => '2024-01-15 11:00:00',
        ]);

        // Another read notification
        $readId2 = $this->insertNotification([
            'title'   => 'Also Read',
            'is_read' => 1,
            'read_at' => '2024-01-15 12:00:00',
        ]);

        $this->repo->softDeleteAllRead(self::USER_ID, self::BUSINESS_ID);

        // Verify unread notification is NOT soft-deleted
        $stmt = $this->db->prepare("SELECT deleted_at FROM notifications WHERE id = :id");
        $stmt->execute(['id' => $unreadId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNull($row['deleted_at']);

        // Verify read notifications ARE soft-deleted
        $stmt->execute(['id' => $readId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotNull($row['deleted_at']);

        $stmt->execute(['id' => $readId2]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertNotNull($row['deleted_at']);
    }

    /**
     * Test softDeleteAllRead does not affect already soft-deleted notifications.
     *
     * Validates: Requirements 8.2
     */
    public function testSoftDeleteAllReadSkipsAlreadyDeleted(): void
    {
        $originalDeletedAt = '2024-01-10 08:00:00';

        // Already soft-deleted read notification
        $id = $this->insertNotification([
            'is_read'    => 1,
            'read_at'    => '2024-01-09 10:00:00',
            'deleted_at' => $originalDeletedAt,
        ]);

        $this->repo->softDeleteAllRead(self::USER_ID, self::BUSINESS_ID);

        // Verify deleted_at was not changed (the WHERE clause has deleted_at IS NULL)
        $stmt = $this->db->prepare("SELECT deleted_at FROM notifications WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame($originalDeletedAt, $row['deleted_at']);
    }

    // ─── permanentDeleteOlderThan() Tests ───────────────────────────────────────

    /**
     * Test permanentDeleteOlderThan respects retention boundary.
     * Uses a mock PDO since the method uses MySQL-specific INTERVAL syntax.
     *
     * Validates: Requirements 8.4
     */
    public function testPermanentDeleteOlderThanRespectsRetentionBoundary(): void
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
            ->willReturn(3);

        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('deleted_at < NOW() - INTERVAL'))
            ->willReturn($mockStmt);

        $repo = new NotificationRepository($mockPdo);
        $deletedCount = $repo->permanentDeleteOlderThan(90);

        $this->assertSame(3, $deletedCount);
    }

    /**
     * Test permanentDeleteOlderThan with zero results returns 0.
     *
     * Validates: Requirements 8.4
     */
    public function testPermanentDeleteOlderThanReturnsZeroWhenNothingToDelete(): void
    {
        $mockStmt = $this->createMock(\PDOStatement::class);
        $mockStmt->method('bindValue');
        $mockStmt->method('execute')->willReturn(true);
        $mockStmt->method('rowCount')->willReturn(0);

        $mockPdo = $this->createMock(PDO::class);
        $mockPdo->method('prepare')->willReturn($mockStmt);

        $repo = new NotificationRepository($mockPdo);
        $deletedCount = $repo->permanentDeleteOlderThan(90);

        $this->assertSame(0, $deletedCount);
    }

    // ─── findById() Tests ───────────────────────────────────────────────────────

    /**
     * Test findById returns null for wrong user.
     *
     * Validates: Requirements 9.2
     */
    public function testFindByIdReturnsNullForWrongUser(): void
    {
        $id = $this->insertNotification([
            'recipient_id' => self::USER_ID,
            'business_id'  => self::BUSINESS_ID,
        ]);

        // Try to access with a different user
        $result = $this->repo->findById($id, self::OTHER_USER_ID, self::BUSINESS_ID);

        $this->assertNull($result);
    }

    /**
     * Test findById returns null for wrong business.
     *
     * Validates: Requirements 9.2
     */
    public function testFindByIdReturnsNullForWrongBusiness(): void
    {
        $id = $this->insertNotification([
            'recipient_id' => self::USER_ID,
            'business_id'  => self::BUSINESS_ID,
        ]);

        // Try to access with a different business
        $result = $this->repo->findById($id, self::USER_ID, self::OTHER_BUSINESS_ID);

        $this->assertNull($result);
    }

    /**
     * Test findById returns the notification for correct user and business.
     *
     * Validates: Requirements 9.2
     */
    public function testFindByIdReturnsNotificationForCorrectUserAndBusiness(): void
    {
        $id = $this->insertNotification([
            'title'        => 'My Notification',
            'recipient_id' => self::USER_ID,
            'business_id'  => self::BUSINESS_ID,
        ]);

        $result = $this->repo->findById($id, self::USER_ID, self::BUSINESS_ID);

        $this->assertNotNull($result);
        $this->assertSame('My Notification', $result['title']);
        $this->assertEquals($id, $result['id']);
    }
}
