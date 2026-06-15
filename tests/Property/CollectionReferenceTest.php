<?php

namespace Tests\Property;

use App\Repository\AccountRepository;
use App\Repository\CollectionRepository;
use App\Repository\PeopleRepository;
use App\Services\AuditService;
use App\Services\CollectionService;
use App\Services\ExchangeRateService;
use App\Services\SettlementEngine;
use App\Support\AuthContext;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Feature: operations-financial-integrity, Property 11: Collection Slip Invoice Reference Validation
 *
 * For any collection slip with reference_type "SALES_INVOICE", the creation succeeds
 * if and only if the referenced invoice exists, belongs to the same business, is in
 * DELIVERED status, and is not already fully paid. Partial amounts (less than invoice
 * total) are accepted, and multiple slips may reference the same invoice until fully paid.
 *
 * **Validates: Requirements 8.1, 8.2, 8.4, 8.5**
 */
class CollectionReferenceTest extends TestCase
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
     * Data provider generating 100 random collection slip scenarios with invoice references.
     *
     * Scenarios:
     * - Valid: Invoice exists, same business, DELIVERED, not fully paid → succeeds
     * - Invalid: Invoice does not exist → fails
     * - Invalid: Invoice exists but different business → fails
     * - Invalid: Invoice exists, same business, but not DELIVERED (DRAFT/CANCELLED) → fails
     * - Invalid: Invoice exists, same business, DELIVERED, but fully paid → fails
     * - Valid: Partial amount (less than invoice total) → succeeds
     *
     * Returns: [referenceId, invoiceExists, invoiceBusiness, invoiceStatus, invoiceTotal, totalCollected, shouldSucceed, errorFragment]
     */
    public static function collectionReferenceProvider(): array
    {
        $cases = [];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $scenario = mt_rand(1, 100);
            $invoiceTotal = round(mt_rand(10000, 10000000) / 100, 2);

            if ($scenario <= 20) {
                // Valid: Invoice exists, same business, DELIVERED, not fully paid
                $totalCollected = round(mt_rand(0, (int)(($invoiceTotal - 1) * 100)) / 100, 2);
                if ($totalCollected >= $invoiceTotal) {
                    $totalCollected = round($invoiceTotal - 1.00, 2);
                }
                $cases["iter_{$i}_valid"] = [
                    mt_rand(1, 1000),
                    true,
                    1,
                    'DELIVERED',
                    $invoiceTotal,
                    $totalCollected,
                    true,
                    null,
                ];
            } elseif ($scenario <= 40) {
                // Invalid: Invoice does not exist
                $cases["iter_{$i}_not_exists"] = [
                    mt_rand(1, 1000),
                    false,
                    1,
                    'DELIVERED',
                    $invoiceTotal,
                    0.0,
                    false,
                    'Referenced sales invoice not found',
                ];
            } elseif ($scenario <= 55) {
                // Invalid: Invoice exists but different business
                $cases["iter_{$i}_wrong_business"] = [
                    mt_rand(1, 1000),
                    true,
                    999,
                    'DELIVERED',
                    $invoiceTotal,
                    0.0,
                    false,
                    'does not belong to this business',
                ];
            } elseif ($scenario <= 70) {
                // Invalid: Invoice exists, same business, wrong status
                $nonDeliveredStatuses = ['DRAFT', 'CANCELLED'];
                $status = $nonDeliveredStatuses[array_rand($nonDeliveredStatuses)];
                $cases["iter_{$i}_wrong_status_{$status}"] = [
                    mt_rand(1, 1000),
                    true,
                    1,
                    $status,
                    $invoiceTotal,
                    0.0,
                    false,
                    'not in DELIVERED status',
                ];
            } elseif ($scenario <= 85) {
                // Invalid: Invoice fully paid
                $overpay = round(mt_rand(0, 10000) / 100, 2);
                $totalCollected = $invoiceTotal + $overpay;
                $cases["iter_{$i}_fully_paid"] = [
                    mt_rand(1, 1000),
                    true,
                    1,
                    'DELIVERED',
                    $invoiceTotal,
                    $totalCollected,
                    false,
                    'Invoice is already fully paid.',
                ];
            } else {
                // Valid: Partial amount (less than invoice total)
                $partialCollected = round(mt_rand(1, (int)(($invoiceTotal / 2) * 100)) / 100, 2);
                $cases["iter_{$i}_partial"] = [
                    mt_rand(1, 1000),
                    true,
                    1,
                    'DELIVERED',
                    $invoiceTotal,
                    $partialCollected,
                    true,
                    null,
                ];
            }
        }
        return $cases;
    }

    /**
     * Property 11: Collection Slip Invoice Reference Validation
     *
     * For any collection slip with reference_type "SALES_INVOICE":
     * - Creation succeeds iff invoice exists, same business, DELIVERED, not fully paid
     * - Partial amounts accepted
     * - Multiple slips allowed until fully paid
     *
     * **Validates: Requirements 8.1, 8.2, 8.4, 8.5**
     *
     * @dataProvider collectionReferenceProvider
     */
    public function testCollectionSlipInvoiceReferenceValidation(
        int $referenceId,
        bool $invoiceExists,
        int $invoiceBusiness,
        string $invoiceStatus,
        float $invoiceTotal,
        float $totalCollected,
        bool $shouldSucceed,
        ?string $errorFragment
    ): void {
        $service = $this->buildCollectionService(
            $referenceId,
            $invoiceExists,
            $invoiceBusiness,
            $invoiceStatus,
            $invoiceTotal,
            $totalCollected
        );

        $data = [
            'account_id'     => 1,
            'reference_type' => 'SALES_INVOICE',
            'reference_id'   => $referenceId,
            'slip_number'    => 'COL-' . mt_rand(1000, 9999),
            'slip_date'      => '2024-01-15',
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
     * Test that multiple collection slips can reference the same invoice until fully paid.
     *
     * **Validates: Requirements 8.5**
     */
    public function testMultipleCollectionSlipsCanReferenceSameInvoice(): void
    {
        $referenceId = 42;
        $invoiceTotal = 1000.00;

        // First collection: partially paid (300 collected so far)
        $service1 = $this->buildCollectionService($referenceId, true, 1, 'DELIVERED', $invoiceTotal, 0.0);
        $data = [
            'account_id'     => 1,
            'reference_type' => 'SALES_INVOICE',
            'reference_id'   => $referenceId,
            'slip_number'    => 'COL-0001',
            'slip_date'      => '2024-01-15',
            'currency'       => 'usd',
            'exchange_rate'  => 1.0,
            'amount_fc'      => 300.00,
            'method'         => 'bank_transfer',
            'payee_id'       => 10,
        ];

        $id1 = $service1->create($data);
        $this->assertIsInt($id1);

        // Second collection: still not fully paid (600 total collected)
        $service2 = $this->buildCollectionService($referenceId, true, 1, 'DELIVERED', $invoiceTotal, 300.0);
        $data['slip_number'] = 'COL-0002';
        $data['amount_fc'] = 300.00;

        $id2 = $service2->create($data);
        $this->assertIsInt($id2);
        $this->assertGreaterThan(0, $id2);
    }

    /**
     * Build a CollectionService with mocked dependencies for testing invoice reference validation.
     */
    private function buildCollectionService(
        int $referenceId,
        bool $invoiceExists,
        int $invoiceBusiness,
        string $invoiceStatus,
        float $invoiceTotal,
        float $totalCollected
    ): CollectionService {
        $businessId = $this->businessId;

        // Mock CollectionRepository
        $collectionRepo = $this->createMock(CollectionRepository::class);
        $collectionRepo->method('create')->willReturn(1);

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
        $settlementEngine->method('evaluateCustomerSettlement')->willReturn([]);

        // Mock PDO for invoice reference validation queries
        // Query 1: SELECT from sales_invoices
        $invoiceQueryStmt = $this->createMock(PDOStatement::class);
        $invoiceQueryStmt->method('execute')->willReturn(true);

        if ($invoiceExists) {
            $invoiceQueryStmt->method('fetch')->willReturn([
                'id'              => $referenceId,
                'business_id'     => $invoiceBusiness,
                'status'          => $invoiceStatus,
                'total_amount_bc' => $invoiceTotal,
            ]);
        } else {
            $invoiceQueryStmt->method('fetch')->willReturn(false);
        }

        // Query 2: SELECT SUM from collection_slips (fully paid check)
        $collectionSumStmt = $this->createMock(PDOStatement::class);
        $collectionSumStmt->method('execute')->willReturn(true);
        $collectionSumStmt->method('fetchColumn')->willReturn($totalCollected);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')
            ->willReturnOnConsecutiveCalls($invoiceQueryStmt, $collectionSumStmt);
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
