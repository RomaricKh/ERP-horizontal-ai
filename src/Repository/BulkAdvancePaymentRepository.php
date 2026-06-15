<?php

namespace App\Repository;

use App\Support\AuthContext;
use PDO;

class BulkAdvancePaymentRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Create a new bulk advance payment record and return the new ID.
     *
     * Expected keys: recurrence_schedule_id, total_amount, total_amount_bc,
     *                currency, periods_count, payment_date, payment_record_id, created_by
     */
    public function create(array $data): int
    {
        $businessId = AuthContext::businessId();

        $stmt = $this->db->prepare("
            INSERT INTO bulk_advance_payments
              (business_id, recurrence_schedule_id, total_amount, total_amount_bc,
               currency, periods_count, payment_date, payment_record_id, created_by,
               created_at)
            VALUES
              (:business_id, :recurrence_schedule_id, :total_amount, :total_amount_bc,
               :currency, :periods_count, :payment_date, :payment_record_id, :created_by,
               NOW())
        ");
        $stmt->execute([
            'business_id'            => $businessId,
            'recurrence_schedule_id' => $data['recurrence_schedule_id'],
            'total_amount'           => $data['total_amount'],
            'total_amount_bc'        => $data['total_amount_bc'],
            'currency'               => $data['currency'],
            'periods_count'          => $data['periods_count'],
            'payment_date'           => $data['payment_date'],
            'payment_record_id'      => $data['payment_record_id'] ?? null,
            'created_by'             => $data['created_by'],
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Find a bulk advance payment by ID, scoped to the current business.
     */
    public function findById(int $id): ?array
    {
        $businessId = AuthContext::businessId();

        $stmt = $this->db->prepare("
            SELECT id, business_id, recurrence_schedule_id, total_amount, total_amount_bc,
                   currency, periods_count, payment_date, payment_record_id, created_by,
                   created_at
            FROM bulk_advance_payments
            WHERE id          = :id
              AND business_id = :business_id
            LIMIT 1
        ");
        $stmt->execute(['id' => $id, 'business_id' => $businessId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Find bulk advance payments for the current business with optional filters.
     *
     * @param array $filters Supported keys: entity_type, date_from, date_to,
     *                       recurrence_schedule_id
     */
    public function findByBusiness(array $filters = []): array
    {
        $businessId = AuthContext::businessId();

        $sql = "
            SELECT bap.id, bap.business_id, bap.recurrence_schedule_id,
                   bap.total_amount, bap.total_amount_bc, bap.currency,
                   bap.periods_count, bap.payment_date, bap.payment_record_id,
                   bap.created_by, bap.created_at
            FROM bulk_advance_payments bap
        ";

        $params = ['business_id' => $businessId];
        $conditions = ["bap.business_id = :business_id"];

        // entity_type filter requires joining to recurrence_schedules
        if (!empty($filters['entity_type'])) {
            $sql .= " INNER JOIN recurrence_schedules rs ON rs.id = bap.recurrence_schedule_id";
            $conditions[] = "rs.entity_type = :entity_type";
            $params['entity_type'] = $filters['entity_type'];
        }

        if (!empty($filters['date_from'])) {
            $conditions[] = "bap.payment_date >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $conditions[] = "bap.payment_date <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }

        if (!empty($filters['recurrence_schedule_id'])) {
            $conditions[] = "bap.recurrence_schedule_id = :recurrence_schedule_id";
            $params['recurrence_schedule_id'] = $filters['recurrence_schedule_id'];
        }

        $sql .= " WHERE " . implode(' AND ', $conditions);
        $sql .= " ORDER BY bap.payment_date DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Delete a bulk advance payment by ID, scoped to the current business.
     */
    public function delete(int $id): bool
    {
        $businessId = AuthContext::businessId();

        $stmt = $this->db->prepare("
            DELETE FROM bulk_advance_payments
            WHERE id          = :id
              AND business_id = :business_id
        ");
        $stmt->execute(['id' => $id, 'business_id' => $businessId]);

        return $stmt->rowCount() > 0;
    }
}
