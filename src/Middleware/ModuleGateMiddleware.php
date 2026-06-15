<?php
namespace App\Middleware;

use App\Repository\ModuleRepository;
use App\Services\ModuleActivationService;
use App\Support\AuthContext;
use PDO;

class ModuleGateMiddleware {
    private ModuleActivationService $moduleService;
    private array $routePrefixMap;
    private const CORE_MODULES = ['auth', 'users', 'businesses'];

    public function __construct(PDO $db) {
        $this->moduleService = new ModuleActivationService($db);
        $moduleRepo = new ModuleRepository($db);
        $this->routePrefixMap = $moduleRepo->getRoutePrefixMap();
    }

    /**
     * Check if the current request's module is active.
     * Extracts first URI segment, maps to module code, checks activation.
     * Blocks with 403 if module is inactive.
     * Passes through for core modules and unrecognized prefixes.
     */
    public function handle(string $uri): void {
        $prefix = $this->extractRoutePrefix($uri);

        // Look up module code from route prefix
        $moduleCode = $this->routePrefixMap[$prefix] ?? null;

        // Unrecognized prefix: allow through (fail-open)
        if ($moduleCode === null) {
            return;
        }

        // Core modules are always allowed
        if (in_array($moduleCode, self::CORE_MODULES, true)) {
            return;
        }

        // Tenant owners bypass module gating (they have access to all modules)
        if (AuthContext::isTenantOwner()) {
            return;
        }

        // Check if module is active for the current business
        $businessId = AuthContext::businessId();
        if ($businessId === null) {
            return; // No business context — allow through (auth handles access)
        }
        if (!$this->moduleService->isModuleActive($moduleCode, $businessId)) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Module not available for this business'
            ]);
            exit;
        }
    }

    /**
     * Extract the first path segment from a URI (e.g., "/items/123" → "items")
     */
    private function extractRoutePrefix(string $uri): string {
        $uri = ltrim($uri, '/');
        $segments = explode('/', $uri);
        return $segments[0] ?? '';
    }
}
