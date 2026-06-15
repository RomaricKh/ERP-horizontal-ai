<?php
namespace App\Repository;

use PDO;

class NotificationPreferenceRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Return all notification preference records for a user+business.
     *
     * @return array<int, array{id: int, user_id: int, business_id: int, type: string, enabled: int, updated_at: string}>
     */
    public function getForUser(int $userId, int $businessId): array {
        $stmt = $this->db->prepare("
            SELECT id, user_id, business_id, type, enabled, updated_at
            FROM notification_preferences
            WHERE user_id     = :user_id
              AND business_id = :business_id
        ");
        $stmt->execute([
            'user_id'     => $userId,
            'business_id' => $businessId,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Insert or update a notification preference for a user+business+type.
     * Uses MySQL's INSERT ... ON DUPLICATE KEY UPDATE pattern on the
     * unique key (user_id, business_id, type).
     */
    public function upsert(int $userId, int $businessId, string $type, bool $enabled): void {
        $stmt = $this->db->prepare("
            INSERT INTO notification_preferences
              (user_id, business_id, type, enabled)
            VALUES
              (:user_id, :business_id, :type, :enabled)
            ON DUPLICATE KEY UPDATE
              enabled    = VALUES(enabled),
              updated_at = NOW()
        ");
        $stmt->execute([
            'user_id'     => $userId,
            'business_id' => $businessId,
            'type'        => $type,
            'enabled'     => $enabled ? 1 : 0,
        ]);
    }

    /**
     * Set (upsert) a single notification preference for a user+business+type.
     * Alias for upsert() — satisfies the setPreference() contract.
     */
    public function setPreference(int $userId, int $businessId, string $type, bool $enabled): void {
        $this->upsert($userId, $businessId, $type, $enabled);
    }

    /**
     * Get all notification preferences for a user+business.
     * Alias for getForUser() — satisfies the getPreferences() contract.
     *
     * @return array<int, array{id: int, user_id: int, business_id: int, type: string, enabled: int, updated_at: string}>
     */
    public function getPreferences(int $userId, int $businessId): array {
        return $this->getForUser($userId, $businessId);
    }

    /**
     * Check if a notification type is enabled for a user+business.
     * Returns true if no record exists (default enabled / fail-open)
     * or if the record has enabled=1.
     */
    public function isTypeEnabled(int $userId, int $businessId, string $type): bool {
        $stmt = $this->db->prepare("
            SELECT enabled
            FROM notification_preferences
            WHERE user_id     = :user_id
              AND business_id = :business_id
              AND type        = :type
            LIMIT 1
        ");
        $stmt->execute([
            'user_id'     => $userId,
            'business_id' => $businessId,
            'type'        => $type,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // No record means default enabled (fail-open for notifications)
        if ($row === false) {
            return true;
        }

        return (bool) $row['enabled'];
    }
}
