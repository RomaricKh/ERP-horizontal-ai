<?php

namespace App\Repository;

use App\Support\AuthContext;
use PDO;

class PrepaidAssetRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Create a new prepaid asset record and return its ID.
     *
     * Expected keys: bulk_advance_payment_id, total_amount, total_amount_bc,
     *                per_period_amount, per_period_amount_bc, currency,
     *                periods_total, periods_recognized (default 0), status (default 'active')
     */
    public function create(array $data): int
    {
        $businessId = AuthContext::businessId();

        $stmt = $this->db->prepare("
            INSERT INTO prepaid_assets
              (business_id, bulk_advance_payment_id, total_amount, total_amount_bc,
               per_period_amount, per_period_amount_bc, currency, periods_total,
               periods_recognized, status, created_at, updated_at)
            VALUES
              (:business_id, :bulk_advance_payment_id, :total_amount, :total_amount_bc,
               :per_period_amount, :per_period_amount_bc, :currency, :periods_total,
               :periods_recognized, :status, NOW(), NOW())
        ");
        $stmt->execute([
            'business_id'              => $businessId,
            'bulk_advance_payment_id'  => $data['bulk_advance_payment_id'],
            'total_amount'             => $data['total_amount'],
            'total_amount_bc'          => $data['total_amount_bc'],
            'per_period_amount'        => $data['per_period_amount'],
            'per_period_amount_bc'     => $data['per_period_amount_bc'],
            'currency'                 => $data['currency'],
            'periods_total'            => $data['periods_total'],
            'periods_recognized'       => $data['periods_recognized'] ?? 0,
            'status'                   => $data['status'] ?? 'active',
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Find a prepaid asset by ID, scoped to the current business.
     */
    public function findById(int $id): ?array
    {
        $businessId = AuthContext::businessId();

        $stmt = $this->db->prepare("
            SELECT id, business_id, bulk_advance_payment_id, total_amount, total_amount_bc,
                   per_period_amount, per_period_amount_bc, currency, periods_total,
                   periods_recognized, status, created_at, updated_at
            FROM prepaid_assets
            WHERE id = :id
              AND business_id = :business_id
            LIMIT 1
        ");
        $stmt->execute(['id' => $id, 'business_id' => $businessId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Find the prepaid asset linked to a specific bulk advance payment.
     * Used for reversal lookup.
     * Scoped to the current business.
     */
    public function findByBulkAdvancePayment(int $bapId): ?array
    {
        $businessId = AuthContext::businessId();

        $stmt = $this->db->prepare("
            SELECT id, business_id, bulk_advance_payment_id, total_amount, total_amount_bc,
                   per_period_amount, per_period_amount_bc, currency, periods_total,
                   periods_recognized, status, created_at, updated_at
            FROM prepaid_assets
            WHERE bulk_advance_payment_id = :bap_id
              AND business_id = :business_id
            LIMIT 1
        ");
        $stmt->execute([
            'bap_id'      => $bapId,
            'business_id' => $businessId,
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Find prepaid assets for the current business with optional filters.
     *
     * @param array $filters Supported keys: status, from_date, to_date
     */
    public function findByBusiness(array $filters = []): array
    {
        $businessId = AuthContext::businessId();

        $sql = "
            SELECT id, business_id, bulk_advance_payment_id, total_amount, total_amount_bc,
                   per_period_amount, per_period_amount_bc, currency, periods_total,
                   periods_recognized, status, created_at, updated_at
            FROM prepaid_assets
            WHERE business_id = :business_id
        ";
        $params = ['business_id' => $businessId];

        if (!empty($filters['status'])) {
            $sql .= " AND status = :status";
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['from_date'])) {
            $sql .= " AND created_at >= :from_date";
            $params['from_date'] = $filters['from_date'];
        }

        if (!empty($filters['to_date'])) {
            $sql .= " AND created_at <= :to_date";
            $params['to_date'] = $filters['to_date'];
        }

        $sql .= " ORDER BY created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update a prepaid asset record by ID, scoped to the current business.
     * Used for status and periods_recognized updates.
     *
     * @param int $id The prepaid asset ID
     * @param array $data Key-value pairs to update (e.g., status, periods_recognized)
     */
    public function update(int $id, array $data): void
    {
        $businessId = AuthContext::businessId();

        $allowedFields = ['status', 'periods_recognized'];
        $sets = [];
        $params = ['id' => $id, 'business_id' => $businessId];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $sets[] = "{$field} = :{$field}";
                $params[$field] = $data[$field];
            }
        }

        if (empty($sets)) {
            return;
        }

        $sets[] = "updated_at = NOW()";
        $sql = "UPDATE prepaid_assets SET " . implode(', ', $sets) . " WHERE id = :id AND business_id = :business_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }
}
