<?php

namespace Tests\Unit;

use App\Repository\AccountRepository;
use App\Repository\PeopleRepository;
use App\Repository\SalesInvoiceRepository;
use App\Services\AuditService;
use App\Services\ExchangeRateService;
use App\Services\ItemService;
use App\Services\SalesInvoiceService;
use App\Services\StockValidator;
use App\Support\AuthContext;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for SalesInvoiceService balance edge cases.
 *
 * Validates: Requirements 2.2, 2.4, 8.2, 8.3
 */
class SalesInvoiceBalanceTest extends TestCase
{
    private int $businessId = 1;
    private int $invoiceId  = 100;

    protected function setUp(): void
    {
        AuthContext::set((object) [
            'user_id'     => 1,
            'tenant_id'   => 1,
            'business_id' => $this->businessId,
        ]);
    }

    /**
     * Test: CANCELLED from DELIVERED with null customer_id — no balance call.
     *
     * When an invoice has no customer assigned (customer_id is null), cancelling
     * from DELIVERED must not call updatePayeeBalance().
     *
     * Validates: Requirements 2.2, 2.4
     */
    public function testCancelFromDeliveredWithNullCustomerIdDoesNotCallBalance(): void
    {
        $balanceDeltas = [];

        $invoiceRecord = $this->makeInvoiceRecord([
            'customer_id'     => null,
            'total_amount_bc' => 500.00,
            'status'          => 'DELIVERED',
        ]);

        $siRepo     = $this->makeSiRepoStub($invoiceRecord, 'DELIVERED');
        $peopleRepo = $this->makePeopleRepoStub($balanceDeltas);
        $service    = $this->buildService($siRepo, $peopleRepo, 500.00, 0.0);

        $service->updateStatus($this->invoiceId, 'CANCELLED');

        $this->assertCount(
            0,
            $balanceDeltas,
            'No balance call expected when customer_id is null'
        );
    }

    /**
     * Test: CANCELLED from DELIVERED with zero total_amount_bc — no balance call.
     *
     * When an invoice has a zero total_amount_bc, the guard condition ($totalBc > 0)
     * should prevent any balance update.
     *
     * Validates: Requirements 2.2, 8.2
     */
    public function testCancelFromDeliveredWithZeroAmountDoesNotCallBalance(): void
    {
        $balanceDeltas = [];

        $invoiceRecord = $this->makeInvoiceRecord([
            'customer_id'     => 42,
            'total_amount_bc' => 0.00,
            'status'          => 'DELIVERED',
        ]);

        $siRepo     = $this->makeSiRepoStub($invoiceRecord, 'DELIVERED');
        $peopleRepo = $this->makePeopleRepoStub($balanceDeltas);
        $service    = $this->buildService($siRepo, $peopleRepo, 0.00, 0.0);

        $service->updateStatus($this->invoiceId, 'CANCELLED');

        $this->assertCount(
            0,
            $balanceDeltas,
            'No balance call expected when total_amount_bc is zero'
        );
    }

    /**
     * Test: CANCELLED from DRAFT — no balance call.
     *
     * Cancelling an invoice from DRAFT (not DELIVERED) must not touch the
     * customer balance because no balance increase happened at DRAFT.
     *
     * Validates: Requirement 8.3
     */
    public function testCancelFromDraftDoesNotCallBalance(): void
    {
        $balanceDeltas = [];

        $invoiceRecord = $this->makeInvoiceRecord([
            'customer_id'     => 42,
            'total_amount_bc' => 1500.00,
            'status'          => 'DRAFT',
        ]);

        $siRepo     = $this->makeSiRepoStub($invoiceRecord, 'DRAFT');
        $peopleRepo = $this->makePeopleRepoStub($balanceDeltas);
        $service    = $this->buildService($siRepo, $peopleRepo);

        $service->updateStatus($this->invoiceId, 'CANCELLED');

        $this->assertCount(
            0,
            $balanceDeltas,
            'No balance call expected when cancelling from DRAFT status'
        );
    }

    /**
     * Test: DELIVERED correctly increases balance.
     *
     * When an invoice transitions to DELIVERED with a valid customer and positive
     * total_amount_bc, the customer balance must increase by that amount.
     *
     * Validates: Requirement 2.2
     */
    public function testDeliveredIncreasesCustomerBalance(): void
    {
        $balanceDeltas = [];
        $customerId    = 77;
        $totalBc       = 2500.50;

        $invoiceRecord = $this->makeInvoiceRecord([
            'customer_id'     => $customerId,
            'total_amount_bc' => $totalBc,
            'status'          => 'DRAFT',
        ]);

        $siRepo     = $this->makeSiRepoStub($invoiceRecord, 'DRAFT');
        $peopleRepo = $this->makePeopleRepoStub($balanceDeltas);
        $service    = $this->buildService($siRepo, $peopleRepo);

        $service->updateStatus($this->invoiceId, 'DELIVERED');

        $this->assertCount(
            1,
            $balanceDeltas,
            'Expected exactly 1 balance call for DELIVERED transition'
        );
        $this->assertSame(
            $customerId,
            $balanceDeltas[0]['payeeId'],
            'Balance update must target the correct customer'
        );
        $this->assertEqualsWithDelta(
            $totalBc,
            $balanceDeltas[0]['delta'],
            0.001,
            'DELIVERED should increase customer balance by total_amount_bc'
        );
    }

    // ---------------------------------------------------------------
    // Helper methods
    // ---------------------------------------------------------------

    /**
     * Build a base invoice record array, merging in overrides.
     */
    private function makeInvoiceRecord(array $overrides = []): array
    {
        return array_merge([
            'id'              => $this->invoiceId,
            'business_id'     => $this->businessId,
            'customer_id'     => null,
            'invoice_number'  => 'INV-TEST-001',
            'invoice_date'    => '2025-01-15',
            'currency'        => 'USD',
            'exchange_rate'   => 1.0,
            'total_amount_fc' => 0.00,
            'total_amount_bc' => 0.00,
            'notes'           => null,
            'created_at'      => '2025-01-15',
            'updated_at'      => '2025-01-15',
            'created_by'      => 1,
        ], $overrides);
    }

    /**
     * Create a SI repository stub that always returns the given record
     * with the specified status.
     */
    private function makeSiRepoStub(array $record, string $status): SalesInvoiceRepository
    {
        return new class($record, $status) extends SalesInvoiceRepository {
            private array  $record;
            private string $status;

            public function __construct(array $record, string $status)
            {
                $this->record = $record;
                $this->status = $status;
            }

            public function findById(int $id, int $businessId): ?array
            {
                return array_merge($this->record, ['status' => $this->status]);
            }

            public function updateStatus(int $id, int $businessId, string $status): void
            {
                // no-op
            }

            public function getLines(int $invoiceId): array
            {
                return [];
            }
        };
    }

    /**
     * Create a People repository stub that tracks all updatePayeeBalance calls.
     */
    private function makePeopleRepoStub(array &$deltas): PeopleRepository
    {
        return new class($deltas) extends PeopleRepository {
            private array $deltas;

            public function __construct(array &$deltas)
            {
                $this->deltas = &$deltas;
            }

            public function updatePayeeBalance(int $payeeId, float $delta): void
            {
                $this->deltas[] = ['payeeId' => $payeeId, 'delta' => $delta];
            }
        };
    }

    /**
     * Build a SalesInvoiceService with mocked dependencies injected via reflection.
     *
     * @param float $totalAmountBc  The total_amount_bc to return from the DB query (for computePaymentStatusInternal)
     * @param float $totalCollected The total collected amount to return from the DB query (for computePaymentStatusInternal)
     */
    private function buildService(
        SalesInvoiceRepository $siRepo,
        PeopleRepository $peopleRepo,
        float $totalAmountBc = 0.0,
        float $totalCollected = 0.0
    ): SalesInvoiceService {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Mock PDO::prepare for computePaymentStatusInternal queries
        // First prepare call: SELECT total_amount_bc FROM sales_invoices
        $stmtInvoice = $this->createMock(PDOStatement::class);
        $stmtInvoice->method('execute')->willReturn(true);
        $stmtInvoice->method('fetchColumn')->willReturn($totalAmountBc);

        // Second prepare call: SELECT COALESCE(SUM(amount_bc), 0) FROM collection_slips
        $stmtCollection = $this->createMock(PDOStatement::class);
        $stmtCollection->method('execute')->willReturn(true);
        $stmtCollection->method('fetchColumn')->willReturn($totalCollected);

        $pdo->method('prepare')->willReturnOnConsecutiveCalls($stmtInvoice, $stmtCollection);

        $audit = new class extends AuditService {
            public function __construct() {}
            public function log(string $eventType, string $table, $recordId,
                                string $action, ?array $oldValues = null, ?array $newValues = null,
                                ?int $userId = null, ?int $tenantId = null, ?int $businessId = null, bool $force = false, bool $tenantScope = false): void {}
        };

        $itemService = new class extends ItemService {
            public function __construct() {}
            public function adjustStock(int $itemId, string $type, float $quantity, ?string $reference, ?string $notes): void {}
        };

        $accountRepo = new class extends AccountRepository {
            public function __construct() {}
            public function findById(int $id, int $businessId): ?array { return null; }
            public function updateBalance(int $accountId, float $delta, PDO $db, float $deltaFc = 0.0): void {}
        };

        $exchangeRateService = new class extends ExchangeRateService {
            public function __construct() {}
            public function resolveRate(string $currency, ?float $providedRate): float {
                return $providedRate ?? 1.0;
            }
        };

        $stockValidator = new class extends StockValidator {
            public function __construct() {}
            public function assertAvailable(int $itemId, float $requestedQty, int $businessId): void {}
            public function checkBatch(array $requirements, int $businessId): array { return []; }
        };

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
            'stockValidator'      => $stockValidator,
        ];

        foreach ($props as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($service, $value);
        }

        return $service;
    }
}
