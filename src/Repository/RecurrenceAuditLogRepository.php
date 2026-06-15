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

    /**
     * Create a new audit log entry.
     */
    public function create(array $data): int
    {
        $ctx = AuthContext::get();
        $sql = "
            INSERT INTO recurrence_audit_log
                (business_id, user_id, entity_type, entity_id, action_type, previous_state, new_state, metadata)
            VALUES
                (:business_id, :user_id, :entity_type, :entity_id, :action_type, :previous_state, :new_state, :metadata)
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'business_id'    => $ctx->business_id,
            'user_id'        => $ctx->user_id ?? null,
            'entity_type'    => $data['entity_type'],
            'entity_id'      => $data['entity_id'],
            'action_type'    => $data['action_type'],
            'previous_state' => isset($data['previous_state']) ? json_encode($data['previous_state']) : null,
            'new_state'      => isset($data['new_state']) ? json_encode($data['new_state']) : null,
            'metadata'       => isset($data['metadata']) ? json_encode($data['metadata']) : null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Retrieve audit log entries for the current business with optional filters.
     *
     * Supported filters: entity_type, entity_id, action_type, user_id, from_date, to_date, limit, offset
     *
     * @return array{entries: array, total: int}
     */
    public function findByBusiness(array $filters = []): array
    {
        $ctx = AuthContext::get();
        $conditions = ['ral.business_id = :business_id'];
        $params = ['business_id' => $ctx->business_id];

        if (!empty($filters['entity_type'])) {
            $conditions[] = 'ral.entity_type = :entity_type';
            $params['entity_type'] = $filters['entity_type'];
        }
        if (!empty($filters['entity_id'])) {
            $conditions[] = 'ral.entity_id = :entity_id';
            $params['entity_id'] = (int) $filters['entity_id'];
        }
        if (!empty($filters['action_type'])) {
            $conditions[] = 'ral.action_type = :action_type';
            $params['action_type'] = $filters['action_type'];
        }
        if (!empty($filters['user_id'])) {
            $conditions[] = 'ral.user_id = :user_id';
            $params['user_id'] = (int) $filters['user_id'];
        }
        if (!empty($filters['from_date'])) {
            $conditions[] = 'ral.created_at >= :from_date';
            $params['from_date'] = $filters['from_date'] . ' 00:00:00';
        }
        if (!empty($filters['to_date'])) {
            $conditions[] = 'ral.created_at <= :to_date';
            $params['to_date'] = $filters['to_date'] . ' 23:59:59';
        }

        $where = implode(' AND ', $conditions);

        // Count total
        $countSql = "SELECT COUNT(*) AS total FROM recurrence_audit_log ral WHERE {$where}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // Fetch entries with user info
        $limit = isset($filters['limit']) ? (int) $filters['limit'] : 50;
        $offset = isset($filters['offset']) ? (int) $filters['offset'] : 0;

        $sql = "
            SELECT
                ral.id,
                ral.business_id,
                ral.user_id,
                ral.entity_type,
                ral.entity_id,
                ral.action_type,
                ral.previous_state,
                ral.new_state,
                ral.metadata,
                ral.created_at,
                CONCAT(u.first_name, ' ', u.last_name) AS user_name,
                u.email AS user_email
            FROM recurrence_audit_log ral
            LEFT JOIN users u ON u.id = ral.user_id
            WHERE {$where}
            ORDER BY ral.created_at DESC
            LIMIT {$limit} OFFSET {$offset}
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Decode JSON fields
        foreach ($entries as &$entry) {
            $entry['previous_state'] = $entry['previous_state'] ? json_decode($entry['previous_state'], true) : null;
            $entry['new_state'] = $entry['new_state'] ? json_decode($entry['new_state'], true) : null;
            $entry['metadata'] = $entry['metadata'] ? json_decode($entry['metadata'], true) : null;
        }

        return ['entries' => $entries, 'total' => $total];
    }
}
