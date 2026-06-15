<?php

namespace Tests\Unit;

use App\Repository\AccountRepository;
use App\Repository\ExpenseRepository;
use App\Repository\PaymentRepository;
use App\Repository\PeopleRepository;
use App\Services\AuditService;
use App\Services\ExchangeRateService;
use App\Services\ExpenseService;
use App\Services\PaymentService;
use App\Support\AuthContext;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for fallback rate resolution in financial document services.
 *
 * Verifies that:
 * - Submitting a document without exchange rate uses the looked-up rate
 * - Submitting a document with an exchange rate preserves the user-provided value
 * - Submitting without exchange rate and no rate in table throws exception
 *
 * Validates: Requirements 7.1, 7.2, 7.3
 */
class FallbackRateResolutionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        AuthContext::set((object) [
            'user_id'     => 1,
            'tenant_id'   => 1,
            'business_id' => 100,
        ]);
    }

    // ─── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Build an ExchangeRateService stub that is configurable:
     * - If $lookupRate is a float, resolveRate will return it when no rate is provided.
     * - If $lookupRate is null, resolveRate will throw when no rate is provided.
     * - When a rate IS provided, resolveRate returns it as-is.
     */
    private function makeExchangeRateService(?float $lookupRate): ExchangeRateService
    {
        return new class($lookupRate) extends ExchangeRateService {
            private ?float $lookupRate;

            public function __construct(?float $lookupRate)
            {
                // Skip parent constructor (needs PDO)
                $this->lookupRate = $lookupRate;
            }

            public function resolveRate(string $currency, ?float $providedRate): float
            {
                if ($providedRate !== null) {
                    return $providedRate;
                }

                if ($this->lookupRate !== null) {
                    return $this->lookupRate;
                }

                throw new \InvalidArgumentException("Exchange rate is required for currency {$currency}");
            }
        };
    }

    private function makeAuditStub(): AuditService
    {
        return new class extends AuditService {
            public function __construct()
            {
                // Skip parent constructor
            }

            public function log(string $eventType, string $table, $recordId,
                                string $action, ?array $oldValues = null, ?array $newValues = null,
                                ?int $userId = null, ?int $tenantId = null, ?int $businessId = null, bool $force = false, bool $tenantScope = false): void
            {
                // no-op
            }
        };
    }

    private function makePdoStub(): PDO
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);
        return $pdo;
    }

    // ─── PaymentService builders ────────────────────────────────────────────────

    private function buildPaymentService(?float $lookupRate, string $currency = 'EUR'): PaymentService
    {
        $ref     = new ReflectionClass(PaymentService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $paymentRepo = new class extends PaymentRepository {
            public function __construct() {}
            public function create(array $data): int { return 1; }
            public function findById(int $id, int $businessId): ?array { return null; }
            public function delete(int $id, int $businessId): void {}
        };

        $accountRepo = new class($currency) extends AccountRepository {
            private string $currency;
            public function __construct(string $currency) { $this->currency = $currency; }
            public function findById(int $id, int $businessId): ?array
            {
                return ['id' => $id, 'business_id' => $businessId, 'currency' => $this->currency, 'balance' => 0, 'balance_fc' => 0];
            }
            public function updateBalance(int $accountId, float $delta, PDO $db, float $deltaFc = 0.0): void {}
        };

        $peopleRepo = new class extends PeopleRepository {
            public function __construct() {}
            public function updatePayeeBalance(int $payeeId, float $delta): void {}
        };

        $settlementEngine = new class extends \App\Services\SettlementEngine {
            public function __construct() {}
            public function evaluateSupplierSettlement(int $payeeId, int $businessId): array { return []; }
            public function evaluateCustomerSettlement(int $payeeId, int $businessId): array { return []; }
        };

        $props = [
            'db'                  => $this->makePdoStub(),
            'repo'                => $paymentRepo,
            'accountRepo'         => $accountRepo,
            'peopleRepo'          => $peopleRepo,
            'exchangeRateService' => $this->makeExchangeRateService($lookupRate),
            'audit'               => $this->makeAuditStub(),
            'settlementEngine'    => $settlementEngine,
        ];

        foreach ($props as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($service, $value);
        }

        return $service;
    }

    /**
     * Build a PaymentService that captures the exchange_rate passed to the repository.
     */
    private function buildPaymentServiceWithCapture(?float $lookupRate, array &$captured, string $currency = 'USD'): PaymentService
    {
        $ref     = new ReflectionClass(PaymentService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $paymentRepo = new class($captured) extends PaymentRepository {
            private array $captured;

            public function __construct(array &$captured)
            {
                $this->captured = &$captured;
            }

            public function create(array $data): int
            {
                $this->captured['exchange_rate'] = $data['exchange_rate'];
                $this->captured['amount_bc']     = $data['amount_bc'];
                return 1;
            }

            public function findById(int $id, int $businessId): ?array { return null; }
            public function delete(int $id, int $businessId): void {}
        };

        $accountRepo = new class($currency) extends AccountRepository {
            private string $currency;
            public function __construct(string $currency) { $this->currency = $currency; }
            public function findById(int $id, int $businessId): ?array
            {
                return ['id' => $id, 'business_id' => $businessId, 'currency' => $this->currency, 'balance' => 0, 'balance_fc' => 0];
            }
            public function updateBalance(int $accountId, float $delta, PDO $db, float $deltaFc = 0.0): void {}
        };

        $peopleRepo = new class extends PeopleRepository {
            public function __construct() {}
            public function updatePayeeBalance(int $payeeId, float $delta): void {}
        };

        $settlementEngine = new class extends \App\Services\SettlementEngine {
            public function __construct() {}
            public function evaluateSupplierSettlement(int $payeeId, int $businessId): array { return []; }
            public function evaluateCustomerSettlement(int $payeeId, int $businessId): array { return []; }
        };

        $props = [
            'db'                  => $this->makePdoStub(),
            'repo'                => $paymentRepo,
            'accountRepo'         => $accountRepo,
            'peopleRepo'          => $peopleRepo,
            'exchangeRateService' => $this->makeExchangeRateService($lookupRate),
            'audit'               => $this->makeAuditStub(),
            'settlementEngine'    => $settlementEngine,
        ];

        foreach ($props as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($service, $value);
        }

        return $service;
    }

    // ─── ExpenseService builders ────────────────────────────────────────────────

    private function buildExpenseService(?float $lookupRate): ExpenseService
    {
        $ref     = new ReflectionClass(ExpenseService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $expenseRepo = new class extends ExpenseRepository {
            public function __construct() {}
            public function create(array $data): int { return 1; }
            public function findById(int $id, int $businessId): ?array { return null; }
            public function update(int $id, int $businessId, array $data): void {}
            public function delete(int $id, int $businessId): void {}
        };

        $peopleRepo = new class extends PeopleRepository {
            public function __construct() {}
            public function updatePayeeBalance(int $payeeId, float $delta): void {}
        };

        $props = [
            'db'                  => $this->makePdoStub(),
            'repo'                => $expenseRepo,
            'peopleRepo'          => $peopleRepo,
            'exchangeRateService' => $this->makeExchangeRateService($lookupRate),
            'audit'               => $this->makeAuditStub(),
        ];

        foreach ($props as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($service, $value);
        }

        return $service;
    }

    /**
     * Build an ExpenseService that captures the exchange_rate passed to the repository.
     */
    private function buildExpenseServiceWithCapture(?float $lookupRate, array &$captured): ExpenseService
    {
        $ref     = new ReflectionClass(ExpenseService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $expenseRepo = new class($captured) extends ExpenseRepository {
            private array $captured;

            public function __construct(array &$captured)
            {
                $this->captured = &$captured;
            }

            public function create(array $data): int
            {
                $this->captured['exchange_rate'] = $data['exchange_rate'];
                $this->captured['amount_bc']     = $data['amount_bc'];
                return 1;
            }

            public function findById(int $id, int $businessId): ?array { return null; }
            public function update(int $id, int $businessId, array $data): void {}
            public function delete(int $id, int $businessId): void {}
        };

        $peopleRepo = new class extends PeopleRepository {
            public function __construct() {}
            public function updatePayeeBalance(int $payeeId, float $delta): void {}
        };

        $props = [
            'db'                  => $this->makePdoStub(),
            'repo'                => $expenseRepo,
            'peopleRepo'          => $peopleRepo,
            'exchangeRateService' => $this->makeExchangeRateService($lookupRate),
            'audit'               => $this->makeAuditStub(),
        ];

        foreach ($props as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($service, $value);
        }

        return $service;
    }

    // ═══════════════════════════════════════════════════════════════════════════════
    // PaymentService Tests
    // ═══════════════════════════════════════════════════════════════════════════════

    /**
     * Test that PaymentService::create() without exchange rate uses the looked-up rate.
     *
     * Validates: Requirements 7.1
     */
    public function testPaymentWithoutExchangeRateUsesLookedUpRate(): void
    {
        $captured = [];
        $service  = $this->buildPaymentServiceWithCapture(1.08, $captured, 'EUR');

        $service->create([
            'account_id'     => 1,
            'reference_type' => 'GENERAL',
            'payment_number' => 'PAY-001',
            'payment_date'   => '2025-01-15',
            'currency'       => 'EUR',
            'amount_fc'      => 100.00,
            'method'         => 'CASH',
            // exchange_rate intentionally omitted
        ]);

        $this->assertSame(1.08, $captured['exchange_rate']);
        $this->assertEqualsWithDelta(108.00, $captured['amount_bc'], 0.001);
    }

    /**
     * Test that PaymentService::create() with exchange rate preserves the user-provided value.
     *
     * Validates: Requirements 7.3
     */
    public function testPaymentWithExchangeRatePreservesUserValue(): void
    {
        $captured = [];
        // Lookup rate is 1.08, but user provides 1.25
        $service = $this->buildPaymentServiceWithCapture(1.08, $captured, 'EUR');

        $service->create([
            'account_id'     => 1,
            'reference_type' => 'GENERAL',
            'payment_number' => 'PAY-002',
            'payment_date'   => '2025-01-15',
            'currency'       => 'EUR',
            'exchange_rate'  => 1.25,
            'amount_fc'      => 200.00,
            'method'         => 'BANK',
        ]);

        $this->assertSame(1.25, $captured['exchange_rate']);
        $this->assertEqualsWithDelta(250.00, $captured['amount_bc'], 0.001);
    }

    /**
     * Test that PaymentService::create() without exchange rate and no rate in table throws exception.
     *
     * Validates: Requirements 7.2
     */
    public function testPaymentWithoutExchangeRateAndNoTableRateThrowsException(): void
    {
        $service = $this->buildPaymentService(null); // No rate available

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Exchange rate is required for currency JPY');

        $service->create([
            'account_id'     => 1,
            'reference_type' => 'GENERAL',
            'payment_number' => 'PAY-003',
            'payment_date'   => '2025-01-15',
            'currency'       => 'JPY',
            'amount_fc'      => 5000.00,
            'method'         => 'CASH',
            // exchange_rate intentionally omitted
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════════════
    // ExpenseService Tests
    // ═══════════════════════════════════════════════════════════════════════════════

    /**
     * Test that ExpenseService::create() without exchange rate uses the looked-up rate.
     *
     * Validates: Requirements 7.1
     */
    public function testExpenseWithoutExchangeRateUsesLookedUpRate(): void
    {
        $captured = [];
        $service  = $this->buildExpenseServiceWithCapture(0.79, $captured);

        $service->create([
            'expense_date' => '2025-02-01',
            'currency'     => 'GBP',
            'amount_fc'    => 50.00,
            // exchange_rate intentionally omitted
        ]);

        $this->assertSame(0.79, $captured['exchange_rate']);
        $this->assertEqualsWithDelta(39.50, $captured['amount_bc'], 0.001);
    }

    /**
     * Test that ExpenseService::create() with exchange rate preserves the user-provided value.
     *
     * Validates: Requirements 7.3
     */
    public function testExpenseWithExchangeRatePreservesUserValue(): void
    {
        $captured = [];
        // Lookup rate is 0.79, but user provides 0.85
        $service = $this->buildExpenseServiceWithCapture(0.79, $captured);

        $service->create([
            'expense_date'  => '2025-02-01',
            'currency'      => 'GBP',
            'exchange_rate' => 0.85,
            'amount_fc'     => 100.00,
        ]);

        $this->assertSame(0.85, $captured['exchange_rate']);
        $this->assertEqualsWithDelta(85.00, $captured['amount_bc'], 0.001);
    }

    /**
     * Test that ExpenseService::create() without exchange rate and no rate in table throws exception.
     *
     * Validates: Requirements 7.2
     */
    public function testExpenseWithoutExchangeRateAndNoTableRateThrowsException(): void
    {
        $service = $this->buildExpenseService(null); // No rate available

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Exchange rate is required for currency CHF');

        $service->create([
            'expense_date' => '2025-02-01',
            'currency'     => 'CHF',
            'amount_fc'    => 300.00,
            // exchange_rate intentionally omitted
        ]);
    }

    /**
     * Test that PaymentService::create() with empty string exchange rate uses the looked-up rate.
     *
     * Validates: Requirements 7.1
     */
    public function testPaymentWithEmptyStringExchangeRateUsesLookedUpRate(): void
    {
        $captured = [];
        $service  = $this->buildPaymentServiceWithCapture(2.50, $captured, 'AED');

        $service->create([
            'account_id'     => 1,
            'reference_type' => 'GENERAL',
            'payment_number' => 'PAY-004',
            'payment_date'   => '2025-01-20',
            'currency'       => 'AED',
            'exchange_rate'  => '',  // empty string should trigger fallback
            'amount_fc'      => 80.00,
            'method'         => 'CASH',
        ]);

        $this->assertSame(2.50, $captured['exchange_rate']);
        $this->assertEqualsWithDelta(200.00, $captured['amount_bc'], 0.001);
    }

    /**
     * Test that ExpenseService::create() with null exchange rate uses the looked-up rate.
     *
     * Validates: Requirements 7.1
     */
    public function testExpenseWithNullExchangeRateUsesLookedUpRate(): void
    {
        $captured = [];
        $service  = $this->buildExpenseServiceWithCapture(3.67, $captured);

        $service->create([
            'expense_date'  => '2025-03-01',
            'currency'      => 'AED',
            'exchange_rate' => null,  // explicit null should trigger fallback
            'amount_fc'     => 40.00,
        ]);

        $this->assertSame(3.67, $captured['exchange_rate']);
        $this->assertEqualsWithDelta(146.80, $captured['amount_bc'], 0.001);
    }
}
