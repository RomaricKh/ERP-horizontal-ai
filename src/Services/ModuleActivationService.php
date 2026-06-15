<?php
namespace App\Services;

use App\Repository\BusinessTypeRepository;
use App\Repository\ModuleRepository;
use PDO;

class ModuleActivationService {
    private PDO $db;
    private BusinessTypeRepository $businessTypeRepo;
    private ModuleRepository $moduleRepo;

    /** @var array Per-request cache keyed by businessId */
    private static array $cache = [];

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->businessTypeRepo = new BusinessTypeRepository($db);
        $this->moduleRepo = new ModuleRepository($db);
    }

    /**
     * Resolve active module codes for a business.
     * REQ-011: Single lookup via business_type_modules — no overrides needed.
     * Cached per businessId for the request duration.
     *
     * @return string[] Flat array of active module code strings
     */
    public function getActiveModules(int $businessId): array {
        if (array_key_exists($businessId, self::$cache)) {
            return self::$cache[$businessId];
        }

        // Get the business's business_type_id
        $typeId = $this->businessTypeRepo->getBusinessTypeId($businessId);

        if ($typeId === null) {
            self::$cache[$businessId] = [];
            return [];
        }

        // Single lookup: modules come directly from the business type
        $result = $this->businessTypeRepo->getModuleCodesForType($typeId);
        self::$cache[$businessId] = $result;
        return $result;
    }

    /**
     * Check if a specific module is active for a business.
     * REQ-011: Simplified to single lookup via business_type_modules.
     * Returns false for non-existent businessId or moduleCode.
     */
    public function isModuleActive(string $moduleCode, int $businessId): bool {
        $typeId = $this->businessTypeRepo->getBusinessTypeId($businessId);
        if ($typeId === null) {
            return false;
        }
        return $this->businessTypeRepo->hasModule($typeId, $moduleCode);
    }

    /**
     * Filter a permission array, keeping only permissions that belong
     * to active modules or that don't match any module prefix (fail-open).
     *
     * @param string[] $permissions Array of permission code strings
     * @return string[] Filtered permission codes
     */
    public function filterPermissionsByActiveModules(array $permissions, int $businessId): array {
        $activeModules = $this->getActiveModules($businessId);
        $prefixMap = $this->moduleRepo->getPermissionPrefixMap();

        $filtered = [];
        foreach ($permissions as $permission) {
            $matched = false;
            foreach ($prefixMap as $moduleCode => $prefixes) {
                foreach ($prefixes as $prefix) {
                    if (strpos($permission, $prefix) === 0) {
                        $matched = true;
                        if (in_array($moduleCode, $activeModules, true)) {
                            $filtered[] = $permission;
                        }
                        break 2;
                    }
                }
            }
            // Fail-open: include permissions that don't match any module prefix
            if (!$matched) {
                $filtered[] = $permission;
            }
        }

        return $filtered;
    }

    /**
     * Clear the per-request cache (useful for testing and after type changes).
     */
    public static function clearCache(): void {
        self::$cache = [];
    }
}
