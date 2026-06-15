<?php

namespace Tests\Integration;

use App\Repository\AccountRepository;
use App\Repository\CollectionRepository;
use App\Repository\PaymentRepository;
use App\Repository\PeopleRepository;
use App\Services\AuditService;
use App\Services\CollectionService;
use App\Services\ExchangeRateService;
use App\Services\PaymentService;
use App\Services\SettlementEngine;
use App\Support\AuthContext;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Integration test for settlement engine.
 *
 * Tests single large payment settling multiple POs.
 * Tests single large collection settling multiple invoices.
 * Verifies audit logging of settlement actions.
 *
 * Validates: Requirements 9.2, 9.3, 9.5, 10.2, 10.3, 10.5
 */
class SettlementIntegrationTest extends TestCase
{
    private int $businessId = 1;
    private int $supplierId = 42;
    private int $customerId = 55;

    protected function setUp(): void
    {
        AuthContext::set((object) [
            'user_id'     => 1,
            'tenant_id'   => 1,
            'business_id' => $this->businessId,
        ]);
    }

    // ───────────────────────────────────────────────────────────────────────
    // Test: Single large payment settles multiple POs
    // ───────────────────────────────────────────────────────────────────────

    /**
     * When a single large payment brings the supplier balance to >= 0,
     * all RECEIVED POs with UNPAID or PARTIALLY_PAID status are settled.
     *
     * Validates: Requirements 10.2, 10.3, 10.5
     */
    public function testSingleLargePaymentSettlesMultiplePOs(): void
    {
        $auditEntries = [];

        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Settlement engine queries:
        // 1. Balance query: supplier balance = 0 (fully settled after payment)
        $balanceStmt = $this->createMock(PDOStatement::class);
        $balanceStmt->method('execute')->willReturn(true);
        $balanceStmt->method('fetch')->willReturn(['balance' => 0.0]);

        // 2. POs query: 3 RECEIVED POs for this supplier
        $posStmt = $this->createMock(PDOStatement::class);
        $posStmt->method('execute')->willReturn(true);
        $posStmt->method('fetchAll')->willReturn([
            ['id' => 101, 'total_amount_bc' => 500.00],
            ['id' => 102, 'total_amount_bc' => 800.00],
            ['id' => 103, 'total_amount_bc' => 700.00],
        ]);

        // 3. Payment sum queries for each PO:
        //    PO 101: UNPAID (0), PO 102: PARTIALLY_PAID (400), PO 103: PAID (700)
        $paySum101 = $this->createMock(PDOStatement::class);
        $paySum101->method('execute')->willReturn(true);
        $paySum101->method('fetchColumn')->willReturn(0.0);

        $paySum102 = $this->createMock(PDOStatement::class);
        $paySum102->method('execute')->willReturn(true);
        $paySum102->method('fetchColumn')->willReturn(400.0);

        $paySum103 = $this->createMock(PDOStatement::class);
        $paySum103->method('execute')->willReturn(true);
        $paySum103->method('fetchColumn')->willReturn(700.0);

        $callIndex = 0;
        $stmts = [$balanceStmt, $posStmt, $paySum101, $paySum102, $paySum103];
        $pdo->method('prepare')->willReturnCallback(
            function () use (&$callIndex, $stmts) {
                return $stmts[$callIndex++] ?? $stmts[count($stmts) - 1];
            }
        );

        $paymentRepo = new class extends PaymentRepository {
            public function __construct() {}
            public function create(array $data): int { return 901; }
            public function findById(int $id, int $businessId): ?array { return null; }
        };

        $accountRepo = new class extends AccountRepository {
            public function __construct() {}
            public function findById(int $id, int $businessId): ?array
            {
                return ['id' => 1, 'currency' => 'USD', 'balance' => 10000.00];
            }
            public function updateBalance(int $accountId, float $delta, PDO $db, float $deltaFc = 0.0): void {}
        };

        $peopleRepo = new class extends PeopleRepository {
            public function __construct() {}
            public function updatePayeeBalance(int $payeeId, float $delta): void {}
        };

        $audit = $this->makeCapturingAuditService($auditEntries);

        $service = $this->buildPaymentService($pdo, $paymentRepo, $accountRepo, $peopleRepo, $audit);

        $service->create([
            'account_id'     => 1,
            'payee_id'       => $this->supplierId,
            'reference_type' => 'GENERAL',
            'payment_number' => 'PAY-BIG-001',
            'payment_date'   => '2025-02-01',
            'currency'       => 'USD',
            'exchange_rate'  => 1.0,
            'amount_fc'      => 2000.00,
            'method'         => 'bank_transfer',
        ]);

        // Verify settlement audit log was created
        $settlementLogs = array_filter($auditEntries, fn($e) => $e['eventType'] === 'SETTLEMENTS');
        $this->assertCount(1, $settlementLogs, 'Exactly one settlement audit entry expected');

        $settlementEntry = array_values($settlementLogs)[0];
        $this->assertSame('SETTLEMENTS', $settlementEntry['eventType']);
        $this->assertSame('purchase_orders', $settlementEntry['table']);
        $this->assertSame('SETTLEMENT', $settlementEntry['action']);

        // Verify settled PO IDs include 101 (UNPAID) and 102 (PARTIALLY_PAID), but not 103 (PAID)
        $settledIds = $settlementEntry['newValues']['settled_po_ids'];
        sort($settledIds);
        $this->assertSame([101, 102], $settledIds);

        // Verify triggering payment ID is recorded
        $this->assertSame(901, $settlementEntry['newValues']['triggering_payment_id']);
    }

    // ───────────────────────────────────────────────────────────────────────
    // Test: Single large collection settles multiple invoices
    // ───────────────────────────────────────────────────────────────────────

    /**
     * When a single large collection brings the customer balance to <= 0,
     * all DELIVERED invoices with UNPAID or PARTIALLY_PAID status are settled.
     *
     * Validates: Requirements 9.2, 9.3, 9.5
     */
    public function testSingleLargeCollectionSettlesMultipleInvoices(): void
    {
        $auditEntries = [];

        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Settlement engine queries:
        // 1. Balance query: customer balance = 0 (fully settled after collection)
        $balanceStmt = $this->createMock(PDOStatement::class);
        $balanceStmt->method('execute')->willReturn(true);
        $balanceStmt->method('fetch')->willReturn(['balance' => 0.0]);

        // 2. Invoices query: 3 DELIVERED invoices for this customer
        $invoicesStmt = $this->createMock(PDOStatement::class);
        $invoicesStmt->method('execute')->willReturn(true);
        $invoicesStmt->method('fetchAll')->willReturn([
            ['id' => 201, 'total_amount_bc' => 1000.00],
            ['id' => 202, 'total_amount_bc' => 1500.00],
            ['id' => 203, 'total_amount_bc' => 500.00],
        ]);

        // 3. Collection sum queries for each invoice:
        //    INV 201: UNPAID (0), INV 202: PARTIALLY_PAID (500), INV 203: PAID (500)
        $collSum201 = $this->createMock(PDOStatement::class);
        $collSum201->method('execute')->willReturn(true);
        $collSum201->method('fetchColumn')->willReturn(0.0);

        $collSum202 = $this->createMock(PDOStatement::class);
        $collSum202->method('execute')->willReturn(true);
        $collSum202->method('fetchColumn')->willReturn(500.0);

        $collSum203 = $this->createMock(PDOStatement::class);
        $collSum203->method('execute')->willReturn(true);
        $collSum203->method('fetchColumn')->willReturn(500.0);

        $callIndex = 0;
        $stmts = [$balanceStmt, $invoicesStmt, $collSum201, $collSum202, $collSum203];
        $pdo->method('prepare')->willReturnCallback(
            function () use (&$callIndex, $stmts) {
                return $stmts[$callIndex++] ?? $stmts[count($stmts) - 1];
            }
        );

        $collectionRepo = new class extends CollectionRepository {
            public function __construct() {}
            public function create(array $data): int { return 701; }
            public function findById(int $id, int $businessId): ?array { return null; }
        };

        $accountRepo = new class extends AccountRepository {
            public function __construct() {}
            public function findById(int $id, int $businessId): ?array
            {
                return ['id' => 1, 'currency' => 'USD', 'balance' => 5000.00];
            }
            public function updateBalance(int $accountId, float $delta, PDO $db, float $deltaFc = 0.0): void {}
        };

        $peopleRepo = new class extends PeopleRepository {
            public function __construct() {}
            public function updatePayeeBalance(int $payeeId, float $delta): void {}
        };

        $audit = $this->makeCapturingAuditService($auditEntries);

        $service = $this->buildCollectionService($pdo, $collectionRepo, $accountRepo, $peopleRepo, $audit);

        $service->create([
            'account_id'     => 1,
            'payee_id'       => $this->customerId,
            'reference_type' => 'GENERAL',
            'slip_number'    => 'COL-BIG-001',
            'slip_date'      => '2025-02-01',
            'currency'       => 'USD',
            'exchange_rate'  => 1.0,
            'amount_fc'      => 3000.00,
            'method'         => 'bank_transfer',
        ]);

        // Verify settlement audit log was created
        $settlementLogs = array_filter($auditEntries, fn($e) => $e['eventType'] === 'SETTLEMENTS');
        $this->assertCount(1, $settlementLogs, 'Exactly one settlement audit entry expected');

        $settlementEntry = array_values($settlementLogs)[0];
        $this->assertSame('SETTLEMENTS', $settlementEntry['eventType']);
        $this->assertSame('sales_invoices', $settlementEntry['table']);
        $this->assertSame('SETTLEMENT', $settlementEntry['action']);

        // Verify settled invoice IDs include 201 (UNPAID) and 202 (PARTIALLY_PAID), but not 203 (PAID)
        $settledIds = $settlementEntry['newValues']['settled_invoice_ids'];
        sort($settledIds);
        $this->assertSame([201, 202], $settledIds);

        // Verify triggering collection ID is recorded
        $this->assertSame(701, $settlementEntry['newValues']['triggering_collection_id']);
    }

    // ───────────────────────────────────────────────────────────────────────
    // Test: No settlement when balance not reached
    // ───────────────────────────────────────────────────────────────────────

    /**
     * When a payment does not bring the supplier balance to >= 0,
     * no settlement occurs and no settlement audit log is created.
     *
     * Validates: Requirements 10.2, 10.3
     */
    public function testNoSettlementWhenSupplierBalanceStillNegative(): void
    {
        $auditEntries = [];

        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Settlement engine: balance still negative
        $balanceStmt = $this->createMock(PDOStatement::class);
        $balanceStmt->method('execute')->willReturn(true);
        $balanceStmt->method('fetch')->willReturn(['balance' => -500.0]);

        $pdo->method('prepare')->willReturn($balanceStmt);

        $paymentRepo = new class extends PaymentRepository {
            public function __construct() {}
            public function create(array $data): int { return 902; }
            public function findById(int $id, int $businessId): ?array { return null; }
        };

        $accountRepo = new class extends AccountRepository {
            public function __construct() {}
            public function findById(int $id, int $businessId): ?array
            {
                return ['id' => 1, 'currency' => 'USD', 'balance' => 10000.00];
            }
            public function updateBalance(int $accountId, float $delta, PDO $db, float $deltaFc = 0.0): void {}
        };

        $peopleRepo = new class extends PeopleRepository {
            public function __construct() {}
            public function updatePayeeBalance(int $payeeId, float $delta): void {}
        };

        $audit = $this->makeCapturingAuditService($auditEntries);

        $service = $this->buildPaymentService($pdo, $paymentRepo, $accountRepo, $peopleRepo, $audit);

        $service->create([
            'account_id'     => 1,
            'payee_id'       => $this->supplierId,
            'reference_type' => 'GENERAL',
            'payment_number' => 'PAY-PARTIAL-001',
            'payment_date'   => '2025-02-01',
            'currency'       => 'USD',
            'exchange_rate'  => 1.0,
            'amount_fc'      => 500.00,
            'method'         => 'cash',
        ]);

        // Verify NO settlement audit log was created
        $settlementLogs = array_filter($auditEntries, fn($e) => $e['eventType'] === 'SETTLEMENTS');
        $this->assertEmpty($settlementLogs, 'No settlement should occur when balance is still negative');
    }

    // ───────────────────────────────────────────────────────────────────────
    // Helpers
    // ───────────────────────────────────────────────────────────────────────

    private function makeCapturingAuditService(array &$entries): AuditService
    {
        return new class($entries) extends AuditService {
            private array $entries;

            public function __construct(array &$entries)
            {
                $this->entries = &$entries;
            }

            public function log(
                string $eventType, string $table, $recordId,
                string $action, ?array $oldValues = null, ?array $newValues = null,
                ?int $userId = null, ?int $tenantId = null, ?int $businessId = null
            ): void {
                $this->entries[] = [
                    'eventType'  => $eventType,
                    'table'      => $table,
                    'recordId'   => $recordId,
                    'action'     => $action,
                    'oldValues'  => $oldValues,
                    'newValues'  => $newValues,
                ];
            }
        };
    }

    private function buildPaymentService(
        PDO $pdo,
        PaymentRepository $paymentRepo,
        AccountRepository $accountRepo,
        PeopleRepository $peopleRepo,
        AuditService $audit
    ): PaymentService {
        $exchangeRateService = new class extends ExchangeRateService {
            public function __construct() {}
            public function resolveRate(string $currency, ?float $providedRate): float
            {
                return $providedRate ?? 1.0;
            }
        };

        $settlementEngine = new SettlementEngine($pdo);

        $ref     = new ReflectionClass(PaymentService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $props = [
            'db'                  => $pdo,
            'repo'                => $paymentRepo,
            'accountRepo'         => $accountRepo,
            'peopleRepo'          => $peopleRepo,
            'exchangeRateService' => $exchangeRateService,
            'audit'               => $audit,
            'settlementEngine'    => $settlementEngine,
        ];

        foreach ($props as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($service, $value);
        }

        return $service;
    }

    private function buildCollectionService(
        PDO $pdo,
        CollectionRepository $collectionRepo,
        AccountRepository $accountRepo,
        PeopleRepository $peopleRepo,
        AuditService $audit
    ): CollectionService {
        $exchangeRateService = new class extends ExchangeRateService {
            public function __construct() {}
            public function resolveRate(string $currency, ?float $providedRate): float
            {
                return $providedRate ?? 1.0;
            }
        };

        $settlementEngine = new SettlementEngine($pdo);

        $ref     = new ReflectionClass(CollectionService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $props = [
            'db'                  => $pdo,
            'repo'                => $collectionRepo,
            'accountRepo'         => $accountRepo,
            'peopleRepo'          => $peopleRepo,
            'exchangeRateService' => $exchangeRateService,
            'audit'               => $audit,
            'settlementEngine'    => $settlementEngine,
        ];

        foreach ($props as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($service, $value);
        }

        return $service;
    }
}
