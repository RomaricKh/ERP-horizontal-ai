<?php
namespace App\Services;

use App\Repository\NotificationRepository;
use App\Repository\NotificationPreferenceRepository;
use PDO;

class NotificationService {
    private PDO $db;
    private NotificationRepository $notificationRepo;
    private NotificationPreferenceRepository $preferenceRepo;

    /**
     * All valid notification types matching the ENUM in the database.
     */
    private const NOTIFICATION_TYPES = [
        'PO_STATUS_CHANGE',
        'INVOICE_DUE',
        'STOCK_LOW',
        'PAYMENT_RECEIVED',
        'USER_MESSAGE',
        'SYSTEM_ALERT',
        'EXPENSE_DUE',
        'SALARY_DUE',
        'EXPENSE_OVERDUE',
        'SALARY_OVERDUE',
    ];

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->notificationRepo = new NotificationRepository($db);
        $this->preferenceRepo   = new NotificationPreferenceRepository($db);
    }

    /**
     * Create notifications for one or more recipients.
     *
     * This method NEVER throws exceptions — errors are logged and swallowed
     * to ensure notification failures don't break calling operations.
     *
     * USER_MESSAGE type always bypasses preference checks.
     *
     * @param string      $type         Notification type (ENUM value)
     * @param string      $title        Notification title
     * @param string      $body         Notification body
     * @param int|array   $recipientIds Single recipient ID or array of IDs
     * @param string|null $entityType   Related entity type (nullable)
     * @param int|null    $entityId     Related entity ID (nullable)
     * @param int|null    $senderId     Sender user ID (nullable)
     * @param int|null    $businessId   Business context (falls back to AuthContext)
     */
    public function notify(
        string $type,
        string $title,
        string $body,
        $recipientIds,
        ?string $entityType = null,
        ?int $entityId = null,
        ?int $senderId = null,
        ?int $businessId = null
    ): void {
        try {
            // Normalize to array
            if (is_int($recipientIds)) {
                $recipientIds = [$recipientIds];
            }

            if ($businessId === null) {
                $ctx = \App\Support\AuthContext::get();
                if ($ctx) {
                    $businessId = $ctx->business_id;
                }
            }

            foreach ($recipientIds as $recipientId) {
                // Check if recipient is actually in the target business
                if ($businessId === null || !$this->isUserInBusiness($recipientId, $businessId)) {
                    continue; // Skip if no business context or user not in business
                }

                // Check preferences (USER_MESSAGE always bypasses)
                if ($type !== 'USER_MESSAGE') {

                    if (!$this->preferenceRepo->isTypeEnabled($recipientId, $businessId, $type)) {
                        continue; // Skip — user has disabled this type
                    }

                    $this->notificationRepo->create([
                        'type'         => $type,
                        'title'        => $title,
                        'body'         => $body,
                        'sender_id'    => $senderId,
                        'recipient_id' => $recipientId,
                        'business_id'  => $businessId,
                        'entity_type'  => $entityType,
                        'entity_id'    => $entityId,
                    ]);
                } else {
                    // USER_MESSAGE — always deliver


                    $this->notificationRepo->create([
                        'type'         => $type,
                        'title'        => $title,
                        'body'         => $body,
                        'sender_id'    => $senderId,
                        'recipient_id' => $recipientId,
                        'business_id'  => $businessId,
                        'entity_type'  => $entityType,
                        'entity_id'    => $entityId,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            error_log("[NotificationService::notify] Error: " . $e->getMessage());
        }
    }

    /**
     * Poll for notifications created after the given timestamp.
     * Returns notifications and unread count.
     */
    public function pollSince(int $userId, int $businessId, string $since): array {
        $notifications = $this->notificationRepo->findSince($userId, $businessId, $since);
        $unreadCount   = $this->notificationRepo->countUnread($userId, $businessId);

        return [
            'notifications' => $notifications,
            'unread_count'  => $unreadCount,
        ];
    }

    /**
     * Get paginated notifications with filters.
     * Returns notifications and pagination metadata.
     */
    public function getAll(int $userId, int $businessId, array $filters, int $page, int $perPage): array {
        $offset = ($page - 1) * $perPage;
        $notifications = $this->notificationRepo->findAll($userId, $businessId, $filters, $offset, $perPage);
        $total = $this->notificationRepo->countAll($userId, $businessId, $filters);
        $totalPages = (int) ceil($total / $perPage);

        return [
            'notifications' => $notifications,
            'pagination'    => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total'       => $total,
                'total_pages' => $totalPages,
            ],
        ];
    }

    /**
     * Get the count of unread notifications for a user+business.
     */
    public function getUnreadCount(int $userId, int $businessId): int {
        return $this->notificationRepo->countUnread($userId, $businessId);
    }

    /**
     * Mark a single notification as read.
     * Verifies ownership (user+business) before marking.
     */
    public function markAsRead(int $notificationId, int $userId, int $businessId): void {
        $notification = $this->notificationRepo->findById($notificationId, $userId, $businessId);
        if ($notification === null) {
            return; // Not found or not owned — silently ignore
        }

        $this->notificationRepo->markAsRead($notificationId, $userId, $businessId);
    }

    /**
     * Mark all unread notifications as read for a user+business.
     */
    public function markAllAsRead(int $userId, int $businessId): void {
        $this->notificationRepo->markAllAsRead($userId, $businessId);
    }

    /**
     * Mark a batch of notification IDs as read for a user+business.
     */
    public function markBatchAsRead(array $ids, int $userId, int $businessId): void {
        $this->notificationRepo->markBatchAsRead($ids, $userId, $businessId);
    }

    /**
     * Send a user-to-user message notification.
     * Validates that sender and recipient belong to the same business.
     *
     * @throws \InvalidArgumentException if recipient is not in the same business
     */
    public function sendUserMessage(int $senderId, int $recipientId, string $title, string $body, int $businessId): void {
        // Validate recipient belongs to the same business or has a valid global role
        if (!$this->isUserInBusiness($recipientId, $businessId)) {
            throw new \InvalidArgumentException('Recipient not found in your business');
        }

        $this->notificationRepo->create([
            'type'         => 'USER_MESSAGE',
            'title'        => $title,
            'body'         => $body,
            'sender_id'    => $senderId,
            'recipient_id' => $recipientId,
            'business_id'  => $businessId,
            'entity_type'  => null,
            'entity_id'    => null,
        ]);
    }

    /**
     * Soft-delete a notification.
     * Verifies ownership (user+business) before deleting.
     */
    public function softDelete(int $notificationId, int $userId, int $businessId): void {
        $notification = $this->notificationRepo->findById($notificationId, $userId, $businessId);
        if ($notification === null) {
            return; // Not found or not owned — silently ignore
        }

        $this->notificationRepo->softDelete($notificationId, $userId, $businessId);
    }

    /**
     * Soft-delete all read notifications for a user+business.
     */
    public function clearAllRead(int $userId, int $businessId): void {
        $this->notificationRepo->softDeleteAllRead($userId, $businessId);
    }

    /**
     * Get notification preferences for a user+business.
     * Returns defaults (enabled=true) for types that have no record.
     */
    public function getPreferences(int $userId, int $businessId): array {
        $existing = $this->preferenceRepo->getForUser($userId, $businessId);

        // Index existing preferences by type
        $byType = [];
        foreach ($existing as $pref) {
            $byType[$pref['type']] = $pref;
        }

        // Build full list with defaults for missing types
        $preferences = [];
        foreach (self::NOTIFICATION_TYPES as $type) {
            if (isset($byType[$type])) {
                $preferences[] = [
                    'type'    => $type,
                    'enabled' => (bool) $byType[$type]['enabled'],
                ];
            } else {
                $preferences[] = [
                    'type'    => $type,
                    'enabled' => true, // Default enabled
                ];
            }
        }

        return $preferences;
    }

    /**
     * Update notification preferences for a user+business.
     * Upserts each type preference.
     *
     * @param array $preferences Array of ['type' => string, 'enabled' => bool]
     */
    public function updatePreferences(int $userId, int $businessId, array $preferences): void {
        foreach ($preferences as $pref) {
            if (isset($pref['type'], $pref['enabled'])) {
                $this->preferenceRepo->upsert(
                    $userId,
                    $businessId,
                    $pref['type'],
                    (bool) $pref['enabled']
                );
            }
        }
    }

    /**
     * Permanently delete soft-deleted notifications older than the retention period.
     * Returns the number of records deleted.
     */
    public function cleanup(int $retentionDays = 90): int {
        return $this->notificationRepo->permanentDeleteOlderThan($retentionDays);
    }

    /**
     * Check if a user belongs to a specific business.
     */
    private function isUserInBusiness(int $userId, int $businessId): bool {
        $stmt = $this->db->prepare("
            SELECT 1 FROM user_roles ur
            LEFT JOIN roles r ON ur.role_id = r.id
            LEFT JOIN businesses b ON b.id = :business_id
            WHERE ur.user_id = :user_id 
              AND ur.is_active = 1
              AND (
                  ur.business_id = :business_id 
                  OR (r.type = 'TENANT_OWNER' AND ur.tenant_id = b.tenant_id)
                  OR r.type = 'SYSTEM_OWNER'
              )
            LIMIT 1
        ");
        $stmt->execute(['user_id' => $userId, 'business_id' => $businessId]);
        return (bool) $stmt->fetch();
    }
}
