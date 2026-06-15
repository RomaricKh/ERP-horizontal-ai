<?php

namespace App\Controllers;

use App\Config\Database;
use App\Services\ManufacturingService;

class ManufacturingController extends BaseController
{
    private ManufacturingService $service;

    public function __construct()
    {
        $this->service = new ManufacturingService(Database::getConnection());
    }

    // ── RECIPES ───────────────────────────────────────────────────────────────

    // GET /manufacturing/recipes
    public function indexRecipes(): void
    {
        try {
            $this->auth();

            // Support fetching a single recipe by manufactured item_id
            if (!empty($_GET['item_id'])) {
                $recipe = $this->service->getRecipeByItemId((int) $_GET['item_id']);
                $this->json(true, ['recipe' => $recipe]);
                return;
            }

            $recipes = $this->service->listRecipes();
            $this->json(true, ['recipes' => $recipes]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch recipes');
        }
    }

    // GET /manufacturing/recipes/{id}
    public function viewRecipe(int $id): void
    {
        try {
            $this->auth();
            $recipe = $this->service->getRecipe($id);
            $this->json(true, ['recipe' => $recipe]);
        } catch (\RuntimeException $e) {
            $this->notFound($e->getMessage());
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch recipe');
        }
    }

    // POST /manufacturing/recipes
    public function storeRecipe(): void
    {
        try {
            $this->auth();
            $data   = $this->input(['name', 'components', 'yield_quantity']);
            $result = $this->service->createRecipe($data);
            $this->json(true, ['recipe_id' => $result['recipe_id'], 'item_id' => $result['item_id']], null, 201);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to create recipe');
        }
    }

    // PUT /manufacturing/recipes/{id}
    public function updateRecipe(int $id): void
    {
        try {
            $this->auth();
            $data = $this->input(['components', 'yield_quantity']);
            $this->service->updateRecipe($id, $data);
            $this->json(true, ['id' => $id]);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to update recipe');
        }
    }

    // DELETE /manufacturing/recipes/{id}
    public function deleteRecipe(int $id): void
    {
        try {
            $this->auth();
            $this->service->deleteRecipe($id);
            $this->json(true, ['id' => $id]);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to delete recipe');
        }
    }

    // ── PRODUCTION ORDERS ─────────────────────────────────────────────────────

    // GET /manufacturing/orders
    public function indexOrders(): void
    {
        try {
            $this->auth();
            $filters = [];
            if (!empty($_GET['start_date'])) {
                $filters['start_date'] = $_GET['start_date'];
            }
            if (!empty($_GET['end_date'])) {
                $filters['end_date'] = $_GET['end_date'];
            }
            if (!empty($_GET['manufactured_item_id'])) {
                $filters['manufactured_item_id'] = (int) $_GET['manufactured_item_id'];
            }
            $orders = $this->service->listProductionOrders(!empty($filters) ? $filters : null);
            $this->json(true, ['orders' => $orders]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch production orders');
        }
    }

    // GET /manufacturing/orders/{id}
    public function viewOrder(int $id): void
    {
        try {
            $this->auth();
            $order = $this->service->getProductionOrder($id);
            $this->json(true, ['order' => $order]);
        } catch (\RuntimeException $e) {
            $this->notFound($e->getMessage());
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch production order');
        }
    }

    // POST /manufacturing/orders
    public function storeOrder(): void
    {
        try {
            $this->auth();
            $data    = $this->input(['recipe_id', 'production_quantity']);
            $orderId = $this->service->createProductionOrder($data);
            $this->json(true, ['id' => $orderId], null, 201);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to create production order');
        }
    }

    // POST /manufacturing/orders/{id}/reverse
    public function reverseOrder(int $id): void
    {
        try {
            $this->auth();
            $this->service->reverseProductionOrder($id);
            $this->json(true, ['id' => $id]);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to reverse production order');
        }
    }

    // ── COST FLAG & AUTO-MARKUP ───────────────────────────────────────────────

    // POST /manufacturing/items/{id}/dismiss-cost-flag
    public function dismissCostFlag(int $itemId): void
    {
        try {
            $this->auth();
            $this->service->dismissCostChangeFlag($itemId);
            $this->json(true, ['item_id' => $itemId]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to dismiss cost flag');
        }
    }

    // POST /manufacturing/items/{id}/auto-markup
    public function setAutoMarkup(int $itemId): void
    {
        try {
            $this->auth();
            $data = $this->input(['markup_percent']);
            $this->service->setAutoMarkupConfig($itemId, (float) $data['markup_percent']);
            $this->json(true, ['item_id' => $itemId]);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to set auto-markup');
        }
    }
}
