<?php
namespace App\Controllers;

use App\Config\Database;
use App\Services\AssetService;

class AssetsController extends BaseController {

    private AssetService $service;

    public function __construct() {
        $this->service = new AssetService(Database::getConnection());
    }

    // GET /assets
    public function index(): void {
        try {
            $this->auth();

            $assetType = $_GET['asset_type'] ?? null;
            $category  = $_GET['category'] ?? null;

            $result = $this->service->list($assetType, $category);
            $this->json(true, $result);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch assets');
        }
    }

    // GET /assets/categories
    public function indexCategories(): void {
        try {
            $this->auth();
            $categories = $this->service->listCategories();
            $this->json(true, ['categories' => $categories]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch asset categories');
        }
    }

    // GET /assets/{id}
    public function show(int $id): void {
        try {
            $this->auth();
            $asset = $this->service->getById($id);
            $this->json(true, ['asset' => $asset]);
        } catch (\RuntimeException $e) {
            $this->notFound($e->getMessage());
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch asset');
        }
    }

    // POST /assets
    public function store(): void {
        try {
            $this->auth();
            $data = $this->input([
                'name', 'category', 'asset_type', 'purchase_date',
                'purchase_value', 'currency', 'exchange_rate',
                'depreciation_method', 'useful_life'
            ]);
            // category_id is optional in the validation list but passed through to the service
            $id = $this->service->create($data);
            $this->json(true, ['id' => $id], null, 201);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to create asset: ' . $e->getMessage());
        }
    }

    // PUT /assets/{id}
    public function update(int $id): void {
        try {
            $this->auth();
            $data = $this->input([]);
            $this->service->update($id, $data);
            $this->json(true, ['id' => $id]);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->notFound($e->getMessage());
        } catch (\Throwable $e) {
            $this->serverError('Failed to update asset');
        }
    }

    // DELETE /assets/{id}
    public function destroy(int $id): void {
        try {
            $this->auth();
            $this->service->delete($id);
            $this->json(true, ['id' => $id]);
        } catch (\RuntimeException $e) {
            $this->notFound($e->getMessage());
        } catch (\Throwable $e) {
            $this->serverError('Failed to delete asset');
        }
    }

    // POST /assets/{id}/depreciate
    public function depreciate(int $id): void {
        try {
            $this->auth();
            $asset = $this->service->depreciate($id);
            $this->json(true, ['asset' => $asset]);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->notFound($e->getMessage());
        } catch (\Throwable $e) {
            $this->serverError('Failed to depreciate asset');
        }
    }

    // POST /assets/{id}/dispose
    public function dispose(int $id): void {
        try {
            $this->auth();
            $data = $this->input(['disposal_date', 'disposal_proceeds']);
            $asset = $this->service->dispose($id, $data);
            $this->json(true, ['asset' => $asset]);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'Asset not found') {
                $this->notFound($e->getMessage());
            } else {
                $this->json(false, null, $e->getMessage(), 422);
            }
        } catch (\Throwable $e) {
            $this->serverError('Failed to dispose asset: ' . $e->getMessage());
        }
    }
}
