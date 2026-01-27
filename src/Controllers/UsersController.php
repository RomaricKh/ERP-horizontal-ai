<?php
namespace App\Controllers;

use App\Controllers\BaseController;
use App\Services\UserService;

class UsersController extends BaseController {
    private UserService $service;

    public function __construct() {
        $this->service = new UserService();
    }

    // GET /users
    public function index(): void {
        try {
			$ctx = $this->auth();
            $users = $this->service->listForCurrentBusiness($ctx->business_id);
            $this->ok(['users' => $users]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch users'.$e);
        }
    }

    // GET /users/{id}
    public function viewById(int $id): void {
        try {
            $user = $this->service->getForCurrentBusiness($id);
            $this->ok(['user' => $user]);
        } catch (\Throwable $e) {
            $this->notFound('User not found');
        }
    }

    // POST /users
    public function store(): void {
        try {
            $data = $this->input(['username', 'email', 'name', 'role_id']);
            $userId = $this->service->createForCurrentBusiness($data);
            $this->created(['user_id' => $userId]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to create user');
        }
    }

    // PUT /users/{id}
    public function update(int $id): void {
        try {
            $data = $this->input(['name', 'email', 'role_id']);
            $this->service->updateForCurrentBusiness($id, $data);
            $this->ok();
        } catch (\Throwable $e) {
            $this->serverError('Failed to update user');
        }
    }

    // PATCH /users/{id}/toggle
    public function toggleStatus(int $id): void {
        try {
            $this->service->toggleStatusForCurrentBusiness($id);
            $this->ok();
        } catch (\Throwable $e) {
            $this->serverError('Failed to toggle user status');
        }
    }
}
