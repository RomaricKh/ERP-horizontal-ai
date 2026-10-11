<?php
namespace App\Repository;

use PDO;

class PartnerEquityRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Return all partner equity entries for a business with partner name and slip details.
     * JOINs: payees → people (for partner name), collection_slips/payment_slips for slip reference.
     * Optionally filtered by partner_id. Ordered by created_at DESC.
     */
    public function findAll(int $businessId, ?int $partnerId = null): array {
        $sql = "
            SELECT
                pe.id,
                pe.business_id,
                pe.partner_id,
                p.name           AS partner_name,
                pe.equity_type,
                pe.amount,
                pe.currency,
                pe.exchange_rate,
                pe.amount_bc,
                pe.slip_type,
                pe.slip_id,
                pe.notes,
                pe.created_at,
                CASE
                    WHEN pe.slip_type = 'COLLECTION_SLIP' THEN cs.slip_number
                    WHEN pe.slip_type = 'PAYMENT_SLIP' THEN ps.payment_number
                    ELSE NULL
                END AS slip_number
            FROM partner_equity pe
            LEFT JOIN payees py
                ON py.id = pe.partner_id
            LEFT JOIN people p
                ON p.id = py.people_id
            LEFT JOIN collection_slips cs
                ON pe.slip_type = 'COLLECTION_SLIP' AND cs.id = pe.slip_id
            LEFT JOIN payment_slips ps
                ON pe.slip_type = 'PAYMENT_SLIP' AND ps.id = pe.slip_id
            WHERE pe.business_id = :business
        ";
        $params = ['business' => $businessId];

        if ($partnerId !== null) {
            $sql .= " AND pe.partner_id = :partner_id";
            $params['partner_id'] = $partnerId;
        }

        $sql .= " ORDER BY pe.created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Insert a new partner equity record and return the new id.
     *
     * Expected keys: business_id, partner_id, equity_type, amount, slip_type, slip_id, notes
     */
    public function insert(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO partner_equity
              (business_id, partner_id, equity_type, amount, currency, exchange_rate, amount_bc, slip_type, slip_id, notes, created_at, created_by)
            VALUES
              (:business_id, :partner_id, :equity_type, :amount, :currency, :exchange_rate, :amount_bc, :slip_type, :slip_id, :notes, NOW(), :created_by)
        ");
        $stmt->execute([
            'business_id' => $data['business_id'],
            'partner_id'  => $data['partner_id'],
            'equity_type'   => $data['equity_type'],
            'amount'        => $data['amount'],
            'currency'      => $data['currency'] ?? 'USD',
            'exchange_rate' => $data['exchange_rate'] ?? 1.0000,
            'amount_bc'     => $data['amount_bc'] ?? $data['amount'],
            'slip_type'     => $data['slip_type'] ?? null,
            'slip_id'     => $data['slip_id'] ?? null,
            'notes'       => $data['notes'] ?? null,
            'created_by'  => $data['created_by'] ?? self::safeUserId(),
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
     * Count how many equity entries exist for a partner in a business.
     */
    public function countByPartner(int $partnerId, int $businessId): int {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM partner_equity
            WHERE partner_id = :partner_id AND business_id = :business_id
        ");
        $stmt->execute(['partner_id' => $partnerId, 'business_id' => $businessId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Find a single partner equity entry by ID.
     */
    public function findById(int $id, int $businessId): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM partner_equity
            WHERE id = :id AND business_id = :business_id
        ");
        $stmt->execute(['id' => $id, 'business_id' => $businessId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
