<?php

namespace App\Services;

use PDO;

class AccountingEntryService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function createEntry(array $data): int
    {
        return 0;
    }

    public function findByBusiness(array $filters = []): array
    {
        return [];
    }

    public function reverseEntry(int $entryId): void
    {
        // No-op
    }

    public function listPrepaidAssets(array $filters = []): array
    {
        return [];
    }

    public function getPrepaidAsset(int $id): ?array
    {
        return null;
    }

    public function listAccountingEntries(array $filters = []): array
    {
        return [];
    }
}
