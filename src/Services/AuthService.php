<?php
namespace App\Services;

use App\Services\AuditService;
use App\Repository\AuthRepository;
use App\Repository\UserRepository;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AuthService {
    private string $jwtSecret;
	private AuditService $auditLog;
    private UserRepository $userRepo;
    private AuthRepository $authRepo;
    private int $maxAttempts = 5;
    private int $lockMinutes = 15;

    public function __construct(string $jwtSecret) {
        $this->jwtSecret = $jwtSecret;
		$this->auditLog = new AuditService();
        $this->userRepo = new UserRepository();
        $this->authRepo = new AuthRepository();
		$this->deviceId = $this->resolveDeviceId();
    }
	
	private function resolveDeviceId(): string {
        if (!empty($_COOKIE['device_id'])) {
            return $_COOKIE['device_id'];
        }

        $deviceId = $this->generateUuidV4();

        setcookie(
            'device_id',
            $deviceId,
            [
                'expires'  => time() + 31536000, // 1 year
                'path'     => '/',
				'secure' => false,		//Dev ONLY
				//'secure' => true,        // HTTPS only
                'httponly' => false,
                'samesite' => 'Lax'
            ]
        );

        return $deviceId;
    }

    private function generateUuidV4(): string {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public function login(string $username, string $password, bool $remember): array {
        $user = $this->userRepo->findByUsername($username);

        if (!$user) {
			$this->auditLog->log(
				'SECURITY',
				'users',
				0,
				'LOGIN_FAILED',
				null,
				[
					'username' => $username,
					'reason'   => 'USER_NOT_FOUND'
				]
			);
            return ['success' => false, 'message' => 'Invalid username or account locked'];
        }

        // Check password
        if (!$this->authRepo->checkPassword($user, $password)) {
            $this->userRepo->incrementFailedAttempts($user['user_id']);

            if (($user['failed_attempts'] ?? 0) + 1 >= $this->maxAttempts) {
                $this->userRepo->lockAccount($user['user_id'], $this->lockMinutes);
				$this->auditLog->log(
					'SECURITY',
					'users',
					$user['user_id'],
					'ACCOUNT_LOCKED',
					null,
					[
						'failed_attempts' => ($user['failed_attempts'] ?? 0) + 1
					],
					$user['user_id'],   // explicit
					null,
					null
				);
                return ['success' => false, 'message' => "Account locked for {$this->lockMinutes} minutes"];
            }
			$this->auditLog->log(
				'SECURITY',
				'users',
				$user['user_id'] ?? 0,
				'LOGIN_FAILED',
				null,
				[
					'username' => $username,
					'reason'   => 'INVALID_PASSWORD'
				],
				$user['user_id'] ?? 0,   // explicit
				null,
				null
			);
            return ['success' => false, 'message' => 'Invalid password'];
        }

        // Reset failed attempts on successful login
        $this->userRepo->resetFailedAttempts($user['user_id']);

		$accessToken = $this->generateAccessToken($user);

		$refreshToken = $this->generateRefreshToken();
		$refreshHash = $this->hashToken($refreshToken);

		$expires = $remember
			? new \DateTime('+30 days') // 30 days 
			: new \DateTime('+1 days'); // 1 day 

		$this->authRepo->storeRefreshToken(
			$user['user_id'],
			$this->deviceId,
			$refreshHash,
			$expires->format('Y-m-d H:i:s')
		);

        // Remove sensitive data before returning
        unset($user['password_hash'], $user['failed_attempts'], $user['lock_until']);
		
		$this->auditLog->log(
			'AUTH',
			'users',
			$user['user_id'],
			'LOGIN',
			null,
			null,
			$user['user_id'],   // explicit
			null,
			null
		);

		return [
			'success' => true,
			'access_token' => $accessToken,
			'refresh_token' => $refreshToken,
			'refresh_expires' => $expires->getTimestamp(),
			'user' => $user
		];
    }

    public function verifyToken(string $token): ?array {
        try {
            $decoded = JWT::decode($token, new Key($this->jwtSecret, 'HS256'));
            return (array)$decoded;
        } catch (\Exception $e) {
            return null;
        }
    }
	
	private function generateAccessToken(array $user): string {
		$payload = [
			'user_id' => $user['user_id'],
			'roles' => $user['roles'],
			'username' => $user['name'],
			'iat' => time(),
			'exp' => time() + (15 * 60), // 15 minutes
		];

		return JWT::encode($payload, $this->jwtSecret, 'HS256');
	}
	
	private function generateRefreshToken(): string {
		return bin2hex(random_bytes(32));
	}

	private function hashToken(string $token): string {
		return hash('sha256', $token);
	}
	
	public function refreshAccessToken(string $rawRefreshToken): array {
    	
		$hash = $this->hashToken($rawRefreshToken);

		$stored = $this->authRepo->findValidRefreshToken($hash);

		if (!$stored) {
			return ['success' => false, 'message' => 'refresh token not found'];
		}

		// Optional but STRONGLY recommended: rotate token
		$newRefreshToken = $this->generateRefreshToken();
		$newHash = $this->hashToken($newRefreshToken);

		$expires = new \DateTimeImmutable($stored['expires_at']);

		$rotated = $this->authRepo->rotateRefreshToken(
			$hash,
			$newHash
		);

		$user = $this->userRepo->findById($stored['user_id']);

		$accessToken = $this->generateAccessToken($user);
		
		$this->auditLog->log(
			'AUTH',
			'refresh_tokens',
			$stored['user_id'],
			'REFRESH_ACCESS_TOKEN',
			null,
			null,
			$stored['user_id']
		);

		return [
			'success' => true,
			'access_token' => $accessToken,
			'expires_in' => time() + (15 * 60),
			'refresh_token' => $newRefreshToken,
			'refresh_expires' => $expires->getTimestamp()
		];
	}
	
	public function logout(string $rawRefreshToken, $ctx): void {
    	
		$hash = $this->hashToken($rawRefreshToken);
		$this->authRepo->revokeRefreshToken($hash);
		$this->auditLog->log(
			'AUTH',
			'users',
			$ctx->user_id,
			'LOGOUT',
			null,
			null,
			$ctx->user_id,
			$ctx->tenant_id,
			$ctx->business_id
		);

	}

	public function getSessionStatus(string $accessToken, string $rawRefreshToken): array {
		$now = time();
    	
		$hash = $this->hashToken($rawRefreshToken);

		try {
			$refresh = $this->authRepo->findValidRefreshToken($hash);
			$payload = $this->verifyToken($accessToken);

			if (!$payload && !$refresh) {
				return [
					'access_expires_in'  => 0,
					'refresh_expires_in' => 0,
					'is_last_window'     => true
				];
			}

			$accessExpIn = 0;
			
			if($payload)	
				$accessExpIn = max(0, $payload['exp'] - $now);

			
			if (!$refresh) {
				return [
					'access_expires_in'  => $accessExpIn,
					'refresh_expires_in' => 0,
					'is_last_window'     => true
				];
			}

			$expiresAt = new \DateTimeImmutable($refresh['expires_at']);
			$refreshExpIn = max(0, $expiresAt->getTimestamp() - $now);

			return [
				'access_expires_in'  => $accessExpIn,
				'refresh_expires_in' => $refreshExpIn,
				'is_last_window'     =>
					$refreshExpIn > 0 && $refreshExpIn <= $accessExpIn
			];

		} catch (Throwable $e) {
			error_log($e->getMessage());
			return [
				'access_expires_in'  => 0,
				'refresh_expires_in' => 0,
				'is_last_window'     => true
			];
		}
	}

	public function reauth($ctx, string $password): array {
        $user = $this->userRepo->findById($ctx->user_id);

        if (!$user) {
            return ['success' => false, 'message' => 'Invalid username or account locked'];
        }

        // Check password
        if (!$this->authRepo->checkPassword($user, $password)) {
            return ['success' => false, 'message' => 'Invalid password'];
        }

		$accessToken = $this->generateAccessToken($user);

		$refreshToken = $this->generateRefreshToken();
		$refreshHash = $this->hashToken($refreshToken);

		$expires = new \DateTime('+1 days'); // 1 day 

		$this->authRepo->storeRefreshToken(
			$user['user_id'],
			$this->deviceId,
			$refreshHash,
			$expires->format('Y-m-d H:i:s')
		);

        // Remove sensitive data before returning
        unset($user['password_hash'], $user['failed_attempts'], $user['lock_until']);

		$this->auditLog->log(
			'AUTH',
			'users',
			$user['user_id'],
			'REAUTH',
			null,
			null,
			$ctx->user_id,
			$ctx->tenant_id,
			$ctx->business_id
		);
		
		return [
			'success' => true,
			'access_token' => $accessToken,
			'refresh_token' => $refreshToken,
			'refresh_expires' => $expires->getTimestamp(),
			'user' => $user
		];
    }



}
