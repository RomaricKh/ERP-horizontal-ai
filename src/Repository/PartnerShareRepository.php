<?php

namespace App\Repository;

use PDO;

class PartnerShareRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Find all partner shares for a business with partner names.
     */
    public function findAll(int $businessId): array
    {
        $stmt = $this->db->prepare("
            SELECT ps.*, p.name AS partner_name
            FROM partner_shares ps
            JOIN payees py ON py.id = ps.partner_id AND py.business_id = ps.business_id
            JOIN people p ON p.id = py.people_id
            WHERE ps.business_id = :business_id
            ORDER BY ps.share_percent DESC
        ");
        $stmt->execute(['business_id' => $businessId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find a specific partner share by ID within a business.
     */
    public function findById(int $id, int $businessId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT ps.*, p.name AS partner_name
            FROM partner_shares ps
            JOIN payees py ON py.id = ps.partner_id AND py.business_id = ps.business_id
            JOIN people p ON p.id = py.people_id
            WHERE ps.id = :id AND ps.business_id = :business_id
            LIMIT 1
        ");
        $stmt->execute(['id' => $id, 'business_id' => $businessId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Insert a new partner share record.
     */
    public function insert(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO partner_shares (business_id, partner_id, share_percent, invested_capital, created_at)
            VALUES (:business_id, :partner_id, :share_percent, :invested_capital, NOW())
        ");
        $stmt->execute([
            'business_id'      => $data['business_id'],
            'partner_id'       => $data['partner_id'],
            'share_percent'    => $data['share_percent'],
            'invested_capital' => $data['invested_capital'] ?? 0.00,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Update a partner share record.
     */
    public function update(int $id, int $businessId, array $data): void
    {
        $sets = [];
        $params = ['id' => $id, 'business_id' => $businessId];

        if (isset($data['share_percent'])) {
            $sets[] = 'share_percent = :share_percent';
            $params['share_percent'] = $data['share_percent'];
        }
        if (isset($data['invested_capital'])) {
            $sets[] = 'invested_capital = :invested_capital';
            $params['invested_capital'] = $data['invested_capital'];
        }

        if (empty($sets)) return;

        $sets[] = 'updated_at = NOW()';

        $sql = "UPDATE partner_shares SET " . implode(', ', $sets) . " WHERE id = :id AND business_id = :business_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Delete a partner share record.
     */
    public function delete(int $id, int $businessId): void
    {
        $stmt = $this->db->prepare("DELETE FROM partner_shares WHERE id = :id AND business_id = :business_id");
        $stmt->execute(['id' => $id, 'business_id' => $businessId]);
    }

    /**
     * Get the total share percentage for a business, optionally excluding a specific record.
     */
    public function getTotalSharePercent(int $businessId, ?int $excludeId = null): float
    {
        $sql = "SELECT COALESCE(SUM(share_percent), 0) FROM partner_shares WHERE business_id = :business_id";
        $params = ['business_id' => $businessId];

        if ($excludeId !== null) {
            $sql .= " AND id != :exclude_id";
            $params['exclude_id'] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Get all shares for a business (used by profit distribution and equity service).
     */
    public function getSharesForBusiness(int $businessId): array
    {
        $stmt = $this->db->prepare("
            SELECT ps.*, p.name AS partner_name
            FROM partner_shares ps
            JOIN payees py ON py.id = ps.partner_id AND py.business_id = ps.business_id
            JOIN people p ON p.id = py.people_id
            WHERE ps.business_id = :business_id
            ORDER BY ps.id ASC
        ");
        $stmt->execute(['business_id' => $businessId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update the invested_capital field by adding/subtracting an adjustment amount.
     */
    public function updateInvestedCapital(int $id, int $businessId, float $adjustment): void
    {
        $stmt = $this->db->prepare("
            UPDATE partner_shares
            SET invested_capital = invested_capital + :adjustment,
                updated_at = NOW()
            WHERE id = :id AND business_id = :business_id
        ");
        $stmt->execute([
            'adjustment'  => $adjustment,
            'id'          => $id,
            'business_id' => $businessId,
        ]);
    }
}
