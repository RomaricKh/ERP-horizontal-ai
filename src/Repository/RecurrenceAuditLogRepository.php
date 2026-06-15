<?php

namespace App\Repository;

use App\Support\AuthContext;
use PDO;

class RecurrenceAuditLogRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function log(array $data): void
    {
        // Audit log entries are stored in the main audit_logs table via AuditService
        // This repository is a no-op stub since the RecurrenceService also uses AuditService directly
    }

    public function findByBusiness(array $filters = []): array
    {
        return [];
    }
}
