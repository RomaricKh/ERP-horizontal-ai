<?php

namespace Tests\Integration;

use App\Repository\AccountRepository;
use App\Repository\ExpenseRepository;
use App\Repository\PeopleRepository;
use App\Repository\PurchaseOrderRepository;
use App\Repository\SalaryRepository;
use App\Repository\SalesInvoiceRepository;
use App\Services\AuditService;
use App\Services\ExchangeRateService;
use App\Services\ExpenseService;
use App\Services\ItemService;
use App\Services\PurchaseOrderService;
use App\Services\SalaryService;
use App\Services\SalesInvoiceService;
use App\Support\AuthContext;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Integration tests for audit logging of balance changes.
 *
 * Verifies that audit log entries contain the expected information
 * (amounts, status transitions) when balance-impacting operations occur.
 *
 * Validates: Requirements 10.1, 10.2
 */
class BalanceAuditTest extends TestCase
{
    private int $businessId = 1;

    protected function setUp(): void
    {
        AuthContext::set((object) [
            'user_id'     => 1,
            'tenant_id'   => 1,
            'business_id' => $this->businessId,
        ]);
    }

    // ---------------------------------------------------------------
    // Test: Expense create audit entry includes amount_bc
    // ---------------------------------------------------------------

    /**
     * When an expense is created, the audit log entry must include
     * the amount_bc field in the new values.
     *
     * Validates: Requirements 10.1, 10.2
     */
    public function testExpenseCreateAuditEntryIncludesAmountBc(): void
    {
        $auditEntries = [];

        $expenseRepo = new class extends ExpenseRepository {
            public function __construct() {}
            public function create(array $data): int { return 999; }
            public function findById(int $id, int $businessId): ?array { return null; }
        };

        $peopleRepo = $this->makeNoOpPeopleRepoStub();
        $audit      = $this->makeCapturingAuditService($auditEntries);
        $service    = $this->buildExpenseService($expenseRepo, $peopleRepo, $audit);

        $service->create([
            'payee_id'      => 42,
            'expense_date'  => '2025-01-15',
            'currency'      => 'USD',
            'exchange_rate' => 1.5,
            'amount_fc'     => 500.00,
        ]);

        $this->assertCount(1, $auditEntries, 'Expected exactly 1 audit entry for expense create');

        $entry = $auditEntries[0];
        $this->assertSame('EXPENSES', $entry['eventType']);
        $this->assertSame('expenses', $entry['table']);
        $this->assertSame('CREATE', $entry['action']);
        $this->assertSame(999, $entry['recordId']);

        // Verify new values contain amount_bc
        $this->assertNotNull($entry['newValues'], 'Audit new values must not be null');
        $this->assertArrayHasKey('amount_bc', $entry['newValues']);
        $this->assertEqualsWithDelta(
            750.00, // 500 * 1.5
            $entry['newValues']['amount_bc'],
            0.001,
            'Audit entry must include the correct amount_bc'
        );
    }

    // ---------------------------------------------------------------
    // Test: Salary create audit entry includes net_amount_bc
    // ---------------------------------------------------------------

    /**
     * When a salary is created, the audit log entry must include
     * the net_amount_bc field in the new values.
     *
     * Validates: Requirements 10.1, 10.2
     */
    public function testSalaryCreateAuditEntryIncludesNetAmountBc(): void
    {
        $auditEntries = [];

        $salaryRepo = new class extends SalaryRepository {
            public function __construct() {}
            public function create(array $data): int { return 888; }
            public function findById(int $id, int $businessId): ?array { return null; }
        };

        $peopleRepo = $this->makeNoOpPeopleRepoStub();
        $audit      = $this->makeCapturingAuditService($auditEntries);
        $service    = $this->buildSalaryService($salaryRepo, $peopleRepo, $audit);

        $service->create([
            'payee_id'        => 42,
            'period_start'    => '2025-01-01',
            'period_end'      => '2025-01-31',
            'currency'        => 'USD',
            'exchange_rate'   => 1.0,
            'gross_amount_fc' => 5000.00,
            'tax_fc'          => 500.00,
        ]);

        $this->assertCount(1, $auditEntries, 'Expected exactly 1 audit entry for salary create');

        $entry = $auditEntries[0];
        $this->assertSame('SALARIES', $entry['eventType']);
        $this->assertSame('salaries', $entry['table']);
        $this->assertSame('CREATE', $entry['action']);
        $this->assertSame(888, $entry['recordId']);

        // Verify new values contain net_amount_bc
        $this->assertNotNull($entry['newValues'], 'Audit new values must not be null');
        $this->assertArrayHasKey('net_amount_bc', $entry['newValues']);
        $this->assertEqualsWithDelta(
            4500.00, // (5000 - 500) * 1.0
            $entry['newValues']['net_amount_bc'],
            0.001,
            'Audit entry must include the correct net_amount_bc'
        );
    }

    // ---------------------------------------------------------------
    // Test: PO status change audit entry includes status transition
    // ---------------------------------------------------------------

    /**
     * When a PO status changes, the audit log entry must include
     * the old status and new status in old/new values.
     *
     * Validates: Requirements 10.1, 10.2
     */
    public function testPoStatusChangeAuditEntryIncludesStatusTransition(): void
    {
        $auditEntries = [];

        $poRecord = [
            'id'              => 100,
            'business_id'     => $this->businessId,
            'supplier_id'     => 42,
            'po_number'       => 'PO-TEST-001',
            'po_date'         => '2025-01-15',
            'currency'        => 'USD',
            'exchange_rate'   => 1.0,
            'total_amount_fc' => 1000.00,
            'total_amount_bc' => 1000.00,
            'status'          => 'APPROVED',
            'notes'           => null,
            'created_at'      => '2025-01-15',
            'updated_at'      => '2025-01-15',
            'created_by'      => 1,
        ];

        $poRepo = new class($poRecord) extends PurchaseOrderRepository {
            private array $record;
            public function __construct(array $record) { $this->record = $record; }
            public function findById(int $id, int $businessId): ?array { return $this->record; }
            public function updateStatus(int $id, int $businessId, string $status): void {}
            public function getLines(int $poId): array {
                return [['item_id' => 1, 'quantity' => 10]];
            }
        };

        $peopleRepo  = $this->makeNoOpPeopleRepoStub();
        $audit       = $this->makeCapturingAuditService($auditEntries);
        $itemService = new class extends ItemService {
            public function __construct() {}
            public function adjustStock(int $itemId, string $type, float $quantity, ?string $reference, ?string $notes): void {}
        };

        $service = $this->buildPurchaseOrderService($poRepo, $peopleRepo, $audit, $itemService);

        $service->updateStatus(100, 'RECEIVED');

        $this->assertCount(1, $auditEntries, 'Expected exactly 1 audit entry for PO status change');

        $entry = $auditEntries[0];
        $this->assertSame('PURCHASE_ORDERS', $entry['eventType']);
        $this->assertSame('purchase_orders', $entry['table']);
        $this->assertSame('STATUS_CHANGE', $entry['action']);
        $this->assertSame(100, $entry['recordId']);

        // Verify old values contain old status
        $this->assertNotNull($entry['oldValues'], 'Audit old values must not be null for status change');
        $this->assertArrayHasKey('status', $entry['oldValues']);
        $this->assertSame('APPROVED', $entry['oldValues']['status']);

        // Verify new values contain new status
        $this->assertNotNull($entry['newValues'], 'Audit new values must not be null for status change');
        $this->assertArrayHasKey('status', $entry['newValues']);
        $this->assertSame('RECEIVED', $entry['newValues']['status']);
    }

    // ---------------------------------------------------------------
    // Test: SI status change audit entry includes status transition
    // ---------------------------------------------------------------

    /**
     * When a SI status changes, the audit log entry must include
     * the old status and new status in old/new values.
     *
     * Validates: Requirements 10.1, 10.2
     */
    public function testSiStatusChangeAuditEntryIncludesStatusTransition(): void
    {
        $auditEntries = [];

        $invoiceRecord = [
            'id'              => 200,
            'business_id'     => $this->businessId,
            'customer_id'     => 55,
            'invoice_number'  => 'INV-TEST-001',
            'invoice_date'    => '2025-01-15',
            'currency'        => 'USD',
            'exchange_rate'   => 1.0,
            'total_amount_fc' => 2000.00,
            'total_amount_bc' => 2000.00,
            'status'          => 'DRAFT',
            'notes'           => null,
            'created_at'      => '2025-01-15',
            'updated_at'      => '2025-01-15',
            'created_by'      => 1,
        ];

        $siRepo = new class($invoiceRecord) extends SalesInvoiceRepository {
            private array $record;
            public function __construct(array $record) { $this->record = $record; }
            public function findById(int $id, int $businessId): ?array { return $this->record; }
            public function updateStatus(int $id, int $businessId, string $status): void {}
            public function getLines(int $invoiceId): array { return []; }
        };

        $peopleRepo  = $this->makeNoOpPeopleRepoStub();
        $audit       = $this->makeCapturingAuditService($auditEntries);
        $itemService = new class extends ItemService {
            public function __construct() {}
            public function adjustStock(int $itemId, string $type, float $quantity, ?string $reference, ?string $notes): void {}
        };
        $accountRepo = new class extends AccountRepository {
            public function __construct() {}
            public function findById(int $id, int $businessId): ?array { return null; }
            public function updateBalance(int $accountId, float $delta, PDO $db, float $deltaFc = 0.0): void {}
        };

        $service = $this->buildSalesInvoiceService($siRepo, $peopleRepo, $audit, $itemService, $accountRepo);

        $service->updateStatus(200, 'DELIVERED');

        $this->assertCount(1, $auditEntries, 'Expected exactly 1 audit entry for SI status change');

        $entry = $auditEntries[0];
        $this->assertSame('SALES_INVOICES', $entry['eventType']);
        $this->assertSame('sales_invoices', $entry['table']);
        $this->assertSame('STATUS_CHANGE', $entry['action']);
        $this->assertSame(200, $entry['recordId']);

        // Verify old values contain old status
        $this->assertNotNull($entry['oldValues'], 'Audit old values must not be null for status change');
        $this->assertArrayHasKey('status', $entry['oldValues']);
        $this->assertSame('DRAFT', $entry['oldValues']['status']);

        // Verify new values contain new status
        $this->assertNotNull($entry['newValues'], 'Audit new values must not be null for status change');
        $this->assertArrayHasKey('status', $entry['newValues']);
        $this->assertSame('DELIVERED', $entry['newValues']['status']);
    }

    // ---------------------------------------------------------------
    // Shared helpers
    // ---------------------------------------------------------------

    /**
     * Create a capturing AuditService stub that records all log() calls.
     * Each entry is stored as an associative array with all parameters.
     */
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
                    'userId'     => $userId,
                    'tenantId'   => $tenantId,
                    'businessId' => $businessId,
                ];
            }
        };
    }

    /**
     * Create a no-op PeopleRepository stub (balance calls succeed silently).
     */
    private function makeNoOpPeopleRepoStub(): PeopleRepository
    {
        return new class extends PeopleRepository {
            public function __construct() {}
            public function updatePayeeBalance(int $payeeId, float $delta): void {}
        };
    }

    // ---------------------------------------------------------------
    // Service builders using reflection
    // ---------------------------------------------------------------

    /**
     * Build an ExpenseService with injected dependencies via reflection.
     */
    private function buildExpenseService(
        ExpenseRepository $expenseRepo,
        PeopleRepository $peopleRepo,
        AuditService $audit
    ): ExpenseService {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        $exchangeRateService = new class extends ExchangeRateService {
            public function __construct() {}
            public function resolveRate(string $currency, ?float $providedRate): float
            {
                if ($providedRate !== null) {
                    return $providedRate;
                }
                throw new \InvalidArgumentException("Exchange rate is required for currency {$currency}");
            }
        };

        $ref     = new ReflectionClass(ExpenseService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $props = [
            'db'                  => $pdo,
            'repo'                => $expenseRepo,
            'peopleRepo'          => $peopleRepo,
            'exchangeRateService' => $exchangeRateService,
            'audit'               => $audit,
        ];

        foreach ($props as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($service, $value);
        }

        return $service;
    }

    /**
     * Build a SalaryService with injected dependencies via reflection.
     */
    private function buildSalaryService(
        SalaryRepository $salaryRepo,
        PeopleRepository $peopleRepo,
        AuditService $audit
    ): SalaryService {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        $exchangeRateService = new class extends ExchangeRateService {
            public function __construct() {}
            public function resolveRate(string $currency, ?float $providedRate): float
            {
                if ($providedRate !== null) {
                    return $providedRate;
                }
                throw new \InvalidArgumentException("Exchange rate is required for currency {$currency}");
            }
        };

        $ref     = new ReflectionClass(SalaryService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $props = [
            'db'                  => $pdo,
            'repo'                => $salaryRepo,
            'peopleRepo'          => $peopleRepo,
            'exchangeRateService' => $exchangeRateService,
            'audit'               => $audit,
        ];

        foreach ($props as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($service, $value);
        }

        return $service;
    }

    /**
     * Build a PurchaseOrderService with injected dependencies via reflection.
     */
    private function buildPurchaseOrderService(
        PurchaseOrderRepository $poRepo,
        PeopleRepository $peopleRepo,
        AuditService $audit,
        ItemService $itemService
    ): PurchaseOrderService {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        $exchangeRateService = new class extends ExchangeRateService {
            public function __construct() {}
            public function resolveRate(string $currency, ?float $providedRate): float
            {
                if ($providedRate !== null) {
                    return $providedRate;
                }
                throw new \InvalidArgumentException("Exchange rate is required for currency {$currency}");
            }
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

    /**
     * Build a SalesInvoiceService with injected dependencies via reflection.
     */
    private function buildSalesInvoiceService(
        SalesInvoiceRepository $siRepo,
        PeopleRepository $peopleRepo,
        AuditService $audit,
        ItemService $itemService,
        AccountRepository $accountRepo
    ): SalesInvoiceService {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        $exchangeRateService = $this->createMock(ExchangeRateService::class);
        $exchangeRateService->method('resolveRate')->willReturnCallback(
            fn(string $currency, ?float $providedRate) => $providedRate ?? 1.0
        );

        $ref     = new ReflectionClass(SalesInvoiceService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $props = [
            'db'                  => $pdo,
            'repo'                => $siRepo,
            'peopleRepo'          => $peopleRepo,
            'audit'               => $audit,
            'itemService'         => $itemService,
            'accountRepo'         => $accountRepo,
            'exchangeRateService' => $exchangeRateService,
            'stockValidator'      => new class extends \App\Services\StockValidator {
                public function __construct() {}
                public function assertAvailable(int $itemId, float $requestedQty, int $businessId): void {}
                public function checkBatch(array $requirements, int $businessId): array { return []; }
            },
        ];

        foreach ($props as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($service, $value);
        }

        return $service;
    }
}
