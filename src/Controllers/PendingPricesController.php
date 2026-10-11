<?php
namespace App\Controllers;

use App\Services\PendingPriceService;
use PDO;

class PendingPricesController extends BaseController {
    private PendingPriceService $service;

    public function __construct(PDO $db) {
        $this->service = new PendingPriceService($db);
    }

    public function index(): void {
        try {
            $ctx = $this->auth();
            if ($ctx->business_id === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
            $prices = $this->service->getPendingPrices($ctx->tenant_id, $ctx->business_id);
            $this->json(true, ['pending_prices' => $prices]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch pending prices');
        }
    }

    public function apply(): void {
        try {
            $ctx = $this->auth();
            if ($ctx->business_id === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
            $data = $this->input([]);
            $ids = $data['ids'] ?? [];
            if (!is_array($ids) || empty($ids)) {
                $this->json(false, null, 'No IDs provided', 400);
                return;
            }
            // Validate each ID is a positive integer
            $ids = array_filter(array_map('intval', $ids), fn($id) => $id > 0);
            if (empty($ids)) {
                $this->json(false, null, 'Invalid IDs provided', 400);
                return;
            }

            $count = $this->service->applyPendingPrices($ids, $ctx->tenant_id, $ctx->business_id, $ctx->user_id);
            $this->json(true, null, "$count prices applied successfully");
        } catch (\Throwable $e) {
            $this->serverError('Failed to apply pending prices');
        }
    }

    public function cancel(): void {
        try {
            $ctx = $this->auth();
            if ($ctx->business_id === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
            $data = $this->input([]);
            $ids = $data['ids'] ?? [];
            if (!is_array($ids) || empty($ids)) {
                $this->json(false, null, 'No IDs provided', 400);
                return;
            }
            // Validate each ID is a positive integer
            $ids = array_filter(array_map('intval', $ids), fn($id) => $id > 0);
            if (empty($ids)) {
                $this->json(false, null, 'Invalid IDs provided', 400);
                return;
            }

            $count = $this->service->cancelPendingPrices($ids, $ctx->tenant_id, $ctx->business_id, $ctx->user_id);
            $this->json(true, null, "$count prices cancelled successfully");
        } catch (\Throwable $e) {
            $this->serverError('Failed to cancel pending prices');
        }
    }
}
