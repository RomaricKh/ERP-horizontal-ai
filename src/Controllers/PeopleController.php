<?php
namespace App\Controllers;

use App\Config\Database;
use App\Services\PeopleService;
use App\Support\AuthContext;

class PeopleController extends BaseController {

    private PeopleService $service;

    public function __construct() {
        $this->service = new PeopleService(Database::getConnection());
    }



    // GET /people
    public function index(): void {
        try {
            $ctx     = $this->auth();
            $businessId = $ctx->business_id;
            if ($businessId === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
            $people  = $this->service->listForBusiness($ctx->tenant_id, $businessId);
            $this->json(true, ['people' => $people]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch people');
        }
    }

    // GET /people/search-tenant
    public function searchTenant(): void {
        try {
            $ctx   = $this->auth();
            $query = $_GET['q'] ?? '';
            $people = $this->service->searchTenant($ctx->tenant_id, $query);
            $this->json(true, ['people' => $people]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to search people');
        }
    }

    // GET /people/{id}
    public function viewById(int $id): void {
        try {
            $ctx    = $this->auth();
            $businessId = $ctx->business_id;
            if ($businessId === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
            $person = $this->service->getById($id, $ctx->tenant_id);
            $detail = $this->service->getPayeeDetail($id, $businessId);
            $this->json(true, ['person' => $person, 'payee' => $detail]);
        } catch (\Throwable $e) {
            $this->notFound('Person not found');
        }
    }

    // POST /people
    public function store(): void {
        try {
            $ctx  = $this->auth();
            $businessId = $ctx->business_id;
            if ($businessId === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
            $data = $this->input(['name', 'payee_type_ids']);
            $id   = $this->service->create($ctx->tenant_id, $businessId, $ctx->user_id, $data);
            $this->json(true, ['id' => $id], null, 201);
        } catch (\InvalidArgumentException $e) {
            $this->logError('People', __METHOD__, $e);
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->logError('People', __METHOD__, $e);
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->logError('People', __METHOD__, $e);
            $this->serverError('Failed to create person');
        }
    }

    // PUT /people/{id}
    public function update(int $id): void {
        try {
            $ctx  = $this->auth();
            $businessId = $ctx->business_id;
            if ($businessId === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
            $data = $this->input(['name', 'payee_type_ids']);
            $this->service->update($id, $ctx->tenant_id, $businessId, $ctx->user_id, $data);
            $this->json(true, ['id' => $id]);
        } catch (\InvalidArgumentException $e) {
            $this->logError('People', __METHOD__, $e);
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->logError('People', __METHOD__, $e);
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->logError('People', __METHOD__, $e);
            $this->serverError('Failed to update person');
        }
    }

    // DELETE /people/{id}
    public function delete(int $id): void {
        try {
            $ctx = $this->auth();
            $businessId = $ctx->business_id;
            if ($businessId === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
            $this->service->delete($id, $ctx->tenant_id, $businessId, $ctx->user_id);
            $this->json(true, ['id' => $id]);
        } catch (\InvalidArgumentException $e) {
            $this->logError('People', __METHOD__, $e);
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->logError('People', __METHOD__, $e);
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->logError('People', __METHOD__, $e);
            $this->serverError('Failed to delete person');
        }
    }

    // ── PAYEE TYPES ───────────────────────────────────────────────────────────

    // GET /people/payee-types
    public function payeeTypes(): void {
        try {
            $ctx   = $this->auth();
            $businessId = $ctx->business_id;
            if ($businessId === null) {
                $this->json(false, null, 'Active business context required', 422);
                return;
            }
            $types = $this->service->listPayeeTypes($ctx->tenant_id, $businessId);
            $this->json(true, ['payee_types' => $types]);
        } catch (\Throwable $e) {
            $this->logError('People', __METHOD__, $e);
            $this->serverError('Failed to fetch payee types');
        }
    }

    // POST /people/payee-types
    public function storePayeeType(): void {
        try {
            $ctx  = $this->auth();
            $data = $this->input(['name']);
            $id   = $this->service->createPayeeType(
                $ctx->tenant_id, $ctx->business_id, $ctx->user_id, $data['name']
            );
            $this->json(true, ['id' => $id], null, 201);
        } catch (\InvalidArgumentException $e) {
            $this->logError('People', __METHOD__, $e);
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->logError('People', __METHOD__, $e);
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->logError('People', __METHOD__, $e);
            $this->serverError('Failed to create payee type');
        }
    }

    // PUT /people/payee-types/{id}
    public function updatePayeeType(int $id): void {
        try {
            $ctx  = $this->auth();
            $data = $this->input(['name']);
            $this->service->updatePayeeType(
                $id, $ctx->tenant_id, $ctx->business_id, $ctx->user_id, $data['name']
            );
            $this->json(true, ['id' => $id]);
        } catch (\InvalidArgumentException $e) {
            $this->logError('People', __METHOD__, $e);
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->logError('People', __METHOD__, $e);
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->logError('People', __METHOD__, $e);
            $this->serverError('Failed to update payee type');
        }
    }

    // DELETE /people/payee-types/{id}
    public function deletePayeeType(int $id): void {
        try {
            $ctx = $this->auth();
            $this->service->deletePayeeType(
                $id, $ctx->tenant_id, $ctx->business_id, $ctx->user_id
            );
            $this->json(true, ['id' => $id]);
        } catch (\InvalidArgumentException $e) {
            $this->logError('People', __METHOD__, $e);
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->logError('People', __METHOD__, $e);
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->logError('People', __METHOD__, $e);
            $this->serverError('Failed to delete payee type');
        }
    }
}
