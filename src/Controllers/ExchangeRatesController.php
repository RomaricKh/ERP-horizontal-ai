<?php
namespace App\Controllers;

use App\Config\Database;
use App\Services\ExchangeRateService;

class ExchangeRatesController extends BaseController {

    private ExchangeRateService $service;

    public function __construct() {
        $this->service = new ExchangeRateService(Database::getConnection());
    }

    // GET /exchange-rates
    public function index(): void {
        try {
            $this->auth();
            $rates = $this->service->listForBusiness();
            $this->json(true, ['exchange_rates' => $rates]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch exchange rates');
        }
    }

    // GET /exchange-rates/latest?currency=USD
    public function latest(): void {
        try {
            $this->auth();
            $currency = $_GET['currency'] ?? '';

            if (trim($currency) === '') {
                $this->json(false, null, 'Currency is required', 422);
                return;
            }

            $result = $this->service->getLatestForLookup($currency);
            $this->json(true, [
                'rate'      => $result['rate'],
                'rate_date' => $result['rate_date'],
                'is_base'   => $result['is_base'],
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch latest exchange rate');
        }
    }

    // GET /exchange-rates/{id}
    public function show(int $id): void {
        try {
            $this->auth();
            $ctx = \App\Support\AuthContext::get();
            $rate = (new \App\Repository\ExchangeRateRepository(\App\Config\Database::getConnection()))
                ->findById($id, $ctx->tenant_id);
            if (!$rate) {
                $this->json(false, null, 'Exchange rate not found', 404);
                return;
            }
            $this->json(true, ['exchange_rate' => $rate]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch exchange rate');
        }
    }

    // POST /exchange-rates
    public function store(): void {
        try {
            $this->auth();
            $data = $this->input(['currency', 'rate_date', 'rate']);
            $id   = $this->service->create($data);
            $this->json(true, ['id' => $id], null, 201);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to create exchange rate: ' . $e->getMessage());
        }
    }

    // PUT /exchange-rates/{id}
    public function update(int $id): void {
        try {
            $this->auth();
            $data = $this->input([]);
            $this->service->update($id, $data);
            $this->json(true, ['id' => $id]);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to update exchange rate');
        }
    }

    // DELETE /exchange-rates/{id}
    public function delete(int $id): void {
        try {
            $this->auth();
            $this->service->delete($id);
            $this->json(true, ['id' => $id]);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to delete exchange rate');
        }
    }
}
