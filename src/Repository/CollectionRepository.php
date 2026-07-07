<?php
namespace App\Repository;

use PDO;

class CollectionRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Return all collection slips for a business with payee name and account name.
     * JOINs: payees → people (for payee name), business_accounts (for account name).
     */
    public function findAllForBusiness(int $businessId): array {
        $stmt = $this->db->prepare("
            SELECT
                cs.id,
                cs.business_id,
                cs.payee_id,
                p.name           AS payee_name,
                cs.slip_number,
                cs.slip_date,
                cs.total_bc,
                cs.notes,
                cs.created_at,
                cs.updated_at,
                cs.created_by
            FROM collection_slips cs
            LEFT JOIN payees py
                ON py.id = cs.payee_id
            LEFT JOIN people p
                ON p.id = py.people_id
            WHERE cs.business_id = :business
            ORDER BY cs.slip_date DESC, cs.id DESC
        ");
        $stmt->execute(['business' => $businessId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return a single collection slip by id, scoped to business.
     */
    public function findById(int $id, int $businessId): ?array {
        $stmt = $this->db->prepare("
            SELECT
                cs.id,
                cs.business_id,
                cs.payee_id,
                p.name           AS payee_name,
                cs.slip_number,
                cs.slip_date,
                cs.total_bc,
                cs.notes,
                cs.created_at,
                cs.updated_at,
                cs.created_by
            FROM collection_slips cs
            LEFT JOIN payees py
                ON py.id = cs.payee_id
            LEFT JOIN people p
                ON p.id = py.people_id
            WHERE cs.id          = :id
              AND cs.business_id = :business
            LIMIT 1
        ");
        $stmt->execute(['id' => $id, 'business' => $businessId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Insert a new collection slip and return the new id.
     *
     * Expected keys: business_id, payee_id,
     *                slip_number, slip_date, total_bc, notes, created_by
     */
    public function create(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO collection_slips
              (business_id, payee_id,
               slip_number, slip_date, total_bc, notes,
               created_by, created_at)
            VALUES
              (:business_id, :payee_id,
               :slip_number, :slip_date, :total_bc, :notes,
               :created_by, NOW())
        ");
        $stmt->execute([
            'business_id'   => $data['business_id'],
            'payee_id'      => $data['payee_id']      ?? null,
            'slip_number'   => $data['slip_number'],
            'slip_date'     => $data['slip_date'],
            'total_bc'      => $data['total_bc'],
            'notes'         => $data['notes']         ?? null,
            'created_by'    => $data['created_by']    ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Insert a line for a collection slip.
     */
    public function createLine(int $slipId, array $line): void {
        $stmt = $this->db->prepare("
            INSERT INTO collection_slip_lines
              (collection_slip_id, account_id, currency, exchange_rate, amount_fc, amount_bc, method, notes)
            VALUES
              (:slip_id, :account_id, :currency, :exchange_rate, :amount_fc, :amount_bc, :method, :notes)
        ");
        $stmt->execute([
            'slip_id'       => $slipId,
            'account_id'    => $line['account_id'],
            'currency'      => $line['currency'],
            'exchange_rate' => $line['exchange_rate'],
            'amount_fc'     => $line['amount_fc'],
            'amount_bc'     => $line['amount_bc'],
            'method'        => $line['method'],
            'notes'         => $line['notes'] ?? null,
        ]);
    }

    /**
     * Find lines by slip ID.
     */
    public function findLinesBySlipId(int $slipId): array {
        $stmt = $this->db->prepare("
            SELECT csl.*, ba.name AS account_name
            FROM collection_slip_lines csl
            LEFT JOIN business_accounts ba ON ba.id = csl.account_id
            WHERE csl.collection_slip_id = :id
            ORDER BY csl.id ASC
        ");
        $stmt->execute(['id' => $slipId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Delete a collection slip scoped to business.
     */
    public function delete(int $id, int $businessId): void {
        $stmt = $this->db->prepare("
            DELETE FROM collection_slips
            WHERE id          = :id
              AND business_id = :business
        ");
        $stmt->execute(['id' => $id, 'business' => $businessId]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Collection not found or not allowed');
        }
    }

    /**
     * Sum the amount_bc of collection slips for a business within a date period.
     * Returns 0 when no records match.
     */
    public function sumAmountForPeriod(int $businessId, string $start, string $end): float {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(total_bc), 0)
            FROM collection_slips
            WHERE business_id = :business
              AND slip_date >= :start
              AND slip_date <= :end
        ");
        $stmt->execute([
            'business' => $businessId,
            'start'    => $start,
            'end'      => $end,
        ]);
        return (float) $stmt->fetchColumn();
    }
}
