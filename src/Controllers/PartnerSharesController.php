<?php
namespace App\Controllers;

use App\Config\Database;
use App\Services\PartnerShareService;

class PartnerSharesController extends BaseController {

    private PartnerShareService $service;

    public function __construct() {
        $this->service = new PartnerShareService(Database::getConnection());
    }

    // GET /partner-shares
    public function index(): void {
        try {
            $this->auth();
            $shares = $this->service->list();
            $this->json(true, ['partner_shares' => $shares]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch partner shares');
        }
    }

    // POST /partner-shares
    public function store(): void {
        try {
            $this->auth();
            $data = $this->input(['partner_id', 'share_percent', 'invested_capital', 'account_id', 'currency', 'exchange_rate']);
            $id = $this->service->create($data);
            $this->json(true, ['id' => $id], null, 201);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to create partner share');
        }
    }

    // PUT /partner-shares/{id}
    public function update(int $id): void {
        try {
            $this->auth();
            $data = $this->input(['share_percent']);
            $this->service->update($id, $data);
            $this->json(true, ['id' => $id]);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 404);
        } catch (\Throwable $e) {
            $this->serverError('Failed to update partner share');
        }
    }

    // DELETE /partner-shares/{id}
    public function destroy(int $id): void {
        try {
            $this->auth();
            $this->service->delete($id);
            $this->json(true, ['id' => $id]);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 404);
        } catch (\Throwable $e) {
            $this->serverError('Failed to delete partner share');
        }
    }
}
