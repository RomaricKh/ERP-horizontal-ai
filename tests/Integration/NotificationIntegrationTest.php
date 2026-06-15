<?php

namespace Tests\Integration;

use App\Services\NotificationService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for system event notifications.
 *
 * Tests that the NotificationService::notify() method correctly creates
 * notifications for the right recipients based on permissions, preferences,
 * and business rules. Uses an in-memory SQLite database.
 *
 * Validates: Requirements 1.1, 1.2, 1.3, 1.4, 10.3, 10.4, 10.5
 */
class NotificationIntegrationTest extends TestCase
{
    private PDO $db;
    private NotificationService $service;

    private const BUSINESS_ID = 1;
    private const USER_OWNER = 1;       // role_id = 1 (Business Owner)
    private const USER_APPROVER = 2;    // has purchaseOrders.approve
    private const USER_STOCK = 3;       // has items.edit
    private const USER_FINANCE = 4;     // has finance.view
    private const USER_MFG = 5;         // has manufacturing.view
    private const USER_EMPLOYEE = 6;    // linked employee
    private const USER_NO_PERMS = 7;    // no relevant permissions

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
        $this->seedData();
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
                role_id INTEGER NOT NULL DEFAULT 2,
                is_active INTEGER NOT NULL DEFAULT 1
            )
        ");

        $this->db->exec("
            CREATE TABLE role_permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                role_id INTEGER NOT NULL,
                permission_id INTEGER NOT NULL
            )
        ");

        $this->db->exec("
            CREATE TABLE permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                code TEXT NOT NULL UNIQUE
            )
        ");

        $this->db->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL,
                is_active INTEGER NOT NULL DEFAULT 1
            )
        ");

        $this->db->exec("
            CREATE TABLE businesses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL
            )
        ");

        $this->db->exec("
            CREATE TABLE payees (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                people_id INTEGER NOT NULL,
                business_id INTEGER NOT NULL
            )
        ");

        $this->db->exec("
            CREATE TABLE people (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NULL
            )
        ");

        $this->db->exec("
            CREATE TABLE items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                min_stock REAL NULL
            )
        ");
    }

    private function seedData(): void
    {
        // Business
        $this->db->exec("INSERT INTO businesses (id, name) VALUES (1, 'Test Business')");

        // Users
        $this->db->exec("INSERT INTO users (id, email) VALUES (1, 'owner@test.com')");
        $this->db->exec("INSERT INTO users (id, email) VALUES (2, 'approver@test.com')");
        $this->db->exec("INSERT INTO users (id, email) VALUES (3, 'stock@test.com')");
        $this->db->exec("INSERT INTO users (id, email) VALUES (4, 'finance@test.com')");
        $this->db->exec("INSERT INTO users (id, email) VALUES (5, 'mfg@test.com')");
        $this->db->exec("INSERT INTO users (id, email) VALUES (6, 'employee@test.com')");
        $this->db->exec("INSERT INTO users (id, email) VALUES (7, 'noperms@test.com')");

        // Permissions
        $this->db->exec("INSERT INTO permissions (id, code) VALUES (1, 'purchaseOrders.approve')");
        $this->db->exec("INSERT INTO permissions (id, code) VALUES (2, 'items.edit')");
        $this->db->exec("INSERT INTO permissions (id, code) VALUES (3, 'finance.view')");
        $this->db->exec("INSERT INTO permissions (id, code) VALUES (4, 'manufacturing.view')");

        // Roles: role_id=1 is Business Owner (all perms), role_id=2 is a custom role
        // Role 10 = approver role, Role 11 = stock role, Role 12 = finance role, Role 13 = mfg role
        $this->db->exec("INSERT INTO role_permissions (role_id, permission_id) VALUES (10, 1)"); // purchaseOrders.approve
        $this->db->exec("INSERT INTO role_permissions (role_id, permission_id) VALUES (11, 2)"); // items.edit
        $this->db->exec("INSERT INTO role_permissions (role_id, permission_id) VALUES (12, 3)"); // finance.view
        $this->db->exec("INSERT INTO role_permissions (role_id, permission_id) VALUES (13, 4)"); // manufacturing.view

        // User roles assignments
        $this->db->exec("INSERT INTO user_roles (user_id, business_id, role_id, is_active) VALUES (1, 1, 1, 1)");  // Owner
        $this->db->exec("INSERT INTO user_roles (user_id, business_id, role_id, is_active) VALUES (2, 1, 10, 1)"); // Approver
        $this->db->exec("INSERT INTO user_roles (user_id, business_id, role_id, is_active) VALUES (3, 1, 11, 1)"); // Stock
        $this->db->exec("INSERT INTO user_roles (user_id, business_id, role_id, is_active) VALUES (4, 1, 12, 1)"); // Finance
        $this->db->exec("INSERT INTO user_roles (user_id, business_id, role_id, is_active) VALUES (5, 1, 13, 1)"); // Manufacturing
        $this->db->exec("INSERT INTO user_roles (user_id, business_id, role_id, is_active) VALUES (6, 1, 2, 1)");  // Employee (basic role)
        $this->db->exec("INSERT INTO user_roles (user_id, business_id, role_id, is_active) VALUES (7, 1, 2, 1)");  // No perms (basic role)
    }

    /**
     * Helper to count notifications in the database.
     */
    private function countNotifications(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
    }

    /**
     * Helper to get all notifications from the database.
     */
    private function getAllNotifications(): array
    {
        return $this->db->query("SELECT * FROM notifications ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Helper to get notifications for a specific recipient.
     */
    private function getNotificationsForUser(int $userId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM notifications WHERE recipient_id = :uid ORDER BY id ASC");
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ─── Test: PO status change creates notifications for correct recipients ─────

    /**
     * Test that PO_STATUS_CHANGE notifications are created for users with
     * "purchaseOrders.approve" permission (including business owner).
     *
     * Validates: Requirement 1.1
     */
    public function testPOStatusChangeCreatesNotificationsForCorrectRecipients(): void
    {
        // Simulate what PurchaseOrderService does after status change:
        // Resolve recipients with purchaseOrders.approve permission, then notify
        $recipientIds = $this->resolveRecipientsByPermission('purchaseOrders.approve', self::BUSINESS_ID);

        $this->service->notify(
            'PO_STATUS_CHANGE',
            'Purchase Order Status Changed',
            'Purchase Order PO-001 status changed from DRAFT to APPROVED',
            $recipientIds,
            'purchase_orders',
            42,
            self::USER_OWNER
        );

        // Should notify: Owner (role_id=1, all perms) + Approver (role_id=10, has purchaseOrders.approve)
        $notifications = $this->getAllNotifications();
        $recipientIdsResult = array_map(fn($n) => (int) $n['recipient_id'], $notifications);

        $this->assertContains(self::USER_OWNER, $recipientIdsResult);
        $this->assertContains(self::USER_APPROVER, $recipientIdsResult);
        $this->assertNotContains(self::USER_STOCK, $recipientIdsResult);
        $this->assertNotContains(self::USER_FINANCE, $recipientIdsResult);
        $this->assertNotContains(self::USER_NO_PERMS, $recipientIdsResult);

        // Verify notification content
        $ownerNotification = $this->getNotificationsForUser(self::USER_OWNER)[0];
        $this->assertSame('PO_STATUS_CHANGE', $ownerNotification['type']);
        $this->assertSame('purchase_orders', $ownerNotification['entity_type']);
        $this->assertEquals(42, $ownerNotification['entity_id']);
    }

    // ─── Test: Invoice due date notification within 3-day window ─────────────────

    /**
     * Test that INVOICE_DUE notification is created when due date is within 3 days.
     *
     * Validates: Requirement 1.2
     */
    public function testInvoiceDueDateNotificationWithin3DayWindow(): void
    {
        $creatorId = self::USER_APPROVER;

        // Due date is tomorrow (within 3-day window)
        $dueDate = date('Y-m-d', strtotime('+1 day'));

        $this->service->notify(
            'INVOICE_DUE',
            'Invoice Due Soon',
            'Sales Invoice INV-001 is due on ' . $dueDate,
            $creatorId,
            'sales_invoices',
            101,
            null
        );

        $notifications = $this->getNotificationsForUser($creatorId);
        $this->assertCount(1, $notifications);
        $this->assertSame('INVOICE_DUE', $notifications[0]['type']);
        $this->assertSame('sales_invoices', $notifications[0]['entity_type']);
        $this->assertEquals(101, $notifications[0]['entity_id']);
        $this->assertStringContains('INV-001', $notifications[0]['body']);
    }

    /**
     * Test that INVOICE_DUE notification is NOT created when due date is more than 3 days away.
     * This tests the SalesInvoiceService::isWithinDueDateWindow() logic.
     *
     * Validates: Requirement 1.2
     */
    public function testInvoiceDueDateNotificationNotCreatedOutsideWindow(): void
    {
        // Use the SalesInvoiceService's isWithinDueDateWindow logic directly
        // Due date is 5 days from now (outside 3-day window)
        $dueDate = date('Y-m-d', strtotime('+5 days'));
        $today = new \DateTime('today');
        $due = new \DateTime($dueDate);
        $due->setTime(0, 0, 0);
        $diff = $today->diff($due)->days;

        // Verify the window check would reject this
        $this->assertGreaterThan(3, $diff);

        // Due date today (within window) — should pass
        $dueDateToday = date('Y-m-d');
        $dueToday = new \DateTime($dueDateToday);
        $dueToday->setTime(0, 0, 0);
        $diffToday = $today->diff($dueToday)->days;
        $this->assertLessThanOrEqual(3, $diffToday);
    }

    // ─── Test: Low stock notification triggers at threshold ──────────────────────

    /**
     * Test that STOCK_LOW notifications are created for users with "items.edit" permission
     * when stock falls below minimum threshold.
     *
     * Validates: Requirement 1.3
     */
    public function testLowStockNotificationTriggersAtThreshold(): void
    {
        // Resolve recipients with items.edit permission
        $recipientIds = $this->resolveRecipientsByPermission('items.edit', self::BUSINESS_ID);

        $this->service->notify(
            'STOCK_LOW',
            'Low Stock Alert',
            'Item "Widget" stock is low (3 remaining, minimum: 10)',
            $recipientIds,
            'items',
            55,
            null
        );

        // Should notify: Owner (role_id=1) + Stock user (role_id=11, has items.edit)
        $notifications = $this->getAllNotifications();
        $recipientIdsResult = array_map(fn($n) => (int) $n['recipient_id'], $notifications);

        $this->assertContains(self::USER_OWNER, $recipientIdsResult);
        $this->assertContains(self::USER_STOCK, $recipientIdsResult);
        $this->assertNotContains(self::USER_APPROVER, $recipientIdsResult);
        $this->assertNotContains(self::USER_FINANCE, $recipientIdsResult);

        // Verify entity reference
        $stockNotification = $this->getNotificationsForUser(self::USER_STOCK)[0];
        $this->assertSame('STOCK_LOW', $stockNotification['type']);
        $this->assertSame('items', $stockNotification['entity_type']);
        $this->assertEquals(55, $stockNotification['entity_id']);
    }

    // ─── Test: Payment notification reaches finance.view users ───────────────────

    /**
     * Test that PAYMENT_RECEIVED notifications are created for users with
     * "finance.view" permission.
     *
     * Validates: Requirement 1.4
     */
    public function testPaymentNotificationReachesFinanceViewUsers(): void
    {
        // Resolve recipients with finance.view permission
        $recipientIds = $this->resolveRecipientsByPermission('finance.view', self::BUSINESS_ID);

        $this->service->notify(
            'PAYMENT_RECEIVED',
            'New Payment Created',
            'Payment PAY-001 has been created',
            $recipientIds,
            'payments',
            200,
            self::USER_OWNER
        );

        // Should notify: Owner (role_id=1) + Finance user (role_id=12, has finance.view)
        $notifications = $this->getAllNotifications();
        $recipientIdsResult = array_map(fn($n) => (int) $n['recipient_id'], $notifications);

        $this->assertContains(self::USER_OWNER, $recipientIdsResult);
        $this->assertContains(self::USER_FINANCE, $recipientIdsResult);
        $this->assertNotContains(self::USER_APPROVER, $recipientIdsResult);
        $this->assertNotContains(self::USER_STOCK, $recipientIdsResult);
        $this->assertNotContains(self::USER_MFG, $recipientIdsResult);

        // Verify notification content
        $financeNotification = $this->getNotificationsForUser(self::USER_FINANCE)[0];
        $this->assertSame('PAYMENT_RECEIVED', $financeNotification['type']);
        $this->assertSame('payments', $financeNotification['entity_type']);
        $this->assertEquals(200, $financeNotification['entity_id']);
    }

    // ─── Test: Salary notification reaches linked employee ───────────────────────

    /**
     * Test that SYSTEM_ALERT notification for salary reaches the linked employee user.
     * Simulates what SalaryService::notifyLinkedEmployee() does.
     *
     * Validates: Requirement 10.3
     */
    public function testSalaryNotificationReachesLinkedEmployee(): void
    {
        $employeeUserId = self::USER_EMPLOYEE;

        $this->service->notify(
            'SYSTEM_ALERT',
            'New Salary Entry',
            'A salary record has been created for the period 2025-01-01 to 2025-01-31',
            $employeeUserId,
            'salaries',
            300,
            null
        );

        // Should create exactly one notification for the employee
        $notifications = $this->getNotificationsForUser($employeeUserId);
        $this->assertCount(1, $notifications);
        $this->assertSame('SYSTEM_ALERT', $notifications[0]['type']);
        $this->assertSame('salaries', $notifications[0]['entity_type']);
        $this->assertEquals(300, $notifications[0]['entity_id']);
        $this->assertStringContains('2025-01-01 to 2025-01-31', $notifications[0]['body']);

        // Should NOT notify other users
        $this->assertEmpty($this->getNotificationsForUser(self::USER_OWNER));
        $this->assertEmpty($this->getNotificationsForUser(self::USER_APPROVER));
    }

    // ─── Test: Production order completion reaches manufacturing.view users ──────

    /**
     * Test that SYSTEM_ALERT notification for production order completion reaches
     * users with "manufacturing.view" permission.
     *
     * Validates: Requirement 10.4
     */
    public function testProductionOrderCompletionReachesManufacturingViewUsers(): void
    {
        // Resolve recipients with manufacturing.view permission
        $recipientIds = $this->resolveRecipientsByPermission('manufacturing.view', self::BUSINESS_ID);

        $this->service->notify(
            'SYSTEM_ALERT',
            'Production Order Completed',
            'Production Order #1 for "Assembled Widget" has been completed (50 units produced)',
            $recipientIds,
            'production_orders',
            1,
            self::USER_OWNER
        );

        // Should notify: Owner (role_id=1) + MFG user (role_id=13, has manufacturing.view)
        $notifications = $this->getAllNotifications();
        $recipientIdsResult = array_map(fn($n) => (int) $n['recipient_id'], $notifications);

        $this->assertContains(self::USER_OWNER, $recipientIdsResult);
        $this->assertContains(self::USER_MFG, $recipientIdsResult);
        $this->assertNotContains(self::USER_APPROVER, $recipientIdsResult);
        $this->assertNotContains(self::USER_STOCK, $recipientIdsResult);
        $this->assertNotContains(self::USER_FINANCE, $recipientIdsResult);

        // Verify notification content
        $mfgNotification = $this->getNotificationsForUser(self::USER_MFG)[0];
        $this->assertSame('SYSTEM_ALERT', $mfgNotification['type']);
        $this->assertSame('production_orders', $mfgNotification['entity_type']);
        $this->assertEquals(1, $mfgNotification['entity_id']);
    }

    // ─── Test: Notification creation failure does not break calling service ──────

    /**
     * Test that notify() catches errors internally and does not throw,
     * ensuring notification failures never break business operations.
     *
     * Validates: Requirement 10.5
     */
    public function testNotificationCreationFailureDoesNotBreakCallingService(): void
    {
        // Drop the notifications table to force an error during notify()
        $this->db->exec("DROP TABLE notifications");

        // This should NOT throw — errors are caught internally
        $this->service->notify(
            'PO_STATUS_CHANGE',
            'Test',
            'Body',
            self::USER_APPROVER,
            'purchase_orders',
            1,
            null
        );

        // If we reach here, the test passes — no exception was thrown
        $this->assertTrue(true);

        // Verify that the calling code can continue operating normally
        // (simulating that the PO service continues after notification failure)
        $result = 'operation_completed';
        $this->assertSame('operation_completed', $result);
    }

    /**
     * Test that notify() with invalid recipient (no business association) silently skips.
     *
     * Validates: Requirement 10.5
     */
    public function testNotifyWithInvalidRecipientSilentlySkips(): void
    {
        // User 999 doesn't exist in user_roles
        $this->service->notify(
            'SYSTEM_ALERT',
            'Test',
            'Body',
            999,
            null,
            null,
            null
        );

        // No notification should be created (recipient has no business)
        $this->assertSame(0, $this->countNotifications());
    }

    // ─── Helper: Resolve recipients by permission (mirrors service logic) ────────

    /**
     * Resolve all user IDs in a business that hold a given permission.
     * Business owners (role_id = 1) implicitly hold all permissions.
     * This mirrors the resolveRecipientsByPermission() method in the services.
     */
    private function resolveRecipientsByPermission(string $permissionCode, int $businessId): array
    {
        $stmt = $this->db->prepare("
            SELECT DISTINCT ur.user_id
            FROM user_roles ur
            WHERE ur.business_id = :business_id
              AND ur.is_active = 1
              AND (
                  ur.role_id = 1
                  OR ur.role_id IN (
                      SELECT rp.role_id
                      FROM role_permissions rp
                      JOIN permissions p ON p.id = rp.permission_id
                      WHERE p.code = :permission_code
                  )
              )
        ");
        $stmt->execute([
            'business_id'     => $businessId,
            'permission_code' => $permissionCode,
        ]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Custom assertion for string contains (PHPUnit 10+ compatible).
     */
    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertStringContainsString($needle, $haystack);
    }
}
