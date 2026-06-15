<?php

namespace App\Controllers;

use App\Config\Database;
use App\Repository\BusinessTypeRepository;
use App\Services\BusinessTypeService;
use App\Support\AuthContext;

class BusinessTypesController extends BaseController
{
    private BusinessTypeService $service;
    private BusinessTypeRepository $repo;

    public function __construct()
    {
        $db = Database::getConnection();
        $this->service = new BusinessTypeService($db);
        $this->repo = new BusinessTypeRepository($db);
    }

    /**
     * GET /business-types — legacy endpoint returning all types
     */
    public function index(): void
    {
        try {
            $types = $this->service->getAllTypes();
            $this->json(true, $types);
        } catch (\Throwable $e) {
            $this->serverError('Failed to load business types');
        }
    }

    /**
     * GET /tenant/business-types — list tenant-owned types only
     */
    public function listTenantTypes(): void
    {
        try {
            $tenantId = AuthContext::tenantId();
            $types = $this->repo->findByTenant($tenantId);
            $this->json(true, ['business_types' => $types]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to load tenant business types');
        }
    }

    /**
     * POST /tenant/business-types — create by copying a system type
     * Body: { source_system_type_id: int, name: string }
     */
    public function createFromSystem(): void
    {
        try {
            $input = $this->input(['source_system_type_id', 'name']);
            $tenantId = AuthContext::tenantId();

            $sourceId = (int) $input['source_system_type_id'];
            $name = trim($input['name']);

            if (empty($name) || mb_strlen($name) < 2 || mb_strlen($name) > 100) {
                $this->json(false, null, 'Name must be between 2 and 100 characters', 422);
                return;
            }

            $newTypeId = $this->repo->copyFromSystem($sourceId, $tenantId, $name);
            $newType = $this->repo->getById($newTypeId);
            $newType['modules'] = $this->repo->getModulesForType($newTypeId);

            $this->json(true, ['business_type' => $newType], 'Business type created', 201);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to create business type');
        }
    }

    /**
     * PUT /tenant/business-types/{id}/modules — replace module set
     * Body: { module_ids: [int, ...] }
     */
    public function updateModules(int $id): void
    {
        try {
            $input = $this->input(['module_ids']);
            $tenantId = AuthContext::tenantId();

            // Verify the type belongs to this tenant
            $type = $this->repo->getById($id);
            if (!$type || (int)$type['tenant_id'] !== $tenantId) {
                $this->notFound('Business type not found');
                return;
            }
            if ($type['is_system']) {
                $this->json(false, null, 'Cannot modify a system type', 403);
                return;
            }

            $moduleIds = array_map('intval', $input['module_ids']);
            $this->repo->setModules($id, $moduleIds);

            // Clear module activation cache
            \App\Services\ModuleActivationService::clearCache();

            $updatedType = $this->repo->getById($id);
            $updatedType['modules'] = $this->repo->getModulesForType($id);

            $this->json(true, ['business_type' => $updatedType], 'Modules updated');
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to update modules');
        }
    }

    /**
     * DELETE /tenant/business-types/{id} — delete if no businesses are assigned
     */
    public function deleteTenantType(int $id): void
    {
        try {
            $tenantId = AuthContext::tenantId();
            $this->repo->deleteTenantType($id, $tenantId);
            $this->json(true, null, 'Business type deleted');
        } catch (\RuntimeException $e) {
            $status = strpos($e->getMessage(), 'Cannot delete') !== false ? 409 : 404;
            $this->json(false, null, $e->getMessage(), $status);
        } catch (\Throwable $e) {
            $this->serverError('Failed to delete business type');
        }
    }

    /**
     * GET /tenant/business-types/system — list system types (templates for copying)
     */
    public function listSystemTypes(): void
    {
        try {
            $types = $this->repo->findSystemTypes();
            $this->json(true, ['system_types' => $types]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to load system types');
        }
    }
}
