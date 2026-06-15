<?php

namespace Tests\Unit;

use App\Services\SettlementEngine;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Unit test verifying the settlement engine correctly uses allocation-based logic
 * to determine when POs are fully paid (CLOSED).
 *
 * After the migration to payment_allocations, the settlement engine queries
 * payment_allocations.amount_bc WHERE reference_type = 'PURCHASE_ORDER' to determine
 * if a PO is fully covered.
 *
 * Validates: Requirements REQ-004, REQ-021
 */
class PaymentSlipReferenceTypeTest extends TestCase
{
    private int $businessId = 1;
    private int $payeeId = 10;

    /**
     * When payment allocations fully cover a PO's total_amount_bc,
     * the settlement engine should identify the PO as settled.
     */
    public function testFullyAllocatedPOIsSettled(): void
    {
        $engine = $this->buildSettlementEngine([
            ['id' => 1, 'total_amount_bc' => 5000.00],
        ], [5000.00]); // allocation sum = 5000 (fully covered)

        $settledIds = $engine->evaluateSupplierSettlement($this->payeeId, $this->businessId);

        $this->assertSame([1], $settledIds);
    }

    /**
     * When payment allocations only partially cover a PO,
     * the settlement engine should NOT include it in settled list.
     */
    public function testPartiallyAllocatedPOIsNotSettled(): void
    {
        $engine = $this->buildSettlementEngine([
            ['id' => 1, 'total_amount_bc' => 5000.00],
        ], [2500.00]); // allocation sum = 2500 (partial)

        $settledIds = $engine->evaluateSupplierSettlement($this->payeeId, $this->businessId);

        $this->assertEmpty($settledIds);
    }

    /**
     * When multiple POs exist, only fully-covered ones are settled.
     */
    public function testMultiplePOsOnlyFullyCoveredAreSettled(): void
    {
        $engine = $this->buildSettlementEngine([
            ['id' => 10, 'total_amount_bc' => 2000.00],
            ['id' => 11, 'total_amount_bc' => 1500.00],
            ['id' => 12, 'total_amount_bc' => 1500.00],
        ], [2000.00, 1500.00, 750.00]); // PO 10 & 11 fully covered, PO 12 partial

        $settledIds = $engine->evaluateSupplierSettlement($this->payeeId, $this->businessId);

        sort($settledIds);
        $this->assertSame([10, 11], $settledIds);
    }

    /**
     * Verify that the settlement engine queries payment_allocations (not payment_slips)
     * with reference_type = 'PURCHASE_ORDER'.
     */
    public function testSettlementQueryUsesPaymentAllocationsTable(): void
    {
        $capturedQueries = [];

        // Mock PDOStatement for POs query
        $posStmt = $this->createMock(PDOStatement::class);
        $posStmt->method('execute')->willReturn(true);
        $posStmt->method('fetchAll')->willReturn([
            ['id' => 1, 'total_amount_bc' => 1000.00],
        ]);

        // Mock PDOStatement for allocation sum query
        $allocStmt = $this->createMock(PDOStatement::class);
        $allocStmt->method('execute')->willReturn(true);
        $allocStmt->method('fetchColumn')->willReturn(500.00);

        $pdo = $this->createMock(PDO::class);
        $callIndex = 0;
        $stmts = [$posStmt, $allocStmt];

        $pdo->method('prepare')
            ->willReturnCallback(function (string $sql) use (&$callIndex, $stmts, &$capturedQueries) {
                $capturedQueries[] = $sql;
                $result = $stmts[$callIndex] ?? $stmts[count($stmts) - 1];
                $callIndex++;
                return $result;
            });

        $engine = new SettlementEngine($pdo);
        $engine->evaluateSupplierSettlement($this->payeeId, $this->businessId);

        // Should have 2 queries: POs query + allocation sum query
        $this->assertCount(2, $capturedQueries);

        // The second query should reference payment_allocations table
        $allocQuery = $capturedQueries[1];
        $this->assertStringContainsString('payment_allocations', $allocQuery);
        $this->assertStringContainsString('reference_type', $allocQuery);
        // Should NOT reference payment_slips table directly
        $this->assertStringNotContainsString('payment_slips', $allocQuery);
    }

    /**
     * Build a SettlementEngine with mocked PDO for supplier settlement testing.
     *
     * @param array $purchaseOrders Array of [id, total_amount_bc]
     * @param array $allocationSums Array of allocation sums (one per PO, in order)
     */
    private function buildSettlementEngine(array $purchaseOrders, array $allocationSums): SettlementEngine
    {
        $posStmt = $this->createMock(PDOStatement::class);
        $posStmt->method('execute')->willReturn(true);
        $posStmt->method('fetchAll')->willReturn($purchaseOrders);

        $allocStmts = [];
        foreach ($allocationSums as $sum) {
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            $stmt->method('fetchColumn')->willReturn($sum);
            $allocStmts[] = $stmt;
        }

        $pdo = $this->createMock(PDO::class);
        $callIndex = 0;
        $prepareResults = array_merge([$posStmt], $allocStmts);

        $pdo->method('prepare')
            ->willReturnCallback(function (string $sql) use (&$callIndex, $prepareResults) {
                $result = $prepareResults[$callIndex] ?? $prepareResults[count($prepareResults) - 1];
                $callIndex++;
                return $result;
            });

        return new SettlementEngine($pdo);
    }
}
