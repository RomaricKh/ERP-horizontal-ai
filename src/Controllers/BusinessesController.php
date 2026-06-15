<?php
namespace App\Controllers;

use App\Config\Database;
use App\Controllers\BaseController;
use App\Services\BusinessService;
use App\Support\AuthContext;

class BusinessesController extends BaseController {

    private BusinessService $service;

    public function __construct() {
        $db = Database::getConnection();
        $this->service = new BusinessService($db);
    }

    /* =========================
       PROTECTED ENDPOINTS
       (AuthMiddleware required)
       ========================= */

    // GET /businesses
    public function index(): void {
        try {
            $ctx = $this->auth();

            // Tenant owners see all businesses in their tenant
            if (AuthContext::isTenantOwner()) {
                $businesses = $this->service->getAllForTenant($ctx->tenant_id);
            } else {
                $businesses = $this->service->getAllForCurrentUser($ctx->user_id);
            }

            $this->json(true, [
                'businesses' => $businesses
            ]);

        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch businesses');
        }
    }

    // GET /businesses/{id}
    public function viewById(int $business_id): void {
        try {
            $ctx = $this->auth();

            $business = $this->service->getById($business_id);

            $this->json(true, [
                'business' => $business
            ]);

        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch business');
        }
    }

    // POST /businesses
    public function store(): void {
        try {
            $ctx = $this->auth();

            $data = $this->input(['name', 'currency', 'business_type_id']);

            // Pass business_type_id from request body (optional field)
            $business_id = $this->service->create($ctx->user_id, [
                'name' => $data['name'],
                'currency' => $data['currency'],
                'business_type_id' => $data['business_type_id'] ?? null,
                'legal_name' => $data['legal_name'] ?? null,
                'registration_no' => $data['registration_no'] ?? null,
                'address' => $data['address'] ?? null,
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
            ]);

            $this->json(true, ['business_id' => $business_id], null, 201);

        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to create business: ' . $e->getMessage());
        }
    }

    // PUT /businesses/{id}
    public function update(int $id): void {
        try {
            $ctx = $this->auth();

            $data = $this->input(['name', 'currency']);

            $result = $this->service->update($id, $ctx->user_id, $data);

            // If update returned a result array, it means a type change warning/error
            if ($result !== null && !$result['success']) {
                if ($result['warning']) {
                    $this->json(false, $result['warning'], $result['message'], 409);
                    return;
                }
                $this->json(false, null, $result['message'], 422);
                return;
            }

            $this->json(true, ['business_id' => $id]);

        } catch (\Throwable $e) {
            $this->serverError('Failed to update business: ' . $e->getMessage());
        }
    }

    // DELETE /businesses/{id}
    public function delete(int $id): void {
        try {
            $ctx = $this->auth();

            $this->service->delete($id, $ctx->user_id);

            $this->json(true, ['business_id' => $id]);

        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to delete business');
        }
    }

    // PATCH /businesses/{id}/active
    public function toggleActive(int $id): void {
        try {
            $ctx = $this->auth();

            $input = $this->input(['active']);
            $active = (bool) $input['active'];

            $repo = new \App\Repository\BusinessRepository(\App\Config\Database::getConnection());
            $repo->setActive($id, $ctx->tenant_id, $active);

            $this->json(true, [
                'business_id' => $id,
                'is_active'   => $active,
            ]);

        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to update business status');
        }
    }
}
