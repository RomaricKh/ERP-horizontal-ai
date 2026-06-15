<?php
namespace App\Services;

use App\Services\AuditService;
use App\Services\EmailService;
use App\Repository\AuthRepository;
use App\Repository\UserRepository;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PDO;

class AuthService {
    private string $jwtSecret;
	private AuditService $auditLog;
    private UserRepository $userRepo;
    private AuthRepository $authRepo;
    private int $maxAttempts = 5;
    private int $lockMinutes = 15;
	private PDO $db;

    public function __construct(string $jwtSecret, PDO $db) {
		$this->db = $db;
        $this->jwtSecret = $jwtSecret;
		$this->auditLog = new AuditService($this->db);
        $this->userRepo = new UserRepository($this->db);
        $this->authRepo = new AuthRepository($this->db);
		$this->deviceId = $this->resolveDeviceId();
    }
	
	private function resolveDeviceId(): string {
        if (!empty($_COOKIE['device_id'])) {
            return $_COOKIE['device_id'];
        }

        $deviceId = $this->generateUuidV4();

        if (!headers_sent()) {
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
        }

        return $deviceId;
    }

    private function generateUuidV4(): string {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public function login(string $username, string $password, bool $remember): array {
        $userIdentity = $this->userRepo->findIdentityByUsername($username);

        if (!$userIdentity) {
			$this->auditLog->log(
				'LOGIN_FAILED',
				'users',
				0,
				'LOGIN_FAILED',
				null,
				[
					'username'   => $username,
					'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
					'reason'     => 'USER_NOT_FOUND'
				]
			);
            return ['success' => false, 'message' => 'Invalid username or account locked'];
        }

        // Check password
        if (!$this->authRepo->checkPassword($userIdentity, $password)) {
            $this->userRepo->incrementFailedAttempts($userIdentity['user_id']);

            if (($user['failed_attempts'] ?? 0) + 1 >= $this->maxAttempts) {
                $this->userRepo->lockAccount($userIdentity['user_id'], $this->lockMinutes);
				$this->auditLog->log(
					'SECURITY',
					'users',
					$userIdentity['user_id'],
					'ACCOUNT_LOCKED',
					null,
					[
						'failed_attempts' => ($userIdentity['failed_attempts'] ?? 0) + 1
					],
					$userIdentity['user_id'],   // explicit
					null,
					null
				);
                return ['success' => false, 'message' => "Account locked for {$this->lockMinutes} minutes"];
            }
			$this->auditLog->log(
				'LOGIN_FAILED',
				'users',
				$userIdentity['user_id'] ?? 0,
				'LOGIN_FAILED',
				null,
				[
					'username'   => $username,
					'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
					'reason'     => 'INVALID_PASSWORD'
				],
				$userIdentity['user_id'] ?? 0,   // explicit
				null,
				null
			);
            return ['success' => false, 'message' => 'Invalid password'];
        }

        // Reset failed attempts on successful login
        $this->userRepo->resetFailedAttempts($userIdentity['user_id']);
		
		$user = $this->userRepo->fetchAuthContext($userIdentity['user_id']);

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
			'LOGIN',
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

		$user = $this->userRepo->fetchAuthContext($stored['user_id']);

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
			'LOGOUT',
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
        $user = $this->userRepo->fetchAuthContext($ctx->user_id);

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

    /**
     * Request a password reset. Generates a token, stores its hash, and sends an email.
     * Always returns void (no indication of whether email exists — prevents enumeration).
     */
    public function requestPasswordReset(string $email): void
    {
        // Find user by email
        $stmt = $this->db->prepare("SELECT id, email, first_name FROM users WHERE email = :email LIMIT 1");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$user) {
            // Silently return to prevent email enumeration
            return;
        }

        // Generate a secure random token
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);

        // Token expires in 1 hour
        $expiresAt = (new \DateTime('+1 hour'))->format('Y-m-d H:i:s');

        // Store token hash in database
        $stmt = $this->db->prepare("
            INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_at)
            VALUES (:user_id, :token_hash, :expires_at, NOW())
        ");
        $stmt->execute([
            'user_id'    => $user['id'],
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
        ]);

        // Attempt to send email (failure is logged but does not block token creation)
        try {
            // Determine business_id for SMTP config — use first business the user belongs to
            $bizStmt = $this->db->prepare("
                SELECT b.id FROM businesses b
                JOIN user_roles ur ON ur.business_id = b.id
                WHERE ur.user_id = :user_id
                LIMIT 1
            ");
            $bizStmt->execute(['user_id' => $user['id']]);
            $businessId = $bizStmt->fetchColumn() ?: null;

            if ($businessId) {
                $emailService = new EmailService($this->db, (int) $businessId);

                $resetUrl = $this->getBaseUrl() . "#/reset-password?token=" . $rawToken;
                $subject = "Password Reset Request";
                $htmlBody = $this->buildResetEmailHtml($user['first_name'] ?? 'User', $resetUrl);

                $emailService->send($user['email'], $subject, $htmlBody);
            }
        } catch (\Throwable $e) {
            // Log but don't block — user can request again
            error_log("Password reset email failed for user {$user['id']}: " . $e->getMessage());
        }
    }

    /**
     * Reset password using a valid token.
     *
     * @throws \InvalidArgumentException if token is invalid, expired, or already used
     */
    public function resetPassword(string $token, string $newPassword): void
    {
        $tokenHash = hash('sha256', $token);

        // Find the token
        $stmt = $this->db->prepare("
            SELECT id, user_id, expires_at, used_at
            FROM password_reset_tokens
            WHERE token_hash = :token_hash
            LIMIT 1
        ");
        $stmt->execute(['token_hash' => $tokenHash]);
        $record = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$record) {
            throw new \InvalidArgumentException('Invalid or expired reset token.');
        }

        // Check if already used
        if ($record['used_at'] !== null) {
            throw new \InvalidArgumentException('This reset token has already been used.');
        }

        // Check if expired
        $expiresAt = new \DateTime($record['expires_at']);
        if ($expiresAt < new \DateTime()) {
            throw new \InvalidArgumentException('This reset token has expired.');
        }

        // Validate new password
        if (strlen($newPassword) < 6) {
            throw new \InvalidArgumentException('Password must be at least 6 characters.');
        }

        // Update password
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $updateStmt = $this->db->prepare("UPDATE users SET password_hash = :hash WHERE id = :id");
        $updateStmt->execute([
            'hash' => $passwordHash,
            'id'   => $record['user_id'],
        ]);

        // Mark token as used
        $markStmt = $this->db->prepare("UPDATE password_reset_tokens SET used_at = NOW() WHERE id = :id");
        $markStmt->execute(['id' => $record['id']]);

        $this->auditLog->log(
            'SECURITY',
            'users',
            $record['user_id'],
            'PASSWORD_RESET',
            null,
            null,
            $record['user_id']
        );
    }

    /**
     * Get the base URL for building reset links.
     */
    private function getBaseUrl(): string
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $path = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
        // Navigate up from /api to the root
        $basePath = str_replace('/api', '', $path);
        return rtrim($protocol . '://' . $host . $basePath, '/') . '/';
    }

    /**
     * Build the HTML body for the password reset email.
     */
    private function buildResetEmailHtml(string $name, string $resetUrl): string
    {
        return "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
            <h2 style='color: #4e73df;'>Password Reset Request</h2>
            <p>Hello {$name},</p>
            <p>We received a request to reset your password. Click the button below to set a new password:</p>
            <p style='text-align: center; margin: 30px 0;'>
                <a href='{$resetUrl}' 
                   style='background-color: #4e73df; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block;'>
                    Reset Password
                </a>
            </p>
            <p>Or copy and paste this link into your browser:</p>
            <p style='word-break: break-all; color: #666;'>{$resetUrl}</p>
            <p>This link will expire in 1 hour.</p>
            <p>If you did not request a password reset, please ignore this email.</p>
            <hr style='border: none; border-top: 1px solid #eee; margin: 30px 0;'>
            <p style='color: #999; font-size: 12px;'>This is an automated message. Please do not reply.</p>
        </div>";
    }
}
