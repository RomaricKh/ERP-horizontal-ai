<?php

namespace App\Controllers\Tenant;

use App\Config\Database;
use App\Controllers\BaseController;
use App\Support\AuthContext;
use App\Repository\TenantRepository;
use App\Repository\ModuleRepository;
use App\Services\TenantOwnerService;
use App\Services\ReportService;

class TenantCoreController extends BaseController
{
    private TenantRepository $tenantRepository;
    private ModuleRepository $moduleRepository;
    private TenantOwnerService $tenantOwnerService;
    private ReportService $reportService;

    public function __construct()
    {
        $db = Database::getConnection();
        $this->tenantRepository = new TenantRepository($db);
        $this->moduleRepository = new ModuleRepository($db);
        $this->tenantOwnerService = new TenantOwnerService($db);
        $this->reportService = new ReportService($db);
    }

    public function listTenants(): void
    {
        try {
            $userId = AuthContext::userId();
            $tenants = $this->tenantRepository->findAllByOwnerUserId($userId);
            $this->json(true, ['tenants' => $tenants]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch tenants');
        }
    }

    public function createTenant(): void
    {
        try {
            $userId = AuthContext::userId();
            $data = $this->input(['name', 'description']);
            $name = trim($data['name']);
            $description = isset($data['description']) ? trim($data['description']) : null;

            if (strlen($name) < 2 || strlen($name) > 100) {
                $this->json(false, null, 'Tenant name must be between 2 and 100 characters', 422);
                return;
            }
            if ($description !== null && strlen($description) > 500) {
                $this->json(false, null, 'Description must not exceed 500 characters', 422);
                return;
            }

            $tenantId = $this->tenantRepository->create($name, $description);

            // Create the TENANT_OWNER role for the new tenant
            $roleRepository = new \App\Repository\RoleRepository(Database::getConnection());
            $roleId = $roleRepository->createTenantOwnerRole($tenantId);

            $db = Database::getConnection();
            $stmt = $db->prepare("
                INSERT INTO user_roles (user_id, role_id, business_id, tenant_id)
                VALUES (:user_id, :role_id, NULL, :tenant_id)
            ");
            $stmt->execute([
                'user_id' => $userId,
                'role_id' => $roleId,
                'tenant_id' => $tenantId,
            ]);

            $tenant = $this->tenantRepository->findById($tenantId);
            $this->json(true, ['tenant' => $tenant], 'Tenant created successfully', 201);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to create tenant');
        }
    }

    public function updateTenant(int $tenantId): void
    {
        try {
            $userId = AuthContext::userId();
            if (!$this->tenantRepository->userOwnsTenant($userId, $tenantId)) {
                $this->json(false, null, 'Access denied: you do not own this tenant', 403);
                return;
            }

            $data = $this->input(['name', 'description']);
            $name = trim($data['name'] ?? '');
            $description = isset($data['description']) ? trim($data['description']) : null;

            if (strlen($name) < 2 || strlen($name) > 100) {
                $this->json(false, null, 'Tenant name must be between 2 and 100 characters', 422);
                return;
            }
            if ($description !== null && strlen($description) > 500) {
                $this->json(false, null, 'Description must not exceed 500 characters', 422);
                return;
            }

            $this->tenantRepository->update($tenantId, $name, $description);
            $tenant = $this->tenantRepository->findById($tenantId);
            $this->json(true, ['tenant' => $tenant], 'Tenant updated successfully');
        } catch (\Throwable $e) {
            $this->serverError('Failed to update tenant');
        }
    }

    public function toggleTenantActive(int $tenantId): void
    {
        try {
            $userId = AuthContext::userId();
            if (!$this->tenantRepository->userOwnsTenant($userId, $tenantId)) {
                $this->json(false, null, 'Access denied: you do not own this tenant', 403);
                return;
            }

            $data = $this->input(['active']);
            $active = (bool) ($data['active'] ?? false);

            $this->tenantRepository->setActive($tenantId, $active);
            $tenant = $this->tenantRepository->findById($tenantId);
            $this->json(true, ['tenant' => $tenant], $active ? 'Tenant activated' : 'Tenant deactivated');
        } catch (\Throwable $e) {
            $this->serverError('Failed to toggle tenant status');
        }
    }

    public function dashboard(): void
    {
        try {
            $tenantId = AuthContext::tenantId();
            $startDate = $_GET['start_date'] ?? date('Y-m-01');
            $endDate = $_GET['end_date'] ?? date('Y-m-d');
            $aggregation = $_GET['aggregation'] ?? 'monthly';

            $metrics = $this->tenantOwnerService->getDashboardMetrics($tenantId, $startDate, $endDate, $aggregation);
            $this->json(true, $metrics);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 400);
        } catch (\Throwable $e) {
            $this->serverError('Failed to load dashboard');
        }
    }

    public function generateReport(): void
    {
        try {
            $tenantId = AuthContext::tenantId();
            $data = $this->input(['report_type', 'start_date', 'end_date', 'format']);
            $reportType = $data['report_type'];
            $startDate = $data['start_date'];
            $endDate = $data['end_date'];
            $format = $data['format'];
            $businessId = isset($data['business_id']) ? (int) $data['business_id'] : null;

            if ($businessId !== null) {
                $result = $this->reportService->generateBusinessReport(
                    $tenantId,
                    $businessId,
                    $reportType,
                    $startDate,
                    $endDate,
                    $format
                );
            } else {
                $result = $this->reportService->generateTenantReport(
                    $tenantId,
                    $reportType,
                    $startDate,
                    $endDate,
                    $format
                );
            }
            $this->json(true, $result);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'does not belong to your tenant')) {
                $this->json(false, null, $e->getMessage(), 403);
            } else {
                $this->json(false, null, $e->getMessage(), 400);
            }
        } catch (\Throwable $e) {
            $this->serverError('Failed to generate report');
        }
    }

    public function viewTenant(int $tenantId): void
    {
        try {
            $userId = AuthContext::userId();
            if (!$this->tenantRepository->userOwnsTenant($userId, $tenantId)) {
                $this->json(false, null, 'Access denied: you do not own this tenant', 403);
                return;
            }
            $tenant = $this->tenantRepository->findById($tenantId);
            if (!$tenant) {
                $this->notFound('Tenant not found');
                return;
            }
            $this->json(true, ['tenant' => $tenant]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch tenant');
        }
    }

    public function listModules(): void
    {
        try {
            $modules = $this->moduleRepository->getAll();
            $result = array_map(function ($mod) {
                return [
                    'id'       => (int) $mod['id'],
                    'code'     => $mod['code'],
                    'name'     => $mod['name'],
                    'icon'     => $mod['icon'],
                    'category' => $mod['category'],
                ];
            }, $modules);
            $this->json(true, ['modules' => $result]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch modules');
        }
    }
}
