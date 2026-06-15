<?php

namespace App\Services;

use PDO;

class RecurrenceAuditService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Get audit log entries for recurrence actions.
     */
    public function getLog(array $filters = []): array
    {
        return ['entries' => [], 'total' => 0];
    }

    /**
     * Record an audit log entry.
     */
    public function log(string $actionType, array $data = []): void
    {
        // No-op stub
    }
}
