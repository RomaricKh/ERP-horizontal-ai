<?php
namespace App\Controllers;

use App\Config\Database;
use App\Services\ItemService;

class ItemsController extends BaseController {

    private ItemService $service;

    public function __construct() {
        $this->service = new ItemService(Database::getConnection());
    }

    // ── ITEM TYPES ────────────────────────────────────────────────────────────

    // GET /items/types
    public function indexTypes(): void {
        try {
            $this->auth();
            $types = $this->service->listTypes();
            $this->json(true, ['item_types' => $types]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch item types');
        }
    }

    // POST /items/types
    public function storeType(): void {
        try {
            $this->auth();
            $data = $this->input(['name']);
            $id   = $this->service->createType($data['name']);
            $this->json(true, ['id' => $id], null, 201);
        } catch (\Throwable $e) {
            $this->serverError('Failed to create item type: ' . $e->getMessage());
        }
    }

    // PUT /items/types/{id}
    public function updateType(int $id): void {
        try {
            $this->auth();
            $data = $this->input(['name']);
            $this->service->updateType($id, $data['name']);
            $this->json(true, ['id' => $id]);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to update item type');
        }
    }

    // DELETE /items/types/{id}
    public function deleteType(int $id): void {
        try {
            $this->auth();
            $this->service->deleteType($id);
            $this->json(true, ['id' => $id]);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to delete item type');
        }
    }

    // ── ITEMS ─────────────────────────────────────────────────────────────────

    // GET /items
    public function index(): void {
        try {
            $this->auth();
            $excludeTypes = $_GET['exclude_types'] ?? null;
            $items = $this->service->listForCurrentBusiness($excludeTypes);
            $this->json(true, ['items' => $items]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch items');
        }
    }

    // GET /items/{id}
    public function viewById(int $id): void {
        try {
            $this->auth();
            $item = $this->service->getById($id);
            $this->json(true, ['item' => $item]);
        } catch (\Throwable $e) {
            $this->notFound('Item not found');
        }
    }

    // POST /items
    public function store(): void {
        try {
            $this->auth();
            $data = $this->input(['name', 'item_type_id']);
            $id   = $this->service->create($data);
            $this->json(true, ['id' => $id], null, 201);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to create item: ' . $e->getMessage());
        }
    }

    // PUT /items/{id}
    public function update(int $id): void {
        try {
            $this->auth();
            $data = $this->input(['name', 'item_type_id']);
            $this->service->update($id, $data);
            $this->json(true, ['id' => $id]);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to update item');
        }
    }

    // DELETE /items/{id}
    public function delete(int $id): void {
        try {
            $this->auth();
            $this->service->deactivate($id);
            $this->json(true, ['id' => $id]);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to delete item');
        }
    }

    // ── STOCK ─────────────────────────────────────────────────────────────────

    // GET /items/stock
    public function stock(): void {
        try {
            $this->auth();
            $stock = $this->service->getStock();
            $this->json(true, ['stock' => $stock]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch stock');
        }
    }

    // POST /items/{id}/stock
    public function adjustStock(int $id): void {
        try {
            $ctx = $this->auth();
            $data = $this->input(['type', 'quantity']);
            $this->service->adjustStock(
                $id,
                strtoupper($data['type']),
                (float) $data['quantity'],
                $data['reference'] ?? null,
                $data['notes']     ?? null
            );

            // Create batch record if expiry data is provided (IN transactions on expiry-tracked items)
            if (strtoupper($data['type']) === 'IN' && !empty($data['expiry_date'])) {
                $db = \App\Config\Database::getConnection();
                $batchNumber = $data['batch_number'] ?? ('BATCH-' . date('Ymd-His'));
                $stmt = $db->prepare("
                    INSERT INTO item_batches (item_id, business_id, batch_number, quantity, manufacturing_date, expiry_date, status, created_at)
                    VALUES (:item_id, :business_id, :batch_number, :quantity, :mfg_date, :expiry_date, 'active', NOW())
                ");
                $stmt->execute([
                    'item_id'      => $id,
                    'business_id'  => $ctx->business_id,
                    'batch_number' => $batchNumber,
                    'quantity'     => (float) $data['quantity'],
                    'mfg_date'     => $data['manufacturing_date'] ?? null,
                    'expiry_date'  => $data['expiry_date'],
                ]);
            }

            $this->json(true, ['item_id' => $id]);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to adjust stock');
        }
    }

    // GET /items/{id}/stock/history
    public function stockHistory(int $id): void {
        try {
            $this->auth();
            $history = $this->service->getStockTransactions($id);
            $this->json(true, ['history' => $history]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch stock history');
        }
    }

    // GET /items/{id}/price-history
    public function priceHistory(int $id): void {
        try {
            $ctx = $this->auth();
            $service = new \App\Services\PriceHistoryService(Database::getConnection());
            $records = $service->getHistory($id, $ctx->tenant_id);
            $this->json(true, $records);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch price history');
        }
    }

    // GET /items/{id}/stock-check?quantity=N
    public function stockCheck(int $id): void {
        try {
            $this->auth();
            $quantity = (float) ($_GET['quantity'] ?? 0);
            if ($quantity <= 0) {
                $this->json(false, null, 'Quantity must be greater than zero', 422);
                return;
            }
            $available = $this->service->getAvailableStock($id);
            if ($available >= $quantity) {
                $this->json(true, ['available' => $available, 'sufficient' => true]);
            } else {
                $this->json(false, ['available' => $available, 'sufficient' => false], 
                    "Insufficient stock: {$available} available, {$quantity} requested", 422);
            }
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to check stock');
        }
    }
}
