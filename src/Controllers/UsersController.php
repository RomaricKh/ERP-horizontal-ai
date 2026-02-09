<?php
namespace App\Controllers;

use App\Config\Database;
use App\Controllers\BaseController;
use App\Services\UserService;

class UsersController extends BaseController {
    private UserService $service;

    public function __construct() {
        $this->service = new UserService(Database::getConnection());
    }

    // GET /users
    public function index(): void {
        try {
			$ctx = $this->auth();
            $users = $this->service->listForCurrentBusiness($ctx->business_id);
            $this->json(true, ['users' => $users]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch users '.$e);
        }
    }

    // GET /users/{id}
    public function viewById(int $id): void {
        try {
			$ctx = $this->auth();
            $user = $this->service->getByUserId($id, $ctx->business_id);
            $this->json(true, ['user' => $user]);
        } catch (\Throwable $e) {
            $this->notFound('User not found '.$e);
        }
    }
	
	// GET /users/lookup?{params}
	public function lookup() {
		try {
			$ctx = $this->auth();
			$result = $this->service->lookup($_GET);

            $this->json(true, $result);
		} catch (Throwable $e) {
			return $this->serverError('Lookup failed '.$e);
		}
	}

    // POST /users
    public function store(): void {
        try {
			$ctx = $this->auth();
			
            $data = $this->input(['username', 'email', 'first_name', 'last_name', 'password', 'role_id', 'is_active']);
            $userId = $this->service->createForCurrentBusiness($ctx->business_id, $ctx->user_id, $data);
			
            $this->json(true, ['user_id' => $userId]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to create user ');
        }
    }

    // PUT /users/{id}
    public function update(int $id): void {
        try {
			$ctx = $this->auth();
			$selfModified = ($id === $ctx->user_id);
			
            $identity = $this->input(['username', 'email', 'first_name', 'last_name']);
            $access = $this->input(['role_id', 'is_active']);
            $this->service->updateForCurrentBusiness($id, $ctx->business_id, $ctx->user_id, $identity, $access);
			
            $this->json(true, ['selfModified' => $selfModified]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to update user');
        }
    }

    // DELETE /users/{id}
    public function delete(int $id): void {
        try {
			$ctx = $this->auth();
			$selfDeleted = ($id === $ctx->user_id);
			
            $this->service->deleteFromCurrentBusiness($id, $ctx->business_id, $ctx->user_id);
			
            $this->json(true, ['selfDeleted' => $selfDeleted]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to delete user');
        }
    }
}
