<?php

namespace App\Services;

use App\Repository\RecurrenceAuditLogRepository;
use PDO;

class RecurrenceAuditService
{
    private RecurrenceAuditLogRepository $repo;

    public function __construct(PDO $db)
    {
        $this->repo = new RecurrenceAuditLogRepository($db);
    }

    /**
     * Get audit log entries for recurrence actions.
     * Delegates to the repository with full filter support.
     *
     * @return array{entries: array, total: int}
     */
    public function getLog(array $filters = []): array
    {
        return $this->repo->findByBusiness($filters);
    }

    /**
     * Record an audit log entry.
     */
    public function log(string $actionType, array $data = []): void
    {
        $this->repo->create(array_merge($data, ['action_type' => $actionType]));
    }
}
