<?php

namespace Tests\Integration;

use App\Repository\AccountRepository;
use App\Repository\CollectionRepository;
use App\Repository\PeopleRepository;
use App\Repository\SalesInvoiceRepository;
use App\Services\AuditService;
use App\Services\CollectionService;
use App\Services\ExchangeRateService;
use App\Services\ItemService;
use App\Services\SalesInvoiceService;
use App\Services\SettlementEngine;
use App\Services\StockValidator;
use App\Support\AuthContext;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Integration test for Invoice full lifecycle.
 *
 * Tests DRAFT → DELIVERED → collection → settlement flow.
 * Verifies stock changes, balance changes, and payment status at each step.
 * Tests cancellation of unpaid DELIVERED invoice with reversal.
 *
 * Validates: Requirements 6.1, 6.2, 6.3, 7.1, 13.2, 13.4
 */
class InvoiceLifecycleIntegrationTest extends TestCase
{
    private int $businessId = 1;
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
    // Test: Full Invoice lifecycle DRAFT → DELIVERED with side effects
    // ───────────────────────────────────────────────────────────────────────

    /**
     * Tests that transitioning an invoice to DELIVERED triggers stock OUT
     * and increases the customer's payee balance.
     *
     * Validates: Requirements 6.1, 6.2, 6.3
     */
    public function testInvoiceDeliveredTransitionTriggersStockOutAndBalanceIncrease(): void
    {
        $stockAdjustments = [];
        $balanceChanges = [];

        $invoiceRecord = $this->makeInvoiceRecord('DRAFT');

        $siRepo = new class($invoiceRecord) extends SalesInvoiceRepository {
            private array $record;
            public function __construct(array $record) { $this->record = $record; }
            public function findById(int $id, int $businessId): ?array { return $this->record; }
            public function updateStatus(int $id, int $businessId, string $status): void
            {
                $this->record['status'] = $status;
            }
            public function getLines(int $invoiceId): array
            {
                return [
                    ['item_id' => 10, 'quantity' => 3],
                    ['item_id' => 20, 'quantity' => 7],
                ];
            }
        };

        $itemService = $this->makeTrackingItemService($stockAdjustments);
        $peopleRepo = $this->makeTrackingPeopleRepo($balanceChanges);

        $service = $this->buildSalesInvoiceService($siRepo, $peopleRepo, $itemService);

        $service->updateStatus(200, 'DELIVERED');

        // Verify stock OUT for each line item
        $this->assertCount(2, $stockAdjustments);
        $this->assertSame(['item_id' => 10, 'type' => 'OUT', 'quantity' => 3.0], $stockAdjustments[0]);
        $this->assertSame(['item_id' => 20, 'type' => 'OUT', 'quantity' => 7.0], $stockAdjustments[1]);

        // Verify customer balance increased by total_amount_bc
        $this->assertCount(1, $balanceChanges);
        $this->assertSame($this->customerId, $balanceChanges[0]['payee_id']);
        $this->assertEqualsWithDelta(2000.00, $balanceChanges[0]['delta'], 0.001);
    }

    // ───────────────────────────────────────────────────────────────────────
    // Test: PAID status cannot be set manually
    // ───────────────────────────────────────────────────────────────────────

    /**
     * Tests that manually setting PAID status is rejected.
     *
     * Validates: Requirements 6.4, 6.5
     */
    public function testManualPaidStatusIsRejected(): void
    {
        $invoiceRecord = $this->makeInvoiceRecord('DELIVERED');

        $siRepo = new class($invoiceRecord) extends SalesInvoiceRepository {
            private array $record;
            public function __construct(array $record) { $this->record = $record; }
            public function findById(int $id, int $businessId): ?array { return $this->record; }
            public function updateStatus(int $id, int $businessId, string $status): void {}
            public function getLines(int $invoiceId): array { return []; }
        };

        $service = $this->buildSalesInvoiceService($siRepo);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Payment status is computed from collection slips and cannot be set manually.');
        $service->updateStatus(200, 'PAID');
    }

    // ───────────────────────────────────────────────────────────────────────
    // Test: Collection creation triggers customer settlement
    // ───────────────────────────────────────────────────────────────────────

    /**
     * Tests that creating a collection slip triggers settlement evaluation.
     * When customer balance reaches <= 0, settlement engine identifies invoices to settle.
     *
     * Validates: Requirements 7.1, 9.2, 9.3
     */
    public function testCollectionCreationTriggersSettlement(): void
    {
        $auditEntries = [];

        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Mock queries: invoice validation, collection sum check, settlement queries
        $invoiceValidationStmt = $this->createMock(PDOStatement::class);
        $invoiceValidationStmt->method('execute')->willReturn(true);
        $invoiceValidationStmt->method('fetch')->willReturn([
            'id' => 200,
            'business_id' => $this->businessId,
            'status' => 'DELIVERED',
            'total_amount_bc' => 2000.00,
        ]);

        // Collection sum for fully-paid check (returns 0 = not yet paid)
        $collSumStmt = $this->createMock(PDOStatement::class);
        $collSumStmt->method('execute')->willReturn(true);
        $collSumStmt->method('fetchColumn')->willReturn(0.0);

        // Settlement: balance query (customer balance = 0 after collection)
        $balanceStmt = $this->createMock(PDOStatement::class);
        $balanceStmt->method('execute')->willReturn(true);
        $balanceStmt->method('fetch')->willReturn(['balance' => 0.0]);

        // Settlement: invoices query
        $invoicesStmt = $this->createMock(PDOStatement::class);
        $invoicesStmt->method('execute')->willReturn(true);
        $invoicesStmt->method('fetchAll')->willReturn([
            ['id' => 200, 'total_amount_bc' => 2000.00],
        ]);

        // Settlement: collection sum for invoice (UNPAID)
        $invCollSumStmt = $this->createMock(PDOStatement::class);
        $invCollSumStmt->method('execute')->willReturn(true);
        $invCollSumStmt->method('fetchColumn')->willReturn(0.0);

        $callIndex = 0;
        $stmts = [$invoiceValidationStmt, $collSumStmt, $balanceStmt, $invoicesStmt, $invCollSumStmt];
        $pdo->method('prepare')->willReturnCallback(
            function () use (&$callIndex, $stmts) {
                return $stmts[$callIndex++] ?? $stmts[count($stmts) - 1];
            }
        );

        $collectionRepo = new class extends CollectionRepository {
            public function __construct() {}
            public function create(array $data): int { return 601; }
            public function findById(int $id, int $businessId): ?array { return null; }
        };

        $accountRepo = new class extends AccountRepository {
            public function __construct() {}
            public function findById(int $id, int $businessId): ?array
            {
                return ['id' => 1, 'currency' => 'USD', 'balance' => 3000.00];
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
            'reference_type' => 'SALES_INVOICE',
            'reference_id'   => 200,
            'slip_number'    => 'COL-001',
            'slip_date'      => '2025-01-25',
            'currency'       => 'USD',
            'exchange_rate'  => 1.0,
            'amount_fc'      => 2000.00,
            'method'         => 'bank_transfer',
        ]);

        // Verify settlement audit log was created
        $settlementLogs = array_filter($auditEntries, fn($e) => $e['eventType'] === 'SETTLEMENTS');
        $this->assertNotEmpty($settlementLogs, 'Settlement audit log should be created when customer is settled');
    }

    // ───────────────────────────────────────────────────────────────────────
    // Test: Cancellation of unpaid DELIVERED invoice reverses side effects
    // ───────────────────────────────────────────────────────────────────────

    /**
     * Tests that cancelling an unpaid DELIVERED invoice reverses stock OUT
     * and customer balance increase.
     *
     * Validates: Requirements 13.2, 13.4
     */
    public function testCancellationOfUnpaidDeliveredInvoiceReversesSideEffects(): void
    {
        $stockAdjustments = [];
        $balanceChanges = [];

        $invoiceRecord = $this->makeInvoiceRecord('DELIVERED');

        // Mock PDO for the payment status check (collection sum = 0 = UNPAID)
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        $totalStmt = $this->createMock(PDOStatement::class);
        $totalStmt->method('execute')->willReturn(true);
        $totalStmt->method('fetchColumn')->willReturn(2000.00);

        $collSumStmt = $this->createMock(PDOStatement::class);
        $collSumStmt->method('execute')->willReturn(true);
        $collSumStmt->method('fetchColumn')->willReturn(0.0); // UNPAID

        $callIndex = 0;
        $stmts = [$totalStmt, $collSumStmt];
        $pdo->method('prepare')->willReturnCallback(
            function () use (&$callIndex, $stmts) {
                return $stmts[$callIndex++] ?? $stmts[count($stmts) - 1];
            }
        );

        $siRepo = new class($invoiceRecord) extends SalesInvoiceRepository {
            private array $record;
            public function __construct(array $record) { $this->record = $record; }
            public function findById(int $id, int $businessId): ?array { return $this->record; }
            public function updateStatus(int $id, int $businessId, string $status): void {}
            public function getLines(int $invoiceId): array
            {
                return [
                    ['item_id' => 10, 'quantity' => 3],
                    ['item_id' => 20, 'quantity' => 7],
                ];
            }
        };

        $itemService = $this->makeTrackingItemService($stockAdjustments);
        $peopleRepo = $this->makeTrackingPeopleRepo($balanceChanges);

        $service = $this->buildSalesInvoiceServiceWithPdo($pdo, $siRepo, $peopleRepo, $itemService);

        $service->updateStatus(200, 'CANCELLED');

        // Verify stock IN (reversal of stock OUT) for each line item
        $this->assertCount(2, $stockAdjustments);
        $this->assertSame(['item_id' => 10, 'type' => 'IN', 'quantity' => 3.0], $stockAdjustments[0]);
        $this->assertSame(['item_id' => 20, 'type' => 'IN', 'quantity' => 7.0], $stockAdjustments[1]);

        // Verify customer balance reversal (negative delta = undo the positive)
        $this->assertCount(1, $balanceChanges);
        $this->assertSame($this->customerId, $balanceChanges[0]['payee_id']);
        $this->assertEqualsWithDelta(-2000.00, $balanceChanges[0]['delta'], 0.001);
    }

    // ───────────────────────────────────────────────────────────────────────
    // Test: Cancellation of paid DELIVERED invoice is rejected
    // ───────────────────────────────────────────────────────────────────────

    /**
     * Tests that cancelling a DELIVERED invoice with collections is rejected.
     *
     * Validates: Requirement 13.2
     */
    public function testCancellationOfPaidDeliveredInvoiceIsRejected(): void
    {
        $invoiceRecord = $this->makeInvoiceRecord('DELIVERED');

        // Mock PDO for the payment status check (collection sum > 0 = has collections)
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('rollBack')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        $totalStmt = $this->createMock(PDOStatement::class);
        $totalStmt->method('execute')->willReturn(true);
        $totalStmt->method('fetchColumn')->willReturn(2000.00);

        $collSumStmt = $this->createMock(PDOStatement::class);
        $collSumStmt->method('execute')->willReturn(true);
        $collSumStmt->method('fetchColumn')->willReturn(1000.0); // Has collections

        $callIndex = 0;
        $stmts = [$totalStmt, $collSumStmt];
        $pdo->method('prepare')->willReturnCallback(
            function () use (&$callIndex, $stmts) {
                return $stmts[$callIndex++] ?? $stmts[count($stmts) - 1];
            }
        );

        $siRepo = new class($invoiceRecord) extends SalesInvoiceRepository {
            private array $record;
            public function __construct(array $record) { $this->record = $record; }
            public function findById(int $id, int $businessId): ?array { return $this->record; }
            public function updateStatus(int $id, int $businessId, string $status): void {}
            public function getLines(int $invoiceId): array { return []; }
        };

        $service = $this->buildSalesInvoiceServiceWithPdo($pdo, $siRepo);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot cancel: invoice has associated collections. Reverse collections first.');
        $service->updateStatus(200, 'CANCELLED');
    }

    // ───────────────────────────────────────────────────────────────────────
    // Helpers
    // ───────────────────────────────────────────────────────────────────────

    private function makeInvoiceRecord(string $status): array
    {
        return [
            'id'              => 200,
            'business_id'     => $this->businessId,
            'customer_id'     => $this->customerId,
            'invoice_number'  => 'INV-001',
            'invoice_date'    => '2025-01-15',
            'currency'        => 'USD',
            'exchange_rate'   => 1.0,
            'total_amount_fc' => 2000.00,
            'total_amount_bc' => 2000.00,
            'status'          => $status,
            'notes'           => null,
            'created_at'      => '2025-01-15',
            'updated_at'      => '2025-01-15',
            'created_by'      => 1,
        ];
    }

    /**
     * Create an ItemService that tracks stock adjustments.
     */
    private function makeTrackingItemService(array &$stockAdjustments): ItemService
    {
        return new class($stockAdjustments) extends ItemService {
            private array $adjustments;

            public function __construct(array &$adjustments)
            {
                $this->adjustments = &$adjustments;
            }

            public function adjustStock(int $itemId, string $type, float $quantity, ?string $reference, ?string $notes): void
            {
                $this->adjustments[] = ['item_id' => $itemId, 'type' => $type, 'quantity' => $quantity];
            }
        };
    }

    /**
     * Create a PeopleRepository that tracks balance changes.
     */
    private function makeTrackingPeopleRepo(array &$balanceChanges): PeopleRepository
    {
        return new class($balanceChanges) extends PeopleRepository {
            private array $changes;

            public function __construct(array &$balanceChanges)
            {
                $this->changes = &$balanceChanges;
            }

            public function updatePayeeBalance(int $payeeId, float $delta): void
            {
                $this->changes[] = ['payee_id' => $payeeId, 'delta' => $delta];
            }
        };
    }

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

    private function buildSalesInvoiceService(
        SalesInvoiceRepository $siRepo,
        ?PeopleRepository $peopleRepo = null,
        ?ItemService $itemService = null
    ): SalesInvoiceService {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        return $this->buildSalesInvoiceServiceWithPdo($pdo, $siRepo, $peopleRepo, $itemService);
    }

    private function buildSalesInvoiceServiceWithPdo(
        PDO $pdo,
        SalesInvoiceRepository $siRepo,
        ?PeopleRepository $peopleRepo = null,
        ?ItemService $itemService = null
    ): SalesInvoiceService {
        $peopleRepo = $peopleRepo ?? new class extends PeopleRepository {
            public function __construct() {}
            public function updatePayeeBalance(int $payeeId, float $delta): void {}
        };

        $itemService = $itemService ?? new class extends ItemService {
            public function __construct() {}
            public function adjustStock(int $itemId, string $type, float $quantity, ?string $reference, ?string $notes): void {}
        };

        $exchangeRateService = new class extends ExchangeRateService {
            public function __construct() {}
            public function resolveRate(string $currency, ?float $providedRate): float
            {
                return $providedRate ?? 1.0;
            }
        };

        $accountRepo = new class extends AccountRepository {
            public function __construct() {}
            public function findById(int $id, int $businessId): ?array { return null; }
            public function updateBalance(int $accountId, float $delta, PDO $db, float $deltaFc = 0.0): void {}
        };

        $stockValidator = new class extends StockValidator {
            public function __construct() {}
            public function assertAvailable(int $itemId, float $requestedQty, int $businessId): void {}
            public function checkBatch(array $requirements, int $businessId): array { return []; }
        };

        $audit = new class extends AuditService {
            public function __construct() {}
            public function log(
                string $eventType, string $table, $recordId,
                string $action, ?array $oldValues = null, ?array $newValues = null,
                ?int $userId = null, ?int $tenantId = null, ?int $businessId = null
            ): void {}
        };

        $ref     = new ReflectionClass(SalesInvoiceService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $props = [
            'db'                  => $pdo,
            'repo'                => $siRepo,
            'peopleRepo'          => $peopleRepo,
            'exchangeRateService' => $exchangeRateService,
            'audit'               => $audit,
            'itemService'         => $itemService,
            'accountRepo'         => $accountRepo,
            'stockValidator'      => $stockValidator,
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
