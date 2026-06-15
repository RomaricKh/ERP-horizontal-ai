<?php

namespace App\Services;

use App\Repository\TranslationRepository;
use PDO;

class TranslationService
{
    private TranslationRepository $repo;

    public function __construct(PDO $db)
    {
        $this->repo = new TranslationRepository($db);
    }

    /**
     * Returns all key-value pairs for the given language code.
     *
     * @throws \InvalidArgumentException if language code not found
     */
    public function getByLanguage(string $lang): array
    {
        if (!$this->repo->languageExists($lang)) {
            throw new \InvalidArgumentException('Language not found');
        }

        return $this->repo->findByLanguage($lang);
    }
}
