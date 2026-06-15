<?php
namespace App\Controllers;

use App\Config\Database;
use App\Controllers\BaseController;
use App\Services\AuthService;
use App\Services\ModuleActivationService;
use App\Support\AuthContext;

class AuthController extends BaseController {

    private AuthService $service;

    public function __construct(string $jwtSecret) {
        $this->service = new AuthService($jwtSecret, Database::getConnection());
    }

    /* =========================
       PUBLIC ENDPOINTS
       ========================= */

    // POST /api/auth/login
    public function login(): void {
        $input = $this->input(['username', 'password']);

        $result = $this->service->login(
            trim($input['username']),
            $input['password'],
            (bool)($input['remember'] ?? false)
        );

        if (!$result['success']) {
			$this->serverError($result['message']);
            return;
        }

        $this->setRefreshCookie(
            $result['refresh_token'],
            $result['refresh_expires']
        );

        $this->json(true, [
            'token' => $result['access_token'],
            'user'  => $result['user']
        ]);
    }

    // POST /api/auth/refresh
    public function refresh(): void {
        $refreshToken = $_COOKIE['refresh_token'] ?? null;

        if (!$refreshToken) {
			$this->serverError('Missing refresh token');
            return;
        }

        $result = $this->service->refreshAccessToken($refreshToken);

        if (!$result['success']) {
			$this->serverError($result['message']);
            return;
        }

        $this->setRefreshCookie(
            $result['refresh_token'],
            $result['refresh_expires']
        );

        $this->json(true, [
            'token' => $result['access_token'],
			'expires' => $result['expires_in']
        ]);
    }

    /* =========================
       PROTECTED ENDPOINTS
       (AuthMiddleware required)
       ========================= */

    // GET /api/auth/me
    public function me(): void {
		$ctx = $this->auth();

        $moduleService = new ModuleActivationService(Database::getConnection());
        $activeModules = $ctx->business_id
            ? $moduleService->getActiveModules($ctx->business_id)
            : [];

        // Fetch user identity fields for profile
        $userRepo = new \App\Repository\UserRepository(Database::getConnection());
        $identity = $userRepo->findIdentityById($ctx->user_id);

        $this->json(true, [
            'user_id'         => $ctx->user_id,
            'tenant_id'       => $ctx->tenant_id,
            'business_id'     => $ctx->business_id,
            'role_id'         => $ctx->role_id,
            'permissions'     => $ctx->permissions,
            'active_modules'  => $activeModules,
            'is_tenant_owner' => $ctx->is_tenant_owner ?? false,
            'username'        => $identity['username'] ?? null,
            'first_name'      => $identity['first_name'] ?? null,
            'last_name'       => $identity['last_name'] ?? null,
            'email'           => $identity['email'] ?? null
        ]);
    }

    // POST /api/auth/logout
    public function logout(): void {
		$ctx = $this->auth();
        if (!empty($_COOKIE['refresh_token'])) {
            $this->service->logout($_COOKIE['refresh_token'], $ctx);
        }

        setcookie('refresh_token', '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax'
        ]);

        $this->json(true, null, 'Logged out');
    }

    // GET /api/auth/session-status
    public function sessionStatus(): void {	
		
		$headers = getallheaders();

		$authHeader = $headers['Authorization']
				   ?? $headers['authorization']
				   ?? null;

		if (!$authHeader || !preg_match('/Bearer\s+(.+)/', $authHeader, $matches)) {
			$this->json(false, null, 'Missing Authorization header', 401);
			return;
		}

		$accessToken = $matches[1];
		$refreshToken = $_COOKIE['refresh_token'] ?? null;

		if (!$refreshToken) {
			$this->json(true, ['authenticated' => false]);
			return;
		}
		$result = $this->service->getSessionStatus($accessToken, $refreshToken);

		$this->json(true, $result);
    }
	
	// POST /api/auth/reauth
	public function reauth(): void {
		$ctx = $this->auth();
		
		$password = $this->input(['password']);


		$result = $this->service->reauth($ctx, $password);
		
		if ($result['success']) {
			// Successful login, return JWT + user info
			$this->setRefreshCookie(
				$result['refresh_token'],
				$result['refresh_expires']
			);

			$this->json(true, [
				'token' => $result['access_token'],
				'user'  => $result['user']
			]);
		} else {
			// Failed login
			$this->serverError($result['message']);
		}
	}

    // POST /api/auth/forgot-password
    public function forgotPassword(): void {
        $input = $this->input(['email']);
        $email = trim($input['email']);

        if (empty($email)) {
            $this->json(false, null, 'Email is required', 400);
            return;
        }

        try {
            $this->service->requestPasswordReset($email);
        } catch (\Throwable $e) {
            // Log but don't expose errors to prevent enumeration
            error_log("Forgot password error: " . $e->getMessage());
        }

        // Always return success to prevent email enumeration
        $this->json(true, null, 'If an account with that email exists, a reset link has been sent.');
    }

    // POST /api/auth/reset-password
    public function resetPassword(): void {
        $input = $this->input(['token', 'password']);
        $token = trim($input['token']);
        $password = $input['password'];

        if (empty($token) || empty($password)) {
            $this->json(false, null, 'Token and password are required', 400);
            return;
        }

        try {
            $this->service->resetPassword($token, $password);
            $this->json(true, null, 'Password has been reset successfully.');
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            error_log("Reset password error: " . $e->getMessage());
            $this->serverError('An error occurred while resetting the password.');
        }
    }

    /* =========================
       INTERNAL HELPERS
       ========================= */

    private function setRefreshCookie(string $token, int $expires): void {
		// Successful login, return JWT + user info
		setcookie(
			'refresh_token',
			$token,
			[
				'expires' => $expires,
				'path' => '/',
				'secure' => false,		//Dev ONLY
				//'secure' => true,        // HTTPS only
				'httponly' => true,      // JS cannot read
				'samesite' => 'Strict'
			]
		);
    }
}
