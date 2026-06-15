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
 * Feature: operations-financial-integrity, Properties 7 & 8: Invoice State Machine
 *
 * Property 7: For any sales invoice in any status and for any target status,
 * the transition succeeds if and only if the target is in the allowed set for
 * that source status (DRAFT→{DELIVERED,CANCELLED}, DELIVERED→{CANCELLED with guards}),
 * and transitions to APPROVED or PAID are always rejected.
 *
 * Property 8: For any sales invoice with any set of line items, when the status
 * transitions to DELIVERED, the stock for each line item decreases by exactly the
 * line's quantity, and the customer's payee balance increases by exactly the
 * invoice's total_amount_bc.
 *
 * **Validates: Requirements 6.1, 6.2, 6.3, 6.4, 6.5**
 */
class InvoiceStateMachineTest extends TestCase
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
     * All valid invoice statuses (source states).
     */
    private static function allStatuses(): array
    {
        return ['DRAFT', 'DELIVERED', 'CANCELLED'];
    }

    /**
     * All possible target statuses including invalid ones.
     */
    private static function allTargetStatuses(): array
    {
        return ['DRAFT', 'DELIVERED', 'CANCELLED', 'APPROVED', 'PAID', 'INVALID'];
    }

    /**
     * The allowed transitions map per the requirements.
     */
    private static function allowedTransitions(): array
    {
        return [
            'DRAFT'     => ['DELIVERED', 'CANCELLED'],
            'DELIVERED' => ['CANCELLED'],
        ];
    }

    /**
     * Data provider generating 100 random (source, target) status pairs.
     */
    public static function invoiceStateMachineProvider(): array
    {
        $cases = [];
        $sources = self::allStatuses();
        $targets = self::allTargetStatuses();
        $allowed = self::allowedTransitions();

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $source = $sources[array_rand($sources)];
            $target = $targets[array_rand($targets)];

            // Determine expected outcome
            $shouldSucceed = isset($allowed[$source]) && in_array($target, $allowed[$source], true);

            $cases["iter_{$i}_{$source}_to_{$target}"] = [
                $source,
                $target,
                $shouldSucceed,
            ];
        }
        return $cases;
    }

    /**
     * Property 7: Invoice State Machine Validity
     *
     * For any source status and target status, the transition succeeds iff
     * the target is in the allowed set for that source. APPROVED and PAID
     * are always rejected with specific messages.
     *
     * **Validates: Requirements 6.1, 6.4, 6.5**
     *
     * @dataProvider invoiceStateMachineProvider
     */
    public function testInvoiceTransitionSucceedsIffTargetInAllowedSet(
        string $sourceStatus,
        string $targetStatus,
        bool $shouldSucceed
    ): void {
        $invoiceId = 42;
        $customerId = 10;

        $service = $this->buildSalesInvoiceService(
            $invoiceId,
            $sourceStatus,
            $customerId,
            [],
            100.0,
            0.0 // UNPAID — no collections (allows DELIVERED→CANCELLED)
        );

        $exceptionThrown = false;
        $exceptionMessage = '';

        try {
            $service->updateStatus($invoiceId, $targetStatus);
        } catch (\RuntimeException $e) {
            $exceptionThrown = true;
            $exceptionMessage = $e->getMessage();
        }

        if ($shouldSucceed) {
            $this->assertFalse(
                $exceptionThrown,
                sprintf(
                    'Expected transition %s→%s to SUCCEED but got exception: "%s"',
                    $sourceStatus,
                    $targetStatus,
                    $exceptionMessage
                )
            );
        } else {
            $this->assertTrue(
                $exceptionThrown,
                sprintf(
                    'Expected transition %s→%s to be REJECTED but it succeeded.',
                    $sourceStatus,
                    $targetStatus
                )
            );

            // PAID target should have specific message
            if ($targetStatus === 'PAID') {
                $this->assertStringContainsString(
                    'Payment status is computed from collection slips and cannot be set manually',
                    $exceptionMessage,
                    sprintf(
                        'PAID rejection should have specific message. Got: "%s"',
                        $exceptionMessage
                    )
                );
            }
        }
    }

    /**
     * Data provider generating 100 random invoices with 1-20 line items for DELIVERED transition.
     *
     * Returns: [lineItems, totalAmountBc, customerId]
     */
    public static function invoiceDeliveredSideEffectsProvider(): array
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

            $cases["iter_{$i}_lines_{$numLines}_customer_{$customerId}"] = [
                $lines,
                $totalBc,
                $customerId,
            ];
        }
        return $cases;
    }

    /**
     * Property 8: Invoice DELIVERED Transition Side Effects
     *
     * For any sales invoice with any set of line items, when the status transitions
     * to DELIVERED, the stock for each line item decreases by exactly the line's quantity,
     * and the customer's payee balance increases by exactly the invoice's total_amount_bc.
     *
     * **Validates: Requirements 6.2, 6.3**
     *
     * @dataProvider invoiceDeliveredSideEffectsProvider
     */
    public function testInvoiceDeliveredTransitionAdjustsStockAndBalance(
        array $lines,
        float $totalAmountBc,
        int $customerId
    ): void {
        $invoiceId = 42;

        // Track stock adjustments and balance changes
        $stockAdjustments = [];
        $balanceChanges = [];

        $service = $this->buildSalesInvoiceServiceWithTracking(
            $invoiceId,
            'DRAFT', // Must be DRAFT to transition to DELIVERED
            $customerId,
            $lines,
            $totalAmountBc,
            $stockAdjustments,
            $balanceChanges
        );

        $service->updateStatus($invoiceId, 'DELIVERED');

        // Assert: stock decreases by exactly each line's quantity (OUT)
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
                sprintf('Expected stock OUT for item %d', $itemId)
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

        // Assert: customer balance increases by exactly total_amount_bc
        $this->assertArrayHasKey(
            $customerId,
            $balanceChanges,
            'Expected balance change for customer'
        );

        $this->assertEquals(
            $totalAmountBc,
            $balanceChanges[$customerId],
            sprintf(
                'Expected customer balance to increase by %.2f, got %.2f',
                $totalAmountBc,
                $balanceChanges[$customerId]
            )
        );
    }

    /**
     * Build a SalesInvoiceService with mocked dependencies for state machine testing.
     */
    private function buildSalesInvoiceService(
        int $invoiceId,
        string $currentStatus,
        int $customerId,
        array $lines,
        float $totalAmountBc,
        float $totalCollected
    ): SalesInvoiceService {
        $stockAdjustments = [];
        $balanceChanges = [];
        return $this->buildSalesInvoiceServiceWithTracking(
            $invoiceId,
            $currentStatus,
            $customerId,
            $lines,
            $totalAmountBc,
            $stockAdjustments,
            $balanceChanges,
            $totalCollected
        );
    }

    /**
     * Build a SalesInvoiceService with tracking arrays for side effect verification.
     */
    private function buildSalesInvoiceServiceWithTracking(
        int $invoiceId,
        string $currentStatus,
        int $customerId,
        array $lines,
        float $totalAmountBc,
        array &$stockAdjustments,
        array &$balanceChanges,
        float $totalCollected = 0.0
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

        // Mock PDO for transaction management and payment status query
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('rollBack')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Mock PDOStatement for payment status queries (two prepare calls)
        // First: SELECT total_amount_bc FROM sales_invoices
        // Second: SELECT COALESCE(SUM(amount_bc), 0) FROM collection_slips
        $invoiceTotalStmt = $this->createMock(\PDOStatement::class);
        $invoiceTotalStmt->method('execute')->willReturn(true);
        $invoiceTotalStmt->method('fetchColumn')->willReturn($totalAmountBc);

        $collectionSumStmt = $this->createMock(\PDOStatement::class);
        $collectionSumStmt->method('execute')->willReturn(true);
        $collectionSumStmt->method('fetchColumn')->willReturn($totalCollected);

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
