<?php

namespace App\Repository;

use PDO;

class BulkAdvancePaymentRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function create(array $data): int
    {
        // Table doesn't exist yet — return 0 as placeholder
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

    public function delete(int $id): void
    {
        // No-op
    }
}
