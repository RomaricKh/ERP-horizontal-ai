<?php

namespace App\Controllers;

use App\Config\Database;
use App\Repository\ModuleRepository;
use App\Repository\BusinessTypeRepository;
use App\Repository\BusinessModuleOverrideRepository;
use App\Services\BusinessTypeService;
use App\Services\AuditService;
use PDO;

class ModulesController extends BaseController
{
    private PDO $db;
    private ModuleRepository $moduleRepo;
    private BusinessTypeRepository $businessTypeRepo;
    private BusinessModuleOverrideRepository $overrideRepo;
    private BusinessTypeService $businessTypeService;
    private AuditService $audit;

    /**
     * Core modules that cannot be deactivated.
     */
    private const CORE_MODULES = ['auth', 'users', 'businesses'];

    public function __construct()
    {
        $this->db = Database::getConnection();
        $this->moduleRepo = new ModuleRepository($this->db);
        $this->businessTypeRepo = new BusinessTypeRepository($this->db);
        $this->overrideRepo = new BusinessModuleOverrideRepository($this->db);
        $this->businessTypeService = new BusinessTypeService($this->db);
        $this->audit = new AuditService($this->db);
    }

    /**
     * GET /settings/modules
     * Returns all modules with their activation status for the current business.
     * Status: type_default | override_activate | override_deactivate | inactive
     */
    public function index(): void
    {
        try {
            $auth = $this->auth();
            $businessId = $auth->business_id;

            // Get the business's type
            $stmt = $this->db->prepare("SELECT business_type_id FROM businesses WHERE id = :id");
            $stmt->execute(['id' => $businessId]);
            $businessTypeId = (int) $stmt->fetchColumn();

            // Get type default module codes
            $typeModuleCodes = $this->businessTypeRepo->getModuleCodesForType($businessTypeId);
            $typeDefaults = array_flip($typeModuleCodes);

            // Get overrides for this business [module_code => action]
            $overrides = $this->overrideRepo->getOverridesForBusiness($businessId);

            // Get all modules
            $allModules = $this->moduleRepo->getAll();

            // Determine status for each module
            $modules = [];
            foreach ($allModules as $module) {
                $code = $module['code'];
                $inTypeDefaults = isset($typeDefaults[$code]);
                $override = $overrides[$code] ?? null;

                if ($override === 'activate') {
                    $status = 'override_activate';
                } elseif ($override === 'deactivate') {
                    $status = 'override_deactivate';
                } elseif ($inTypeDefaults) {
                    $status = 'type_default';
                } else {
                    $status = 'inactive';
                }

                $modules[] = [
                    'code'     => $code,
                    'name'     => $module['name'],
                    'icon'     => $module['icon'],
                    'category' => $module['category'],
                    'status'   => $status,
                    'is_core'  => in_array($code, self::CORE_MODULES, true),
                ];
            }

            $this->json(true, ['modules' => $modules]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to load modules');
        }
    }

    /**
     * DELETE /settings/modules/override/{moduleCode}
     * Removes an override, reverting to type default.
     */
    public function removeOverride(string $moduleCode): void
    {
        try {
            $auth = $this->auth();
            $businessId = $auth->business_id;
            $userId = $auth->user_id;

            // Validate module exists
            $module = $this->moduleRepo->getByCode($moduleCode);
            if (!$module) {
                $this->notFound('Module not found');
                return;
            }

            // Delete the override
            $this->overrideRepo->delete($businessId, (int) $module['id']);

            // Audit log
            $this->audit->log(
                'MODULE_OVERRIDE_REMOVED',
                'business_module_overrides',
                $module['id'],
                'DELETE',
                ['module_code' => $moduleCode],
                null,
                $userId,
                null,
                $businessId
            );

            $this->json(true, null, 'Module override removed');
        } catch (\Throwable $e) {
            $this->serverError('Failed to remove module override');
        }
    }
}
