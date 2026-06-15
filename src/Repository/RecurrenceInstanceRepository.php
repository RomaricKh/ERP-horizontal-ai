<?php

namespace App\Repository;

use App\Support\AuthContext;
use PDO;

class RecurrenceInstanceRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Create a new recurrence instance and return the new id.
     *
     * Expected keys: recurrence_schedule_id, entity_type, due_date,
     *                scheduled_amount, currency, period_start, period_end
     */
    public function create(array $data): int
    {
        $businessId = AuthContext::businessId();

        $stmt = $this->db->prepare("
            INSERT INTO recurrence_instances
              (recurrence_schedule_id, entity_type, business_id, due_date,
               scheduled_amount, actual_amount, currency, status, paid_date,
               notification_sent, payment_record_id, period_start, period_end,
               created_at, updated_at)
            VALUES
              (:recurrence_schedule_id, :entity_type, :business_id, :due_date,
               :scheduled_amount, :actual_amount, :currency, :status, :paid_date,
               :notification_sent, :payment_record_id, :period_start, :period_end,
               NOW(), NOW())
        ");
        $stmt->execute([
            'recurrence_schedule_id'  => $data['recurrence_schedule_id'],
            'entity_type'             => $data['entity_type'],
            'business_id'             => $businessId,
            'due_date'                => $data['due_date'],
            'scheduled_amount'        => $data['scheduled_amount'],
            'actual_amount'           => $data['actual_amount'] ?? null,
            'currency'                => $data['currency'],
            'status'                  => $data['status'] ?? 'pending',
            'paid_date'               => $data['paid_date'] ?? null,
            'notification_sent'       => $data['notification_sent'] ?? 0,
            'payment_record_id'       => $data['payment_record_id'] ?? null,
            'period_start'            => $data['period_start'] ?? null,
            'period_end'              => $data['period_end'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Find a recurrence instance by id, scoped to the current business.
     */
    public function findById(int $id): ?array
    {
        $businessId = AuthContext::businessId();

        $stmt = $this->db->prepare("
            SELECT id, recurrence_schedule_id, entity_type, business_id, due_date,
                   scheduled_amount, actual_amount, currency, status, paid_date,
                   notification_sent, payment_record_id, period_start, period_end,
                   created_at, updated_at
            FROM recurrence_instances
            WHERE id          = :id
              AND business_id = :business_id
            LIMIT 1
        ");
        $stmt->execute(['id' => $id, 'business_id' => $businessId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Find instances for a given schedule, scoped to the current business.
     * Optionally filterable by status and date range.
     *
     * @param array $filters Supported keys: status, date_from, date_to
     */
    public function findBySchedule(int $scheduleId, array $filters = []): array
    {
        $businessId = AuthContext::businessId();

        $sql = "
            SELECT id, recurrence_schedule_id, entity_type, business_id, due_date,
                   scheduled_amount, actual_amount, currency, status, paid_date,
                   notification_sent, payment_record_id, period_start, period_end,
                   created_at, updated_at
            FROM recurrence_instances
            WHERE recurrence_schedule_id = :schedule_id
              AND business_id            = :business_id
        ";
        $params = [
            'schedule_id' => $scheduleId,
            'business_id' => $businessId,
        ];

        if (!empty($filters['status'])) {
            $sql .= " AND status = :status";
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['date_from'])) {
            $sql .= " AND due_date >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND due_date <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }

        $sql .= " ORDER BY due_date ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find instances for the current business with optional filters.
     *
     * @param array $filters Supported keys: status, date_from, date_to,
     *                       entity_type, recurrence_schedule_id
     */
    public function findByBusinessFiltered(array $filters = []): array
    {
        $businessId = AuthContext::businessId();

        $sql = "
            SELECT id, recurrence_schedule_id, entity_type, business_id, due_date,
                   scheduled_amount, actual_amount, currency, status, paid_date,
                   notification_sent, payment_record_id, period_start, period_end,
                   created_at, updated_at
            FROM recurrence_instances
            WHERE business_id = :business_id
        ";
        $params = ['business_id' => $businessId];

        if (!empty($filters['status'])) {
            $sql .= " AND status = :status";
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['date_from'])) {
            $sql .= " AND due_date >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND due_date <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }

        if (!empty($filters['entity_type'])) {
            $sql .= " AND entity_type = :entity_type";
            $params['entity_type'] = $filters['entity_type'];
        }

        if (!empty($filters['recurrence_schedule_id'])) {
            $sql .= " AND recurrence_schedule_id = :recurrence_schedule_id";
            $params['recurrence_schedule_id'] = $filters['recurrence_schedule_id'];
        }

        $sql .= " ORDER BY due_date ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update an instance's status and related fields.
     *
     * Supported keys: status, actual_amount, paid_date, payment_record_id,
     *                 is_advance_payment, bulk_advance_payment_id, notification_sent
     */
    public function updateStatus(int $id, array $data): bool
    {
        $businessId = AuthContext::businessId();

        $fields = [];
        $params = ['id' => $id, 'business_id' => $businessId];

        $allowedFields = [
            'status', 'scheduled_amount', 'actual_amount', 'paid_date', 'payment_record_id',
            'notification_sent',
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = :{$field}";
                $params[$field] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = "updated_at = NOW()";
        $fieldsSql = implode(', ', $fields);

        $stmt = $this->db->prepare("
            UPDATE recurrence_instances
            SET {$fieldsSql}
            WHERE id          = :id
              AND business_id = :business_id
        ");
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * Find pending instances due within N days where notification has not been sent.
     * Scoped to the current business.
     *
     * @param int $daysAhead Number of days ahead to look (default 3)
     */
    public function findDueForNotification(int $daysAhead = 3): array
    {
        $businessId = AuthContext::businessId();

        $stmt = $this->db->prepare("
            SELECT id, recurrence_schedule_id, entity_type, business_id, due_date,
                   scheduled_amount, actual_amount, currency, status, paid_date,
                   notification_sent, payment_record_id, period_start, period_end,
                   created_at, updated_at
            FROM recurrence_instances
            WHERE business_id       = :business_id
              AND status            = 'pending'
              AND notification_sent = 0
              AND due_date          <= DATE_ADD(CURDATE(), INTERVAL :days_ahead DAY)
              AND due_date          >= CURDATE()
            ORDER BY due_date ASC
        ");
        $stmt->execute([
            'business_id' => $businessId,
            'days_ahead'  => $daysAhead,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find pending instances that are overdue (due_date < today).
     * Scoped to the current business.
     */
    public function findOverdue(): array
    {
        $businessId = AuthContext::businessId();

        $stmt = $this->db->prepare("
            SELECT id, recurrence_schedule_id, entity_type, business_id, due_date,
                   scheduled_amount, actual_amount, currency, status, paid_date,
                   notification_sent, payment_record_id, period_start, period_end,
                   created_at, updated_at
            FROM recurrence_instances
            WHERE business_id = :business_id
              AND status      = 'pending'
              AND due_date    < CURDATE()
            ORDER BY due_date ASC
        ");
        $stmt->execute(['business_id' => $businessId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Check if an instance already exists for a given schedule and due date.
     * Used for idempotent generation — prevents duplicate instances.
     */
    public function existsForScheduleAndDate(int $scheduleId, string $dueDate): bool
    {
        $businessId = AuthContext::businessId();

        $stmt = $this->db->prepare("
            SELECT COUNT(*) as cnt
            FROM recurrence_instances
            WHERE recurrence_schedule_id = :schedule_id
              AND due_date               = :due_date
              AND business_id            = :business_id
            LIMIT 1
        ");
        $stmt->execute([
            'schedule_id' => $scheduleId,
            'due_date'    => $dueDate,
            'business_id' => $businessId,
        ]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int) $result['cnt'] > 0;
    }
}
