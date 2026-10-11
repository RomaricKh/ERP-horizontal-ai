<?php

namespace App\Services;

use App\Repository\TenantOwnerRepository;
use App\Repository\UserRepository;
use App\Repository\RoleRepository;
use App\Services\AuditService;
use App\Support\AuthContext;
use InvalidArgumentException;
use RuntimeException;
use PDO;

class TenantOwnerService
{
    private PDO $db;
    private TenantOwnerRepository $repo;
    private AuditService $audit;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->repo = new TenantOwnerRepository($db);
        $this->audit = new AuditService($db);
    }

    /**
     * Validate that a password meets the minimum length requirement.
     *
     * @throws InvalidArgumentException if password is less than 8 characters
     */
    private static function validatePassword(string $password): void
    {
        if (strlen($password) < 8) {
            throw new InvalidArgumentException('Password must be at least 8 characters');
        }
    }

    /**
     * Full designation flow called from the controller.
     * Handles: user lookup/creation, duplicate check, max-owner enforcement,
     * business-role removal + audit, role assignment.
     *
     * @param int   $tenantId
     * @param array $data {email, username, first_name, last_name, password}
     * @return int The designated user's ID
     *
     * @throws InvalidArgumentException on validation failure
     * @throws RuntimeException on business rule violation
     */
    public function designateFromController(int $tenantId, array $data): int
    {
        $email = trim($data['email'] ?? '');
        $username = trim($data['username'] ?? '');
        $firstName = trim($data['first_name'] ?? '');
        $lastName = trim($data['last_name'] ?? '');
        $password = $data['password'] ?? null;

        // 1. Validate email format
        if (!$email) {
            throw new InvalidArgumentException('Email is required');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email format');
        }

        // 2. Validate username is provided
        if (!$username) {
            throw new InvalidArgumentException('Username is required');
        }

        // 3. Validate password length if provided
        if ($password !== null && $password !== '') {
            self::validatePassword($password);
        }

        // 4. Look up existing user by email, then by username
        $userRepo = new UserRepository($this->db);
        $existing = $userRepo->findIdentityByEmail($email);
        if (!$existing) {
            $existing = $userRepo->findIdentityByUsername($username);
        }

        $this->db->beginTransaction();
        try {
            if ($existing) {
                $userId = (int) $existing['user_id'];

                // Check not already a tenant owner
                if ($this->repo->exists($tenantId, $userId)) {
                    throw new InvalidArgumentException('User is already a Tenant Owner for this tenant');
                }

                // Remove business-level roles + audit
                $removedRoles = $this->getBusinessRolesForUser($userId, $tenantId);

                $stmt = $this->db->prepare("
                    DELETE FROM user_roles
                    WHERE user_id = :user_id
                      AND tenant_id = :tenant_id
                      AND business_id IS NOT NULL
                ");
                $stmt->execute(['user_id' => $userId, 'tenant_id' => $tenantId]);

                // Audit log the business-role deletion within the same transaction
                if (!empty($removedRoles)) {
                    $actingUserId = AuthContext::userId();
                    $this->audit->log(
                        'TENANT_OWNER_CHANGE',
                        'user_roles',
                        $userId,
                        'DELETE_BUSINESS_ROLES',
                        ['removed_roles' => $removedRoles],
                        null,
                        $actingUserId,
                        $tenantId,
                        null
                    );
                }

            } else {
                // New user: require first_name, last_name, password
                if (!$firstName || !$lastName) {
                    throw new InvalidArgumentException('First name and last name are required for new users');
                }
                if (!$password) {
                    throw new InvalidArgumentException('Password is required for new users');
                }
                self::validatePassword($password);

                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $userId = $userRepo->createIdentity([
                    'username' => $username,
                    'email' => $email,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'password_hash' => $passwordHash,
                ]);
            }

            // 6. Enforce max 10 owners
            $currentCount = $this->repo->countByTenantId($tenantId);
            if ($currentCount >= 10) {
                throw new RuntimeException('Maximum of 10 Tenant Owners per tenant reached');
            }

            // 7. Insert tenant owner role assignment
            $roleRepo = new RoleRepository($this->db);
            $roleId = $roleRepo->createTenantOwnerRole($tenantId);

            $stmt = $this->db->prepare("
                INSERT INTO user_roles (user_id, role_id, business_id, tenant_id, is_active)
                VALUES (:user_id, :role_id, NULL, :tenant_id, 1)
            ");
            $stmt->execute([
                'user_id' => $userId,
                'role_id' => $roleId,
                'tenant_id' => $tenantId,
            ]);

            // 8. Audit log the designation
            $actingUserId = AuthContext::userId();
            $this->audit->log(
                'TENANT_OWNER_CHANGE',
                'user_roles',
                $userId,
                'DESIGNATE_OWNER',
                null,
                ['tenant_id' => $tenantId, 'user_id' => $userId, 'role_id' => $roleId],
                $actingUserId,
                $tenantId,
                null
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        // 9. Return user ID
        return $userId;
    }

    /**
     * Get all business-level role data for a user within a tenant (for audit logging).
     */
    private function getBusinessRolesForUser(int $userId, int $tenantId): array
    {
        $stmt = $this->db->prepare("
            SELECT ur.id, ur.role_id, ur.business_id, r.name AS role_name
            FROM user_roles ur
            INNER JOIN roles r ON r.id = ur.role_id
            WHERE ur.user_id = :user_id
              AND ur.tenant_id = :tenant_id
              AND ur.business_id IS NOT NULL
        ");
        $stmt->execute(['user_id' => $userId, 'tenant_id' => $tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Check whether a user is a Tenant Owner (in any tenant).
     */
    public function isTenantOwner(int $userId): bool
    {
        return $this->repo->findByUserId($userId) !== null;
    }

    /**
     * Get the tenant_id for a Tenant Owner (first record ordered by id ASC).
     * Returns null if the user is not a Tenant Owner.
     */
    public function getTenantIdForOwner(int $userId): ?int
    {
        $record = $this->repo->findByUserId($userId);
        return $record ? (int) $record['tenant_id'] : null;
    }

    /**
     * Get all Tenant Owners for a given tenant.
     */
    public function getAllTenantOwners(int $tenantId): array
    {
        return $this->repo->findAllByTenantId($tenantId);
    }

    /**
     * Designate a user as a Tenant Owner.
     *
     * Validates:
     * - Acting user is a Tenant Owner of the same tenant (Req 1.9)
     * - Target user exists (Req 1.7)
     * - Target user belongs to the tenant (has user_roles in any business under the tenant) (Req 1.7)
     * - Maximum 10 owners per tenant not exceeded (Req 1.4)
     * - Duplicate designation is handled gracefully (Req 1.2)
     *
     * @throws InvalidArgumentException on validation failure
     * @throws RuntimeException on business rule violation
     */
    public function designate(int $tenantId, int $userId, int $actingUserId): void
    {
        // 1. Validate acting user is a Tenant Owner of this tenant (Req 1.9)
        if (!$this->repo->exists($tenantId, $actingUserId)) {
            throw new InvalidArgumentException("Only Tenant Owners can designate new owners");
        }

        // 2. Validate target user exists
        $stmt = $this->db->prepare("SELECT id FROM users WHERE id = :user_id LIMIT 1");
        $stmt->execute(['user_id' => $userId]);
        if (!$stmt->fetch()) {
            throw new InvalidArgumentException("User is invalid for tenant owner designation");
        }

        // 3. Validate target user belongs to the tenant (has user_roles in any business under the tenant)
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM user_roles ur
            JOIN businesses b ON b.id = ur.business_id
            WHERE ur.user_id = :user_id AND b.tenant_id = :tenant_id
        ");
        $stmt->execute(['user_id' => $userId, 'tenant_id' => $tenantId]);
        $belongsToTenant = (int) $stmt->fetchColumn() > 0;

        if (!$belongsToTenant) {
            throw new InvalidArgumentException("User is invalid for tenant owner designation");
        }

        // 4. Check for duplicate designation (handle gracefully via unique constraint)
        if ($this->repo->exists($tenantId, $userId)) {
            throw new InvalidArgumentException("User is already a Tenant Owner for this tenant");
        }

        // 5. Enforce max 10 owners per tenant (Req 1.4)
        $currentCount = $this->repo->countByTenantId($tenantId);
        if ($currentCount >= 10) {
            throw new RuntimeException("Maximum of 10 Tenant Owners per tenant reached");
        }

        // 6. Insert the tenant owner record
        $this->repo->insert($tenantId, $userId, $actingUserId);

        // 7. Log via AuditService (Req 1.6, 6.4)
        $this->audit->log(
            'TENANT_OWNER_CHANGE',
            'tenant_owners',
            $userId,
            'designate',
            null,
            ['tenant_id' => $tenantId, 'user_id' => $userId, 'acting_user_id' => $actingUserId],
            $actingUserId,
            $tenantId,
            null,
            true, // force — no business context for tenant-level operations
            true  // tenantScope — action performed via tenant owner scope (Req 6.4)
        );
    }

    /**
     * Get paginated business summaries for a tenant.
     *
     * @param int $tenantId The tenant to list businesses for
     * @param int $page     Current page number (1-based)
     * @param int $perPage  Number of items per page
     * @return array Pagination envelope: {data, total, current_page, last_page, per_page}
     */
    public function getBusinessSummaries(int $tenantId, int $page = 1, int $perPage = 20): array
    {
        // Count total businesses
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM businesses WHERE tenant_id = :tenant_id");
        $countStmt->execute(['tenant_id' => $tenantId]);
        $total = (int) $countStmt->fetchColumn();

        // Fetch paginated businesses
        $offset = ($page - 1) * $perPage;
        $stmt = $this->db->prepare("
            SELECT b.id, b.name, b.legal_name, b.registration_no,
                   b.phone, b.email, b.currency, b.is_active,
                   bt.name AS business_type
            FROM businesses b
            LEFT JOIN business_types bt ON bt.id = b.business_type_id
            WHERE b.tenant_id = :tenant_id
            ORDER BY b.id ASC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':tenant_id', $tenantId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $businesses = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return [
            'data'         => $businesses,
            'total'        => $total,
            'current_page' => $page,
            'last_page'    => max(1, (int) ceil($total / $perPage)),
            'per_page'     => $perPage,
        ];
    }

    /**
     * Get dashboard metrics aggregated across all businesses under the tenant.
     *
     * Aggregates revenue, expenses, outstanding receivables/payables, and user count
     * per business. Converts all monetary amounts to the tenant's base_currency using
     * the latest exchange rates from the exchange_rates table.
     *
     * @param int    $tenantId    The tenant to aggregate metrics for
     * @param string $startDate   Start date in YYYY-MM-DD format
     * @param string $endDate     End date in YYYY-MM-DD format
     * @param string $aggregation Aggregation period: daily, weekly, monthly, yearly
     * @return array Dashboard response data
     *
     * @throws InvalidArgumentException on validation failure (Req 7.4, 7.7)
     */
    public function getDashboardMetrics(int $tenantId, string $startDate, string $endDate, string $aggregation = 'monthly'): array
    {
        // 1. Validate aggregation period
        $validAggregations = ['daily', 'weekly', 'monthly', 'yearly'];
        if (!in_array($aggregation, $validAggregations, true)) {
            throw new InvalidArgumentException(
                "Invalid aggregation period. Must be one of: " . implode(', ', $validAggregations)
            );
        }

        // 2. Validate date format (YYYY-MM-DD) (Req 7.7)
        if (!$this->isValidDateFormat($startDate)) {
            throw new InvalidArgumentException("Invalid start_date format. Must be YYYY-MM-DD");
        }
        if (!$this->isValidDateFormat($endDate)) {
            throw new InvalidArgumentException("Invalid end_date format. Must be YYYY-MM-DD");
        }

        // 3. Validate end >= start (Req 7.7)
        $start = new \DateTime($startDate);
        $end = new \DateTime($endDate);
        if ($end < $start) {
            throw new InvalidArgumentException("end_date must be greater than or equal to start_date");
        }

        // 4. Validate max 12 months span (Req 7.4, 7.7)
        $interval = $start->diff($end);
        $totalMonths = ($interval->y * 12) + $interval->m + ($interval->d > 0 ? 1 : 0);
        if ($totalMonths > 12) {
            throw new InvalidArgumentException("Date range must not exceed 12 months");
        }

        // 5. Get tenant base_currency
        $baseCurrency = $this->getTenantBaseCurrency($tenantId);

        // 6. Get all businesses under the tenant
        $businesses = $this->getBusinessesForTenant($tenantId);

        // 7. Aggregate metrics per business with partial failure handling (Req 7.8)
        $businessMetrics = [];
        $errors = [];
        $totals = [
            'revenue' => 0.0,
            'expenses' => 0.0,
            'outstanding_receivables' => 0.0,
            'outstanding_payables' => 0.0,
            'total_users' => 0,
        ];

        foreach ($businesses as $business) {
            try {
                $metrics = $this->aggregateBusinessMetrics(
                    $business,
                    $startDate,
                    $endDate,
                    $baseCurrency
                );

                $businessMetrics[] = $metrics;
                $totals['revenue'] += $metrics['revenue'];
                $totals['expenses'] += $metrics['expenses'];
                $totals['outstanding_receivables'] += $metrics['outstanding_receivables'] ?? 0.0;
                $totals['outstanding_payables'] += $metrics['outstanding_payables'] ?? 0.0;
                $totals['total_users'] += $metrics['active_users'];
            } catch (\Throwable $e) {
                $errors[] = [
                    'business_id' => (int) $business['id'],
                    'business_name' => $business['name'],
                    'error' => $e->getMessage(),
                ];
            }
        }

        // 8. Build response matching the Dashboard Response Model
        return [
            'date_range' => [
                'start' => $startDate,
                'end' => $endDate,
            ],
            'aggregation' => $aggregation,
            'currency' => $baseCurrency,
            'totals' => [
                'revenue' => round($totals['revenue'], 2),
                'expenses' => round($totals['expenses'], 2),
                'outstanding_receivables' => round($totals['outstanding_receivables'], 2),
                'outstanding_payables' => round($totals['outstanding_payables'], 2),
                'total_users' => $totals['total_users'],
            ],
            'businesses' => array_map(function ($m) {
                return [
                    'id' => $m['id'],
                    'name' => $m['name'],
                    'business_type' => $m['business_type'],
                    'active_users' => $m['active_users'],
                    'revenue' => round($m['revenue'], 2),
                    'expenses' => round($m['expenses'], 2),
                    'net' => round($m['revenue'] - $m['expenses'], 2),
                ];
            }, $businessMetrics),
            'errors' => $errors,
        ];
    }

    /**
     * Validate that a date string is in YYYY-MM-DD format and represents a real date.
     */
    private function isValidDateFormat(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        $parts = explode('-', $date);
        return checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);
    }

    /**
     * Get the tenant's base_currency from the tenants table.
     */
    private function getTenantBaseCurrency(int $tenantId): string
    {
        $stmt = $this->db->prepare("SELECT base_currency FROM tenants WHERE id = :tenant_id LIMIT 1");
        $stmt->execute(['tenant_id' => $tenantId]);
        $currency = $stmt->fetchColumn();
        return $currency ?: 'USD';
    }

    /**
     * Get all businesses under a tenant with their type name.
     */
    private function getBusinessesForTenant(int $tenantId): array
    {
        $stmt = $this->db->prepare("
            SELECT b.id, b.name, b.currency, bt.name AS business_type
            FROM businesses b
            LEFT JOIN business_types bt ON bt.id = b.business_type_id
            WHERE b.tenant_id = :tenant_id
            ORDER BY b.id ASC
        ");
        $stmt->execute(['tenant_id' => $tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Aggregate financial metrics for a single business, converting to tenant base currency.
     *
     * Returns zero values for businesses with no transactions (Req 7.6).
     */
    private function aggregateBusinessMetrics(array $business, string $startDate, string $endDate, string $baseCurrency): array
    {
        $businessId = (int) $business['id'];
        $businessCurrency = $business['currency'] ?? 'USD';

        // Get exchange rate for conversion (business currency → tenant base currency)
        $exchangeRate = $this->getConversionRate($businessCurrency, $baseCurrency, $businessId);

        // Revenue: sum of total_amount_bc from sales_invoices with status in ('PAID', 'DELIVERED')
        $revenue = $this->getBusinessRevenue($businessId, $startDate, $endDate);

        // Expenses: sum of amount_bc from expenses
        $expenses = $this->getBusinessExpenses($businessId, $startDate, $endDate);

        // Outstanding receivables: unpaid/partially paid invoices within date range
        $receivables = $this->getOutstandingReceivables($businessId, $startDate, $endDate);

        // Outstanding payables: unpaid/partially paid POs within date range
        $payables = $this->getOutstandingPayables($businessId, $startDate, $endDate);

        // Active user count
        $activeUsers = $this->getActiveUserCount($businessId);

        // Convert amounts to tenant base currency
        return [
            'id' => $businessId,
            'name' => $business['name'],
            'business_type' => $business['business_type'] ?? 'general',
            'active_users' => $activeUsers,
            'revenue' => $revenue * $exchangeRate,
            'expenses' => $expenses * $exchangeRate,
            'outstanding_receivables' => $receivables * $exchangeRate,
            'outstanding_payables' => $payables * $exchangeRate,
        ];
    }

    /**
     * Get the conversion rate from business base currency to tenant base currency.
     * Returns 1.0 if currencies are the same.
     *
     * The _bc values from business queries are already in the business's base currency.
     * To aggregate across businesses at the tenant level, we need to convert each
     * business's base currency to the tenant's base currency using tenant-scoped
     * exchange rates (Req 7.1).
     */
    private function getConversionRate(string $fromCurrency, string $toCurrency, int $businessId): float
    {
        if ($fromCurrency === $toCurrency) {
            return 1.0;
        }

        // Look up the conversion rate for the business's base currency at the tenant level.
        // We query by tenant_id (not business_id) because we need a cross-business rate
        // that converts from the business's base currency to the tenant's base currency.
        $stmt = $this->db->prepare("
            SELECT rate FROM exchange_rates
            WHERE currency = :currency
              AND tenant_id = (SELECT tenant_id FROM businesses WHERE id = :business_id)
            ORDER BY rate_date DESC
            LIMIT 1
        ");
        $stmt->execute(['currency' => $fromCurrency, 'business_id' => $businessId]);
        $rate = $stmt->fetchColumn();

        if ($rate !== false && $rate !== null) {
            return (float) $rate;
        }

        // If no rate found, return 1.0 as fallback (log a warning in production)
        // The _bc columns already represent amounts in the business's base currency,
        // so if no conversion rate exists, we treat them as 1:1 to avoid report failures.
        return 1.0;
    }

    /**
     * Get total revenue for a business within a date range.
     * Revenue = sum of total_amount_bc from paid/delivered sales invoices.
     * Returns 0 for businesses with no transactions (Req 7.6).
     */
    private function getBusinessRevenue(int $businessId, string $startDate, string $endDate): float
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(total_amount_bc), 0)
            FROM sales_invoices
            WHERE business_id = :business_id
              AND invoice_date >= :start_date
              AND invoice_date <= :end_date
              AND status IN ('PAID', 'DELIVERED')
        ");
        $stmt->execute([
            'business_id' => $businessId,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Get total expenses for a business within a date range.
     * Returns 0 for businesses with no transactions (Req 7.6).
     */
    private function getBusinessExpenses(int $businessId, string $startDate, string $endDate): float
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount_bc), 0)
            FROM expenses
            WHERE business_id = :business_id
              AND expense_date >= :start_date
              AND expense_date <= :end_date
        ");
        $stmt->execute([
            'business_id' => $businessId,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Get outstanding receivables for a business within a date range.
     * Outstanding = invoices that are not fully paid and not cancelled.
     * Returns 0 for businesses with no transactions (Req 7.6).
     */
    private function getOutstandingReceivables(int $businessId, string $startDate, string $endDate): float
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(total_amount_bc), 0)
            FROM sales_invoices
            WHERE business_id = :business_id
              AND invoice_date >= :start_date
              AND invoice_date <= :end_date
              AND status NOT IN ('PAID', 'CANCELLED')
        ");
        $stmt->execute([
            'business_id' => $businessId,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Get outstanding payables for a business within a date range.
     * Outstanding = purchase orders that are not fully paid and not cancelled.
     * Returns 0 for businesses with no transactions (Req 7.6).
     */
    private function getOutstandingPayables(int $businessId, string $startDate, string $endDate): float
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(total_amount_bc), 0)
            FROM purchase_orders
            WHERE business_id = :business_id
              AND order_date >= :start_date
              AND order_date <= :end_date
              AND status NOT IN ('PAID', 'CANCELLED')
        ");
        $stmt->execute([
            'business_id' => $businessId,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Get the count of active users for a business.
     */
    private function getActiveUserCount(int $businessId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(DISTINCT user_id)
            FROM user_roles
            WHERE business_id = :business_id
              AND is_active = 1
        ");
        $stmt->execute(['business_id' => $businessId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Revoke a user's Tenant Owner designation.
     *
     * Validates:
     * - Acting user is a Tenant Owner of the same tenant (Req 1.9)
     * - Revocation would not leave 0 owners (Req 1.8)
     * - Target user is actually a Tenant Owner of this tenant
     *
     * @throws InvalidArgumentException on validation failure
     * @throws RuntimeException on business rule violation
     */
    public function revoke(int $tenantId, int $userId, int $actingUserId): void
    {
        // 1. Validate acting user is a Tenant Owner of this tenant (Req 1.9)
        if (!$this->repo->exists($tenantId, $actingUserId)) {
            throw new InvalidArgumentException("Only Tenant Owners can revoke owner designation");
        }

        // 2. Validate target user is actually a Tenant Owner of this tenant
        if (!$this->repo->exists($tenantId, $userId)) {
            throw new InvalidArgumentException("User is not a Tenant Owner for this tenant");
        }

        // 3. Validate not last owner (Req 1.8)
        $currentCount = $this->repo->countByTenantId($tenantId);
        if ($currentCount <= 1) {
            throw new RuntimeException("At least one Tenant Owner must remain");
        }

        // 4. Delete the tenant owner record
        $this->repo->delete($tenantId, $userId);

        // 5. Log via AuditService (Req 1.6, 6.4)
        $this->audit->log(
            'TENANT_OWNER_CHANGE',
            'tenant_owners',
            $userId,
            'revoke',
            ['tenant_id' => $tenantId, 'user_id' => $userId],
            null,
            $actingUserId,
            $tenantId,
            null,
            true, // force — no business context for tenant-level operations
            true  // tenantScope — action performed via tenant owner scope (Req 6.4)
        );
    }
}
