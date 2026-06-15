<?php
namespace App\Services;

use App\Repository\ServiceRepository;
use App\Repository\ServiceCategoryRepository;
use App\Support\AuthContext;
use PDO;

class ServiceService {
    private ServiceRepository         $repo;
    private ServiceCategoryRepository $categoryRepo;
    private AuditService              $audit;
    private PDO                       $db;

    private const MODULE_CODE = 'SERVICES';

    public function __construct(PDO $db) {
        $this->db           = $db;
        $this->repo         = new ServiceRepository($db);
        $this->categoryRepo = new ServiceCategoryRepository($db);
        $this->audit        = new AuditService($db);
    }

    // ── SERVICES ──────────────────────────────────────────────────────────────

    /**
     * List all services for the current business.
     * By default only active services are returned.
     */
    public function list(bool $includeInactive = false): array {
        $ctx = AuthContext::get();
        return $this->repo->findAllForBusiness($ctx->business_id, $ctx->tenant_id, $includeInactive);
    }

    /**
     * Get a single service by ID.
     *
     * @throws \RuntimeException if not found
     */
    public function getById(int $id): array {
        $ctx     = AuthContext::get();
        $service = $this->repo->findById($id, $ctx->business_id, $ctx->tenant_id);
        if (!$service) {
            throw new \RuntimeException('Service not found');
        }
        return $service;
    }

    /**
     * Create a new service.
     * Validates name, unit, and rate before inserting.
     *
     * @throws \InvalidArgumentException if validation fails
     */
    public function create(array $data): int {
        $ctx = AuthContext::get();

        // Validation
        $name = trim($data['name'] ?? '');
        if ($name === '') {
            throw new \InvalidArgumentException('Service name is required');
        }

        $unit = trim($data['unit'] ?? '');
        if ($unit === '') {
            throw new \InvalidArgumentException('Service unit is required');
        }

        $rate = $data['rate'] ?? null;
        if (!is_numeric($rate) || (float) $rate < 0) {
            throw new \InvalidArgumentException('Service rate must be a non-negative number');
        }

        $this->db->beginTransaction();
        try {
            $id = $this->repo->create([
                'tenant_id'   => $ctx->tenant_id,
                'business_id' => $ctx->business_id,
                'category_id' => $data['category_id'] ?? null,
                'name'        => $name,
                'description' => $data['description'] ?? null,
                'unit'        => $unit,
                'rate'        => (float) $rate,
                'created_by'  => $ctx->user_id,
            ]);

            $this->audit->log(
                'CREATE',
                'services',
                $id,
                'CREATE',
                null,
                ['name' => $name, 'unit' => $unit, 'rate' => (float) $rate],
                $ctx->user_id,
                $ctx->tenant_id,
                $ctx->business_id
            );

            $this->db->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Update an existing service.
     * Only changed fields are applied.
     *
     * @throws \RuntimeException if service not found
     * @throws \InvalidArgumentException if validation fails for changed fields
     */
    public function update(int $id, array $data): void {
        $ctx     = AuthContext::get();
        $current = $this->repo->findById($id, $ctx->business_id, $ctx->tenant_id);
        if (!$current) {
            throw new \RuntimeException('Service not found');
        }

        // Validate changed fields
        if (array_key_exists('name', $data)) {
            $name = trim($data['name'] ?? '');
            if ($name === '') {
                throw new \InvalidArgumentException('Service name is required');
            }
            $data['name'] = $name;
        }

        if (array_key_exists('unit', $data)) {
            $unit = trim($data['unit'] ?? '');
            if ($unit === '') {
                throw new \InvalidArgumentException('Service unit is required');
            }
            $data['unit'] = $unit;
        }

        if (array_key_exists('rate', $data)) {
            $rate = $data['rate'] ?? null;
            if (!is_numeric($rate) || (float) $rate < 0) {
                throw new \InvalidArgumentException('Service rate must be a non-negative number');
            }
            $data['rate'] = (float) $rate;
        }

        // Determine which fields actually changed
        $allowed  = ['name', 'description', 'unit', 'rate', 'category_id', 'is_active'];
        $changes  = [];
        $oldVals  = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data) && $data[$field] != ($current[$field] ?? null)) {
                $changes[$field] = $data[$field];
                $oldVals[$field] = $current[$field] ?? null;
            }
        }

        if (empty($changes)) {
            return; // Nothing to update
        }

        $this->db->beginTransaction();
        try {
            $this->repo->update($id, $changes);

            $this->audit->log(
                'UPDATE',
                'services',
                $id,
                'UPDATE',
                $oldVals,
                $changes,
                $ctx->user_id,
                $ctx->tenant_id,
                $ctx->business_id
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Deactivate (soft-delete) a service.
     *
     * @throws \RuntimeException if service not found
     */
    public function deactivate(int $id): void {
        $ctx     = AuthContext::get();
        $current = $this->repo->findById($id, $ctx->business_id, $ctx->tenant_id);
        if (!$current) {
            throw new \RuntimeException('Service not found');
        }

        $this->db->beginTransaction();
        try {
            $this->repo->deactivate($id, $ctx->business_id, $ctx->tenant_id);

            $this->audit->log(
                'UPDATE',
                'services',
                $id,
                'DEACTIVATE',
                ['is_active' => 1],
                ['is_active' => 0],
                $ctx->user_id,
                $ctx->tenant_id,
                $ctx->business_id
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // ── SERVICE CATEGORIES ────────────────────────────────────────────────────

    /**
     * List all service categories for the current business (module-gated).
     */
    public function listCategories(): array {
        $ctx = AuthContext::get();
        return $this->categoryRepo->findForBusiness($ctx->tenant_id, $ctx->business_id);
    }

    /**
     * Create a new service category.
     *
     * @throws \RuntimeException if a category with the same name already exists
     */
    public function createCategory(string $name): int {
        $ctx = AuthContext::get();

        $trimmed = trim($name);
        if ($trimmed === '') {
            throw new \InvalidArgumentException('Category name is required');
        }

        $this->db->beginTransaction();
        try {
            $id = $this->categoryRepo->create([
                'tenant_id'   => $ctx->tenant_id,
                'business_id' => $ctx->business_id,
                'name'        => $trimmed,
                'created_by'  => $ctx->user_id,
            ]);

            $this->audit->log(
                'CREATE',
                'service_categories',
                $id,
                'CREATE',
                null,
                ['name' => $trimmed],
                $ctx->user_id,
                $ctx->tenant_id,
                $ctx->business_id
            );

            $this->db->commit();
            return $id;
        } catch (\PDOException $e) {
            $this->db->rollBack();
            if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'Duplicate entry')) {
                throw new \RuntimeException('A category with this name already exists');
            }
            throw $e;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Update a service category name.
     *
     * @throws \RuntimeException if category not found or duplicate name
     */
    public function updateCategory(int $id, string $name): void {
        $ctx     = AuthContext::get();
        $current = $this->categoryRepo->findById($id, $ctx->tenant_id);
        if (!$current) {
            throw new \RuntimeException('Service category not found');
        }

        $trimmed = trim($name);
        if ($trimmed === '') {
            throw new \InvalidArgumentException('Category name is required');
        }

        if ($current['name'] === $trimmed) {
            return;
        }

        $this->db->beginTransaction();
        try {
            $this->categoryRepo->update($id, $ctx->tenant_id, $trimmed);

            $this->audit->log(
                'UPDATE',
                'service_categories',
                $id,
                'UPDATE',
                ['name' => $current['name']],
                ['name' => $trimmed],
                $ctx->user_id,
                $ctx->tenant_id,
                $ctx->business_id
            );

            $this->db->commit();
        } catch (\PDOException $e) {
            $this->db->rollBack();
            if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'Duplicate entry')) {
                throw new \RuntimeException('A category with this name already exists');
            }
            throw $e;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Delete a service category.
     * Rejects deletion if any services reference this category.
     *
     * @throws \RuntimeException if category not found or in use
     */
    public function deleteCategory(int $id): void {
        $ctx     = AuthContext::get();
        $current = $this->categoryRepo->findById($id, $ctx->tenant_id);
        if (!$current) {
            throw new \RuntimeException('Service category not found');
        }

        $count = $this->categoryRepo->countServicesForCategory($id, $ctx->business_id, $ctx->tenant_id);
        if ($count > 0) {
            throw new \RuntimeException('Cannot delete category: it has services assigned');
        }

        $this->db->beginTransaction();
        try {
            $this->categoryRepo->delete($id, $ctx->tenant_id, $ctx->business_id);

            $this->audit->log(
                'DELETE',
                'service_categories',
                $id,
                'DELETE',
                $current,
                null,
                $ctx->user_id,
                $ctx->tenant_id,
                $ctx->business_id
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
