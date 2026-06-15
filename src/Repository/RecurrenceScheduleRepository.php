<?php

namespace App\Repository;

use App\Support\AuthContext;
use PDO;

class RecurrenceScheduleRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findActiveByBusiness(array $filters = []): array
    {
        $businessId = AuthContext::businessId();
        $sql = "SELECT * FROM recurrence_schedules WHERE business_id = :business_id AND is_active = 1";
        $params = ['business_id' => $businessId];

        if (!empty($filters['entity_type'])) {
            $sql .= " AND entity_type = :entity_type";
            $params['entity_type'] = $filters['entity_type'];
        }

        $sql .= " ORDER BY start_date DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findByEntity(string $entityType, int $entityId): ?array
    {
        $businessId = AuthContext::businessId();
        $stmt = $this->db->prepare("
            SELECT * FROM recurrence_schedules
            WHERE entity_type = :entity_type
              AND entity_id = :entity_id
              AND business_id = :business_id
              AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'business_id' => $businessId,
        ]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM recurrence_schedules WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO recurrence_schedules
              (entity_type, entity_id, business_id, frequency, default_amount, default_currency, start_date, end_date, is_active, created_at, updated_at)
            VALUES
              (:entity_type, :entity_id, :business_id, :frequency, :default_amount, :default_currency, :start_date, :end_date, 1, NOW(), NOW())
        ");
        $stmt->execute([
            'entity_type' => $data['entity_type'],
            'entity_id' => $data['entity_id'],
            'business_id' => $data['business_id'],
            'frequency' => $data['frequency'],
            'default_amount' => $data['default_amount'],
            'default_currency' => $data['default_currency'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $sets = [];
        $params = ['id' => $id];
        foreach ($data as $key => $value) {
            $sets[] = "`$key` = :$key";
            $params[$key] = $value;
        }
        if (empty($sets)) return;

        // Explicitly set updated_at for clarity (also auto-set by MySQL ON UPDATE)
        $sets[] = "updated_at = NOW()";

        $sql = "UPDATE recurrence_schedules SET " . implode(', ', $sets) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    public function deactivate(int $id): void
    {
        $stmt = $this->db->prepare("UPDATE recurrence_schedules SET is_active = 0, updated_at = NOW() WHERE id = :id");
        $stmt->execute(['id' => $id]);
    }
}
