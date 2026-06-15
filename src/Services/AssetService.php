<?php
namespace App\Services;

use App\Repository\AssetRepository;
use App\Repository\AssetCategoryRepository;
use App\Support\AuthContext;
use PDO;

class AssetService {
    private AssetRepository         $repo;
    private AssetCategoryRepository $categoryRepo;
    private AuditService            $audit;
    private PDO                     $db;

    public function __construct(PDO $db) {
        $this->db           = $db;
        $this->repo         = new AssetRepository($db);
        $this->categoryRepo = new AssetCategoryRepository($db);
        $this->audit        = new AuditService($db);
    }

    /**
     * Return all asset categories for the current business (module-gated).
     */
    public function listCategories(): array {
        $ctx = AuthContext::get();
        return $this->categoryRepo->findForBusiness($ctx->tenant_id, $ctx->business_id);
    }

    /**
     * Return all assets for the current business with optional filters and a summary object.
     */
    public function list(?string $assetType = null, ?string $category = null): array {
        $businessId = AuthContext::businessId();

        $assets  = $this->repo->findAll($businessId, $assetType, $category);
        $summary = $this->repo->getSummary($businessId, $assetType, $category);

        return [
            'assets'  => $assets,
            'summary' => $summary,
        ];
    }

    /**
     * Return a single asset by ID.
     *
     * @throws \RuntimeException if not found
     */
    public function getById(int $id): array {
        $businessId = AuthContext::businessId();
        $asset = $this->repo->findById($id, $businessId);

        if (!$asset) {
            throw new \RuntimeException('Asset not found');
        }

        return $asset;
    }

    /**
     * Create a new asset.
     *
     * Calculates value_bc = purchase_value * exchange_rate,
     * sets current_value_bc = value_bc, accumulated_depreciation = 0.
     * Logs via AuditService.
     *
     * @return int The new asset ID
     */
    public function create(array $data): int {
        $ctx = AuthContext::get();

        $purchaseValue = (float) $data['purchase_value'];
        $exchangeRate  = (float) $data['exchange_rate'];
        $valueBc       = round($purchaseValue * $exchangeRate, 2);

        // Resolve category: the form sends category_id (int). Store both the id and
        // a human-readable name in the legacy `category` column for display robustness.
        $categoryId   = isset($data['category_id']) && $data['category_id'] !== null
            ? (int) $data['category_id']
            : (is_numeric($data['category'] ?? null) ? (int) $data['category'] : null);
        $categoryName = $data['category'] ?? '';
        if ($categoryId !== null) {
            $cat = $this->categoryRepo->findById($categoryId, $ctx->tenant_id);
            if ($cat) {
                $categoryName = $cat['name'];
            }
        }

        $record = [
            'business_id'              => $ctx->business_id,
            'name'                     => $data['name'],
            'category'                 => $categoryName,
            'category_id'              => $categoryId,
            'asset_type'               => $data['asset_type'],
            'purchase_date'            => $data['purchase_date'],
            'purchase_value'           => $purchaseValue,
            'currency'                 => $data['currency'],
            'exchange_rate'            => $exchangeRate,
            'value_bc'                 => $valueBc,
            'current_value_bc'         => $valueBc,
            'accumulated_depreciation' => 0,
            'depreciation_method'      => $data['depreciation_method'],
            'useful_life'              => (int) $data['useful_life'],
            'notes'                    => $data['notes'] ?? null,
        ];

        $id = $this->repo->insert($record);

        $this->audit->log(
            'CREATE',
            'assets',
            $id,
            'CREATE',
            null,
            $record,
            $ctx->user_id,
            $ctx->tenant_id,
            $ctx->business_id
        );

        return $id;
    }

    /**
     * Update an existing asset.
     *
     * Rejects changes to purchase_value, currency, and exchange_rate.
     * Logs via AuditService.
     *
     * @throws \InvalidArgumentException if immutable fields are changed
     * @throws \RuntimeException if asset not found
     */
    public function update(int $id, array $data): void {
        $ctx = AuthContext::get();

        // Reject immutable field changes
        $immutableFields = ['purchase_value', 'currency', 'exchange_rate'];
        foreach ($immutableFields as $field) {
            if (array_key_exists($field, $data)) {
                throw new \InvalidArgumentException("Cannot modify '{$field}' after creation");
            }
        }

        $before = $this->repo->findById($id, $ctx->business_id);
        if (!$before) {
            throw new \RuntimeException('Asset not found');
        }

        $updateData = [
            'name'                => $data['name'] ?? $before['name'],
            'category'            => $data['category'] ?? $before['category'],
            'asset_type'          => $data['asset_type'] ?? $before['asset_type'],
            'depreciation_method' => $data['depreciation_method'] ?? $before['depreciation_method'],
            'useful_life'         => $data['useful_life'] ?? $before['useful_life'],
            'notes'               => $data['notes'] ?? $before['notes'],
        ];

        $this->repo->update($id, $ctx->business_id, $updateData);

        $this->audit->log(
            'UPDATE',
            'assets',
            $id,
            'UPDATE',
            $before,
            $updateData,
            $ctx->user_id,
            $ctx->tenant_id,
            $ctx->business_id
        );
    }

    /**
     * Delete an asset.
     * Logs via AuditService.
     *
     * @throws \RuntimeException if asset not found
     */
    public function delete(int $id): void {
        $ctx = AuthContext::get();

        $before = $this->repo->findById($id, $ctx->business_id);
        if (!$before) {
            throw new \RuntimeException('Asset not found');
        }

        $this->repo->delete($id, $ctx->business_id);

        $this->audit->log(
            'DELETE',
            'assets',
            $id,
            'DELETE',
            $before,
            null,
            $ctx->user_id,
            $ctx->tenant_id,
            $ctx->business_id
        );
    }

    /**
     * Apply one period of depreciation to an asset.
     *
     * STRAIGHT_LINE:      annual_depreciation = value_bc / useful_life
     * DECLINING_BALANCE:  annual_depreciation = current_value_bc * (2 / useful_life)
     *
     * Caps accumulated_depreciation at value_bc (current_value_bc cannot go below zero).
     * Rejects depreciation on CURRENT type assets.
     *
     * @return array The updated asset record
     * @throws \InvalidArgumentException if asset type is CURRENT
     * @throws \RuntimeException if asset not found
     */
    public function depreciate(int $id): array {
        $ctx = AuthContext::get();

        $asset = $this->repo->findById($id, $ctx->business_id);
        if (!$asset) {
            throw new \RuntimeException('Asset not found');
        }

        if ($asset['asset_type'] === 'CURRENT') {
            throw new \InvalidArgumentException('Cannot depreciate CURRENT type assets');
        }

        $valueBc                = (float) $asset['value_bc'];
        $currentValueBc         = (float) $asset['current_value_bc'];
        $accumulatedDepreciation = (float) $asset['accumulated_depreciation'];
        $usefulLife             = (int) $asset['useful_life'];
        $method                 = $asset['depreciation_method'];

        // Calculate annual depreciation based on method
        if ($method === 'STRAIGHT_LINE') {
            $annualDepreciation = $valueBc / $usefulLife;
        } else { // DECLINING_BALANCE
            $annualDepreciation = $currentValueBc * (2 / $usefulLife);
        }

        $annualDepreciation = round($annualDepreciation, 2);

        // Apply depreciation with cap
        $newAccumulated = $accumulatedDepreciation + $annualDepreciation;
        if ($newAccumulated >= $valueBc) {
            $newAccumulated = $valueBc;
            $newCurrentValue = 0;
        } else {
            $newCurrentValue = round($valueBc - $newAccumulated, 2);
        }

        // Update the asset directly in the database
        $stmt = $this->db->prepare("
            UPDATE assets
            SET accumulated_depreciation = :accumulated,
                current_value_bc         = :current_value,
                updated_at               = NOW()
            WHERE id          = :id
              AND business_id = :business
        ");
        $stmt->execute([
            'accumulated'   => $newAccumulated,
            'current_value' => $newCurrentValue,
            'id'            => $id,
            'business'      => $ctx->business_id,
        ]);

        $this->audit->log(
            'UPDATE',
            'assets',
            $id,
            'DEPRECIATE',
            [
                'accumulated_depreciation' => $accumulatedDepreciation,
                'current_value_bc'         => $currentValueBc,
            ],
            [
                'accumulated_depreciation' => $newAccumulated,
                'current_value_bc'         => $newCurrentValue,
                'depreciation_applied'     => $annualDepreciation,
                'method'                   => $method,
            ],
            $ctx->user_id,
            $ctx->tenant_id,
            $ctx->business_id
        );

        // Return the updated asset
        return $this->repo->findById($id, $ctx->business_id);
    }
}
