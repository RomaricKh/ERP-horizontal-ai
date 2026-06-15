<?php

namespace Tests\Property;

use App\Repository\PeopleRepository;
use App\Repository\PurchaseOrderRepository;
use App\Services\AuditService;
use App\Services\ExchangeRateService;
use App\Services\ItemService;
use App\Services\PurchaseOrderService;
use App\Support\AuthContext;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Feature: operations-financial-integrity, Properties 3 & 4: PO Payment Status
 *
 * Property 3: For any RECEIVED purchase order and for any set of payment slips
 * referencing that PO, the computed Payment_Status equals PAID when
 * sum(amount_bc) >= total_amount_bc, PARTIALLY_PAID when 0 < sum < total,
 * and UNPAID when sum = 0.
 *
 * Property 4: For any RECEIVED purchase order, the payment shortcut data contains
 * the PO's supplier_id as payee, total_amount_bc as amount, po_number as reference,
 * reference_type as "PURCHASE_ORDER", and reference_id as the PO ID.
 *
 * **Validates: Requirements 2.1, 2.2, 2.3, 2.4, 3.1, 3.2**
 */
class POPaymentStatusTest extends TestCase
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
     * Data provider generating 100 random PO amounts and payment slip totals.
     *
     * Returns: [poTotalAmountBc, totalPaid, expectedStatus]
     */
    public static function poPaymentStatusProvider(): array
    {
        $cases = [];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // PO total: between 100.00 and 100000.00
            $poTotal = round(mt_rand(10000, 10000000) / 100, 2);

            // Decide scenario randomly: UNPAID (30%), PARTIALLY_PAID (40%), PAID (30%)
            $scenario = mt_rand(1, 100);

            if ($scenario <= 30) {
                // UNPAID: sum = 0
                $totalPaid = 0.0;
                $expectedStatus = 'UNPAID';
            } elseif ($scenario <= 70) {
                // PARTIALLY_PAID: 0 < sum < total
                $totalPaid = round(mt_rand(1, (int)(($poTotal - 0.01) * 100)) / 100, 2);
                // Ensure it's strictly less than total
                if ($totalPaid >= $poTotal) {
                    $totalPaid = round($poTotal - 0.01, 2);
                }
                if ($totalPaid <= 0) {
                    $totalPaid = 0.01;
                }
                $expectedStatus = 'PARTIALLY_PAID';
            } else {
                // PAID: sum >= total
                $overpay = round(mt_rand(0, 10000) / 100, 2);
                $totalPaid = $poTotal + $overpay;
                $expectedStatus = 'PAID';
            }

            $cases["iter_{$i}_total_{$poTotal}_paid_{$totalPaid}"] = [
                $poTotal,
                $totalPaid,
                $expectedStatus,
            ];
        }
        return $cases;
    }

    /**
     * Property 3: PO Payment Status Computation
     *
     * For any RECEIVED purchase order and for any set of payment slips:
     * - PAID when sum(amount_bc) >= total_amount_bc
     * - PARTIALLY_PAID when 0 < sum(amount_bc) < total_amount_bc
     * - UNPAID when sum(amount_bc) = 0
     *
     * **Validates: Requirements 2.1, 2.2, 2.3, 2.4**
     *
     * @dataProvider poPaymentStatusProvider
     */
    public function testPOPaymentStatusComputedCorrectly(
        float $poTotalAmountBc,
        float $totalPaid,
        string $expectedStatus
    ): void {
        $poId = 42;
        $supplierId = 10;

        $service = $this->buildPurchaseOrderServiceForPaymentStatus(
            $poId,
            'RECEIVED',
            $supplierId,
            $poTotalAmountBc,
            $totalPaid
        );

        $status = $service->computePaymentStatus($poId);

        $this->assertSame(
            $expectedStatus,
            $status,
            sprintf(
                'Expected payment status "%s" for PO total %.2f with %.2f paid, got "%s"',
                $expectedStatus,
                $poTotalAmountBc,
                $totalPaid,
                $status
            )
        );
    }

    /**
     * Data provider generating 100 random RECEIVED POs for payment shortcut data.
     *
     * Returns: [poId, supplierId, totalAmountBc, poNumber]
     */
    public static function poPaymentShortcutProvider(): array
    {
        $cases = [];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $poId = mt_rand(1, 10000);
            $supplierId = mt_rand(1, 5000);
            $totalAmountBc = round(mt_rand(100, 10000000) / 100, 2);
            $poNumber = 'PO-2024-' . str_pad((string) mt_rand(1, 999), 3, '0', STR_PAD_LEFT);

            $cases["iter_{$i}_po_{$poId}_supplier_{$supplierId}"] = [
                $poId,
                $supplierId,
                $totalAmountBc,
                $poNumber,
            ];
        }
        return $cases;
    }

    /**
     * Property 4: PO Payment Shortcut Data Correctness
     *
     * For any RECEIVED purchase order, the payment shortcut data contains:
     * - supplier_id as payee_id
     * - total_amount_bc as amount
     * - po_number as reference
     * - "PURCHASE_ORDER" as reference_type
     * - PO ID as reference_id
     *
     * **Validates: Requirements 3.1, 3.2**
     *
     * @dataProvider poPaymentShortcutProvider
     */
    public function testPOPaymentShortcutDataCorrect(
        int $poId,
        int $supplierId,
        float $totalAmountBc,
        string $poNumber
    ): void {
        $service = $this->buildPurchaseOrderServiceForShortcut(
            $poId,
            'RECEIVED',
            $supplierId,
            $totalAmountBc,
            $poNumber
        );

        $shortcut = $service->getPaymentShortcutData($poId);

        $this->assertSame(
            $supplierId,
            $shortcut['payee_id'],
            sprintf('Expected payee_id %d, got %s', $supplierId, var_export($shortcut['payee_id'], true))
        );

        $this->assertEquals(
            $totalAmountBc,
            $shortcut['amount_fc'],
            sprintf('Expected amount_fc %.2f, got %.2f', $totalAmountBc, $shortcut['amount_fc'])
        );

        $this->assertSame(
            $poNumber,
            $shortcut['reference'],
            sprintf('Expected reference "%s", got "%s"', $poNumber, $shortcut['reference'])
        );

        $this->assertSame(
            'PURCHASE_ORDER',
            $shortcut['reference_type'],
            sprintf('Expected reference_type "PURCHASE_ORDER", got "%s"', $shortcut['reference_type'])
        );

        $this->assertSame(
            $poId,
            $shortcut['reference_id'],
            sprintf('Expected reference_id %d, got %s', $poId, var_export($shortcut['reference_id'], true))
        );
    }

    /**
     * Build a PurchaseOrderService for payment status computation testing.
     */
    private function buildPurchaseOrderServiceForPaymentStatus(
        int $poId,
        string $status,
        int $supplierId,
        float $totalAmountBc,
        float $totalPaid
    ): PurchaseOrderService {
        $businessId = $this->businessId;

        // Mock PurchaseOrderRepository
        $repo = $this->createMock(PurchaseOrderRepository::class);
        $repo->method('findById')
            ->willReturnCallback(function ($id, $bid) use ($poId, $businessId, $status, $supplierId, $totalAmountBc) {
                if ($id === $poId && $bid === $businessId) {
                    return [
                        'id'              => $poId,
                        'business_id'     => $businessId,
                        'supplier_id'     => $supplierId,
                        'po_number'       => 'PO-2024-001',
                        'po_date'         => '2024-01-15',
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

        // Mock PDO with payment sum query
        $paymentStmt = $this->createMock(\PDOStatement::class);
        $paymentStmt->method('execute')->willReturn(true);
        $paymentStmt->method('fetchColumn')->willReturn($totalPaid);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($paymentStmt);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Mock other dependencies
        $peopleRepo = $this->createMock(PeopleRepository::class);
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

        // Build service using reflection
        $ref = new ReflectionClass(PurchaseOrderService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $props = [
            'db'                  => $pdo,
            'repo'                => $repo,
            'peopleRepo'          => $peopleRepo,
            'exchangeRateService' => $exchangeRateService,
            'audit'               => $auditService,
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
     * Build a PurchaseOrderService for payment shortcut data testing.
     */
    private function buildPurchaseOrderServiceForShortcut(
        int $poId,
        string $status,
        int $supplierId,
        float $totalAmountBc,
        string $poNumber
    ): PurchaseOrderService {
        $businessId = $this->businessId;

        // Mock PurchaseOrderRepository
        $repo = $this->createMock(PurchaseOrderRepository::class);
        $repo->method('findById')
            ->willReturnCallback(function ($id, $bid) use ($poId, $businessId, $status, $supplierId, $totalAmountBc, $poNumber) {
                if ($id === $poId && $bid === $businessId) {
                    return [
                        'id'              => $poId,
                        'business_id'     => $businessId,
                        'supplier_id'     => $supplierId,
                        'po_number'       => $poNumber,
                        'po_date'         => '2024-01-15',
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

        // Mock PDO — need to handle the payment_allocations query
        $allocStmt = $this->createMock(\PDOStatement::class);
        $allocStmt->method('execute')->willReturn(true);
        $allocStmt->method('fetchColumn')->willReturn(0.0); // No prior payments

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($allocStmt);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Mock other dependencies
        $peopleRepo = $this->createMock(PeopleRepository::class);
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

        // Build service using reflection
        $ref = new ReflectionClass(PurchaseOrderService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $props = [
            'db'                  => $pdo,
            'repo'                => $repo,
            'peopleRepo'          => $peopleRepo,
            'exchangeRateService' => $exchangeRateService,
            'audit'               => $auditService,
            'itemService'         => $itemService,
        ];

        foreach ($props as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($service, $value);
        }

        return $service;
    }
}
