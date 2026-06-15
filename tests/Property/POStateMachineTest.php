<?php

namespace Tests\Property;

use App\Repository\PeopleRepository;
use App\Repository\PurchaseOrderRepository;
use App\Services\AuditService;
use App\Services\ExchangeRateService;
use App\Services\ItemService;
use App\Services\PriceUpdater;
use App\Services\PurchaseOrderService;
use App\Support\AuthContext;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Feature: operations-financial-integrity, Properties 1 & 2: PO State Machine
 *
 * Property 1: For any purchase order in any status and for any target status,
 * the transition succeeds if and only if the target is in the allowed set for
 * that source status (DRAFT→{APPROVED,CANCELLED}, APPROVED→{RECEIVED,CANCELLED},
 * RECEIVED→{CANCELLED}), and all other transitions are rejected.
 * CLOSED is always rejected with specific message.
 *
 * Property 2: For any purchase order with any set of line items, when the status
 * transitions to RECEIVED, the stock for each line item increases by exactly the
 * line's quantity, and the supplier's payee balance decreases by exactly the PO's
 * total_amount_bc.
 *
 * **Validates: Requirements 1.1, 1.2, 1.3, 1.4, 1.5**
 */
class POStateMachineTest extends TestCase
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
     * All valid PO statuses (source states).
     */
    private static function allStatuses(): array
    {
        return ['DRAFT', 'APPROVED', 'RECEIVED', 'CANCELLED', 'CLOSED'];
    }

    /**
     * All possible target statuses including invalid ones.
     */
    private static function allTargetStatuses(): array
    {
        return ['DRAFT', 'APPROVED', 'RECEIVED', 'CANCELLED', 'CLOSED', 'PAID', 'INVALID'];
    }

    /**
     * The allowed transitions map per the requirements.
     */
    private static function allowedTransitions(): array
    {
        return [
            'DRAFT'    => ['APPROVED', 'CANCELLED'],
            'APPROVED' => ['RECEIVED', 'CANCELLED'],
            'RECEIVED' => ['CANCELLED'],
        ];
    }

    /**
     * Data provider generating 100 random (source, target) status pairs.
     */
    public static function poStateMachineProvider(): array
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
     * Property 1: PO State Machine Validity
     *
     * For any source status and target status, the transition succeeds iff
     * the target is in the allowed set for that source. CLOSED is always
     * rejected with a specific message.
     *
     * **Validates: Requirements 1.1, 1.2, 1.4, 1.5**
     *
     * @dataProvider poStateMachineProvider
     */
    public function testPOTransitionSucceedsIffTargetInAllowedSet(
        string $sourceStatus,
        string $targetStatus,
        bool $shouldSucceed
    ): void {
        $poId = 42;
        $supplierId = 10;

        $service = $this->buildPurchaseOrderService(
            $poId,
            $sourceStatus,
            $supplierId,
            [],
            0.0 // no payments for basic state machine test
        );

        $exceptionThrown = false;
        $exceptionMessage = '';

        try {
            $service->updateStatus($poId, $targetStatus);
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

            // CLOSED target should have specific message
            if ($targetStatus === 'CLOSED') {
                $this->assertStringContainsString(
                    'CLOSED status is no longer valid',
                    $exceptionMessage,
                    sprintf(
                        'CLOSED rejection should have specific message. Got: "%s"',
                        $exceptionMessage
                    )
                );
            }
        }
    }

    /**
     * Data provider generating 100 random POs with 1-20 line items for RECEIVED transition.
     *
     * Returns: [lineItems, totalAmountBc, supplierId]
     */
    public static function poReceivedSideEffectsProvider(): array
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
     * Property 2: PO RECEIVED Transition Side Effects
     *
     * For any purchase order with any set of line items, when the status transitions
     * to RECEIVED, the stock for each line item increases by exactly the line's quantity,
     * and the supplier's payee balance decreases by exactly the PO's total_amount_bc.
     *
     * **Validates: Requirements 1.3**
     *
     * @dataProvider poReceivedSideEffectsProvider
     */
    public function testPOReceivedTransitionAdjustsStockAndBalance(
        array $lines,
        float $totalAmountBc,
        int $supplierId
    ): void {
        $poId = 42;

        // Track stock adjustments and balance changes
        $stockAdjustments = [];
        $balanceChanges = [];

        $service = $this->buildPurchaseOrderServiceWithTracking(
            $poId,
            'APPROVED', // Must be APPROVED to transition to RECEIVED
            $supplierId,
            $lines,
            $totalAmountBc,
            $stockAdjustments,
            $balanceChanges
        );

        $service->updateStatus($poId, 'RECEIVED');

        // Assert: stock increases by exactly each line's quantity
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
                sprintf('Expected stock IN for item %d', $itemId)
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

        // Assert: supplier balance decreases by exactly total_amount_bc
        $this->assertArrayHasKey(
            $supplierId,
            $balanceChanges,
            'Expected balance change for supplier'
        );

        $this->assertEquals(
            -$totalAmountBc,
            $balanceChanges[$supplierId],
            sprintf(
                'Expected supplier balance to decrease by %.2f, got %.2f',
                $totalAmountBc,
                $balanceChanges[$supplierId]
            )
        );
    }

    /**
     * Build a PurchaseOrderService with mocked dependencies for state machine testing.
     */
    private function buildPurchaseOrderService(
        int $poId,
        string $currentStatus,
        int $supplierId,
        array $lines,
        float $totalPaid
    ): PurchaseOrderService {
        $stockAdjustments = [];
        $balanceChanges = [];
        return $this->buildPurchaseOrderServiceWithTracking(
            $poId,
            $currentStatus,
            $supplierId,
            $lines,
            100.0, // default total
            $stockAdjustments,
            $balanceChanges,
            $totalPaid
        );
    }

    /**
     * Build a PurchaseOrderService with tracking arrays for side effect verification.
     */
    private function buildPurchaseOrderServiceWithTracking(
        int $poId,
        string $currentStatus,
        int $supplierId,
        array $lines,
        float $totalAmountBc,
        array &$stockAdjustments,
        array &$balanceChanges,
        float $totalPaid = 0.0
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

        // Mock PDO for transaction management and payment status query
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('rollBack')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Mock PDOStatement for payment status query
        $paymentStmt = $this->createMock(\PDOStatement::class);
        $paymentStmt->method('execute')->willReturn(true);
        $paymentStmt->method('fetchColumn')->willReturn($totalPaid);

        $pdo->method('prepare')
            ->willReturn($paymentStmt);

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
