<?php
namespace App\Repository;

use PDO;

class NotificationRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Insert a new notification record and return the new ID.
     *
     * Expected keys: notification_type_id, title, body, recipient_id, business_id,
     *                sender_id (nullable), entity_type (nullable), entity_id (nullable)
     */
    public function create(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO notifications
              (notification_type_id, type, title, body, sender_id, recipient_id, business_id,
               entity_type, entity_id, is_read, created_at)
            VALUES
              (:notification_type_id, :type, :title, :body, :sender_id, :recipient_id, :business_id,
               :entity_type, :entity_id, 0, NOW())
        ");
        $stmt->execute([
            'notification_type_id' => $data['notification_type_id'] ?? null,
            'type'                 => $data['type'] ?? 'INFO',
            'title'                => $data['title'],
            'body'                 => $data['body'],
            'sender_id'            => $data['sender_id'] ?? null,
            'recipient_id'         => $data['recipient_id'],
            'business_id'          => $data['business_id'],
            'entity_type'          => $data['entity_type'] ?? null,
            'entity_id'            => $data['entity_id'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Return notifications created after the given timestamp for a user+business,
     * excluding soft-deleted records.
     */
    public function findSince(int $userId, int $businessId, string $since): array {
        $stmt = $this->db->prepare("
            SELECT
                id, type, title, body, sender_id, recipient_id, business_id,
                entity_type, entity_id, is_read, read_at, created_at
            FROM notifications
            WHERE recipient_id = :user_id
              AND business_id  = :business_id
              AND created_at   > :since
              AND deleted_at   IS NULL
            ORDER BY created_at DESC
        ");
        $stmt->execute([
            'user_id'     => $userId,
            'business_id' => $businessId,
            'since'       => $since,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return paginated notifications for a user+business with optional filters,
     * excluding soft-deleted records.
     *
     * Supported filters: type (string), is_read (0 or 1)
     */
    public function findAll(int $userId, int $businessId, array $filters, int $offset, int $limit): array {
        $sql = "
            SELECT
                id, type, title, body, sender_id, recipient_id, business_id,
                entity_type, entity_id, is_read, read_at, created_at
            FROM notifications
            WHERE recipient_id = :user_id
              AND business_id  = :business_id
              AND deleted_at   IS NULL
        ";
        $params = [
            'user_id'     => $userId,
            'business_id' => $businessId,
        ];

        if (isset($filters['type'])) {
            $sql .= " AND type = :type";
            $params['type'] = $filters['type'];
        }

        if (isset($filters['is_read'])) {
            $sql .= " AND is_read = :is_read";
            $params['is_read'] = (int) $filters['is_read'];
        }

        $sql .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Count notifications matching the same filters as findAll,
     * excluding soft-deleted records.
     */
    public function countAll(int $userId, int $businessId, array $filters): int {
        $sql = "
            SELECT COUNT(*) AS total
            FROM notifications
            WHERE recipient_id = :user_id
              AND business_id  = :business_id
              AND deleted_at   IS NULL
        ";
        $params = [
            'user_id'     => $userId,
            'business_id' => $businessId,
        ];

        if (isset($filters['type'])) {
            $sql .= " AND type = :type";
            $params['type'] = $filters['type'];
        }

        if (isset($filters['is_read'])) {
            $sql .= " AND is_read = :is_read";
            $params['is_read'] = (int) $filters['is_read'];
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) $row['total'];
    }

    /**
     * Count unread notifications for a user+business,
     * excluding soft-deleted records.
     */
    public function countUnread(int $userId, int $businessId): int {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) AS total
            FROM notifications
            WHERE recipient_id = :user_id
              AND business_id  = :business_id
              AND is_read      = 0
              AND deleted_at   IS NULL
        ");
        $stmt->execute([
            'user_id'     => $userId,
            'business_id' => $businessId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) $row['total'];
    }

    /**
     * Return a single notification by ID, scoped to user+business.
     * Returns null if not found or does not belong to the user+business.
     */
    public function findById(int $id, int $userId, int $businessId): ?array {
        $stmt = $this->db->prepare("
            SELECT
                id, type, title, body, sender_id, recipient_id, business_id,
                entity_type, entity_id, is_read, read_at, deleted_at, created_at
            FROM notifications
            WHERE id           = :id
              AND recipient_id = :user_id
              AND business_id  = :business_id
            LIMIT 1
        ");
        $stmt->execute([
            'id'          => $id,
            'user_id'     => $userId,
            'business_id' => $businessId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Mark a notification as read (sets is_read=1 and read_at=NOW())
     * only if it is currently unread (idempotence).
     */
    public function markAsRead(int $id, int $userId, int $businessId): void {
        $stmt = $this->db->prepare("
            UPDATE notifications
            SET is_read = 1,
                read_at = NOW()
            WHERE id           = :id
              AND recipient_id = :user_id
              AND business_id  = :business_id
              AND is_read      = 0
        ");
        $stmt->execute([
            'id'          => $id,
            'user_id'     => $userId,
            'business_id' => $businessId,
        ]);
    }

    /**
     * Mark all unread notifications as read for a user+business.
     */
    public function markAllAsRead(int $userId, int $businessId): void {
        $stmt = $this->db->prepare("
            UPDATE notifications
            SET is_read = 1,
                read_at = NOW()
            WHERE recipient_id = :user_id
              AND business_id  = :business_id
              AND is_read      = 0
              AND deleted_at   IS NULL
        ");
        $stmt->execute([
            'user_id'     => $userId,
            'business_id' => $businessId,
        ]);
    }

    /**
     * Mark specific notification IDs as read for a user+business.
     * Only updates notifications that are currently unread.
     */
    public function markBatchAsRead(array $ids, int $userId, int $businessId): void {
        if (empty($ids)) {
            return;
        }

        $placeholders = [];
        $params = [
            'user_id'     => $userId,
            'business_id' => $businessId,
        ];

        foreach ($ids as $index => $id) {
            $key = "id_{$index}";
            $placeholders[] = ":{$key}";
            $params[$key] = (int) $id;
        }

        $inClause = implode(', ', $placeholders);

        $stmt = $this->db->prepare("
            UPDATE notifications
            SET is_read = 1,
                read_at = NOW()
            WHERE id IN ({$inClause})
              AND recipient_id = :user_id
              AND business_id  = :business_id
              AND is_read      = 0
        ");
        $stmt->execute($params);
    }

    /**
     * Soft-delete a notification (sets deleted_at=NOW()),
     * scoped to user+business.
     */
    public function softDelete(int $id, int $userId, int $businessId): void {
        $stmt = $this->db->prepare("
            UPDATE notifications
            SET deleted_at = NOW()
            WHERE id           = :id
              AND recipient_id = :user_id
              AND business_id  = :business_id
        ");
        $stmt->execute([
            'id'          => $id,
            'user_id'     => $userId,
            'business_id' => $businessId,
        ]);
    }

    /**
     * Soft-delete all read notifications for a user+business.
     */
    public function softDeleteAllRead(int $userId, int $businessId): void {
        $stmt = $this->db->prepare("
            UPDATE notifications
            SET deleted_at = NOW()
            WHERE recipient_id = :user_id
              AND business_id  = :business_id
              AND is_read      = 1
              AND deleted_at   IS NULL
        ");
        $stmt->execute([
            'user_id'     => $userId,
            'business_id' => $businessId,
        ]);
    }

    /**
     * Permanently delete notifications where deleted_at is older than the given number of days.
     * Returns the number of records deleted.
     */
    public function permanentDeleteOlderThan(int $days): int {
        $stmt = $this->db->prepare("
            DELETE FROM notifications
            WHERE deleted_at IS NOT NULL
              AND deleted_at < NOW() - INTERVAL :days DAY
        ");
        $stmt->bindValue('days', $days, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount();
    }
}
