<?php

namespace Tests\Property;

use App\Repository\AccountRepository;
use App\Repository\PaymentRepository;
use App\Repository\PeopleRepository;
use App\Services\AuditService;
use App\Services\ExchangeRateService;
use App\Services\PaymentService;
use App\Services\SettlementEngine;
use App\Support\AuthContext;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Feature: operations-financial-integrity, Property 19: Payment Slip PO Reference Validation
 *
 * For any payment slip with reference_type "PURCHASE_ORDER", the creation succeeds
 * if and only if the referenced PO exists, belongs to the same business, and is in
 * RECEIVED status. Multiple payment slips may reference the same PO.
 *
 * **Validates: Requirements 14.1, 14.4**
 */
class PaymentReferenceTest extends TestCase
{
    private const ITERATIONS = 100;

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
     * Data provider generating 100 random payment slip scenarios with PO references.
     *
     * Scenarios:
     * - Valid: PO exists, same business, RECEIVED status → succeeds
     * - Invalid: PO does not exist → fails
     * - Invalid: PO exists but different business → fails
     * - Invalid: PO exists, same business, but not RECEIVED (DRAFT/APPROVED/CANCELLED) → fails
     * - Valid: No reference_id (general payment) → succeeds
     *
     * Returns: [referenceId, poExists, poBusiness, poStatus, shouldSucceed, errorFragment]
     */
    public static function paymentReferenceProvider(): array
    {
        $cases = [];
        $statuses = ['DRAFT', 'APPROVED', 'RECEIVED', 'CANCELLED'];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $scenario = mt_rand(1, 100);

            if ($scenario <= 25) {
                // Valid: PO exists, same business, RECEIVED
                $cases["iter_{$i}_valid_received"] = [
                    'referenceId'   => mt_rand(1, 1000),
                    'poExists'      => true,
                    'poBusiness'    => 1, // same business
                    'poStatus'      => 'RECEIVED',
                    'shouldSucceed' => true,
                    'errorFragment' => null,
                ];
            } elseif ($scenario <= 45) {
                // Invalid: PO does not exist
                $cases["iter_{$i}_not_exists"] = [
                    'referenceId'   => mt_rand(1, 1000),
                    'poExists'      => false,
                    'poBusiness'    => 1,
                    'poStatus'      => 'RECEIVED',
                    'shouldSucceed' => false,
                    'errorFragment' => 'not found',
                ];
            } elseif ($scenario <= 65) {
                // Invalid: PO exists but different business
                $cases["iter_{$i}_wrong_business"] = [
                    'referenceId'   => mt_rand(1, 1000),
                    'poExists'      => true,
                    'poBusiness'    => 999, // different business
                    'poStatus'      => 'RECEIVED',
                    'shouldSucceed' => false,
                    'errorFragment' => 'does not belong to this business',
                ];
            } elseif ($scenario <= 85) {
                // Invalid: PO exists, same business, wrong status
                $nonReceivedStatuses = ['DRAFT', 'APPROVED', 'CANCELLED'];
                $status = $nonReceivedStatuses[array_rand($nonReceivedStatuses)];
                $cases["iter_{$i}_wrong_status_{$status}"] = [
                    'referenceId'   => mt_rand(1, 1000),
                    'poExists'      => true,
                    'poBusiness'    => 1,
                    'poStatus'      => $status,
                    'shouldSucceed' => false,
                    'errorFragment' => 'not in RECEIVED status',
                ];
            } else {
                // Valid: No reference_id (general payment)
                $cases["iter_{$i}_no_reference"] = [
                    'referenceId'   => null,
                    'poExists'      => false,
                    'poBusiness'    => 1,
                    'poStatus'      => 'RECEIVED',
                    'shouldSucceed' => true,
                    'errorFragment' => null,
                ];
            }
        }
        return $cases;
    }

    /**
     * Property 19: Payment Slip PO Reference Validation
     *
     * For any payment slip with reference_type "PURCHASE_ORDER":
     * - Creation succeeds iff referenced PO exists, same business, RECEIVED status
     * - Multiple slips can reference same PO
     * - Payment slips without reference_id (general payments) always succeed
     *
     * **Validates: Requirements 14.1, 14.4**
     *
     * @dataProvider paymentReferenceProvider
     */
    public function testPaymentSlipPOValidation(
        ?int $referenceId,
        bool $poExists,
        int $poBusiness,
        string $poStatus,
        bool $shouldSucceed,
        ?string $errorFragment
    ): void {
        $service = $this->buildPaymentService($referenceId, $poExists, $poBusiness, $poStatus);

        $data = [
            'account_id'     => 1,
            'reference_type' => 'PURCHASE_ORDER',
            'reference_id'   => $referenceId,
            'payment_number' => 'PAY-' . mt_rand(1000, 9999),
            'payment_date'   => '2024-01-15',
            'currency'       => 'usd',
            'exchange_rate'  => 1.0,
            'amount_fc'      => round(mt_rand(100, 10000) / 100, 2),
            'method'         => 'bank_transfer',
            'payee_id'       => 10,
        ];

        if ($shouldSucceed) {
            $id = $service->create($data);
            $this->assertIsInt($id);
            $this->assertGreaterThan(0, $id);
        } else {
            $this->expectException(\RuntimeException::class);
            if ($errorFragment) {
                $this->expectExceptionMessage($errorFragment);
            }
            $service->create($data);
        }
    }

    /**
     * Test that multiple payment slips can reference the same PO.
     *
     * **Validates: Requirements 14.4**
     */
    public function testMultiplePaymentSlipsCanReferenceSamePO(): void
    {
        $referenceId = 42;

        // Create first payment
        $service1 = $this->buildPaymentService($referenceId, true, 1, 'RECEIVED');
        $data = [
            'account_id'     => 1,
            'reference_type' => 'PURCHASE_ORDER',
            'reference_id'   => $referenceId,
            'payment_number' => 'PAY-0001',
            'payment_date'   => '2024-01-15',
            'currency'       => 'usd',
            'exchange_rate'  => 1.0,
            'amount_fc'      => 50.00,
            'method'         => 'bank_transfer',
            'payee_id'       => 10,
        ];

        $id1 = $service1->create($data);
        $this->assertIsInt($id1);

        // Create second payment referencing same PO
        $service2 = $this->buildPaymentService($referenceId, true, 1, 'RECEIVED');
        $data['payment_number'] = 'PAY-0002';
        $data['amount_fc'] = 75.00;

        $id2 = $service2->create($data);
        $this->assertIsInt($id2);
        $this->assertGreaterThan(0, $id2);
    }

    /**
     * Build a PaymentService with mocked dependencies for testing PO reference validation.
     */
    private function buildPaymentService(
        ?int $referenceId,
        bool $poExists,
        int $poBusiness,
        string $poStatus
    ): PaymentService {
        $businessId = $this->businessId;

        // Mock PaymentRepository
        $paymentRepo = $this->createMock(PaymentRepository::class);
        $paymentRepo->method('create')->willReturn(1);

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

        // Mock SettlementEngine
        $settlementEngine = $this->getMockBuilder(SettlementEngine::class)
            ->disableOriginalConstructor()
            ->getMock();
        $settlementEngine->method('evaluateSupplierSettlement')->willReturn([]);

        // Mock PDO for PO reference validation query
        $poQueryStmt = $this->createMock(PDOStatement::class);
        $poQueryStmt->method('execute')->willReturn(true);

        if ($referenceId !== null && $poExists) {
            $poQueryStmt->method('fetch')->willReturn([
                'id'          => $referenceId,
                'business_id' => $poBusiness,
                'status'      => $poStatus,
            ]);
        } else {
            $poQueryStmt->method('fetch')->willReturn(false);
        }

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($poQueryStmt);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('rollBack')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Build service using reflection
        $ref = new ReflectionClass(PaymentService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $props = [
            'db'                  => $pdo,
            'repo'                => $paymentRepo,
            'accountRepo'         => $accountRepo,
            'peopleRepo'          => $peopleRepo,
            'exchangeRateService' => $exchangeRateService,
            'audit'               => $auditService,
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
