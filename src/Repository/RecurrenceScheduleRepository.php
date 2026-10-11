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

    /**
     * Resolve entity metadata (entity_name, payee_id, exchange_rate) for a single schedule entity.
     *
     * For EXPENSE: entity_name = "payee_name — category_name", payee_id from expense, exchange_rate from expense
     * For SALARY: entity_name = person name, payee_id = entity_id, exchange_rate = latest salary exchange_rate or null
     *
     * @return array{entity_name: string, payee_id: int|null, exchange_rate: float|null}
     */
    public function findScheduleEntityMetadata(string $entityType, int $entityId, int $businessId): array
    {
        $default = ['entity_name' => '—', 'payee_id' => null, 'exchange_rate' => null];

        if ($entityType === 'EXPENSE') {
            // Get entity_name: payee name + category name
            $stmt = $this->db->prepare("
                SELECT e.id, e.payee_id, e.exchange_rate,
                       COALESCE(p.name, '') AS payee_name,
                       COALESCE(ec.name, '') AS category_name
                FROM expenses e
                LEFT JOIN payees py ON py.id = e.payee_id
                LEFT JOIN people p ON p.id = py.people_id
                LEFT JOIN expense_categories ec ON ec.id = e.expense_category_id
                WHERE e.id = :entity_id AND e.business_id = :business_id
                LIMIT 1
            ");
            $stmt->execute(['entity_id' => $entityId, 'business_id' => $businessId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                return $default;
            }

            $parts = array_filter([$row['payee_name'], $row['category_name']]);
            $entityName = implode(' — ', $parts) ?: "Expense #$entityId";

            return [
                'entity_name' => $entityName,
                'payee_id' => $row['payee_id'] ? (int) $row['payee_id'] : null,
                'exchange_rate' => (float) ($row['exchange_rate'] ?? 1.0),
            ];
        }

        if ($entityType === 'SALARY') {
            // entity_name = person name from payee
            $stmt = $this->db->prepare("
                SELECT p.name
                FROM payees py
                JOIN people p ON p.id = py.people_id
                WHERE py.id = :entity_id AND py.business_id = :business_id
                LIMIT 1
            ");
            $stmt->execute(['entity_id' => $entityId, 'business_id' => $businessId]);
            $name = $stmt->fetchColumn();

            // exchange_rate = latest salary exchange_rate for this payee/business
            $stmt2 = $this->db->prepare("
                SELECT exchange_rate FROM salaries
                WHERE payee_id = :payee_id AND business_id = :business_id
                ORDER BY period_end DESC
                LIMIT 1
            ");
            $stmt2->execute(['payee_id' => $entityId, 'business_id' => $businessId]);
            $latestRate = $stmt2->fetchColumn();

            return [
                'entity_name' => $name ?: "Employee #$entityId",
                'payee_id' => $entityId,
                'exchange_rate' => $latestRate !== false ? (float) $latestRate : null,
            ];
        }

        return $default;
    }

    /**
     * Retrieve all schedules for a business with entity metadata enrichment.
     * Returns schedules with entity_name, payee_id, and exchange_rate JOINed.
     *
     * @param int $businessId
     * @param array $filters Optional filters: entity_type, is_active
     * @return array
     */
    public function findAllEnrichedForBusiness(int $businessId, array $filters = []): array
    {
        $sql = "SELECT rs.* FROM recurrence_schedules rs WHERE rs.business_id = :business_id";
        $params = ['business_id' => $businessId];

        if (!empty($filters['entity_type'])) {
            $sql .= " AND rs.entity_type = :entity_type";
            $params['entity_type'] = $filters['entity_type'];
        }

        if (isset($filters['is_active'])) {
            $sql .= " AND rs.is_active = :is_active";
            $params['is_active'] = (int) $filters['is_active'];
        }

        $sql .= " ORDER BY rs.start_date DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Enrich each schedule with entity metadata
        foreach ($schedules as &$schedule) {
            $metadata = $this->findScheduleEntityMetadata(
                $schedule['entity_type'],
                (int) $schedule['entity_id'],
                $businessId
            );
            $schedule['entity_name'] = $metadata['entity_name'];
            $schedule['payee_id'] = $metadata['payee_id'];
            $schedule['exchange_rate'] = $metadata['exchange_rate'];
        }

        return $schedules;
    }
}
