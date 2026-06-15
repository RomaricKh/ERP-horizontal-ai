<?php

namespace App\Repository;

use PDO;

class TranslationRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Check if a language code exists in the languages table.
     */
    public function languageExists(string $lang): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM languages WHERE code = :code
        ");
        $stmt->execute(['code' => $lang]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Fetch all translations for a language as [key => value].
     */
    public function findByLanguage(string $lang): array
    {
        $stmt = $this->db->prepare("
            SELECT `key`, `value`
            FROM translations
            WHERE lang = :lang
        ");
        $stmt->execute(['lang' => $lang]);

        $translations = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $translations[$row['key']] = $row['value'];
        }

        return $translations;
    }
}
