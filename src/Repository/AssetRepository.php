<?php
namespace App\Repository;

use PDO;

class AssetRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Return all assets for a business, optionally filtered by asset_type and/or category.
     * Ordered by purchase_date descending.
     */
    public function findAll(int $businessId, ?string $assetType = null, ?string $category = null): array {
        $sql = "
            SELECT a.id, a.business_id, a.name, a.category, a.category_id, a.asset_type, a.purchase_date,
                   a.purchase_value, a.currency, a.exchange_rate, a.value_bc,
                   a.current_value_bc, a.accumulated_depreciation,
                   a.depreciation_method, a.useful_life, a.notes,
                   a.created_at, a.updated_at,
                   ac.name AS category_name
            FROM assets a
            LEFT JOIN asset_categories ac
                ON ac.id = COALESCE(a.category_id, CAST(NULLIF(a.category, '') AS UNSIGNED))
            WHERE a.business_id = :business
        ";
        $params = ['business' => $businessId];

        if ($assetType !== null) {
            $sql .= " AND a.asset_type = :asset_type";
            $params['asset_type'] = $assetType;
        }

        if ($category !== null) {
            $sql .= " AND a.category = :category";
            $params['category'] = $category;
        }

        $sql .= " ORDER BY a.purchase_date DESC, a.id DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return a single asset by id, scoped to business.
     */
    public function findById(int $id, int $businessId): ?array {
        $stmt = $this->db->prepare("
            SELECT a.id, a.business_id, a.name, a.category, a.category_id, a.asset_type, a.purchase_date,
                   a.purchase_value, a.currency, a.exchange_rate, a.value_bc,
                   a.current_value_bc, a.accumulated_depreciation,
                   a.depreciation_method, a.useful_life, a.notes,
                   a.created_at, a.updated_at,
                   ac.name AS category_name
            FROM assets a
            LEFT JOIN asset_categories ac
                ON ac.id = COALESCE(a.category_id, CAST(NULLIF(a.category, '') AS UNSIGNED))
            WHERE a.id          = :id
              AND a.business_id = :business
            LIMIT 1
        ");
        $stmt->execute(['id' => $id, 'business' => $businessId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Insert a new asset and return the new id.
     *
     * Expected keys: business_id, name, category, asset_type, purchase_date,
     *                purchase_value, currency, exchange_rate, value_bc,
     *                current_value_bc, accumulated_depreciation,
     *                depreciation_method, useful_life, notes
     */
    public function insert(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO assets
              (business_id, name, category, category_id, asset_type, purchase_date,
               purchase_value, currency, exchange_rate, value_bc,
               current_value_bc, accumulated_depreciation,
               depreciation_method, useful_life, notes,
               created_at, updated_at, created_by)
            VALUES
              (:business_id, :name, :category, :category_id, :asset_type, :purchase_date,
               :purchase_value, :currency, :exchange_rate, :value_bc,
               :current_value_bc, :accumulated_depreciation,
               :depreciation_method, :useful_life, :notes,
               NOW(), NOW(), :created_by)
        ");
        $stmt->execute([
            'business_id'              => $data['business_id'],
            'name'                     => $data['name'],
            'category'                 => $data['category'],
            'category_id'              => $data['category_id'] ?? null,
            'asset_type'               => $data['asset_type'],
            'purchase_date'            => $data['purchase_date'],
            'purchase_value'           => $data['purchase_value'],
            'currency'                 => $data['currency'],
            'exchange_rate'            => $data['exchange_rate'],
            'value_bc'                 => $data['value_bc'],
            'current_value_bc'         => $data['current_value_bc'],
            'accumulated_depreciation' => $data['accumulated_depreciation'],
            'depreciation_method'      => $data['depreciation_method'],
            'useful_life'              => $data['useful_life'],
            'notes'                    => $data['notes'] ?? null,
            'created_by'               => $data['created_by'] ?? self::safeUserId(),
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
     * Update an asset scoped to business.
     * Only allowed fields: name, category, asset_type, depreciation_method, useful_life, notes.
     */
    public function update(int $id, int $businessId, array $data): void {
        $stmt = $this->db->prepare("
            UPDATE assets
            SET name                = :name,
                category            = :category,
                asset_type          = :asset_type,
                depreciation_method = :depreciation_method,
                useful_life         = :useful_life,
                notes               = :notes,
                updated_at          = NOW()
            WHERE id          = :id
              AND business_id = :business
        ");
        $stmt->execute([
            'name'                => $data['name'],
            'category'            => $data['category'],
            'asset_type'          => $data['asset_type'],
            'depreciation_method' => $data['depreciation_method'],
            'useful_life'         => $data['useful_life'],
            'notes'               => $data['notes'] ?? null,
            'id'                  => $id,
            'business'            => $businessId,
        ]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Asset not found or not allowed');
        }
    }

    /**
     * Delete an asset scoped to business.
     */
    public function delete(int $id, int $businessId): void {
        $stmt = $this->db->prepare("
            DELETE FROM assets
            WHERE id          = :id
              AND business_id = :business
        ");
        $stmt->execute(['id' => $id, 'business' => $businessId]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Asset not found or not allowed');
        }
    }

    /**
     * Return summary totals for assets, optionally filtered by asset_type and/or category.
     * Returns: total_purchase_value_bc, total_current_value_bc, total_accumulated_depreciation
     */
    public function getSummary(int $businessId, ?string $assetType = null, ?string $category = null): array {
        $sql = "
            SELECT
                COALESCE(SUM(value_bc), 0)                  AS total_purchase_value_bc,
                COALESCE(SUM(current_value_bc), 0)          AS total_current_value_bc,
                COALESCE(SUM(accumulated_depreciation), 0)  AS total_accumulated_depreciation
            FROM assets
            WHERE business_id = :business
        ";
        $params = ['business' => $businessId];

        if ($assetType !== null) {
            $sql .= " AND asset_type = :asset_type";
            $params['asset_type'] = $assetType;
        }

        if ($category !== null) {
            $sql .= " AND category = :category";
            $params['category'] = $category;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
