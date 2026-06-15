<?php

namespace App\Services;

use Firebase\JWT\JWT;
use PDO;
use Throwable;

/**
 * SetupService
 *
 * Orchestrates the creation of the first tenant and owner user during the
 * fresh-install setup flow. All inserts run inside a single PDO transaction
 * with a `SELECT COUNT(*) FROM tenants FOR UPDATE` lock at the start to
 * prevent race conditions between concurrent setup requests
 * (per Requirements 5.1, 13.1).
 *
 * On success the service returns the created tenant_id, user_id, and a JWT
 * token so the frontend can immediately authenticate as the Tenant Owner
 * without a separate login step (Requirement 5.7).
 *
 * The plain-text password is never stored, logged, or returned — it is only
 * passed to `password_hash($password, PASSWORD_BCRYPT)` and discarded
 * (Requirements 6.1, 6.2).
 */
class SetupService
{
    private string $jwtSecret;
    private PDO $db;

    public function __construct(string $jwtSecret, PDO $db)
    {
        $this->jwtSecret = $jwtSecret;
        $this->db = $db;
    }

    /**
     * Create the first tenant + owner user in a single locked transaction.
     *
     * Expected `$data` keys (caller is responsible for validation):
     *   - tenant_name (string)
     *   - username    (string)
     *   - email       (string)
     *   - password    (string, plain text — hashed here, never stored raw)
     *   - first_name  (string)
     *   - last_name   (string)
     *
     * Return shape:
     *   - On success: ['success' => true, 'data' => ['tenant_id', 'user_id', 'token']]
     *   - On lock conflict: ['success' => false, 'status' => 409, 'message' => '...']
     *   - On exception:    ['success' => false, 'status' => 500, 'message' => '...']
     */
    public function initialize(array $data): array
    {
        $this->db->beginTransaction();

        try {
            // 1. Acquire a row-level lock on the tenants table and re-check
            //    fresh-install state. This prevents two concurrent setup
            //    requests from both succeeding (Requirement 13.1).
            $lockStmt = $this->db->query('SELECT COUNT(*) FROM tenants FOR UPDATE');
            $lockedCount = (int) $lockStmt->fetchColumn();

            if ($lockedCount > 0) {
                $this->db->rollBack();
                return [
                    'success' => false,
                    'status'  => 409,
                    'message' => 'Setup has already been completed by another request',
                ];
            }

            // 2. Create the tenant row first (Requirement 5.2).
            $tenantId = $this->createTenant((string) $data['tenant_name']);

            // 3. Create the owner user with a bcrypt password hash
            //    (Requirements 5.3, 6.1).
            $userId = $this->createUser($data);

            // 4. Designate the user as a Tenant Owner by linking them in the
            //    tenant_owners table (Requirement 5.4).
            $this->designateTenantOwner($userId, $tenantId);

            $this->db->commit();
        } catch (Throwable $e) {
            // Any failure in the transaction triggers a rollback so the
            // database state remains untouched (Requirement 5.5).
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            return [
                'success' => false,
                'status'  => 500,
                'message' => 'Setup failed: ' . $e->getMessage(),
            ];
        }

        // 5. Generate a JWT for the new Tenant Owner so the frontend can
        //    authenticate immediately for Step 2 (Requirement 5.7).
        $token = $this->generateJwt($userId, $tenantId, (string) $data['username']);

        return [
            'success' => true,
            'data'    => [
                'tenant_id' => $tenantId,
                'user_id'   => $userId,
                'token'     => $token,
            ],
        ];
    }

    /**
     * Insert a new tenant row and return its auto-generated id.
     */
    private function createTenant(string $name): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO tenants (name, created_at) VALUES (:name, NOW())'
        );
        $stmt->execute(['name' => $name]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Insert a new user row with a bcrypt-hashed password and return the
     * auto-generated user id.
     *
     * The plain-text password is hashed inline and never stored, logged, or
     * returned (Requirements 6.1, 6.2).
     */
    private function createUser(array $data): int
    {
        $passwordHash = password_hash((string) $data['password'], PASSWORD_BCRYPT);

        $stmt = $this->db->prepare("
            INSERT INTO users
                (username, email, first_name, last_name, password_hash, created_at)
            VALUES
                (:username, :email, :first_name, :last_name, :password_hash, NOW())
        ");

        $stmt->execute([
            'username'      => (string) $data['username'],
            'email'         => (string) $data['email'],
            'first_name'    => (string) $data['first_name'],
            'last_name'     => (string) $data['last_name'],
            'password_hash' => $passwordHash,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Link the newly created user to the tenant in the tenant_owners table
     * so they are recognised as a Tenant Owner by AuthMiddleware on the
     * very next request.
     */
    private function designateTenantOwner(int $userId, int $tenantId): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO tenant_owners
                (tenant_id, user_id, created_at, created_by)
            VALUES
                (:tenant_id, :user_id, NOW(), NULL)
        ");

        $stmt->execute([
            'tenant_id' => $tenantId,
            'user_id'   => $userId,
        ]);
    }

    /**
     * Build a JWT for the freshly created Tenant Owner.
     *
     * The payload mirrors the structure consumed by AuthMiddleware: user_id
     * is the only claim it strictly requires, but we also include
     * tenant_id, business_id (null — no business yet), is_tenant_owner=true,
     * and empty permissions/roles arrays so any client-side code that reads
     * these fields gets a coherent shape.
     */
    private function generateJwt(int $userId, int $tenantId, string $username): string
    {
        $now = time();

        $payload = [
            'user_id'         => $userId,
            'tenant_id'       => $tenantId,
            'business_id'     => null,
            'is_tenant_owner' => true,
            'permissions'     => [],
            'roles'           => [],
            'username'        => $username,
            'iat'             => $now,
            'exp'             => $now + (15 * 60), // 15 minutes — matches AuthService
        ];

        return JWT::encode($payload, $this->jwtSecret, 'HS256');
    }
}
