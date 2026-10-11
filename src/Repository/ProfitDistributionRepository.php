<?php
namespace App\Repository;

use PDO;

class ProfitDistributionRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Insert a batch of distribution records for a profit record.
     *
     * Each distribution entry should have: partner_id, share_percent, amount_bc, payment_slip_id
     */
    public function createBatch(int $profitId, array $distributions): void {
        $stmt = $this->db->prepare("
            INSERT INTO profit_distributions
              (business_profit_id, partner_id, share_percent, amount_bc, payment_slip_id, created_at)
            VALUES
              (:profit_id, :partner_id, :share_percent, :amount_bc, :payment_slip_id, NOW())
        ");

        foreach ($distributions as $dist) {
            $stmt->execute([
                'profit_id'       => $profitId,
                'partner_id'      => $dist['partner_id'],
                'share_percent'   => $dist['share_percent'],
                'amount_bc'       => $dist['amount_bc'],
                'payment_slip_id' => $dist['payment_slip_id'] ?? null,
            ]);
        }
    }

    /**
     * Find all distribution records for a given profit record.
     * Includes partner name via payees → people JOIN.
     */
    public function findByProfitId(int $profitId): array {
        $stmt = $this->db->prepare("
            SELECT
                pd.id,
                pd.business_profit_id,
                pd.partner_id,
                p.name           AS partner_name,
                pd.share_percent,
                pd.amount_bc,
                pd.payment_slip_id,
                MAX(ps.payment_number) AS payment_slip_number,
                pd.created_at
            FROM profit_distributions pd
            LEFT JOIN payees py
                ON py.id = pd.partner_id
            LEFT JOIN people p
                ON p.id = py.people_id
            LEFT JOIN payment_allocations pa
                ON pa.reference_type = 'PARTNER_PROFIT' AND pa.reference_id = pd.business_profit_id
            LEFT JOIN payment_slips ps
                ON (ps.id = pd.payment_slip_id) OR (ps.id = pa.payment_slip_id AND ps.payee_id = pd.partner_id)
            WHERE pd.business_profit_id = :profit_id
            GROUP BY 
                pd.id, 
                pd.business_profit_id, 
                pd.partner_id, 
                p.name, 
                pd.share_percent, 
                pd.amount_bc, 
                pd.payment_slip_id, 
                pd.created_at
            ORDER BY pd.id ASC
        ");
        $stmt->execute(['profit_id' => $profitId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Count how many profit distributions exist for a partner in a business.
     */
    public function countByPartner(int $partnerId, int $businessId): int {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM profit_distributions pd
            JOIN business_profit bp ON bp.id = pd.business_profit_id
            WHERE pd.partner_id = :partner_id AND bp.business_id = :business_id
        ");
        $stmt->execute(['partner_id' => $partnerId, 'business_id' => $businessId]);
        return (int) $stmt->fetchColumn();
    }
}
