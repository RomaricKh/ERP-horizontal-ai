<?php
namespace App\Controllers;

use App\Config\Database;
use App\Services\InsightsService;
use App\Support\AuthContext;

class InsightsController extends BaseController {

    private InsightsService $service;

    public function __construct() {
        $this->service = new InsightsService(Database::getConnection());
    }

    /**
     * Log an exception with structured context for diagnostics.
     * Format: [People] {method}: {ExceptionClass} - {message} (user={id}, business={id})
     */
    private function logError(string $method, \Throwable $e): void
    {
        try {
            $userId = AuthContext::userId();
        } catch (\Throwable $t) {
            $userId = 'unknown';
        }

        try {
            $businessId = AuthContext::businessId();
        } catch (\Throwable $t) {
            $businessId = 'unknown';
        }

        $class = get_class($e);
        error_log("[People] {$method}: {$class} - {$e->getMessage()} (user={$userId}, business={$businessId})");
        error_log($e->getTraceAsString());
    }

    // GET /people/{payeeId}/insights/supplier
    public function supplier(int $payeeId): void {
        try {
            $ctx = $this->auth();
            $businessId = $ctx->business_id;

            $items = $this->service->getSupplierInsights($payeeId, $businessId);

            $this->json(true, ['items' => $items]);
        } catch (\InvalidArgumentException $e) {
            $this->logError(__METHOD__, $e);
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->logError(__METHOD__, $e);
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->logError(__METHOD__, $e);
            $this->serverError('Failed to load insights');
        }
    }

    // GET /people/{payeeId}/insights/customer
    public function customer(int $payeeId): void {
        try {
            $ctx = $this->auth();
            $businessId = $ctx->business_id;

            $items = $this->service->getCustomerInsights($payeeId, $businessId);

            $this->json(true, ['items' => $items]);
        } catch (\InvalidArgumentException $e) {
            $this->logError(__METHOD__, $e);
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->logError(__METHOD__, $e);
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->logError(__METHOD__, $e);
            $this->serverError('Failed to load insights');
        }
    }

    // GET /people/{payeeId}/insights/general
    public function general(int $payeeId): void {
        try {
            $ctx = $this->auth();
            $businessId = $ctx->business_id;

            $data = $this->service->getGeneralInsights($payeeId, $businessId);

            $this->json(true, $data);
        } catch (\InvalidArgumentException $e) {
            $this->logError(__METHOD__, $e);
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->logError(__METHOD__, $e);
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->logError(__METHOD__, $e);
            $this->serverError('Failed to load insights');
        }
    }

    // GET /people/{payeeId}/customer-items
    public function customerItems(int $payeeId): void {
        try {
            $ctx = $this->auth();
            $businessId = $ctx->business_id;

            $items = $this->service->getCustomerItems($payeeId, $businessId);

            $this->json(true, ['items' => $items]);
        } catch (\InvalidArgumentException $e) {
            $this->logError(__METHOD__, $e);
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->logError(__METHOD__, $e);
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->logError(__METHOD__, $e);
            $this->serverError('Failed to load insights');
        }
    }

    // GET /people/{payeeId}/insights/partner
    public function partner(int $payeeId): void {
        try {
            $ctx = $this->auth();
            $businessId = $ctx->business_id;

            $data = $this->service->getPartnerInsights($payeeId, $businessId);

            $this->json(true, $data);
        } catch (\InvalidArgumentException $e) {
            $this->logError(__METHOD__, $e);
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->logError(__METHOD__, $e);
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->logError(__METHOD__, $e);
            $this->serverError('Failed to load insights');
        }
    }
}
