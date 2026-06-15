<?php

namespace Tests\Property;

use App\Repository\AccountRepository;
use App\Repository\PeopleRepository;
use App\Repository\PurchaseOrderRepository;
use App\Repository\SalesInvoiceRepository;
use App\Services\AuditService;
use App\Services\ExchangeRateService;
use App\Services\ItemService;
use App\Services\PurchaseOrderService;
use App\Services\SalesInvoiceService;
use App\Services\StockValidator;
use App\Support\AuthContext;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Feature: operations-financial-integrity, Properties 17 & 18: Cancellation Guards
 *
 * Property 17: For any RECEIVED purchase order with Payment_Status PARTIALLY_PAID
 * or PAID, cancellation is rejected. For any DELIVERED sales invoice with
 * Payment_Status PARTIALLY_PAID or PAID, cancellation is rejected.
 *
 * Property 18: For any RECEIVED purchase order with Payment_Status UNPAID,
 * cancellation reverses stock IN (decreases stock by line quantities) and reverses
 * the supplier's payee balance change. For any DELIVERED sales invoice with
 * Payment_Status UNPAID, cancellation reverses stock OUT (increases stock by line
 * quantities) and reverses the customer's payee balance change.
 *
 * **Validates: Requirements 13.1, 13.2, 13.3, 13.4**
 */
class CancellationGuardsTest extends TestCase
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
     * Data provider generating 100 RECEIVED POs with various payment statuses.
     *
     * Returns: [totalAmountBc, totalPaid, shouldReject]
     */
    public static function cancellationGuardProvider(): array
    {
        $cases = [];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // PO total: between 100.00 and 50000.00
            $poTotal = round(mt_rand(10000, 5000000) / 100, 2);

            // Decide scenario: UNPAID (33%), PARTIALLY_PAID (34%), PAID (33%)
            $scenario = mt_rand(1, 100);

            if ($scenario <= 33) {
                // UNPAID: no payments — cancellation allowed
                $totalPaid = 0.0;
                $shouldReject = false;
            } elseif ($scenario <= 67) {
                // PARTIALLY_PAID: some payments — cancellation rejected
                $totalPaid = round(mt_rand(1, (int)(($poTotal - 0.01) * 100)) / 100, 2);
                if ($totalPaid >= $poTotal) {
                    $totalPaid = round($poTotal - 0.01, 2);
                }
                if ($totalPaid <= 0) {
                    $totalPaid = 0.01;
                }
                $shouldReject = true;
            } else {
                // PAID: full payment — cancellation rejected
                $overpay = round(mt_rand(0, 10000) / 100, 2);
                $totalPaid = $poTotal + $overpay;
                $shouldReject = true;
            }

            $cases["iter_{$i}_total_{$poTotal}_paid_{$totalPaid}"] = [
                $poTotal,
                $totalPaid,
                $shouldReject,
            ];
        }
        return $cases;
    }

    /**
     * Property 17: Cancellation Guard for Paid Documents — PO portion
     *
     * For any RECEIVED PO with PARTIALLY_PAID or PAID status, cancellation is rejected.
     * For UNPAID POs, cancellation is allowed.
     *
     * **Validates: Requirements 13.1**
     *
     * @dataProvider cancellationGuardProvider
     */
    public function testCancellationRejectedWhenPOHasPayments(
        float $totalAmountBc,
        float $totalPaid,
        bool $shouldReject
    ): void {
        $poId = 42;
        $supplierId = 10;
        $lines = [
            [
                'id'                => 1,
                'purchase_order_id' => $poId,
                'item_id'           => 100,
                'item_name'         => 'Item_100',
                'quantity'          => 5.0,
                'unit_price_fc'     => 10.0,
                'unit_price_bc'     => 10.0,
                'total_price_fc'    => 50.0,
                'total_price_bc'    => 50.0,
                'created_at'        => '2024-01-01',
            ],
        ];

        $service = $this->buildPurchaseOrderServiceForCancellation(
            $poId,
            'RECEIVED',
            $supplierId,
            $lines,
            $totalAmountBc,
            $totalPaid
        );

        $exceptionThrown = false;
        $exceptionMessage = '';

        try {
            $service->updateStatus($poId, 'CANCELLED');
        } catch (\RuntimeException $e) {
            $exceptionThrown = true;
            $exceptionMessage = $e->getMessage();
        }

        if ($shouldReject) {
            $this->assertTrue(
                $exceptionThrown,
                sprintf(
                    'Expected cancellation to be REJECTED (total: %.2f, paid: %.2f) but it succeeded.',
                    $totalAmountBc,
                    $totalPaid
                )
            );
            $this->assertStringContainsString(
                'Cannot cancel: purchase order has associated payments',
                $exceptionMessage,
                sprintf('Expected specific rejection message. Got: "%s"', $exceptionMessage)
            );
        } else {
            $this->assertFalse(
                $exceptionThrown,
                sprintf(
                    'Expected cancellation to SUCCEED (UNPAID, total: %.2f, paid: %.2f) but got: "%s"',
                    $totalAmountBc,
                    $totalPaid,
                    $exceptionMessage
                )
            );
        }
    }

    /**
     * Data provider generating 100 RECEIVED POs with UNPAID status and line items.
     *
     * Returns: [lines, totalAmountBc, supplierId]
     */
    public static function cancellationReversalProvider(): array
    {
        $cases = [];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $numLines = mt_rand(1, 20);
            $supplierId = mt_rand(1, 1000);
            $lines = [];
            $totalBc = 0.0;

            for ($j = 0; $j < $numLines; $j++) {
                $itemId = 100 + $j;
                $quantity = round(mt_rand(1, 10000) / 100, 2);
                $unitPriceBc = round(mt_rand(100, 50000) / 100, 2);
                $totalPriceBc = round($quantity * $unitPriceBc, 2);
                $totalBc += $totalPriceBc;

                $lines[] = [
                    'id'                => $j + 1,
                    'purchase_order_id' => 42,
                    'item_id'           => $itemId,
                    'item_name'         => 'Item_' . $itemId,
                    'quantity'          => $quantity,
                    'unit_price_fc'     => $unitPriceBc,
                    'unit_price_bc'     => $unitPriceBc,
                    'total_price_fc'    => $totalPriceBc,
                    'total_price_bc'    => $totalPriceBc,
                    'created_at'        => '2024-01-01',
                ];
            }

            $totalBc = round($totalBc, 2);

            $cases["iter_{$i}_lines_{$numLines}_supplier_{$supplierId}"] = [
                $lines,
                $totalBc,
                $supplierId,
            ];
        }
        return $cases;
    }

    /**
     * Property 18: Cancellation Reversal of Unpaid Documents — PO portion
     *
     * For any RECEIVED PO with UNPAID status, cancellation reverses stock IN
     * (decreases stock by line quantities) and reverses the supplier's payee
     * balance change (increases balance by total_amount_bc).
     *
     * **Validates: Requirements 13.3**
     *
     * @dataProvider cancellationReversalProvider
     */
    public function testCancellationReversesStockAndBalanceForUnpaidPO(
        array $lines,
        float $totalAmountBc,
        int $supplierId
    ): void {
        $poId = 42;

        // Track stock adjustments and balance changes
        $stockAdjustments = [];
        $balanceChanges = [];

        $service = $this->buildPurchaseOrderServiceForCancellationWithTracking(
            $poId,
            'RECEIVED',
            $supplierId,
            $lines,
            $totalAmountBc,
            0.0, // UNPAID — no payments
            $stockAdjustments,
            $balanceChanges
        );

        $service->updateStatus($poId, 'CANCELLED');

        // Assert: stock decreases by each line's quantity (OUT adjustment)
        foreach ($lines as $line) {
            $itemId = $line['item_id'];
            $expectedQty = (float) $line['quantity'];

            $this->assertArrayHasKey(
                $itemId,
                $stockAdjustments,
                sprintf('Expected stock adjustment for item %d', $itemId)
            );

            $this->assertEquals(
                'OUT',
                $stockAdjustments[$itemId]['type'],
                sprintf('Expected stock OUT for item %d on cancellation', $itemId)
            );

            $this->assertEquals(
                $expectedQty,
                $stockAdjustments[$itemId]['quantity'],
                sprintf(
                    'Expected stock decrease of %.2f for item %d, got %.2f',
                    $expectedQty,
                    $itemId,
                    $stockAdjustments[$itemId]['quantity']
                )
            );
        }

        // Assert: supplier balance reversed (increased by total_amount_bc)
        $this->assertArrayHasKey(
            $supplierId,
            $balanceChanges,
            'Expected balance change for supplier on cancellation'
        );

        $this->assertEquals(
            $totalAmountBc,
            $balanceChanges[$supplierId],
            sprintf(
                'Expected supplier balance to increase by %.2f (reversal), got %.2f',
                $totalAmountBc,
                $balanceChanges[$supplierId]
            )
        );
    }

    // =========================================================================
    // Invoice Cancellation Guard Tests (Property 17 & 18 — Invoice portion)
    // =========================================================================

    /**
     * Data provider generating 100 DELIVERED invoices with various payment statuses.
     *
     * Returns: [totalAmountBc, totalCollected, shouldReject]
     */
    public static function invoiceCancellationGuardProvider(): array
    {
        $cases = [];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Invoice total: between 100.00 and 50000.00
            $invoiceTotal = round(mt_rand(10000, 5000000) / 100, 2);

            // Decide scenario: UNPAID (33%), PARTIALLY_PAID (34%), PAID (33%)
            $scenario = mt_rand(1, 100);

            if ($scenario <= 33) {
                // UNPAID: no collections — cancellation allowed
                $totalCollected = 0.0;
                $shouldReject = false;
            } elseif ($scenario <= 67) {
                // PARTIALLY_PAID: some collections — cancellation rejected
                $totalCollected = round(mt_rand(1, (int)(($invoiceTotal - 0.01) * 100)) / 100, 2);
                if ($totalCollected >= $invoiceTotal) {
                    $totalCollected = round($invoiceTotal - 0.01, 2);
                }
                if ($totalCollected <= 0) {
                    $totalCollected = 0.01;
                }
                $shouldReject = true;
            } else {
                // PAID: full collection — cancellation rejected
                $overpay = round(mt_rand(0, 10000) / 100, 2);
                $totalCollected = $invoiceTotal + $overpay;
                $shouldReject = true;
            }

            $cases["iter_{$i}_inv_total_{$invoiceTotal}_collected_{$totalCollected}"] = [
                $invoiceTotal,
                $totalCollected,
                $shouldReject,
            ];
        }
        return $cases;
    }

    /**
     * Property 17: Cancellation Guard for Paid Documents — Invoice portion
     *
     * For any DELIVERED invoice with PARTIALLY_PAID or PAID status, cancellation is rejected.
     * For UNPAID invoices, cancellation is allowed.
     *
     * **Validates: Requirements 13.2**
     *
     * @dataProvider invoiceCancellationGuardProvider
     */
    public function testCancellationRejectedWhenInvoiceHasCollections(
        float $totalAmountBc,
        float $totalCollected,
        bool $shouldReject
    ): void {
        $invoiceId = 42;
        $customerId = 10;
        $lines = [
            [
                'id'              => 1,
                'invoice_id'      => $invoiceId,
                'item_id'         => 100,
                'item_name'       => 'Item_100',
                'quantity'        => 5.0,
                'unit_price_fc'   => 10.0,
                'unit_price_bc'   => 10.0,
                'total_price_fc'  => 50.0,
                'total_price_bc'  => 50.0,
                'created_at'      => '2024-01-01',
            ],
        ];

        $service = $this->buildSalesInvoiceServiceForCancellation(
            $invoiceId,
            'DELIVERED',
            $customerId,
            $lines,
            $totalAmountBc,
            $totalCollected
        );

        $exceptionThrown = false;
        $exceptionMessage = '';

        try {
            $service->updateStatus($invoiceId, 'CANCELLED');
        } catch (\RuntimeException $e) {
            $exceptionThrown = true;
            $exceptionMessage = $e->getMessage();
        }

        if ($shouldReject) {
            $this->assertTrue(
                $exceptionThrown,
                sprintf(
                    'Expected cancellation to be REJECTED (total: %.2f, collected: %.2f) but it succeeded.',
                    $totalAmountBc,
                    $totalCollected
                )
            );
            $this->assertStringContainsString(
                'Cannot cancel: invoice has associated collections',
                $exceptionMessage,
                sprintf('Expected specific rejection message. Got: "%s"', $exceptionMessage)
            );
        } else {
            $this->assertFalse(
                $exceptionThrown,
                sprintf(
                    'Expected cancellation to SUCCEED (UNPAID, total: %.2f, collected: %.2f) but got: "%s"',
                    $totalAmountBc,
                    $totalCollected,
                    $exceptionMessage
                )
            );
        }
    }

    /**
     * Data provider generating 100 DELIVERED invoices with UNPAID status and line items.
     *
     * Returns: [lines, totalAmountBc, customerId]
     */
    public static function invoiceCancellationReversalProvider(): array
    {
        $cases = [];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $numLines = mt_rand(1, 20);
            $customerId = mt_rand(1, 1000);
            $lines = [];
            $totalBc = 0.0;

            for ($j = 0; $j < $numLines; $j++) {
                $itemId = 100 + $j;
                $quantity = round(mt_rand(1, 10000) / 100, 2);
                $unitPriceBc = round(mt_rand(100, 50000) / 100, 2);
                $totalPriceBc = round($quantity * $unitPriceBc, 2);
                $totalBc += $totalPriceBc;

                $lines[] = [
                    'id'              => $j + 1,
                    'invoice_id'      => 42,
                    'item_id'         => $itemId,
                    'item_name'       => 'Item_' . $itemId,
                    'quantity'        => $quantity,
                    'unit_price_fc'   => $unitPriceBc,
                    'unit_price_bc'   => $unitPriceBc,
                    'total_price_fc'  => $totalPriceBc,
                    'total_price_bc'  => $totalPriceBc,
                    'created_at'      => '2024-01-01',
                ];
            }

            $totalBc = round($totalBc, 2);

            $cases["iter_{$i}_inv_lines_{$numLines}_customer_{$customerId}"] = [
                $lines,
                $totalBc,
                $customerId,
            ];
        }
        return $cases;
    }

    /**
     * Property 18: Cancellation Reversal of Unpaid Documents — Invoice portion
     *
     * For any DELIVERED invoice with UNPAID status, cancellation reverses stock OUT
     * (increases stock by line quantities) and reverses the customer's payee
     * balance change (decreases balance by total_amount_bc).
     *
     * **Validates: Requirements 13.4**
     *
     * @dataProvider invoiceCancellationReversalProvider
     */
    public function testCancellationReversesStockAndBalanceForUnpaidInvoice(
        array $lines,
        float $totalAmountBc,
        int $customerId
    ): void {
        $invoiceId = 42;

        // Track stock adjustments and balance changes
        $stockAdjustments = [];
        $balanceChanges = [];

        $service = $this->buildSalesInvoiceServiceForCancellationWithTracking(
            $invoiceId,
            'DELIVERED',
            $customerId,
            $lines,
            $totalAmountBc,
            0.0, // UNPAID — no collections
            $stockAdjustments,
            $balanceChanges
        );

        $service->updateStatus($invoiceId, 'CANCELLED');

        // Assert: stock increases by each line's quantity (IN adjustment — reversal of OUT)
        foreach ($lines as $line) {
            $itemId = $line['item_id'];
            $expectedQty = (float) $line['quantity'];

            $this->assertArrayHasKey(
                $itemId,
                $stockAdjustments,
                sprintf('Expected stock adjustment for item %d', $itemId)
            );

            $this->assertEquals(
                'IN',
                $stockAdjustments[$itemId]['type'],
                sprintf('Expected stock IN for item %d on cancellation (reversal of OUT)', $itemId)
            );

            $this->assertEquals(
                $expectedQty,
                $stockAdjustments[$itemId]['quantity'],
                sprintf(
                    'Expected stock increase of %.2f for item %d, got %.2f',
                    $expectedQty,
                    $itemId,
                    $stockAdjustments[$itemId]['quantity']
                )
            );
        }

        // Assert: customer balance reversed (decreased by total_amount_bc)
        $this->assertArrayHasKey(
            $customerId,
            $balanceChanges,
            'Expected balance change for customer on cancellation'
        );

        $this->assertEquals(
            -$totalAmountBc,
            $balanceChanges[$customerId],
            sprintf(
                'Expected customer balance to decrease by %.2f (reversal), got %.2f',
                $totalAmountBc,
                $balanceChanges[$customerId]
            )
        );
    }

    // =========================================================================
    // PO Builder Methods
    // =========================================================================

    /**
     * Build a PurchaseOrderService for cancellation guard testing.
     */
    private function buildPurchaseOrderServiceForCancellation(
        int $poId,
        string $currentStatus,
        int $supplierId,
        array $lines,
        float $totalAmountBc,
        float $totalPaid
    ): PurchaseOrderService {
        $stockAdjustments = [];
        $balanceChanges = [];
        return $this->buildPurchaseOrderServiceForCancellationWithTracking(
            $poId,
            $currentStatus,
            $supplierId,
            $lines,
            $totalAmountBc,
            $totalPaid,
            $stockAdjustments,
            $balanceChanges
        );
    }

    /**
     * Build a PurchaseOrderService with tracking for cancellation reversal testing.
     */
    private function buildPurchaseOrderServiceForCancellationWithTracking(
        int $poId,
        string $currentStatus,
        int $supplierId,
        array $lines,
        float $totalAmountBc,
        float $totalPaid,
        array &$stockAdjustments,
        array &$balanceChanges
    ): PurchaseOrderService {
        $businessId = $this->businessId;

        // Mock PurchaseOrderRepository
        $repo = $this->createMock(PurchaseOrderRepository::class);
        $repo->method('findById')
            ->willReturnCallback(function ($id, $bid) use ($poId, $businessId, $currentStatus, $supplierId, $totalAmountBc, $lines) {
                if ($id === $poId && $bid === $businessId) {
                    return [
                        'id'              => $poId,
                        'business_id'     => $businessId,
                        'supplier_id'     => $supplierId,
                        'po_number'       => 'PO-2024-001',
                        'po_date'         => '2024-01-15',
                        'status'          => $currentStatus,
                        'currency'        => 'USD',
                        'exchange_rate'   => 1.0,
                        'total_amount_fc' => $totalAmountBc,
                        'total_amount_bc' => $totalAmountBc,
                        'notes'           => null,
                        'created_at'      => '2024-01-01',
                        'updated_at'      => '2024-01-01',
                        'created_by'      => 1,
                        'lines'           => $lines,
                    ];
                }
                return null;
            });
        $repo->method('getLines')->willReturn($lines);
        $repo->method('updateStatus')->willReturnCallback(function () {});

        // Mock PeopleRepository - track balance changes
        $peopleRepo = $this->createMock(PeopleRepository::class);
        $peopleRepo->method('updatePayeeBalance')
            ->willReturnCallback(function ($payeeId, $delta) use (&$balanceChanges) {
                if (!isset($balanceChanges[$payeeId])) {
                    $balanceChanges[$payeeId] = 0.0;
                }
                $balanceChanges[$payeeId] += $delta;
            });

        // Mock ItemService - track stock adjustments
        $itemService = $this->getMockBuilder(ItemService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['adjustStock'])
            ->getMock();
        $itemService->method('adjustStock')
            ->willReturnCallback(function ($itemId, $type, $quantity) use (&$stockAdjustments) {
                $stockAdjustments[$itemId] = ['type' => $type, 'quantity' => $quantity];
            });

        // Mock AuditService
        $auditService = $this->getMockBuilder(AuditService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['log'])
            ->getMock();

        // Mock ExchangeRateService
        $exchangeRateService = $this->getMockBuilder(ExchangeRateService::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Mock PDO with payment status query
        $paymentStmt = $this->createMock(\PDOStatement::class);
        $paymentStmt->method('execute')->willReturn(true);
        $paymentStmt->method('fetchColumn')->willReturn($totalPaid);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('rollBack')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->method('prepare')->willReturn($paymentStmt);

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

    // =========================================================================
    // Invoice Builder Methods
    // =========================================================================

    /**
     * Build a SalesInvoiceService for cancellation guard testing.
     */
    private function buildSalesInvoiceServiceForCancellation(
        int $invoiceId,
        string $currentStatus,
        int $customerId,
        array $lines,
        float $totalAmountBc,
        float $totalCollected
    ): SalesInvoiceService {
        $stockAdjustments = [];
        $balanceChanges = [];
        return $this->buildSalesInvoiceServiceForCancellationWithTracking(
            $invoiceId,
            $currentStatus,
            $customerId,
            $lines,
            $totalAmountBc,
            $totalCollected,
            $stockAdjustments,
            $balanceChanges
        );
    }

    /**
     * Build a SalesInvoiceService with tracking for cancellation reversal testing.
     */
    private function buildSalesInvoiceServiceForCancellationWithTracking(
        int $invoiceId,
        string $currentStatus,
        int $customerId,
        array $lines,
        float $totalAmountBc,
        float $totalCollected,
        array &$stockAdjustments,
        array &$balanceChanges
    ): SalesInvoiceService {
        $businessId = $this->businessId;

        // Mock SalesInvoiceRepository
        $repo = $this->createMock(SalesInvoiceRepository::class);
        $repo->method('findById')
            ->willReturnCallback(function ($id, $bid) use ($invoiceId, $businessId, $currentStatus, $customerId, $totalAmountBc, $lines) {
                if ($id === $invoiceId && $bid === $businessId) {
                    return [
                        'id'              => $invoiceId,
                        'business_id'     => $businessId,
                        'customer_id'     => $customerId,
                        'invoice_number'  => 'INV-2024-001',
                        'invoice_date'    => '2024-01-15',
                        'status'          => $currentStatus,
                        'currency'        => 'USD',
                        'exchange_rate'   => 1.0,
                        'total_amount_fc' => $totalAmountBc,
                        'total_amount_bc' => $totalAmountBc,
                        'notes'           => null,
                        'created_at'      => '2024-01-01',
                        'updated_at'      => '2024-01-01',
                        'created_by'      => 1,
                        'lines'           => $lines,
                    ];
                }
                return null;
            });
        $repo->method('getLines')->willReturn($lines);
        $repo->method('updateStatus')->willReturnCallback(function () {});

        // Mock PeopleRepository - track balance changes
        $peopleRepo = $this->createMock(PeopleRepository::class);
        $peopleRepo->method('updatePayeeBalance')
            ->willReturnCallback(function ($payeeId, $delta) use (&$balanceChanges) {
                if (!isset($balanceChanges[$payeeId])) {
                    $balanceChanges[$payeeId] = 0.0;
                }
                $balanceChanges[$payeeId] += $delta;
            });

        // Mock ItemService - track stock adjustments
        $itemService = $this->getMockBuilder(ItemService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['adjustStock'])
            ->getMock();
        $itemService->method('adjustStock')
            ->willReturnCallback(function ($itemId, $type, $quantity) use (&$stockAdjustments) {
                $stockAdjustments[$itemId] = ['type' => $type, 'quantity' => $quantity];
            });

        // Mock AuditService
        $auditService = $this->getMockBuilder(AuditService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['log'])
            ->getMock();

        // Mock ExchangeRateService
        $exchangeRateService = $this->getMockBuilder(ExchangeRateService::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Mock AccountRepository
        $accountRepo = $this->createMock(AccountRepository::class);

        // Mock StockValidator
        $stockValidator = $this->getMockBuilder(StockValidator::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Mock PDO with payment status queries (two prepare calls):
        // 1. SELECT total_amount_bc FROM sales_invoices
        // 2. SELECT COALESCE(SUM(amount_bc), 0) FROM collection_slips
        $invoiceTotalStmt = $this->createMock(\PDOStatement::class);
        $invoiceTotalStmt->method('execute')->willReturn(true);
        $invoiceTotalStmt->method('fetchColumn')->willReturn($totalAmountBc);

        $collectionSumStmt = $this->createMock(\PDOStatement::class);
        $collectionSumStmt->method('execute')->willReturn(true);
        $collectionSumStmt->method('fetchColumn')->willReturn($totalCollected);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('rollBack')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->method('prepare')
            ->willReturnOnConsecutiveCalls($invoiceTotalStmt, $collectionSumStmt);

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
