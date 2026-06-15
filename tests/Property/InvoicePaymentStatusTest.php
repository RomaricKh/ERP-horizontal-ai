<?php

namespace Tests\Property;

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
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Feature: operations-financial-integrity, Properties 9 & 10: Invoice Payment Status
 *
 * Property 9: For any DELIVERED sales invoice and for any set of collection slips
 * where reference_type is "SALES_INVOICE" and reference_id matches the invoice ID,
 * the computed Payment_Status equals PAID when sum(amount_bc) >= total_amount_bc,
 * PARTIALLY_PAID when 0 < sum(amount_bc) < total_amount_bc, and UNPAID when
 * sum(amount_bc) = 0.
 *
 * Property 10: For any sales invoice where the sum of existing collection slip
 * amounts equals or exceeds the invoice total_amount_bc, computePaymentStatus
 * returns PAID.
 *
 * **Validates: Requirements 7.1, 7.2, 7.3, 7.4, 7.6**
 */
class InvoicePaymentStatusTest extends TestCase
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
     * Data provider generating 100 random invoice amounts and collection slip totals.
     *
     * Returns: [invoiceTotalAmountBc, totalCollected, expectedStatus]
     */
    public static function invoicePaymentStatusProvider(): array
    {
        $cases = [];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Invoice total: between 100.00 and 100000.00
            $invoiceTotal = round(mt_rand(10000, 10000000) / 100, 2);

            // Decide scenario randomly: UNPAID (30%), PARTIALLY_PAID (40%), PAID (30%)
            $scenario = mt_rand(1, 100);

            if ($scenario <= 30) {
                // UNPAID: sum = 0
                $totalCollected = 0.0;
                $expectedStatus = 'UNPAID';
            } elseif ($scenario <= 70) {
                // PARTIALLY_PAID: 0 < sum < total
                $totalCollected = round(mt_rand(1, (int)(($invoiceTotal - 0.01) * 100)) / 100, 2);
                // Ensure it's strictly less than total
                if ($totalCollected >= $invoiceTotal) {
                    $totalCollected = round($invoiceTotal - 0.01, 2);
                }
                if ($totalCollected <= 0) {
                    $totalCollected = 0.01;
                }
                $expectedStatus = 'PARTIALLY_PAID';
            } else {
                // PAID: sum >= total
                $overpay = round(mt_rand(0, 10000) / 100, 2);
                $totalCollected = $invoiceTotal + $overpay;
                $expectedStatus = 'PAID';
            }

            $cases["iter_{$i}_total_{$invoiceTotal}_collected_{$totalCollected}"] = [
                $invoiceTotal,
                $totalCollected,
                $expectedStatus,
            ];
        }
        return $cases;
    }

    /**
     * Property 9: Invoice Payment Status Computation
     *
     * For any DELIVERED sales invoice and for any set of collection slips:
     * - PAID when sum(amount_bc) >= total_amount_bc
     * - PARTIALLY_PAID when 0 < sum(amount_bc) < total_amount_bc
     * - UNPAID when sum(amount_bc) = 0
     *
     * **Validates: Requirements 7.1, 7.2, 7.3, 7.4**
     *
     * @dataProvider invoicePaymentStatusProvider
     */
    public function testInvoicePaymentStatusComputedCorrectly(
        float $invoiceTotalAmountBc,
        float $totalCollected,
        string $expectedStatus
    ): void {
        $invoiceId = 42;
        $customerId = 10;

        $service = $this->buildSalesInvoiceServiceForPaymentStatus(
            $invoiceId,
            'DELIVERED',
            $customerId,
            $invoiceTotalAmountBc,
            $totalCollected
        );

        $status = $service->computePaymentStatus($invoiceId);

        $this->assertSame(
            $expectedStatus,
            $status,
            sprintf(
                'Expected payment status "%s" for invoice total %.2f with %.2f collected, got "%s"',
                $expectedStatus,
                $invoiceTotalAmountBc,
                $totalCollected,
                $status
            )
        );
    }

    /**
     * Data provider generating 100 invoices where sum(collection slips) >= total.
     *
     * Returns: [invoiceTotalAmountBc, totalCollected]
     */
    public static function fullyPaidInvoiceProvider(): array
    {
        $cases = [];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Invoice total: between 100.00 and 100000.00
            $invoiceTotal = round(mt_rand(10000, 10000000) / 100, 2);

            // Total collected >= invoice total (overpay by 0 to 100.00)
            $overpay = round(mt_rand(0, 10000) / 100, 2);
            $totalCollected = $invoiceTotal + $overpay;

            $cases["iter_{$i}_total_{$invoiceTotal}_collected_{$totalCollected}"] = [
                $invoiceTotal,
                $totalCollected,
            ];
        }
        return $cases;
    }

    /**
     * Property 10: Fully Paid Invoice Rejects Additional Collections
     *
     * For any sales invoice where sum(collection slips) >= total_amount_bc,
     * computePaymentStatus returns PAID, confirming the invoice is fully paid.
     * (The actual rejection of new collection slips is handled by CollectionService,
     * but this test validates the underlying status computation that drives that guard.)
     *
     * **Validates: Requirements 7.6**
     *
     * @dataProvider fullyPaidInvoiceProvider
     */
    public function testFullyPaidInvoiceReturnsPayedStatus(
        float $invoiceTotalAmountBc,
        float $totalCollected
    ): void {
        $invoiceId = 42;
        $customerId = 10;

        $service = $this->buildSalesInvoiceServiceForPaymentStatus(
            $invoiceId,
            'DELIVERED',
            $customerId,
            $invoiceTotalAmountBc,
            $totalCollected
        );

        $status = $service->computePaymentStatus($invoiceId);

        $this->assertSame(
            'PAID',
            $status,
            sprintf(
                'Expected PAID status for invoice total %.2f with %.2f collected (>= total), got "%s"',
                $invoiceTotalAmountBc,
                $totalCollected,
                $status
            )
        );
    }

    /**
     * Build a SalesInvoiceService for payment status computation testing.
     */
    private function buildSalesInvoiceServiceForPaymentStatus(
        int $invoiceId,
        string $status,
        int $customerId,
        float $totalAmountBc,
        float $totalCollected
    ): SalesInvoiceService {
        $businessId = $this->businessId;

        // Mock SalesInvoiceRepository
        $repo = $this->createMock(SalesInvoiceRepository::class);
        $repo->method('findById')
            ->willReturnCallback(function ($id, $bid) use ($invoiceId, $businessId, $status, $customerId, $totalAmountBc) {
                if ($id === $invoiceId && $bid === $businessId) {
                    return [
                        'id'              => $invoiceId,
                        'business_id'     => $businessId,
                        'customer_id'     => $customerId,
                        'invoice_number'  => 'INV-2024-001',
                        'invoice_date'    => '2024-01-15',
                        'status'          => $status,
                        'currency'        => 'USD',
                        'exchange_rate'   => 1.0,
                        'total_amount_fc' => $totalAmountBc,
                        'total_amount_bc' => $totalAmountBc,
                        'notes'           => null,
                        'created_at'      => '2024-01-01',
                        'updated_at'      => '2024-01-01',
                        'created_by'      => 1,
                        'lines'           => [],
                    ];
                }
                return null;
            });

        // Mock PDO with two prepare calls for computePaymentStatusInternal:
        // 1. SELECT total_amount_bc FROM sales_invoices
        // 2. SELECT COALESCE(SUM(amount_bc), 0) FROM collection_slips
        $invoiceTotalStmt = $this->createMock(\PDOStatement::class);
        $invoiceTotalStmt->method('execute')->willReturn(true);
        $invoiceTotalStmt->method('fetchColumn')->willReturn($totalAmountBc);

        $collectionSumStmt = $this->createMock(\PDOStatement::class);
        $collectionSumStmt->method('execute')->willReturn(true);
        $collectionSumStmt->method('fetchColumn')->willReturn($totalCollected);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')
            ->willReturnOnConsecutiveCalls($invoiceTotalStmt, $collectionSumStmt);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Mock other dependencies
        $peopleRepo = $this->createMock(PeopleRepository::class);
        $accountRepo = $this->createMock(AccountRepository::class);
        $exchangeRateService = $this->getMockBuilder(ExchangeRateService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $auditService = $this->getMockBuilder(AuditService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['log'])
            ->getMock();
        $itemService = $this->getMockBuilder(ItemService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $stockValidator = $this->getMockBuilder(StockValidator::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Build service using reflection
        $ref = new ReflectionClass(SalesInvoiceService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $props = [
            'db'                  => $pdo,
            'repo'                => $repo,
            'peopleRepo'          => $peopleRepo,
            'exchangeRateService' => $exchangeRateService,
            'audit'               => $auditService,
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
}
