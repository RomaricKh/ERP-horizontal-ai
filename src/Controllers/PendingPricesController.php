<?php
namespace App\Controllers;

use App\Services\PendingPriceService;
use App\Support\AuthContext;
use App\Support\Response;
use PDO;

class PendingPricesController {
    private PendingPriceService $service;

    public function __construct(PDO $db) {
        $this->service = new PendingPriceService($db);
    }

    public function index(): void {
        $ctx = AuthContext::get();
        $prices = $this->service->getPendingPrices($ctx->tenant_id, $ctx->business_id);
        Response::json(['success' => true, 'data' => ['pending_prices' => $prices]]);
    }

    public function apply(): void {
        $ctx = AuthContext::get();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $ids = $data['ids'] ?? [];
        if (!is_array($ids) || empty($ids)) {
            Response::json(['success' => false, 'message' => 'No IDs provided'], 400);
            return;
        }

        try {
            $count = $this->service->applyPendingPrices($ids, $ctx->tenant_id, $ctx->user_id);
            Response::json(['success' => true, 'message' => "$count prices applied successfully"]);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function cancel(): void {
        $ctx = AuthContext::get();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $ids = $data['ids'] ?? [];
        if (!is_array($ids) || empty($ids)) {
            Response::json(['success' => false, 'message' => 'No IDs provided'], 400);
            return;
        }

        try {
            $count = $this->service->cancelPendingPrices($ids, $ctx->tenant_id, $ctx->user_id);
            Response::json(['success' => true, 'message' => "$count prices cancelled successfully"]);
        } catch (\Throwable $e) {
            Response::json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
