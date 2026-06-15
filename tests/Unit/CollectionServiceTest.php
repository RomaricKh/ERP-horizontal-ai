<?php

namespace Tests\Unit;

use App\Repository\AccountRepository;
use App\Repository\CollectionAllocationRepository;
use App\Repository\CollectionRepository;
use App\Repository\PeopleRepository;
use App\Repository\SalesInvoiceRepository;
use App\Services\AuditService;
use App\Services\CollectionService;
use App\Services\ExchangeRateService;
use App\Services\NotificationService;
use App\Services\SettlementEngine;
use App\Support\AuthContext;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for CollectionService modifications.
 *
 * Tests collection creation, allocation validation, and settlement trigger integration.
 *
 * Validates: Requirements 8.1, 8.2, 8.3, 8.4, 8.5, 7.6, 9.1
 */
class CollectionServiceTest extends TestCase
{
    private $tenantId = 1;
    private $businessId = 1;
    private $userId = 1;

    protected function setUp(): void
    {
        AuthContext::set((object) [
            'user_id'     => $this->userId,
            'tenant_id'   => $this->tenantId,
            'business_id' => $this->businessId,
        ]);
    }

    /**
     * Test collection creation succeeds with valid data and no allocations.
     *
     * Validates: Requirements 8.1, 8.2
     */
    public function testCollectionWithValidDataSucceeds(): void
    {
        $service = $this->buildCollectionService();

        $data = $this->makeCollectionData();
        $id = $service->create($data);

        $this->assertIsInt($id);
        $this->assertSame(1, $id);
    }

    /**
     * Test collection with allocations that match slip amount succeeds.
     *
     * Validates: Requirements 8.1, 8.4
     */
    public function testCollectionWithMatchingAllocationsSucceeds(): void
    {
        $service = $this->buildCollectionService(0.0, 1000.00);

        $data = $this->makeCollectionData([
            'amount_fc' => 500.00,
            'allocations' => [
                ['reference_type' => 'SALES_INVOICE', 'reference_id' => 42, 'amount_fc' => 500.00, 'amount_bc' => 500.00],
            ],
        ]);
        $id = $service->create($data);

        $this->assertIsInt($id);
        $this->assertSame(1, $id);
    }

    /**
     * Test collection rejects allocations that don't sum to slip amount.
     *
     * Validates: Requirements 8.3
     */
    public function testCollectionRejectsAllocationSumMismatch(): void
    {
        $service = $this->buildCollectionService(0.0, 1000.00);

        $data = $this->makeCollectionData([
            'amount_fc' => 500.00,
            'allocations' => [
                ['reference_type' => 'SALES_INVOICE', 'reference_id' => 42, 'amount_fc' => 300.00, 'amount_bc' => 300.00],
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Allocation sum');
        $service->create($data);
    }

    /**
     * Test collection rejects over-allocation on an invoice.
     *
     * Validates: Requirements 7.6
     */
    public function testCollectionRejectsOverAllocation(): void
    {
        // Invoice total is 1000, already allocated 900, trying to add 200 => over
        $service = $this->buildCollectionService(900.0, 1000.00);

        $data = $this->makeCollectionData([
            'amount_fc' => 200.00,
            'allocations' => [
                ['reference_type' => 'SALES_INVOICE', 'reference_id' => 42, 'amount_fc' => 200.00, 'amount_bc' => 200.00],
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Over-allocation');
        $service->create($data);
    }

    /**
     * Test partial collection amount (less than invoice total) succeeds.
     *
     * Validates: Requirements 8.4
     */
    public function testPartialCollectionAmountSucceeds(): void
    {
        $service = $this->buildCollectionService(0.0, 1000.00);

        // Collect only 200 of 1000 total
        $data = $this->makeCollectionData([
            'amount_fc' => 200.00,
            'allocations' => [
                ['reference_type' => 'SALES_INVOICE', 'reference_id' => 42, 'amount_fc' => 200.00, 'amount_bc' => 200.00],
            ],
        ]);
        $id = $service->create($data);

        $this->assertIsInt($id);
        $this->assertSame(1, $id);
    }

    /**
     * Test multiple collections accumulate allocations correctly.
     *
     * Validates: Requirements 8.5, 7.6
     */
    public function testMultipleCollectionsAccumulateAllocations(): void
    {
        // First collection: 600 allocated, total 1000, no prior allocation
        $service1 = $this->buildCollectionService(0.0, 1000.00);

        $data = $this->makeCollectionData([
            'amount_fc' => 600.00,
            'allocations' => [
                ['reference_type' => 'SALES_INVOICE', 'reference_id' => 42, 'amount_fc' => 600.00, 'amount_bc' => 600.00],
            ],
        ]);
        $id1 = $service1->create($data);
        $this->assertSame(1, $id1);

        // Second collection: 400 more, prior allocation is 600, total 1000 — exactly fills
        $service2 = $this->buildCollectionService(600.0, 1000.00);

        $data2 = $this->makeCollectionData([
            'slip_number' => 'COL-0002',
            'amount_fc' => 400.00,
            'allocations' => [
                ['reference_type' => 'SALES_INVOICE', 'reference_id' => 42, 'amount_fc' => 400.00, 'amount_bc' => 400.00],
            ],
        ]);
        $id2 = $service2->create($data2);
        $this->assertSame(1, $id2);

        // Third collection: over-allocation (1000 already allocated, adding 100)
        $service3 = $this->buildCollectionService(1000.0, 1000.00);

        $data3 = $this->makeCollectionData([
            'slip_number' => 'COL-0003',
            'amount_fc' => 100.00,
            'allocations' => [
                ['reference_type' => 'SALES_INVOICE', 'reference_id' => 42, 'amount_fc' => 100.00, 'amount_bc' => 100.00],
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Over-allocation');
        $service3->create($data3);
    }

    /**
     * Test settlement trigger is called after collection creation.
     *
     * Validates: Requirements 9.1
     */
    public function testSettlementTriggerCalledAfterCreation(): void
    {
        $settlementEngine = $this->getMockBuilder(SettlementEngine::class)
            ->disableOriginalConstructor()
            ->getMock();

        $settlementEngine->expects($this->once())
            ->method('evaluateCustomerSettlement')
            ->with(10, $this->businessId)
            ->willReturn([501, 502]);

        $settlementEngine->method('autoAllocateFIFO')->willReturn([]);

        $invoiceRepo = $this->createMock(SalesInvoiceRepository::class);
        $invoiceRepo->expects($this->exactly(2))
            ->method('updateStatus');

        $service = $this->buildCollectionService(0.0, 0.0, $settlementEngine, $invoiceRepo);

        $data = $this->makeCollectionData(['payee_id' => 10]);
        $id = $service->create($data);

        $this->assertSame(1, $id);
    }

    /**
     * Test settlement is not triggered when no payee_id is provided.
     *
     * Validates: Requirements 9.1
     */
    public function testSettlementNotTriggeredWithoutPayee(): void
    {
        $settlementEngine = $this->getMockBuilder(SettlementEngine::class)
            ->disableOriginalConstructor()
            ->getMock();

        $settlementEngine->expects($this->never())
            ->method('evaluateCustomerSettlement');

        $service = $this->buildCollectionService(0.0, 0.0, $settlementEngine);

        // No payee_id → general collection, no settlement evaluation
        $data = $this->makeCollectionData(['payee_id' => null]);
        $id = $service->create($data);

        $this->assertSame(1, $id);
    }

    /**
     * Test collection with missing account_id is rejected.
     *
     * Validates: Requirements 8.1
     */
    public function testCollectionRejectsMissingAccountId(): void
    {
        $service = $this->buildCollectionService();

        $data = $this->makeCollectionData(['account_id' => '']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Account is required');
        $service->create($data);
    }

    // ── Helper Methods ────────────────────────────────────────────────────

    private function makeCollectionData(array $overrides = []): array
    {
        return array_merge([
            'account_id'     => 1,
            'slip_number'    => 'COL-2024-001',
            'slip_date'      => '2024-01-15',
            'currency'       => 'usd',
            'exchange_rate'  => 1.0,
            'amount_fc'      => 500.00,
            'method'         => 'bank_transfer',
            'payee_id'       => 10,
        ], $overrides);
    }

    /**
     * @param float $allocationSum  Amount already allocated for the invoice
     * @param float $invoiceTotal   Total amount of the invoice
     * @param SettlementEngine|null $settlementEngine  Custom settlement engine mock
     * @param SalesInvoiceRepository|null $invoiceRepo  Custom invoice repo mock
     */
    private function buildCollectionService(
        $allocationSum = 0.0,
        $invoiceTotal = 0.0,
        $settlementEngine = null,
        $invoiceRepo = null
    ) {
        $businessId = $this->businessId;

        // Mock CollectionRepository
        $collectionRepo = $this->createMock(CollectionRepository::class);
        $collectionRepo->method('create')->willReturn(1);

        // Mock CollectionAllocationRepository
        $allocationRepo = $this->createMock(CollectionAllocationRepository::class);
        $allocationRepo->method('sumForDocument')->willReturn($allocationSum);

        // Mock AccountRepository
        $accountRepo = $this->createMock(AccountRepository::class);
        $accountRepo->method('findById')->willReturn([
            'id'         => 1,
            'business_id' => $businessId,
            'name'       => 'Test Account',
            'currency'   => 'USD',
            'balance'    => 10000.00,
            'balance_fc' => 10000.00,
        ]);

        // Mock PeopleRepository
        $peopleRepo = $this->createMock(PeopleRepository::class);

        // Mock ExchangeRateService
        $exchangeRateService = $this->getMockBuilder(ExchangeRateService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $exchangeRateService->method('resolveRate')->willReturn(1.0);

        // Mock AuditService
        $auditService = $this->getMockBuilder(AuditService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['log'])
            ->getMock();

        // Mock NotificationService
        $notificationService = $this->getMockBuilder(NotificationService::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Settlement engine
        if ($settlementEngine === null) {
            $settlementEngine = $this->getMockBuilder(SettlementEngine::class)
                ->disableOriginalConstructor()
                ->getMock();
            $settlementEngine->method('evaluateCustomerSettlement')->willReturn([]);
            $settlementEngine->method('autoAllocateFIFO')->willReturn([]);
        }

        // Invoice repository
        if ($invoiceRepo === null) {
            $invoiceRepo = $this->createMock(SalesInvoiceRepository::class);
        }

        // Mock PDO — needed for getInvoiceTotal() and resolveRecipientsByPermission() private methods
        $invoiceTotalStmt = $this->createMock(PDOStatement::class);
        $invoiceTotalStmt->method('execute')->willReturn(true);
        $invoiceTotalStmt->method('fetch')->willReturn(
            $invoiceTotal > 0 ? ['total_amount_bc' => $invoiceTotal] : false
        );
        $invoiceTotalStmt->method('fetchColumn')->willReturn($invoiceTotal);

        $recipientsStmt = $this->createMock(PDOStatement::class);
        $recipientsStmt->method('execute')->willReturn(true);
        $recipientsStmt->method('fetchAll')->willReturn([]);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnOnConsecutiveCalls($invoiceTotalStmt, $recipientsStmt);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('rollBack')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Build service using reflection
        $ref = new ReflectionClass(CollectionService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $props = [
            'db'                  => $pdo,
            'repo'                => $collectionRepo,
            'allocationRepo'      => $allocationRepo,
            'accountRepo'         => $accountRepo,
            'peopleRepo'          => $peopleRepo,
            'invoiceRepo'         => $invoiceRepo,
            'exchangeRateService' => $exchangeRateService,
            'audit'               => $auditService,
            'settlementEngine'    => $settlementEngine,
            'notificationService' => $notificationService,
        ];

        foreach ($props as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($service, $value);
        }

        return $service;
    }
}
