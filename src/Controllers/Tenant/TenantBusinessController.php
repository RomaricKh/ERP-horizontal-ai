<?php

namespace App\Controllers\Tenant;

use App\Config\Database;
use App\Controllers\BaseController;
use App\Support\AuthContext;
use App\Services\TenantOwnerService;
use App\Services\BusinessService;
use App\Services\BusinessTypeService;

class TenantBusinessController extends BaseController
{
    private TenantOwnerService $tenantOwnerService;
    private BusinessService $businessService;
    private BusinessTypeService $businessTypeService;

    public function __construct()
    {
        $db = Database::getConnection();
        $this->tenantOwnerService = new TenantOwnerService($db);
        $this->businessService = new BusinessService($db);
        $this->businessTypeService = new BusinessTypeService($db);
    }

    public function listBusinesses(): void
    {
        try {
            $tenantId = AuthContext::tenantId();

            $page = max(1, (int) ($_GET['page'] ?? 1));
            $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 20)));

            $result = $this->tenantOwnerService->getBusinessSummaries($tenantId, $page, $perPage);

            $this->json(true, $result);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch businesses');
        }
    }

    public function createBusiness(): void
    {
        try {
            $tenantId = AuthContext::tenantId();
            $actingUserId = AuthContext::userId();

            $data = $this->input(['name', 'business_type_id', 'currency', 'legal_name', 'registration_no', 'address', 'phone', 'email']);

            $businessId = $this->businessService->createForTenantOwner($tenantId, $actingUserId, $data);

            $this->json(true, ['business_id' => $businessId], 'Business created successfully', 201);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 400);
        } catch (\Throwable $e) {
            $this->serverError('Failed to create business');
        }
    }

    public function viewBusiness(int $businessId): void
    {
        try {
            $tenantId = AuthContext::tenantId();
            $business = $this->businessService->getById($businessId);

            if (!$business || (int) $business['tenant_id'] !== $tenantId) {
                $this->json(false, null, 'Access denied: business does not belong to your tenant', 403);
                return;
            }

            $this->json(true, ['business' => $business]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch business');
        }
    }

    public function updateBusiness(int $businessId): void
    {
        try {
            $tenantId = AuthContext::tenantId();
            $business = $this->businessService->getById($businessId);
            if (!$business || (int)$business['tenant_id'] !== $tenantId) {
                $this->json(false, null, 'Access denied: business does not belong to your tenant', 403);
                return;
            }

            $actingUserId = AuthContext::userId();

            $data = $this->input(['name', 'currency', 'business_type_id', 'legal_name', 'registration_no', 'address', 'phone', 'email']);

            $result = $this->businessService->update($businessId, $actingUserId, $data);

            if ($result !== null && !$result['success']) {
                if ($result['warning'] ?? false) {
                    $this->json(false, $result['warning'], $result['message'], 409);
                    return;
                }
                $this->json(false, null, $result['message'], 422);
                return;
            }

            $this->json(true, ['business_id' => $businessId]);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to update business');
        }
    }

    public function deleteBusiness(int $businessId): void
    {
        try {
            $actingUserId = AuthContext::userId();

            $this->businessService->delete($businessId, $actingUserId);

            $this->json(true, ['business_id' => $businessId]);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to delete business');
        }
    }

    public function changeBusinessType(int $businessId): void
    {
        try {
            $tenantId = AuthContext::tenantId();
            $actingUserId = AuthContext::userId();

            $data = $this->input(['business_type_id']);
            $newTypeId = (int) $data['business_type_id'];
            $force = (bool) ($data['force'] ?? false);

            $result = $this->businessService->changeBusinessTypeForTenantOwner(
                $tenantId,
                $businessId,
                $newTypeId,
                $force,
                $actingUserId
            );

            $this->json(true, $result);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 409);
        } catch (\Throwable $e) {
            $this->serverError('Failed to change business type');
        }
    }

    public function listBusinessTypes(): void
    {
        try {
            $tenantId = AuthContext::tenantId();
            $types = $this->businessTypeService->listForTenant($tenantId);

            $this->json(true, ['business_types' => $types]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch business types');
        }
    }

    public function listSystemBusinessTypes(): void
    {
        try {
            $systemTypes = $this->businessTypeService->getSystemTypesWithModules();

            $this->json(true, ['system_types' => $systemTypes]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch system business types');
        }
    }

    public function createBusinessType(): void
    {
        try {
            $tenantId = AuthContext::tenantId();

            $data = $this->input(['name', 'description', 'module_codes']);
            $name = $data['name'];
            $description = $data['description'];
            $moduleCodes = $data['module_codes'];

            $typeId = $this->businessTypeService->createCustomType($tenantId, $name, $description, $moduleCodes);

            $this->json(true, ['business_type_id' => $typeId], 'Business type created successfully', 201);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 400);
        } catch (\Throwable $e) {
            $this->serverError('Failed to create business type');
        }
    }

    public function updateBusinessType(int $id): void
    {
        try {
            $tenantId = AuthContext::tenantId();

            $data = $this->input(['name', 'description', 'module_codes']);
            $name = $data['name'];
            $description = $data['description'];
            $moduleCodes = $data['module_codes'];

            $this->businessTypeService->updateCustomType($id, $tenantId, $name, $description, $moduleCodes);

            $this->json(true, null, 'Business type updated successfully');
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'system-defined')) {
                $this->json(false, null, $e->getMessage(), 403);
            } elseif (str_contains($e->getMessage(), 'not found')) {
                $this->json(false, null, $e->getMessage(), 404);
            } else {
                $this->json(false, null, $e->getMessage(), 400);
            }
        } catch (\Throwable $e) {
            $this->serverError('Failed to update business type');
        }
    }

    public function deleteBusinessType(int $id): void
    {
        try {
            $tenantId = AuthContext::tenantId();

            $this->businessTypeService->deleteCustomType($id, $tenantId);

            $this->json(true, null, 'Business type deleted successfully');
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'system-defined')) {
                $this->json(false, null, $e->getMessage(), 403);
            } elseif (str_contains($e->getMessage(), 'not found')) {
                $this->json(false, null, $e->getMessage(), 404);
            } elseif (str_contains($e->getMessage(), 'Cannot delete')) {
                $this->json(false, null, $e->getMessage(), 409);
            } else {
                $this->json(false, null, $e->getMessage(), 400);
            }
        } catch (\Throwable $e) {
            $this->serverError('Failed to delete business type');
        }
    }
}
