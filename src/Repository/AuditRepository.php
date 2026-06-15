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

    /**
     * Retrieve paginated audit log entries for a business with optional filters.
     *
     * @param int    $businessId
     * @param array  $filters    Supported keys: event_type (string), from_date (string), to_date (string)
     * @param int    $limit
     * @param int    $offset
     * @return array{entries: array, total: int}
     */
    public function findForBusiness(int $businessId, array $filters, int $limit, int $offset): array
    {
        $conditions = ['al.business_id = :business_id'];
        $params = [':business_id' => $businessId];

        if (!empty($filters['event_type'])) {
            $conditions[] = 'al.event_type = :event_type';
            $params[':event_type'] = $filters['event_type'];
        }

        if (!empty($filters['from_date'])) {
            $conditions[] = 'DATE(al.created_at) >= :from_date';
            $params[':from_date'] = $filters['from_date'];
        }

        if (!empty($filters['to_date'])) {
            $conditions[] = 'DATE(al.created_at) <= :to_date';
            $params[':to_date'] = $filters['to_date'];
        }

        $where = implode(' AND ', $conditions);

        // Count total matching rows
        $countSql = "
            SELECT COUNT(*) AS total
            FROM audit_logs al
            WHERE {$where}
        ";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Fetch paginated rows with user name/email via LEFT JOIN
        $dataSql = "
            SELECT
                al.id,
                al.tenant_id,
                al.business_id,
                al.user_id,
                al.event_type,
                al.table_name,
                al.record_id,
                al.action,
                al.old_values,
                al.new_values,
                al.ip_address,
                al.user_agent,
                al.created_at,
                CONCAT(u.first_name, ' ', u.last_name) AS user_name,
                u.email AS user_email
            FROM audit_logs al
            LEFT JOIN users u ON u.id = al.user_id
            WHERE {$where}
            ORDER BY al.created_at DESC
            LIMIT :limit OFFSET :offset
        ";

        $dataStmt = $this->db->prepare($dataSql);

        // Bind named params first, then add limit/offset as integers
        foreach ($params as $key => $value) {
            $dataStmt->bindValue($key, $value);
        }
        $dataStmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        $dataStmt->execute();
        $entries = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'entries' => $entries,
            'total'   => $total,
        ];
    }
}
