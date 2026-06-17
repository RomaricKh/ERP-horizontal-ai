<?php
namespace App\Repository;

use PDO;

class BusinessProfitRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Return all profit records for a business, ordered by period_start descending.
     */
    public function findAll(int $businessId): array {
        $stmt = $this->db->prepare("
            SELECT id, business_id, period_start, period_end,
                   total_revenue, total_cogs, gross_profit,
                   total_expenses, salary_expenses, net_profit,
                   safety_net_percent, safety_net_amount, distributable_profit,
                   status, created_at, updated_at
            FROM business_profit
            WHERE business_id = :business
            ORDER BY period_start DESC, id DESC
        ");
        $stmt->execute(['business' => $businessId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return a single profit record by id, scoped to business.
     */
    public function findById(int $id, int $businessId): ?array {
        $stmt = $this->db->prepare("
            SELECT id, business_id, period_start, period_end,
                   total_revenue, total_cogs, gross_profit,
                   total_expenses, salary_expenses, net_profit,
                   safety_net_percent, safety_net_amount, distributable_profit,
                   status, created_at, updated_at
            FROM business_profit
            WHERE id          = :id
              AND business_id = :business
            LIMIT 1
        ");
        $stmt->execute(['id' => $id, 'business' => $businessId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Insert a new profit record and return the new id.
     */
    public function insert(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO business_profit
              (business_id, period_start, period_end,
               total_revenue, total_cogs, gross_profit,
               total_expenses, salary_expenses, net_profit,
               safety_net_percent, safety_net_amount, distributable_profit,
               status, created_at, updated_at, created_by)
            VALUES
              (:business_id, :period_start, :period_end,
               :total_revenue, :total_cogs, :gross_profit,
               :total_expenses, :salary_expenses, :net_profit,
               :safety_net_percent, :safety_net_amount, :distributable_profit,
               :status, NOW(), NOW(), :created_by)
        ");
        $stmt->execute([
            'business_id'          => $data['business_id'],
            'period_start'         => $data['period_start'],
            'period_end'           => $data['period_end'],
            'total_revenue'        => $data['total_revenue'],
            'total_cogs'           => $data['total_cogs'] ?? 0,
            'gross_profit'         => $data['gross_profit'] ?? 0,
            'total_expenses'       => $data['total_expenses'],
            'salary_expenses'      => $data['salary_expenses'] ?? 0,
            'net_profit'           => $data['net_profit'],
            'safety_net_percent'   => $data['safety_net_percent'],
            'safety_net_amount'    => $data['safety_net_amount'],
            'distributable_profit' => $data['distributable_profit'],
            'status'               => $data['status'],
            'created_by'           => $data['created_by'] ?? self::safeUserId(),
        ]);
        return (int) $this->db->lastInsertId();
    }

    private static function safeUserId(): ?int {
        try {
            return \App\Support\AuthContext::userId();
        } catch (\RuntimeException $e) {
            return null;
        }
    }

    /**
     * Update a profit record scoped to business.
     * Core fields: safety_net_percent, safety_net_amount, distributable_profit, status.
     * Optional computed fields: total_revenue, total_cogs, gross_profit, total_expenses, salary_expenses, net_profit.
     */
    public function update(int $id, int $businessId, array $data): void {
        $allowedFields = [
            'safety_net_percent',
            'safety_net_amount',
            'distributable_profit',
            'status',
            'total_revenue',
            'total_cogs',
            'gross_profit',
            'total_expenses',
            'salary_expenses',
            'net_profit',
        ];

        $setClauses = [];
        $params     = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $setClauses[]       = "{$field} = :{$field}";
                $params[$field]     = $data[$field];
            }
        }

        if (empty($setClauses)) {
            throw new \InvalidArgumentException('No valid fields to update');
        }

        $setClauses[] = "updated_at = NOW()";
        $params['id']       = $id;
        $params['business'] = $businessId;

        $sql = "UPDATE business_profit SET " . implode(', ', $setClauses) .
               " WHERE id = :id AND business_id = :business";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Profit record not found or not allowed');
        }
    }

    /**
     * Delete a profit record scoped to business.
     */
    public function delete(int $id, int $businessId): void {
        $stmt = $this->db->prepare("
            DELETE FROM business_profit
            WHERE id          = :id
              AND business_id = :business
        ");
        $stmt->execute(['id' => $id, 'business' => $businessId]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Profit record not found or not allowed');
        }
    }

    /**
     * Find an overlapping profit record for the given period.
     */
    public function findOverlapping(int $businessId, string $start, string $end, ?int $excludeId = null): ?array {
        $sql = "
            SELECT id, business_id, period_start, period_end,
                   total_revenue, total_cogs, gross_profit,
                   total_expenses, salary_expenses, net_profit,
                   safety_net_percent, safety_net_amount, distributable_profit,
                   status, created_at, updated_at
            FROM business_profit
            WHERE business_id  = :business
              AND period_start <= :end
              AND period_end   >= :start
        ";
        $params = [
            'business' => $businessId,
            'start'    => $start,
            'end'      => $end,
        ];

        if ($excludeId !== null) {
            $sql .= " AND id != :exclude_id";
            $params['exclude_id'] = $excludeId;
        }

        $sql .= " LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
