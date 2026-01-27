<?php
namespace App\Services;

use App\Support\AuthContext;
use App\Repository\AuditRepository;

class AuditService
{
    private AuditRepository $repo;

    public function __construct()
    {
        $this->repo = new AuditRepository();
    }

    public function log(string $eventType, string $table, int|string $recordId,
						string $action, ?array $oldValues = null, ?array $newValues = null,
						?int $userId = null, ?int $tenantId = null, ?int $businessId = null): void {

        $this->repo->insert([
            'tenant_id'   => $tenantId,
            'business_id' => $businessId,
            'user_id'     => $userId,
            'event_type'  => $eventType,
            'table_name'  => $table,
            'record_id'   => (int)$recordId,
            'action'      => $action,
            'old_values'  => $oldValues ? json_encode($oldValues) : null,
            'new_values'  => $newValues ? json_encode($newValues) : null,
            'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    }
}
