<?php

namespace Tests\Integration;

use App\Repository\AccountRepository;
use App\Repository\PaymentRepository;
use App\Repository\PeopleRepository;
use App\Repository\PurchaseOrderRepository;
use App\Services\AuditService;
use App\Services\ExchangeRateService;
use App\Services\ItemService;
use App\Services\PaymentService;
use App\Services\PriceUpdater;
use App\Services\PurchaseOrderService;
use App\Services\SettlementEngine;
use App\Support\AuthContext;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Integration test for PO full lifecycle.
 *
 * Tests DRAFT → APPROVED → RECEIVED → payment → settlement flow.
 * Verifies stock changes, balance changes, and payment status at each step.
 * Tests cancellation of unpaid RECEIVED PO with reversal.
 *
 * Validates: Requirements 1.1, 1.2, 1.3, 1.4, 2.1, 13.1, 13.3
 */
class POLifecycleIntegrationTest extends TestCase
{
    private int $businessId = 1;
    private int $supplierId = 42;

    protected function setUp(): void
    {
        AuthContext::set((object) [
            'user_id'     => 1,
            'tenant_id'   => 1,
            'business_id' => $this->businessId,
        ]);
    }

    // ───────────────────────────────────────────────────────────────────────
    // Test: Full PO lifecycle DRAFT → APPROVED → RECEIVED with side effects
    // ───────────────────────────────────────────────────────────────────────

    /**
     * Tests the complete PO lifecycle from DRAFT through APPROVED to RECEIVED.
     * Verifies:
     * - DRAFT → APPROVED is allowed (no side effects)
     * - APPROVED → RECEIVED triggers stock IN and supplier balance decrease
     *
     * Validates: Requirements 1.1, 1.2, 1.3
     */
    public function testFullPOLifecycleDraftToApprovedToReceived(): void
    {
        $stockAdjustments = [];
        $balanceChanges = [];
        $statusUpdates = [];

        $poRecord = $this->makePoRecord('DRAFT');

        $poRepo = $this->makeTrackingPoRepo($poRecord, $statusUpdates);
        $itemService = $this->makeTrackingItemService($stockAdjustments);
        $peopleRepo = $this->makeTrackingPeopleRepo($balanceChanges);

        $service = $this->buildPurchaseOrderService($poRepo, $peopleRepo, $itemService);

        // Step 1: DRAFT → APPROVED (no stock/balance side effects)
        $service->updateStatus(100, 'APPROVED');
        $this->assertSame(['APPROVED'], $statusUpdates);
        $this->assertEmpty($stockAdjustments, 'No stock changes on APPROVED transition');
        $this->assertEmpty($balanceChanges, 'No balance changes on APPROVED transition');

        // Step 2: APPROVED → RECEIVED (triggers stock IN + balance decrease)
        $service->updateStatus(100, 'RECEIVED');
        $this->assertSame(['APPROVED', 'RECEIVED'], $statusUpdates);

        // Verify stock IN for each line item
        $this->assertCount(2, $stockAdjustments);
        $this->assertSame(['item_id' => 1, 'type' => 'IN', 'quantity' => 10.0], $stockAdjustments[0]);
        $this->assertSame(['item_id' => 2, 'type' => 'IN', 'quantity' => 5.0], $stockAdjustments[1]);

        // Verify supplier balance decreased by total_amount_bc
        $this->assertCount(1, $balanceChanges);
        $this->assertSame($this->supplierId, $balanceChanges[0]['payee_id']);
        $this->assertEqualsWithDelta(-1000.00, $balanceChanges[0]['delta'], 0.001);
    }

    // ───────────────────────────────────────────────────────────────────────
    // Test: RECEIVED is terminal — rejects further transitions except CANCELLED
    // ───────────────────────────────────────────────────────────────────────

    /**
     * Tests that RECEIVED is a terminal state and rejects transitions to CLOSED.
     *
     * Validates: Requirements 1.4, 1.5
     */
    public function testReceivedRejectsClosedTransition(): void
    {
        $poRecord = $this->makePoRecord('RECEIVED');

        $poRepo = new class($poRecord) extends PurchaseOrderRepository {
            private array $record;
            public function __construct(array $record) { $this->record = $record; }
            public function findById(int $id, int $businessId): ?array { return $this->record; }
            public function updateStatus(int $id, int $businessId, string $status): void {}
            public function getLines(int $poId): array { return []; }
        };

        $service = $this->buildPurchaseOrderService($poRepo);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CLOSED status is no longer valid; RECEIVED is the terminal state.');
        $service->updateStatus(100, 'CLOSED');
    }

    // ───────────────────────────────────────────────────────────────────────
    // Test: Payment creation → settlement flow
    // ───────────────────────────────────────────────────────────────────────

    /**
     * Tests that creating a payment slip triggers settlement evaluation.
     * When supplier balance reaches >= 0, settlement engine identifies POs to settle.
     *
     * Validates: Requirements 2.1, 10.2, 10.3
     */
    public function testPaymentCreationTriggersSettlement(): void
    {
        $auditEntries = [];

        // Simulate: supplier balance is 0 after payment (fully settled)
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Mock the PO reference validation query
        $poValidationStmt = $this->createMock(PDOStatement::class);
        $poValidationStmt->method('execute')->willReturn(true);
        $poValidationStmt->method('fetch')->willReturn([
            'id' => 100,
            'business_id' => $this->businessId,
            'status' => 'RECEIVED',
        ]);

        // Settlement engine queries: balance, POs, payment sums
        $balanceStmt = $this->createMock(PDOStatement::class);
        $balanceStmt->method('execute')->willReturn(true);
        $balanceStmt->method('fetch')->willReturn(['balance' => 0.0]);

        $posStmt = $this->createMock(PDOStatement::class);
        $posStmt->method('execute')->willReturn(true);
        $posStmt->method('fetchAll')->willReturn([
            ['id' => 100, 'total_amount_bc' => 1000.00],
        ]);

        $paymentSumStmt = $this->createMock(PDOStatement::class);
        $paymentSumStmt->method('execute')->willReturn(true);
        $paymentSumStmt->method('fetchColumn')->willReturn(0.0); // UNPAID

        $callIndex = 0;
        $stmts = [$poValidationStmt, $balanceStmt, $posStmt, $paymentSumStmt];
        $pdo->method('prepare')->willReturnCallback(
            function () use (&$callIndex, $stmts) {
                return $stmts[$callIndex++] ?? $stmts[count($stmts) - 1];
            }
        );

        $paymentRepo = new class extends PaymentRepository {
            public function __construct() {}
            public function create(array $data): int { return 501; }
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

        $service = $this->buildPaymentService($pdo, $paymentRepo, $accountRepo, $peopleRepo, $audit);

        $service->create([
            'account_id'     => 1,
            'payee_id'       => $this->supplierId,
            'reference_type' => 'PURCHASE_ORDER',
            'reference_id'   => 100,
            'payment_number' => 'PAY-001',
            'payment_date'   => '2025-01-20',
            'currency'       => 'USD',
            'exchange_rate'  => 1.0,
            'amount_fc'      => 1000.00,
            'method'         => 'bank_transfer',
        ]);

        // Verify settlement audit log was created
        $settlementLogs = array_filter($auditEntries, fn($e) => $e['eventType'] === 'SETTLEMENTS');
        $this->assertNotEmpty($settlementLogs, 'Settlement audit log should be created when supplier is settled');
    }

    // ───────────────────────────────────────────────────────────────────────
    // Test: Cancellation of unpaid RECEIVED PO reverses stock and balance
    // ───────────────────────────────────────────────────────────────────────

    /**
     * Tests that cancelling an unpaid RECEIVED PO reverses stock IN and supplier balance.
     *
     * Validates: Requirements 13.1, 13.3
     */
    public function testCancellationOfUnpaidReceivedPOReversesSideEffects(): void
    {
        $stockAdjustments = [];
        $balanceChanges = [];

        $poRecord = $this->makePoRecord('RECEIVED');

        // Mock PDO for the payment status check query (returns 0 = UNPAID)
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        $paymentCheckStmt = $this->createMock(PDOStatement::class);
        $paymentCheckStmt->method('execute')->willReturn(true);
        $paymentCheckStmt->method('fetchColumn')->willReturn(0.0); // No payments = UNPAID

        $pdo->method('prepare')->willReturn($paymentCheckStmt);

        $poRepo = new class($poRecord) extends PurchaseOrderRepository {
            private array $record;
            public function __construct(array $record) { $this->record = $record; }
            public function findById(int $id, int $businessId): ?array { return $this->record; }
            public function updateStatus(int $id, int $businessId, string $status): void {}
            public function getLines(int $poId): array
            {
                return [
                    ['item_id' => 1, 'quantity' => 10],
                    ['item_id' => 2, 'quantity' => 5],
                ];
            }
        };

        $itemService = $this->makeTrackingItemService($stockAdjustments);
        $peopleRepo = $this->makeTrackingPeopleRepo($balanceChanges);

        $service = $this->buildPurchaseOrderServiceWithPdo($pdo, $poRepo, $peopleRepo, $itemService);

        $service->updateStatus(100, 'CANCELLED');

        // Verify stock OUT (reversal of stock IN) for each line item
        $this->assertCount(2, $stockAdjustments);
        $this->assertSame(['item_id' => 1, 'type' => 'OUT', 'quantity' => 10.0], $stockAdjustments[0]);
        $this->assertSame(['item_id' => 2, 'type' => 'OUT', 'quantity' => 5.0], $stockAdjustments[1]);

        // Verify supplier balance reversal (positive delta = undo the negative)
        $this->assertCount(1, $balanceChanges);
        $this->assertSame($this->supplierId, $balanceChanges[0]['payee_id']);
        $this->assertEqualsWithDelta(1000.00, $balanceChanges[0]['delta'], 0.001);
    }

    // ───────────────────────────────────────────────────────────────────────
    // Test: Cancellation of paid RECEIVED PO is rejected
    // ───────────────────────────────────────────────────────────────────────

    /**
     * Tests that cancelling a RECEIVED PO with payments is rejected.
     *
     * Validates: Requirement 13.1
     */
    public function testCancellationOfPaidReceivedPOIsRejected(): void
    {
        $poRecord = $this->makePoRecord('RECEIVED');

        // Mock PDO for the payment status check query (returns > 0 = has payments)
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('rollBack')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        $paymentCheckStmt = $this->createMock(PDOStatement::class);
        $paymentCheckStmt->method('execute')->willReturn(true);
        $paymentCheckStmt->method('fetchColumn')->willReturn(500.0); // Has payments

        $pdo->method('prepare')->willReturn($paymentCheckStmt);

        $poRepo = new class($poRecord) extends PurchaseOrderRepository {
            private array $record;
            public function __construct(array $record) { $this->record = $record; }
            public function findById(int $id, int $businessId): ?array { return $this->record; }
            public function updateStatus(int $id, int $businessId, string $status): void {}
            public function getLines(int $poId): array { return []; }
        };

        $service = $this->buildPurchaseOrderServiceWithPdo($pdo, $poRepo);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot cancel: purchase order has associated payments. Reverse payments first.');
        $service->updateStatus(100, 'CANCELLED');
    }

    // ───────────────────────────────────────────────────────────────────────
    // Helpers
    // ───────────────────────────────────────────────────────────────────────

    private function makePoRecord(string $status): array
    {
        return [
            'id'              => 100,
            'business_id'     => $this->businessId,
            'supplier_id'     => $this->supplierId,
            'po_number'       => 'PO-001',
            'po_date'         => '2025-01-15',
            'currency'        => 'USD',
            'exchange_rate'   => 1.0,
            'total_amount_fc' => 1000.00,
            'total_amount_bc' => 1000.00,
            'status'          => $status,
            'notes'           => null,
            'created_at'      => '2025-01-15',
            'updated_at'      => '2025-01-15',
            'created_by'      => 1,
        ];
    }

    /**
     * Create a PurchaseOrderRepository that tracks status updates and
     * updates its internal record state for multi-step lifecycle tests.
     */
    private function makeTrackingPoRepo(array $poRecord, array &$statusUpdates): PurchaseOrderRepository
    {
        return new class($poRecord, $statusUpdates) extends PurchaseOrderRepository {
            private array $record;
            private array $statusUpdates;

            public function __construct(array $record, array &$statusUpdates)
            {
                $this->record = $record;
                $this->statusUpdates = &$statusUpdates;
            }

            public function findById(int $id, int $businessId): ?array
            {
                return $this->record;
            }

            public function updateStatus(int $id, int $businessId, string $status): void
            {
                $this->statusUpdates[] = $status;
                $this->record['status'] = $status;
            }

            public function getLines(int $poId): array
            {
                return [
                    ['item_id' => 1, 'quantity' => 10],
                    ['item_id' => 2, 'quantity' => 5],
                ];
            }
        };
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
                $this->adjustments[] = [
                    'item_id'  => $itemId,
                    'type'     => $type,
                    'quantity' => $quantity,
                ];
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

    private function buildPurchaseOrderService(
        PurchaseOrderRepository $poRepo,
        ?PeopleRepository $peopleRepo = null,
        ?ItemService $itemService = null
    ): PurchaseOrderService {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        return $this->buildPurchaseOrderServiceWithPdo($pdo, $poRepo, $peopleRepo, $itemService);
    }

    private function buildPurchaseOrderServiceWithPdo(
        PDO $pdo,
        PurchaseOrderRepository $poRepo,
        ?PeopleRepository $peopleRepo = null,
        ?ItemService $itemService = null
    ): PurchaseOrderService {
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

        $audit = new class extends AuditService {
            public function __construct() {}
            public function log(
                string $eventType, string $table, $recordId,
                string $action, ?array $oldValues = null, ?array $newValues = null,
                ?int $userId = null, ?int $tenantId = null, ?int $businessId = null
            ): void {}
        };

        $ref     = new ReflectionClass(PurchaseOrderService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $props = [
            'db'                  => $pdo,
            'repo'                => $poRepo,
            'peopleRepo'          => $peopleRepo,
            'exchangeRateService' => $exchangeRateService,
            'audit'               => $audit,
            'itemService'         => $itemService,
        ];

        foreach ($props as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($service, $value);
        }

        return $service;
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
}
