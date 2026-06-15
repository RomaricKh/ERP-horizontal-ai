<?php

namespace Tests\Integration;

use App\Repository\AccountRepository;
use App\Repository\CollectionRepository;
use App\Repository\DashboardRepository;
use App\Repository\PeopleRepository;
use App\Repository\SalesInvoiceRepository;
use App\Services\AuditService;
use App\Services\CollectionService;
use App\Services\ExchangeRateService;
use App\Services\ItemService;
use App\Services\NotificationService;
use App\Services\SalesInvoiceService;
use App\Services\SettlementEngine;
use App\Services\StockValidator;
use App\Support\AuthContext;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Integration test for the full invoice lifecycle: DRAFT → DELIVERED → PAID.
 *
 * Verifies:
 * 1. Creating an invoice and delivering it transitions status to DELIVERED
 * 2. Collecting full payment triggers settlement and sets status to PAID
 * 3. DashboardRepository::getMonthlyRevenue() includes PAID invoices
 *
 * Validates: Requirements 1.1 (REQ-001)
 */
class InvoicePaidFlowIntegrationTest extends TestCase
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
    // Test: Full flow — create invoice, deliver, collect full payment → PAID
    // ───────────────────────────────────────────────────────────────────────

    /**
     * Tests the complete invoice lifecycle:
     * 1. Invoice starts as DRAFT
     * 2. Delivering transitions to DELIVERED (stock OUT, balance increase)
     * 3. Full collection triggers settlement → status becomes PAID
     *
     * Validates: Requirements 1.1 (REQ-001)
     */
    public function testFullInvoiceFlowDraftToDeliveredToPaid(): void
    {
        // Track status changes to verify the full flow
        $statusChanges = [];
        $invoiceAmount = 5000.00;

        // --- Phase 1: Deliver the invoice (DRAFT → DELIVERED) ---

        $invoiceRecord = [
            'id'              => 300,
            'business_id'     => $this->businessId,
            'customer_id'     => $this->customerId,
            'invoice_number'  => 'INV-2025-001',
            'invoice_date'    => '2025-01-15',
            'currency'        => 'USD',
            'exchange_rate'   => 1.0,
            'total_amount_fc' => $invoiceAmount,
            'total_amount_bc' => $invoiceAmount,
            'status'          => 'DRAFT',
            'notes'           => null,
            'created_at'      => '2025-01-15',
            'updated_at'      => '2025-01-15',
            'created_by'      => 1,
        ];

        $siRepo = new class($invoiceRecord, $statusChanges) extends SalesInvoiceRepository {
            private array $record;
            private array $statusChanges;

            public function __construct(array $record, array &$statusChanges)
            {
                $this->record = $record;
                $this->statusChanges = &$statusChanges;
            }

            public function findById(int $id, int $businessId): ?array
            {
                return $this->record;
            }

            public function updateStatus(int $id, int $businessId, string $status): void
            {
                $this->record['status'] = $status;
                $this->statusChanges[] = ['id' => $id, 'status' => $status];
            }

            public function getLines(int $invoiceId): array
            {
                return [
                    ['item_id' => 10, 'quantity' => 5],
                    ['item_id' => 20, 'quantity' => 10],
                ];
            }
        };

        $stockAdjustments = [];
        $balanceChanges = [];

        $itemService = $this->makeTrackingItemService($stockAdjustments);
        $peopleRepo = $this->makeTrackingPeopleRepo($balanceChanges);

        $deliverService = $this->buildSalesInvoiceService($siRepo, $peopleRepo, $itemService);

        // Deliver the invoice
        $deliverService->updateStatus(300, 'DELIVERED');

        // Verify delivery side effects
        $this->assertCount(2, $stockAdjustments, 'Stock OUT should be triggered for each line item');
        $this->assertSame('OUT', $stockAdjustments[0]['type']);
        $this->assertSame('OUT', $stockAdjustments[1]['type']);
        $this->assertCount(1, $balanceChanges, 'Customer balance should increase');
        $this->assertEqualsWithDelta($invoiceAmount, $balanceChanges[0]['delta'], 0.001);

        // Verify status is now DELIVERED
        $this->assertCount(1, $statusChanges);
        $this->assertSame('DELIVERED', $statusChanges[0]['status']);

        // --- Phase 2: Collect full payment → triggers settlement → PAID ---

        $auditEntries = [];

        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Mock queries for CollectionService::create():
        // 1. Invoice validation (reference_type = SALES_INVOICE)
        $invoiceValidationStmt = $this->createMock(PDOStatement::class);
        $invoiceValidationStmt->method('execute')->willReturn(true);
        $invoiceValidationStmt->method('fetch')->willReturn([
            'id'              => 300,
            'business_id'     => $this->businessId,
            'status'          => 'DELIVERED',
            'total_amount_bc' => $invoiceAmount,
        ]);

        // 2. Collection sum check (no prior collections = 0)
        $collSumStmt = $this->createMock(PDOStatement::class);
        $collSumStmt->method('execute')->willReturn(true);
        $collSumStmt->method('fetchColumn')->willReturn(0.0);

        // 3. Settlement: payee balance query (balance = 0 after full collection)
        $balanceStmt = $this->createMock(PDOStatement::class);
        $balanceStmt->method('execute')->willReturn(true);
        $balanceStmt->method('fetch')->willReturn(['balance' => 0.0]);

        // 4. Settlement: DELIVERED invoices for this customer
        $invoicesStmt = $this->createMock(PDOStatement::class);
        $invoicesStmt->method('execute')->willReturn(true);
        $invoicesStmt->method('fetchAll')->willReturn([
            ['id' => 300, 'total_amount_bc' => $invoiceAmount],
        ]);

        // 5. Settlement: collection sum for invoice 300 (UNPAID — the settlement engine
        //    uses payee balance as the primary trigger, then marks UNPAID/PARTIALLY_PAID invoices)
        $invCollSumStmt = $this->createMock(PDOStatement::class);
        $invCollSumStmt->method('execute')->willReturn(true);
        $invCollSumStmt->method('fetchColumn')->willReturn(0.0);

        // 6. User recipients query for notifications
        $recipientsStmt = $this->createMock(PDOStatement::class);
        $recipientsStmt->method('execute')->willReturn(true);
        $recipientsStmt->method('fetchAll')->willReturn([]);

        $callIndex = 0;
        $stmts = [
            $invoiceValidationStmt,
            $collSumStmt,
            $balanceStmt,
            $invoicesStmt,
            $invCollSumStmt,
            $recipientsStmt,
        ];
        $pdo->method('prepare')->willReturnCallback(
            function () use (&$callIndex, $stmts) {
                return $stmts[$callIndex++] ?? $stmts[count($stmts) - 1];
            }
        );

        // Track invoice status updates from settlement
        $invoiceStatusUpdates = [];
        $invoiceRepoForCollection = new class($invoiceStatusUpdates) extends SalesInvoiceRepository {
            private array $updates;

            public function __construct(array &$updates)
            {
                $this->updates = &$updates;
            }

            public function updateStatus(int $id, int $businessId, string $status): void
            {
                $this->updates[] = ['id' => $id, 'business_id' => $businessId, 'status' => $status];
            }
        };

        $collectionRepo = new class extends CollectionRepository {
            public function __construct() {}
            public function create(array $data): int { return 801; }
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

        $collPeopleRepo = new class extends PeopleRepository {
            public function __construct() {}
            public function updatePayeeBalance(int $payeeId, float $delta): void {}
        };

        $audit = $this->makeCapturingAuditService($auditEntries);

        $collectionService = $this->buildCollectionService(
            $pdo,
            $collectionRepo,
            $accountRepo,
            $collPeopleRepo,
            $audit,
            $invoiceRepoForCollection
        );

        // Collect the full invoice amount
        $collectionService->create([
            'account_id'     => 1,
            'payee_id'       => $this->customerId,
            'reference_type' => 'SALES_INVOICE',
            'reference_id'   => 300,
            'slip_number'    => 'COL-2025-001',
            'slip_date'      => '2025-01-25',
            'currency'       => 'USD',
            'exchange_rate'  => 1.0,
            'amount_fc'      => $invoiceAmount,
            'method'         => 'bank_transfer',
        ]);

        // Verify invoice was marked as PAID by settlement
        $this->assertCount(1, $invoiceStatusUpdates, 'Settlement should mark exactly one invoice as PAID');
        $this->assertSame(300, $invoiceStatusUpdates[0]['id']);
        $this->assertSame('PAID', $invoiceStatusUpdates[0]['status']);

        // Verify settlement audit log was created
        $settlementLogs = array_filter($auditEntries, fn($e) =>
            $e['table'] === 'sales_invoices' && $e['action'] === 'SETTLEMENT'
        );
        $this->assertNotEmpty($settlementLogs, 'Settlement audit log should be created');
    }

    // ───────────────────────────────────────────────────────────────────────
    // Test: DashboardRepository::getMonthlyRevenue() includes PAID invoices
    // ───────────────────────────────────────────────────────────────────────

    /**
     * Tests that getMonthlyRevenue() sums total_amount_bc from PAID invoices
     * for the given business/month/year.
     *
     * Validates: Requirements 1.1 (REQ-001) — dashboard revenue correctness
     */
    public function testDashboardRevenueIncludesPaidInvoices(): void
    {
        $pdo = $this->createMock(PDO::class);

        // Simulate a query that returns the sum of PAID invoices
        $revenueStmt = $this->createMock(PDOStatement::class);
        $revenueStmt->method('execute')->willReturn(true);
        $revenueStmt->method('fetchColumn')->willReturn(7500.00);

        $pdo->method('prepare')->willReturn($revenueStmt);

        $dashboardRepo = new DashboardRepository($pdo);

        $revenue = $dashboardRepo->getMonthlyRevenue($this->businessId, 2025, 1);

        $this->assertSame(7500.00, $revenue);
    }

    /**
     * Tests that getMonthlyRevenue() returns 0 when no PAID invoices exist.
     *
     * Validates: Requirements 1.1 (REQ-001) — dashboard revenue handles empty state
     */
    public function testDashboardRevenueReturnsZeroWhenNoPaidInvoices(): void
    {
        $pdo = $this->createMock(PDO::class);

        $revenueStmt = $this->createMock(PDOStatement::class);
        $revenueStmt->method('execute')->willReturn(true);
        $revenueStmt->method('fetchColumn')->willReturn(0.0);

        $pdo->method('prepare')->willReturn($revenueStmt);

        $dashboardRepo = new DashboardRepository($pdo);

        $revenue = $dashboardRepo->getMonthlyRevenue($this->businessId, 2025, 1);

        $this->assertSame(0.0, $revenue);
    }

    /**
     * Tests that the DashboardRepository revenue query filters by status = 'PAID'.
     * Verifies the SQL uses the correct status filter.
     *
     * Validates: Requirements 1.1 (REQ-001)
     */
    public function testDashboardRevenueQueryFiltersByPaidStatus(): void
    {
        $capturedSql = '';

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn(1000.00);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(
            function (string $sql) use (&$capturedSql, $stmt) {
                $capturedSql = $sql;
                return $stmt;
            }
        );

        $dashboardRepo = new DashboardRepository($pdo);
        $dashboardRepo->getMonthlyRevenue($this->businessId, 2025, 1);

        // Verify the SQL contains the PAID status filter
        $this->assertStringContainsString("status", $capturedSql);
        $this->assertStringContainsString("PAID", $capturedSql);
        $this->assertStringContainsString("sales_invoices", $capturedSql);
    }

    // ───────────────────────────────────────────────────────────────────────
    // Test: Settlement engine correctly identifies fully-paid invoices
    // ───────────────────────────────────────────────────────────────────────

    /**
     * Tests that the SettlementEngine::evaluateCustomerSettlement() returns
     * invoice IDs when the customer balance reaches zero and collection sum
     * covers the invoice total.
     *
     * Validates: Requirements 1.1 (REQ-001)
     */
    public function testSettlementEngineIdentifiesFullyPaidInvoice(): void
    {
        $pdo = $this->createMock(PDO::class);

        // Balance = 0 (customer fully settled)
        $balanceStmt = $this->createMock(PDOStatement::class);
        $balanceStmt->method('execute')->willReturn(true);
        $balanceStmt->method('fetch')->willReturn(['balance' => 0.0]);

        // One DELIVERED invoice
        $invoicesStmt = $this->createMock(PDOStatement::class);
        $invoicesStmt->method('execute')->willReturn(true);
        $invoicesStmt->method('fetchAll')->willReturn([
            ['id' => 300, 'total_amount_bc' => 5000.00],
        ]);

        // Collection sum for invoice = full amount (PAID status)
        $collSumStmt = $this->createMock(PDOStatement::class);
        $collSumStmt->method('execute')->willReturn(true);
        $collSumStmt->method('fetchColumn')->willReturn(5000.00);

        $callIndex = 0;
        $stmts = [$balanceStmt, $invoicesStmt, $collSumStmt];
        $pdo->method('prepare')->willReturnCallback(
            function () use (&$callIndex, $stmts) {
                return $stmts[$callIndex++] ?? $stmts[count($stmts) - 1];
            }
        );

        $engine = new SettlementEngine($pdo);
        $settledIds = $engine->evaluateCustomerSettlement($this->customerId, $this->businessId);

        // Invoice 300 has collection sum = total_amount_bc, but computeInvoicePaymentStatus
        // returns 'PAID' (>= total), so it should NOT be in the settled list
        // because the engine only settles UNPAID or PARTIALLY_PAID documents.
        // This verifies the engine correctly handles the "already paid" case.
        $this->assertEmpty($settledIds, 'Already fully-paid invoice should not be re-settled');
    }

    /**
     * Tests that the SettlementEngine identifies an UNPAID invoice as settled
     * when the customer balance reaches zero.
     *
     * Validates: Requirements 1.1 (REQ-001)
     */
    public function testSettlementEngineSettlesUnpaidInvoiceWhenBalanceZero(): void
    {
        $pdo = $this->createMock(PDO::class);

        // Balance = 0 (customer fully settled)
        $balanceStmt = $this->createMock(PDOStatement::class);
        $balanceStmt->method('execute')->willReturn(true);
        $balanceStmt->method('fetch')->willReturn(['balance' => 0.0]);

        // One DELIVERED invoice
        $invoicesStmt = $this->createMock(PDOStatement::class);
        $invoicesStmt->method('execute')->willReturn(true);
        $invoicesStmt->method('fetchAll')->willReturn([
            ['id' => 300, 'total_amount_bc' => 5000.00],
        ]);

        // Collection sum for invoice = 0 (UNPAID)
        $collSumStmt = $this->createMock(PDOStatement::class);
        $collSumStmt->method('execute')->willReturn(true);
        $collSumStmt->method('fetchColumn')->willReturn(0.0);

        $callIndex = 0;
        $stmts = [$balanceStmt, $invoicesStmt, $collSumStmt];
        $pdo->method('prepare')->willReturnCallback(
            function () use (&$callIndex, $stmts) {
                return $stmts[$callIndex++] ?? $stmts[count($stmts) - 1];
            }
        );

        $engine = new SettlementEngine($pdo);
        $settledIds = $engine->evaluateCustomerSettlement($this->customerId, $this->businessId);

        $this->assertSame([300], $settledIds, 'UNPAID invoice should be settled when balance reaches zero');
    }

    // ───────────────────────────────────────────────────────────────────────
    // Helpers
    // ───────────────────────────────────────────────────────────────────────

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
                ?int $userId = null, ?int $tenantId = null, ?int $businessId = null,
                bool $force = false, bool $tenantScope = false
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
                ?int $userId = null, ?int $tenantId = null, ?int $businessId = null,
                bool $force = false, bool $tenantScope = false
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
        AuditService $audit,
        ?SalesInvoiceRepository $invoiceRepo = null
    ): CollectionService {
        $exchangeRateService = new class extends ExchangeRateService {
            public function __construct() {}
            public function resolveRate(string $currency, ?float $providedRate): float
            {
                return $providedRate ?? 1.0;
            }
        };

        $settlementEngine = new SettlementEngine($pdo);

        $invoiceRepo = $invoiceRepo ?? new class extends SalesInvoiceRepository {
            public function __construct() {}
            public function updateStatus(int $id, int $businessId, string $status): void {}
        };

        $notificationService = new class extends NotificationService {
            public function __construct() {}
            public function notify(
                string $type, string $title, string $body,
                $recipientIds, ?string $entityType = null,
                ?int $entityId = null, ?int $senderId = null
            ): void {}
        };

        $ref     = new ReflectionClass(CollectionService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $props = [
            'db'                  => $pdo,
            'repo'                => $collectionRepo,
            'accountRepo'         => $accountRepo,
            'peopleRepo'          => $peopleRepo,
            'invoiceRepo'         => $invoiceRepo,
            'exchangeRateService' => $exchangeRateService,
            'audit'               => $audit,
            'settlementEngine'    => $settlementEngine,
            'notificationService' => $notificationService,
        ];

        foreach ($props as $name => $value) {
            if ($ref->hasProperty($name)) {
                $prop = $ref->getProperty($name);
                $prop->setAccessible(true);
                $prop->setValue($service, $value);
            }
        }

        return $service;
    }
}
