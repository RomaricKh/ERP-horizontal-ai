<?php
namespace App\Repository;

use PDO;

class AuditRepository
{
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function insert(array $data): void
    {
        $sql = "
            INSERT INTO audit_logs (
                tenant_id,
                business_id,
                user_id,
                event_type,
                table_name,
                record_id,
                action,
                old_values,
                new_values,
                ip_address,
                user_agent,
                created_at
            ) VALUES (
                :tenant_id,
                :business_id,
                :user_id,
                :event_type,
                :table_name,
                :record_id,
                :action,
                :old_values,
                :new_values,
                :ip_address,
                :user_agent,
                NOW()
            )
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($data);
    }
}
