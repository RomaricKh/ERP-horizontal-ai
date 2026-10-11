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
                s.updated_at,
                COALESCE(
                    (SELECT SUM(pa.amount_bc) FROM payment_allocations pa WHERE pa.reference_type = 'SALARY' AND pa.reference_id = s.id),
                    0
                ) AS paid_amount_bc
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
                s.updated_at,
                COALESCE(
                    (SELECT SUM(pa.amount_bc) FROM payment_allocations pa WHERE pa.reference_type = 'SALARY' AND pa.reference_id = s.id),
                    0
                ) AS paid_amount_bc
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
     * Find all employees (payees with type 'Employee') in a business,
     * LEFT JOINed with their active salary recurrence schedules.
     *
     * Returns rows with: payee_id, employee_name, default_amount,
     *                    default_currency, frequency, schedule_entity_id
     */
    public function findEmployeesWithRecurrences(int $businessId): array {
        $stmt = $this->db->prepare("
            SELECT py.id AS payee_id, p.name AS employee_name,
                   rs.default_amount, rs.default_currency, rs.frequency, rs.entity_id AS schedule_entity_id
            FROM payees py
            JOIN people p ON p.id = py.people_id
            JOIN people_types pt ON pt.people_id = p.id
            JOIN payee_types ptype ON ptype.id = pt.payee_type_id
            LEFT JOIN recurrence_schedules rs ON rs.entity_type = 'SALARY'
                AND rs.entity_id = py.id AND rs.is_active = 1 AND rs.business_id = :business_id
            WHERE py.business_id = :biz_id
              AND ptype.name = 'Employee'
        ");
        $stmt->execute([
            'business_id' => $businessId,
            'biz_id'      => $businessId,
        ]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
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

    /**
     * Check if a salary already exists for the given payee/business that overlaps
     * with the specified date range.
     *
     * Overlap logic: existing record overlaps if period_start <= $end AND period_end >= $start.
     */
    public function existsOverlappingPeriod(int $payeeId, int $businessId, string $start, string $end): bool {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM salaries
            WHERE payee_id = :payee_id
              AND business_id = :business_id
              AND period_start <= :period_end
              AND period_end >= :period_start
        ");
        $stmt->execute([
            'payee_id'     => $payeeId,
            'business_id'  => $businessId,
            'period_end'   => $end,
            'period_start' => $start,
        ]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Find the latest (most recent) salary record for a given payee in a business.
     * Used as a "template" when generating new payroll entries — the latest salary
     * provides default amounts, currency, and exchange rate.
     *
     * @return array|null The salary row as an associative array, or null if none exists.
     */
    public function findLatestForPayee(int $payeeId, int $businessId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM salaries
            WHERE payee_id = :payee_id
              AND business_id = :business_id
            ORDER BY period_end DESC
            LIMIT 1
        ");
        $stmt->execute([
            'payee_id'    => $payeeId,
            'business_id' => $businessId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Sum of payment allocations (in business currency) for a salary record.
     * Used as a delete guard: if the sum > 0, the salary has been partially/fully paid
     * and cannot be deleted.
     */
    public function sumPaymentAllocationsForSalary(int $salaryId): float {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount_bc), 0)
            FROM payment_allocations
            WHERE reference_type = 'SALARY'
              AND reference_id = :id
        ");
        $stmt->execute(['id' => $salaryId]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Return business info (name, legal_name, address, phone, email) for payslip rendering.
     *
     * @throws \RuntimeException If the business is not found.
     */
    public function findBusinessInfo(int $businessId): array {
        $stmt = $this->db->prepare("
            SELECT name, legal_name, address, phone, email
            FROM businesses
            WHERE id = :id
        ");
        $stmt->execute(['id' => $businessId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException('Business not found');
        }
        return $row;
    }
}
