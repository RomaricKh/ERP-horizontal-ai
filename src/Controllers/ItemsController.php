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

            // Parse query params from the raw query string (in case $_GET is affected by rewrite)
            $queryParams = [];
            if (!empty($_SERVER['QUERY_STRING'])) {
                parse_str($_SERVER['QUERY_STRING'], $queryParams);
            }
            // Merge with $_GET as fallback
            $queryParams = array_merge($_GET, $queryParams);

            $excludeTypes = $queryParams['exclude_types'] ?? null;
            $includeInactive = isset($queryParams['include_inactive']) && $queryParams['include_inactive'] === '1';
            $items = $this->service->listForCurrentBusiness($excludeTypes, $includeInactive);

            // Optional: filter by customer payee_id (show global + customer-specific items)
            $customerPayeeId = isset($queryParams['customer_payee_id']) ? (int) $queryParams['customer_payee_id'] : null;
            if ($customerPayeeId) {
                // Resolve payee_id → people_id
                $db = \App\Config\Database::getConnection();
                $stmt = $db->prepare("SELECT people_id FROM payees WHERE id = :id LIMIT 1");
                $stmt->execute(['id' => $customerPayeeId]);
                $peopleId = $stmt->fetchColumn();

                if ($peopleId) {
                    $items = array_values(array_filter($items, function($item) use ($peopleId) {
                        return empty($item['customer_id']) || (int)$item['customer_id'] === (int)$peopleId;
                    }));
                }
            } elseif (isset($queryParams['global_only']) && $queryParams['global_only'] === '1') {
                // Only show items without a customer_id
                $items = array_values(array_filter($items, function($item) {
                    return empty($item['customer_id']);
                }));
            }

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

    // POST /items/{id}/reactivate
    public function reactivate(int $id): void {
        try {
            $this->auth();
            $this->service->reactivate($id);
            $this->json(true, ['id' => $id]);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to reactivate item');
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
            $type = strtoupper($data['type']);
            $quantity = (float) $data['quantity'];

            $this->service->adjustStock(
                $id,
                $type,
                $quantity,
                $data['reference'] ?? null,
                $data['notes']     ?? null
            );

            $db = \App\Config\Database::getConnection();

            // Check if item tracks expiry
            $itemStmt = $db->prepare("SELECT has_expiry FROM items WHERE id = ? LIMIT 1");
            $itemStmt->execute([$id]);
            $hasExpiry = (int) $itemStmt->fetchColumn();

            // ADJ is not allowed on batch-tracked items
            if ($hasExpiry === 1 && $type === 'ADJ') {
                $this->json(false, null, 'Cannot use absolute adjustment on batch-tracked items. Use IN or OUT instead.', 422);
                return;
            }

            if ($hasExpiry === 1) {
                if ($type === 'IN' && !empty($data['expiry_date'])) {
                    // Create a new batch
                    $batchNumber = $data['batch_number'] ?? ('BATCH-' . date('Ymd-His'));
                    $stmt = $db->prepare("
                        INSERT INTO item_batches (item_id, business_id, batch_number, quantity, manufacturing_date, expiry_date, status, created_at)
                        VALUES (:item_id, :business_id, :batch_number, :quantity, :mfg_date, :expiry_date, 'active', NOW())
                    ");
                    $stmt->execute([
                        'item_id'      => $id,
                        'business_id'  => $ctx->business_id,
                        'batch_number' => $batchNumber,
                        'quantity'     => $quantity,
                        'mfg_date'     => $data['manufacturing_date'] ?? null,
                        'expiry_date'  => $data['expiry_date'],
                    ]);
                } elseif ($type === 'OUT') {
                    // FEFO deduction — consume from earliest expiring batches first
                    $remaining = $quantity;
                    $batchesStmt = $db->prepare("
                        SELECT id, quantity FROM item_batches
                        WHERE item_id = :item_id AND business_id = :business_id AND status = 'active' AND quantity > 0
                        ORDER BY expiry_date ASC
                    ");
                    $batchesStmt->execute(['item_id' => $id, 'business_id' => $ctx->business_id]);
                    $batches = $batchesStmt->fetchAll(\PDO::FETCH_ASSOC);

                    foreach ($batches as $batch) {
                        if ($remaining <= 0) break;
                        $batchQty = (float) $batch['quantity'];
                        $deduct = min($remaining, $batchQty);

                        // Update batch quantity
                        $newBatchQty = $batchQty - $deduct;
                        $updateStmt = $db->prepare("UPDATE item_batches SET quantity = :qty, updated_at = NOW() WHERE id = :id");
                        $updateStmt->execute(['qty' => $newBatchQty, 'id' => $batch['id']]);

                        // Record batch transaction
                        $btStmt = $db->prepare("
                            INSERT INTO batch_transactions (batch_id, transaction_type, quantity, reference, notes, created_at, created_by)
                            VALUES (:batch_id, 'OUT', :quantity, :reference, :notes, NOW(), :user_id)
                        ");
                        $btStmt->execute([
                            'batch_id'  => $batch['id'],
                            'quantity'  => $deduct,
                            'reference' => $data['reference'] ?? null,
                            'notes'     => $data['notes'] ?? null,
                            'user_id'   => $ctx->user_id,
                        ]);

                        $remaining -= $deduct;
                    }
                }
                // ADJ: does not touch batches — only adjusts the stock total
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
