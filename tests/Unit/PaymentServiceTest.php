<?php

namespace Tests\Unit;

use App\Repository\AccountRepository;
use App\Repository\PaymentRepository;
use App\Repository\PaymentAllocationRepository;
use App\Repository\PeopleRepository;
use App\Services\AuditService;
use App\Services\ExchangeRateService;
use App\Services\NotificationService;
use App\Services\PaymentService;
use App\Services\SettlementEngine;
use App\Support\AuthContext;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for PaymentService with allocation-based architecture.
 *
 * Tests payment creation, FIFO auto-allocation, and settlement trigger integration.
 *
 * Validates: Requirements 21.9
 */
class PaymentServiceTest extends TestCase
{
    private int $tenantId = 1;
    private int $businessId = 1;
    private int $userId = 1;

    protected function setUp(): void
    {
        AuthContext::set((object) [
            'user_id'     => $this->userId,
            'tenant_id'   => $this->tenantId,
            'business_id' => $this->businessId,
        ]);
    }

    /**
     * Test payment creation succeeds with valid data and no allocations (FIFO auto-allocation).
     *
     * Validates: Requirements 21.9
     */
    public function testPaymentCreationSucceeds(): void
    {
        $service = $this->buildPaymentService();

        $data = $this->makePaymentData();
        $id = $service->create($data);

        $this->assertIsInt($id);
        $this->assertSame(1, $id);
    }

    /**
     * Test payment without payee_id (general payment) succeeds.
     *
     * Validates: Requirements 21.9
     */
    public function testPaymentWithoutPayeeIdSucceeds(): void
    {
        $service = $this->buildPaymentService();

        $data = $this->makePaymentData(['payee_id' => null]);
        $id = $service->create($data);

        $this->assertIsInt($id);
        $this->assertSame(1, $id);
    }

    /**
     * Test settlement trigger is called after payment creation.
     *
     * Validates: Requirements 21.9
     */
    public function testSettlementTriggerCalledAfterCreation(): void
    {
        $settlementEngine = $this->getMockBuilder(SettlementEngine::class)
            ->disableOriginalConstructor()
            ->getMock();

        $settlementEngine->expects($this->once())
            ->method('evaluateSupplierSettlement')
            ->with(10, $this->businessId)
            ->willReturn([101, 102]);

        $settlementEngine->method('autoAllocateFIFO')->willReturn([]);

        $service = $this->buildPaymentService($settlementEngine);

        $data = $this->makePaymentData(['payee_id' => 10]);
        $id = $service->create($data);

        $this->assertSame(1, $id);
    }

    /**
     * Test settlement is not triggered when no payee_id is provided.
     *
     * Validates: Requirements 21.9
     */
    public function testSettlementNotTriggeredWithoutPayee(): void
    {
        $settlementEngine = $this->getMockBuilder(SettlementEngine::class)
            ->disableOriginalConstructor()
            ->getMock();

        $settlementEngine->expects($this->never())
            ->method('evaluateSupplierSettlement');

        $service = $this->buildPaymentService($settlementEngine);

        $data = $this->makePaymentData(['payee_id' => null]);
        $id = $service->create($data);

        $this->assertSame(1, $id);
    }

    /**
     * Test payment with explicit allocations that don't sum to slip amount is rejected.
     *
     * Validates: Requirements 21.9
     */
    public function testPaymentRejectsAllocationSumMismatch(): void
    {
        $service = $this->buildPaymentService();

        $data = $this->makePaymentData([
            'allocations' => [
                ['reference_type' => 'PURCHASE_ORDER', 'reference_id' => 1, 'amount_fc' => 100.00, 'amount_bc' => 100.00],
            ],
            'amount_fc' => 500.00,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Allocation sum');
        $service->create($data);
    }

    /**
     * Test FIFO auto-allocation is called when no allocations provided and payee exists.
     *
     * Validates: Requirements 21.9
     */
    public function testFIFOAutoAllocationCalledWhenNoAllocationsProvided(): void
    {
        $settlementEngine = $this->getMockBuilder(SettlementEngine::class)
            ->disableOriginalConstructor()
            ->getMock();

        $settlementEngine->expects($this->once())
            ->method('autoAllocateFIFO')
            ->willReturn([]);

        $settlementEngine->method('evaluateSupplierSettlement')->willReturn([]);

        $service = $this->buildPaymentService($settlementEngine);

        $data = $this->makePaymentData(['payee_id' => 10]);
        $service->create($data);
    }

    // ── Helper Methods ────────────────────────────────────────────────────

    private function makePaymentData(array $overrides = []): array
    {
        return array_merge([
            'account_id'     => 1,
            'payment_number' => 'PAY-2024-001',
            'payment_date'   => '2024-01-15',
            'currency'       => 'usd',
            'exchange_rate'  => 1.0,
            'amount_fc'      => 500.00,
            'method'         => 'BANK',
            'payee_id'       => 10,
        ], $overrides);
    }

    private function buildPaymentService(
        $settlementEngine = null
    ): PaymentService {
        $businessId = $this->businessId;

        // Mock PaymentRepository
        $paymentRepo = $this->createMock(PaymentRepository::class);
        $paymentRepo->method('create')->willReturn(1);

        // Mock PaymentAllocationRepository
        $allocationRepo = $this->createMock(PaymentAllocationRepository::class);
        $allocationRepo->method('sumForDocument')->willReturn(0.0);

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
            $settlementEngine->method('evaluateSupplierSettlement')->willReturn([]);
            $settlementEngine->method('autoAllocateFIFO')->willReturn([]);
        }

        // Mock PDO
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('rollBack')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn([]);
        $pdo->method('prepare')->willReturn($stmt);

        // Build service using reflection
        $ref = new ReflectionClass(PaymentService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $props = [
            'db'                  => $pdo,
            'repo'                => $paymentRepo,
            'allocationRepo'      => $allocationRepo,
            'accountRepo'         => $accountRepo,
            'peopleRepo'          => $peopleRepo,
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
