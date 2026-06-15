<?php
namespace App\Controllers;

use App\Config\Database;
use App\Services\AccountService;

class AccountsController extends BaseController {

    private AccountService $service;

    public function __construct() {
        $this->service = new AccountService(Database::getConnection());
    }

    // GET /accounts
    public function index(): void {
        try {
            $this->auth();
            $currency = $_GET['currency'] ?? null;
            $accounts = $this->service->list($currency);
            $this->json(true, ['accounts' => $accounts]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch accounts');
        }
    }

    // GET /accounts/{id}
    public function viewById(int $id): void {
        try {
            $this->auth();
            $account = $this->service->getById($id);
            $this->json(true, ['account' => $account]);
        } catch (\RuntimeException $e) {
            $this->notFound($e->getMessage());
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch account');
        }
    }

    // POST /accounts
    public function store(): void {
        try {
            $this->auth();
            $data = $this->input(['name', 'currency']);
            $id   = $this->service->create($data);
            $this->json(true, ['id' => $id], null, 201);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to create account: ' . $e->getMessage());
        }
    }

    // PUT /accounts/{id}
    public function update(int $id): void {
        try {
            $this->auth();
            $data = $this->input([]);
            $this->service->update($id, $data);
            $this->json(true, ['id' => $id]);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to update account');
        }
    }

    // DELETE /accounts/{id}
    public function delete(int $id): void {
        try {
            $this->auth();
            $this->service->delete($id);
            $this->json(true, ['id' => $id]);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to delete account');
        }
    }
}
