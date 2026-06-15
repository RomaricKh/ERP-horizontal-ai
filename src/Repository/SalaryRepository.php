<?php
namespace App\Repository;

use PDO;

class SalaryRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Return all salary records for a business with employee name.
     * JOINs: payees → people (for name).
     */
    public function findAllForBusiness(int $businessId): array {
        $stmt = $this->db->prepare("
            SELECT
                s.id,
                s.business_id,
                s.payee_id,
                p.name          AS employee_name,
                s.period_start,
                s.period_end,
                s.currency,
                s.exchange_rate,
                s.gross_amount_fc,
                s.gross_amount_bc,
                s.tax_fc,
                s.tax_bc,
                s.net_amount_fc,
                s.net_amount_bc,
                s.notes,
                s.created_at,
                s.updated_at
            FROM salaries s
            LEFT JOIN payees py
                ON py.id = s.payee_id
            LEFT JOIN people p
                ON p.id = py.people_id
            WHERE s.business_id = :business
            ORDER BY s.period_start DESC, s.id DESC
        ");
        $stmt->execute(['business' => $businessId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return a single salary record by id, scoped to business.
     */
    public function findById(int $id, int $businessId): ?array {
        $stmt = $this->db->prepare("
            SELECT
                s.id,
                s.business_id,
                s.payee_id,
                p.name          AS employee_name,
                s.period_start,
                s.period_end,
                s.currency,
                s.exchange_rate,
                s.gross_amount_fc,
                s.gross_amount_bc,
                s.tax_fc,
                s.tax_bc,
                s.net_amount_fc,
                s.net_amount_bc,
                s.notes,
                s.created_at,
                s.updated_at
            FROM salaries s
            LEFT JOIN payees py
                ON py.id = s.payee_id
            LEFT JOIN people p
                ON p.id = py.people_id
            WHERE s.id          = :id
              AND s.business_id = :business
            LIMIT 1
        ");
        $stmt->execute(['id' => $id, 'business' => $businessId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Insert a new salary record and return the new id.
     *
     * Expected keys: business_id, payee_id, period_start, period_end,
     *                currency, exchange_rate, gross_amount_fc, gross_amount_bc,
     *                tax_fc, tax_bc, net_amount_fc, net_amount_bc, notes
     */
    public function create(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO salaries
              (business_id, payee_id, period_start, period_end,
               currency, exchange_rate,
               gross_amount_fc, gross_amount_bc,
               tax_fc, tax_bc,
               net_amount_fc, net_amount_bc,
               notes, created_at, updated_at)
            VALUES
              (:business_id, :payee_id, :period_start, :period_end,
               :currency, :exchange_rate,
               :gross_amount_fc, :gross_amount_bc,
               :tax_fc, :tax_bc,
               :net_amount_fc, :net_amount_bc,
               :notes, NOW(), NOW())
        ");
        $stmt->execute([
            'business_id'      => $data['business_id'],
            'payee_id'         => $data['payee_id'] ?? null,
            'period_start'     => $data['period_start'],
            'period_end'       => $data['period_end'],
            'currency'         => $data['currency'],
            'exchange_rate'    => $data['exchange_rate'],
            'gross_amount_fc'  => $data['gross_amount_fc'],
            'gross_amount_bc'  => $data['gross_amount_bc'],
            'tax_fc'           => $data['tax_fc'],
            'tax_bc'           => $data['tax_bc'],
            'net_amount_fc'    => $data['net_amount_fc'],
            'net_amount_bc'    => $data['net_amount_bc'],
            'notes'            => $data['notes'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Update an existing salary record.
     */
    public function update(int $id, int $businessId, array $data): void {
        $stmt = $this->db->prepare("
            UPDATE salaries
            SET payee_id         = :payee_id,
                period_start     = :period_start,
                period_end       = :period_end,
                currency         = :currency,
                exchange_rate    = :exchange_rate,
                gross_amount_fc  = :gross_amount_fc,
                gross_amount_bc  = :gross_amount_bc,
                tax_fc           = :tax_fc,
                tax_bc           = :tax_bc,
                net_amount_fc    = :net_amount_fc,
                net_amount_bc    = :net_amount_bc,
                notes            = :notes,
                updated_at       = NOW()
            WHERE id          = :id
              AND business_id = :business
        ");
        $stmt->execute([
            'payee_id'         => $data['payee_id'] ?? null,
            'period_start'     => $data['period_start'],
            'period_end'       => $data['period_end'],
            'currency'         => $data['currency'],
            'exchange_rate'    => $data['exchange_rate'],
            'gross_amount_fc'  => $data['gross_amount_fc'],
            'gross_amount_bc'  => $data['gross_amount_bc'],
            'tax_fc'           => $data['tax_fc'],
            'tax_bc'           => $data['tax_bc'],
            'net_amount_fc'    => $data['net_amount_fc'],
            'net_amount_bc'    => $data['net_amount_bc'],
            'notes'            => $data['notes'] ?? null,
            'id'               => $id,
            'business'         => $businessId,
        ]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Salary record not found or not allowed');
        }
    }

    /**
     * Delete a salary record scoped to business.
     */
    public function delete(int $id, int $businessId): void {
        $stmt = $this->db->prepare("
            DELETE FROM salaries
            WHERE id          = :id
              AND business_id = :business
        ");
        $stmt->execute(['id' => $id, 'business' => $businessId]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Salary record not found or not allowed');
        }
    }
}
