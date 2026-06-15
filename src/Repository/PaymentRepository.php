<?php
namespace App\Repository;

use PDO;

class PaymentRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Return all payment slips for a business with payee name and account name.
     * JOINs: payees → people (for payee name), business_accounts (for account name).
     */
    public function findAllForBusiness(int $businessId): array {
        $stmt = $this->db->prepare("
            SELECT
                ps.id,
                ps.business_id,
                ps.account_id,
                ba.name          AS account_name,
                ps.payee_id,
                p.name           AS payee_name,
                ps.payment_number,
                ps.payment_date,
                ps.currency,
                ps.exchange_rate,
                ps.amount_fc,
                ps.amount_bc,
                ps.method,
                ps.notes,
                ps.created_at,
                ps.updated_at,
                ps.created_by
            FROM payment_slips ps
            LEFT JOIN business_accounts ba
                ON ba.id = ps.account_id
            LEFT JOIN payees py
                ON py.id = ps.payee_id
            LEFT JOIN people p
                ON p.id = py.people_id
            WHERE ps.business_id = :business
            ORDER BY ps.payment_date DESC, ps.id DESC
        ");
        $stmt->execute(['business' => $businessId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return a single payment slip by id, scoped to business.
     */
    public function findById(int $id, int $businessId): ?array {
        $stmt = $this->db->prepare("
            SELECT
                ps.id,
                ps.business_id,
                ps.account_id,
                ba.name          AS account_name,
                ps.payee_id,
                p.name           AS payee_name,
                ps.payment_number,
                ps.payment_date,
                ps.currency,
                ps.exchange_rate,
                ps.amount_fc,
                ps.amount_bc,
                ps.method,
                ps.notes,
                ps.created_at,
                ps.updated_at,
                ps.created_by
            FROM payment_slips ps
            LEFT JOIN business_accounts ba
                ON ba.id = ps.account_id
            LEFT JOIN payees py
                ON py.id = ps.payee_id
            LEFT JOIN people p
                ON p.id = py.people_id
            WHERE ps.id          = :id
              AND ps.business_id = :business
            LIMIT 1
        ");
        $stmt->execute(['id' => $id, 'business' => $businessId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Insert a new payment slip and return the new id.
     *
     * Expected keys: business_id, account_id, payee_id,
     *                payment_number, payment_date, currency, exchange_rate,
     *                amount_fc, amount_bc, method, notes, created_by
     */
    public function create(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO payment_slips
              (business_id, account_id, payee_id,
               payment_number, payment_date, currency, exchange_rate,
               amount_fc, amount_bc, method, notes, created_by,
               created_at, updated_at)
            VALUES
              (:business_id, :account_id, :payee_id,
               :payment_number, :payment_date, :currency, :exchange_rate,
               :amount_fc, :amount_bc, :method, :notes, :created_by,
               NOW(), NOW())
        ");
        $stmt->execute([
            'business_id'    => $data['business_id'],
            'account_id'     => $data['account_id'],
            'payee_id'       => $data['payee_id'] ?? null,
            'payment_number' => $data['payment_number'],
            'payment_date'   => $data['payment_date'],
            'currency'       => $data['currency'],
            'exchange_rate'  => $data['exchange_rate'],
            'amount_fc'      => $data['amount_fc'],
            'amount_bc'      => $data['amount_bc'],
            'method'         => $data['method'],
            'notes'          => $data['notes'] ?? null,
            'created_by'     => $data['created_by'],
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Delete a payment slip scoped to business.
     */
    public function delete(int $id, int $businessId): void {
        $stmt = $this->db->prepare("
            DELETE FROM payment_slips
            WHERE id          = :id
              AND business_id = :business
        ");
        $stmt->execute(['id' => $id, 'business' => $businessId]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Payment not found or not allowed');
        }
    }

    /**
     * Sum the amount_bc of payment slips for a business within a date period,
     * excluding slips that are allocated exclusively to the specified reference_type.
     * Returns 0 when no records match.
     */
    public function sumAmountExcludingType(int $businessId, string $start, string $end, string $excludeType): float {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(ps.amount_bc), 0)
            FROM payment_slips ps
            WHERE ps.business_id = :business
              AND ps.payment_date >= :start
              AND ps.payment_date <= :end
              AND ps.id NOT IN (
                  SELECT DISTINCT pa.payment_slip_id
                  FROM payment_allocations pa
                  WHERE pa.reference_type = :excludeType
              )
        ");
        $stmt->execute([
            'business'    => $businessId,
            'start'       => $start,
            'end'         => $end,
            'excludeType' => $excludeType,
        ]);
        return (float) $stmt->fetchColumn();
    }
}
