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
            $ctx = $this->auth();
            if ($ctx->business_id === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
            if ($ctx->business_id === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
            $types = $this->service->listTypes();
            $this->json(true, ['item_types' => $types]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch item types');
        }
    }

    // POST /items/types
    public function storeType(): void {
        try {
            $ctx = $this->auth();
            if ($ctx->business_id === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
            if ($ctx->business_id === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
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
            $ctx = $this->auth();
            if ($ctx->business_id === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
            if ($ctx->business_id === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
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
            $ctx = $this->auth();
            if ($ctx->business_id === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
            if ($ctx->business_id === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
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
    // GET /items
    public function index(): void {
        try {
            $ctx = $this->auth();
            if ($ctx->business_id === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
            if ($ctx->business_id === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
            $queryParams = [];
            if (!empty($_SERVER['QUERY_STRING'])) {
                parse_str($_SERVER['QUERY_STRING'], $queryParams);
            }
            $queryParams = array_merge($_GET, $queryParams);

            $excludeTypes = $queryParams['exclude_types'] ?? null;
            $includeInactive = isset($queryParams['include_inactive']) && $queryParams['include_inactive'] === '1';
            $items = $this->service->listForCurrentBusiness($excludeTypes, $includeInactive);
            $this->json(true, ['items' => $items]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch items');
        }
    }

    // GET /items/for-payee/{payeeId} — items visible to a specific customer (global + customer-specific)
    public function indexForPayee(int $payeeId): void {
        try {
            $ctx = $this->auth();
            if ($ctx->business_id === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
            if ($ctx->business_id === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
            $items = $this->service->listForCurrentBusiness(null, false);

            // Resolve payee_id → people_id (scoped to business)
            $db = \App\Config\Database::getConnection();
            $stmt = $db->prepare("SELECT people_id FROM payees WHERE id = :id AND business_id = :business_id LIMIT 1");
            $stmt->execute(['id' => $payeeId, 'business_id' => $ctx->business_id]);
            $peopleId = $stmt->fetchColumn();

            if ($peopleId) {
                $items = array_values(array_filter($items, function($item) use ($peopleId) {
                    return empty($item['customer_id']) || (int)$item['customer_id'] === (int)$peopleId;
                }));
            }

            $this->json(true, ['items' => $items]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch items for payee');
        }
    }

    // GET /items/{id}
    public function viewById(int $id): void {
        try {
            $ctx = $this->auth();
            if ($ctx->business_id === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
            if ($ctx->business_id === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
            $item = $this->service->getById($id);
            $this->json(true, ['item' => $item]);
        } catch (\Throwable $e) {
            $this->notFound('Item not found');
        }
    }

    // GET /items/{id}/insights
    public function getInsights(int $id): void {
        try {
            $ctx = $this->auth();
            if ($ctx->business_id === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
            if ($ctx->business_id === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
            $startDate = $_GET['start_date'] ?? null;
            $endDate = $_GET['end_date'] ?? null;
            $insights = $this->service->getInsights($id, $startDate, $endDate);
            $this->json(true, ['insights' => $insights]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch item insights');
        }
    }

    // POST /items
    public function store(): void {
        try {
            $ctx = $this->auth();
            if ($ctx->business_id === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
            if ($ctx->business_id === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
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
            $ctx = $this->auth();
            if ($ctx->business_id === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
            if ($ctx->business_id === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
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
            $ctx = $this->auth();
            if ($ctx->business_id === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
            if ($ctx->business_id === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
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
            $ctx = $this->auth();
            if ($ctx->business_id === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
            if ($ctx->business_id === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
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
            $ctx = $this->auth();
            if ($ctx->business_id === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
            if ($ctx->business_id === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
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
            if ($ctx->business_id === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
            $data = $this->input(['type', 'quantity']);
            $type = strtoupper($data['type']);
            $quantity = (float) $data['quantity'];

            // All validation and batch logic is handled INSIDE the service
            $this->service->adjustStock(
                $id,
                $type,
                $quantity,
                $data['reference'] ?? null,
                $data['notes'] ?? null,
                $data['expiry_date'] ?? null,
                $data['batch_number'] ?? null,
                $data['manufacturing_date'] ?? null
            );

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
            $ctx = $this->auth();
            if ($ctx->business_id === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
            if ($ctx->business_id === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
            $history = $this->service->getStockTransactions($id);
            $this->json(true, ['history' => $history]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch stock history');
        }
    }

    // GET /batches/{id}
    public function batchTransactions(int $id): void {
        try {
            $ctx = $this->auth();
            if ($ctx->business_id === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
            $repo = new \App\Repository\BatchRepository(\App\Config\Database::getConnection());
            $history = $repo->getTransactionsByItem($id, $ctx->business_id);
            $this->json(true, ['history' => $history]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch batch transactions');
        }
    }

    // GET /items/{id}/price-history
    public function priceHistory(int $id): void {
        try {
            $ctx = $this->auth();
            if ($ctx->business_id === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
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
            $ctx = $this->auth();
            if ($ctx->business_id === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
            if ($ctx->business_id === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
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
