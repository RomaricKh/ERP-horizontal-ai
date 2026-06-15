<?php
namespace App\Repository;

use PDO;

class SettingsRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Return the regional settings row for a business, or null if not found.
     * Joins the languages table to include the text direction.
     */
    public function findByBusiness(int $businessId): ?array {
        $stmt = $this->db->prepare("
            SELECT sr.business_id, sr.language, sr.timezone, sr.date_format,
                   sr.number_format, sr.currency_format, sr.phone_format,
                   l.direction
            FROM settings_regional sr
            JOIN languages l ON sr.language = l.code
            WHERE sr.business_id = :business_id
            LIMIT 1
        ");
        $stmt->execute(['business_id' => $businessId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Insert or update the regional settings row for a business.
     *
     * Expected keys in $data: language, timezone, date_format,
     *                          number_format, currency_format, phone_format
     */
    public function upsert(int $businessId, array $data): void {
        $stmt = $this->db->prepare("
            INSERT INTO settings_regional
              (business_id, language, timezone, date_format,
               number_format, currency_format, phone_format)
            VALUES
              (:business_id, :language, :timezone, :date_format,
               :number_format, :currency_format, :phone_format)
            ON DUPLICATE KEY UPDATE
              language        = VALUES(language),
              timezone        = VALUES(timezone),
              date_format     = VALUES(date_format),
              number_format   = VALUES(number_format),
              currency_format = VALUES(currency_format),
              phone_format    = VALUES(phone_format)
        ");
        $stmt->execute([
            'business_id'     => $businessId,
            'language'        => $data['language'],
            'timezone'        => $data['timezone'],
            'date_format'     => $data['date_format'],
            'number_format'   => $data['number_format'],
            'currency_format' => $data['currency_format'],
            'phone_format'    => $data['phone_format'],
        ]);
    }
}
