<?php

namespace App\Repository;

use PDO;

class PrepaidAssetRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function create(array $data): int
    {
        return 0;
    }

    public function findById(int $id): ?array
    {
        return null;
    }

    public function findByBusiness(array $filters = []): array
    {
        return [];
    }

    public function updateAmortized(int $id, float $amount): void
    {
        // No-op
    }
}
