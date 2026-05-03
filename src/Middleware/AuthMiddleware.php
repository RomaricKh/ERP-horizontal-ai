<?php
namespace App\Middleware;

use App\Config\Database;
use App\Services\AuthService;
use App\Services\UserService;
use App\Services\BusinessService;
use App\Support\AuthContext;

class AuthMiddleware {

    private AuthService $auth;
    private UserService $userS;
    private BusinessService $businessS;

    public function __construct(string $jwtSecret) {
        $this->auth = new AuthService($jwtSecret, Database::getConnection());
        $this->userS = new UserService(Database::getConnection());
        $this->businessS = new BusinessService(Database::getConnection());
    }

    public function handle(string|array|null $requiredPermissions = null): void {
        $headers = getallheaders();

        if (empty($headers['Authorization'])) {
            $this->unauthorized('Missing Authorization header');
        }

        if (!preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $m)) {
            $this->unauthorized('Invalid Authorization format');
        }

        $decoded = $this->auth->verifyToken($m[1]);

        if (!$decoded) {
            $this->unauthorized('Invalid or expired token');
        }
		
		$userId = (int) $decoded['user_id'];

		// 1. Get business context from request
		        // -------- BUSINESS CONTEXT --------
        $businessHeader =
            $headers['X-Business-Id']
            ?? $headers['x-business-id']
            ?? null;

        $businessId = $businessHeader !== null ? (int)$businessHeader : null;
	
		if (!$businessId) {
			$this->forbidden('Business context missing');
		}

		// 1. Resolve tenant from business
		$tenantId = $this->businessS->getTenantIdForBusiness($businessId);

		if (!$tenantId) {
			$this->forbidden('Invalid business');
		}

		// 2. Verify user is linked to business + fetch roles
		$role_id = $this->userS->getUserRoleIdForBusiness($userId, $businessId);

		if ($role_id === null) {
			$this->forbidden('User has no access to this business');
		}
		
		// 3. Resolve permissions from roles
		$permissions = $this->userS->getPermissionsForRole($role_id);


		// 4. Establish backend identity (SOURCE OF TRUTH)
		AuthContext::set((object)[
			'user_id'     => $userId,
    		'tenant_id'   => $tenantId,
			'business_id' => $businessId,
			'role_id'       => $role_id,
			'permissions' => $permissions
		]);

        // Authorization (optional)
        if ($requiredPermissions !== null) {
            $this->authorize($requiredPermissions);
        }
    }

    private function authorize(string|array $required): void {
        $user = AuthContext::get();
        $userPerms = $user->permissions ?? [];

        $required = (array)$required;

        foreach ($required as $perm) {
            if (!in_array($perm, $userPerms, true)) {
                $this->forbidden('Missing permission: ' . $perm);
            }
        }
    }

    private function unauthorized(string $msg): void {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => $msg]);
        exit;
    }

    private function forbidden(string $msg): void {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => $msg]);
        exit;
    }
}
