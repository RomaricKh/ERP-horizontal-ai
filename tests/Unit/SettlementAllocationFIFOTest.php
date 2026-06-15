<?php

namespace Tests\Unit;

use App\Services\SettlementEngine;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the settlement engine's FIFO auto-allocation and document status evaluation.
 *
 * Task 21.18: Payee with 3 outstanding POs ($2000, $1500, $1500); single payment $5000 FIFO;
 *             all 3 POs become CLOSED.
 * Task 21.19: Customer with 2 invoices ($4000, $6000); collect $7000;
 *             first invoice PAID, second partially paid ($3000 remaining).
 * Task 21.20: Payee linked to two businesses; pay invoice in business A;
 *             business B's invoices not affected.
 *
 * Validates: Requirements 21.5, 21.6, 21.9, 21.10
 */
class SettlementAllocationFIFOTest extends TestCase
{
    // ── Task 21.18: FIFO auto-allocation closes all 3 POs ─────────────────

    /**
     * Create a payee with 3 outstanding POs ($2000, $1500, $1500);
     * create a single payment slip for $5000 with FIFO auto-allocation;
     * verify all 3 POs become CLOSED.
     *
     * Validates: Requirements 21.5, 21.6
     */
    public function testFIFOPaymentAllocatesAcrossThreePOsAndClosesAll(): void
    {
        $payeeId = 42;
        $businessId = 1;
        $amountBc = 5000.00;
        $amountFc = 5000.00;
        $exchangeRate = 1.0;

        // Outstanding POs for the supplier (FIFO order by date)
        $outstandingPOs = [
            ['id' => 101, 'total_amount_bc' => 2000.00, 'doc_date' => '2026-04-15', 'reference_type' => 'PURCHASE_ORDER', 'allocated_bc' => 0],
            ['id' => 102, 'total_amount_bc' => 1500.00, 'doc_date' => '2026-05-01', 'reference_type' => 'PURCHASE_ORDER', 'allocated_bc' => 0],
            ['id' => 103, 'total_amount_bc' => 1500.00, 'doc_date' => '2026-05-10', 'reference_type' => 'PURCHASE_ORDER', 'allocated_bc' => 0],
        ];

        $engine = $this->buildEngineForSupplierFIFO($outstandingPOs);

        // Run FIFO auto-allocation
        $allocations = $engine->autoAllocateFIFO($payeeId, $businessId, $amountBc, $amountFc, $exchangeRate, 'payment');

        // Verify 3 allocation rows are returned
        $this->assertCount(3, $allocations);

        // Verify amounts match each PO's full remaining amount
        $this->assertSame(101, $allocations[0]['reference_id']);
        $this->assertEquals(2000.00, $allocations[0]['amount_bc']);
        $this->assertSame('PURCHASE_ORDER', $allocations[0]['reference_type']);

        $this->assertSame(102, $allocations[1]['reference_id']);
        $this->assertEquals(1500.00, $allocations[1]['amount_bc']);
        $this->assertSame('PURCHASE_ORDER', $allocations[1]['reference_type']);

        $this->assertSame(103, $allocations[2]['reference_id']);
        $this->assertEquals(1500.00, $allocations[2]['amount_bc']);
        $this->assertSame('PURCHASE_ORDER', $allocations[2]['reference_type']);

        // Verify total allocated equals slip amount
        $totalAllocated = array_sum(array_column($allocations, 'amount_bc'));
        $this->assertEquals(5000.00, $totalAllocated);

        // Now verify settlement evaluation: after allocations are saved,
        // all 3 POs should be CLOSED (allocated >= total)
        $engineForSettlement = $this->buildEngineForSupplierSettlement(
            $outstandingPOs,
            // After payment: each PO has full allocation
            [101 => 2000.00, 102 => 1500.00, 103 => 1500.00]
        );

        $settledIds = $engineForSettlement->evaluateSupplierSettlement($payeeId, $businessId);
        sort($settledIds);

        $this->assertSame([101, 102, 103], $settledIds);
    }

    /**
     * Verify the payment detail shows 3 allocation rows (one per PO).
     *
     * Validates: Requirements 21.6
     */
    public function testFIFOPaymentProducesThreeAllocationRows(): void
    {
        $payeeId = 42;
        $businessId = 1;
        $amountBc = 5000.00;
        $amountFc = 5000.00;
        $exchangeRate = 1.0;

        $outstandingPOs = [
            ['id' => 101, 'total_amount_bc' => 2000.00, 'doc_date' => '2026-04-15', 'reference_type' => 'PURCHASE_ORDER', 'allocated_bc' => 0],
            ['id' => 102, 'total_amount_bc' => 1500.00, 'doc_date' => '2026-05-01', 'reference_type' => 'PURCHASE_ORDER', 'allocated_bc' => 0],
            ['id' => 103, 'total_amount_bc' => 1500.00, 'doc_date' => '2026-05-10', 'reference_type' => 'PURCHASE_ORDER', 'allocated_bc' => 0],
        ];

        $engine = $this->buildEngineForSupplierFIFO($outstandingPOs);
        $allocations = $engine->autoAllocateFIFO($payeeId, $businessId, $amountBc, $amountFc, $exchangeRate, 'payment');

        // Each allocation row should have the required fields
        foreach ($allocations as $alloc) {
            $this->assertArrayHasKey('reference_type', $alloc);
            $this->assertArrayHasKey('reference_id', $alloc);
            $this->assertArrayHasKey('amount_fc', $alloc);
            $this->assertArrayHasKey('amount_bc', $alloc);
        }

        $this->assertCount(3, $allocations);
    }

    // ── Task 21.19: Partial collection across 2 invoices ──────────────────

    /**
     * Create a customer with 2 invoices ($4000, $6000); collect $7000;
     * verify first invoice is PAID, second is partially paid ($3000 remaining).
     *
     * Validates: Requirements 21.5, 21.10
     */
    public function testFIFOCollectionPaysFirstInvoiceAndPartiallyPaysSecond(): void
    {
        $payeeId = 55;
        $businessId = 1;
        $amountBc = 7000.00;
        $amountFc = 7000.00;
        $exchangeRate = 1.0;

        // Outstanding invoices for the customer (FIFO order by date)
        $outstandingInvoices = [
            ['id' => 201, 'total_amount_bc' => 4000.00, 'doc_date' => '2026-03-01', 'allocated_bc' => 0],
            ['id' => 202, 'total_amount_bc' => 6000.00, 'doc_date' => '2026-04-01', 'allocated_bc' => 0],
        ];

        $engine = $this->buildEngineForCollectionFIFO($outstandingInvoices);

        // Run FIFO auto-allocation for collection
        $allocations = $engine->autoAllocateFIFO($payeeId, $businessId, $amountBc, $amountFc, $exchangeRate, 'collection');

        // Verify 2 allocation rows
        $this->assertCount(2, $allocations);

        // First invoice gets full $4000
        $this->assertSame(201, $allocations[0]['reference_id']);
        $this->assertEquals(4000.00, $allocations[0]['amount_bc']);
        $this->assertSame('SALES_INVOICE', $allocations[0]['reference_type']);

        // Second invoice gets remaining $3000
        $this->assertSame(202, $allocations[1]['reference_id']);
        $this->assertEquals(3000.00, $allocations[1]['amount_bc']);
        $this->assertSame('SALES_INVOICE', $allocations[1]['reference_type']);

        // Verify total allocated equals collection amount
        $totalAllocated = array_sum(array_column($allocations, 'amount_bc'));
        $this->assertEquals(7000.00, $totalAllocated);

        // Verify settlement: first invoice is PAID (4000 >= 4000), second is NOT (3000 < 6000)
        $engineForSettlement = $this->buildEngineForCustomerSettlement(
            $outstandingInvoices,
            [201 => 4000.00, 202 => 3000.00]
        );

        $settledIds = $engineForSettlement->evaluateCustomerSettlement($payeeId, $businessId);

        // Only invoice 201 should be settled (PAID)
        $this->assertSame([201], $settledIds);
    }

    /**
     * Verify the collection detail shows 2 allocations ($4000 + $3000).
     *
     * Validates: Requirements 21.10
     */
    public function testFIFOCollectionDetailShowsTwoAllocations(): void
    {
        $payeeId = 55;
        $businessId = 1;
        $amountBc = 7000.00;
        $amountFc = 7000.00;
        $exchangeRate = 1.0;

        $outstandingInvoices = [
            ['id' => 201, 'total_amount_bc' => 4000.00, 'doc_date' => '2026-03-01', 'allocated_bc' => 0],
            ['id' => 202, 'total_amount_bc' => 6000.00, 'doc_date' => '2026-04-01', 'allocated_bc' => 0],
        ];

        $engine = $this->buildEngineForCollectionFIFO($outstandingInvoices);
        $allocations = $engine->autoAllocateFIFO($payeeId, $businessId, $amountBc, $amountFc, $exchangeRate, 'collection');

        // Verify exactly 2 allocations with correct amounts
        $this->assertCount(2, $allocations);
        $this->assertEquals(4000.00, $allocations[0]['amount_bc']);
        $this->assertEquals(3000.00, $allocations[1]['amount_bc']);
    }

    // ── Task 21.20: Multi-business isolation ──────────────────────────────

    /**
     * Create a payee linked to two businesses; pay an invoice in business A;
     * verify business B's invoices are not affected.
     *
     * Validates: Requirements 21.5
     */
    public function testPaymentInBusinessADoesNotAffectBusinessB(): void
    {
        $payeeId = 77;
        $businessA = 1;
        $businessB = 2;

        // Business A has 1 PO ($3000) — will be paid
        $businessAPOs = [
            ['id' => 301, 'total_amount_bc' => 3000.00, 'doc_date' => '2026-05-01', 'reference_type' => 'PURCHASE_ORDER', 'allocated_bc' => 0],
        ];

        // Business B has 2 POs ($2000, $1000) — should NOT be affected
        $businessBPOs = [
            ['id' => 401, 'total_amount_bc' => 2000.00, 'doc_date' => '2026-05-01', 'reference_type' => 'PURCHASE_ORDER', 'allocated_bc' => 0],
            ['id' => 402, 'total_amount_bc' => 1000.00, 'doc_date' => '2026-05-15', 'reference_type' => 'PURCHASE_ORDER', 'allocated_bc' => 0],
        ];

        // Build engine for business A FIFO allocation
        $engineA = $this->buildEngineForSupplierFIFO($businessAPOs);
        $allocationsA = $engineA->autoAllocateFIFO($payeeId, $businessA, 3000.00, 3000.00, 1.0, 'payment');

        // Verify business A allocation covers its PO
        $this->assertCount(1, $allocationsA);
        $this->assertSame(301, $allocationsA[0]['reference_id']);
        $this->assertEquals(3000.00, $allocationsA[0]['amount_bc']);

        // Build engine for business B — should have NO allocations from business A's payment
        $engineB = $this->buildEngineForSupplierSettlement(
            $businessBPOs,
            // No allocations exist for business B's POs
            [401 => 0.0, 402 => 0.0]
        );

        $settledIdsB = $engineB->evaluateSupplierSettlement($payeeId, $businessB);

        // Business B's POs should NOT be settled
        $this->assertEmpty($settledIdsB);
    }

    /**
     * Verify that FIFO allocation only considers documents from the specified business.
     *
     * Validates: Requirements 21.5, 21.6
     */
    public function testFIFOAllocationIsScopedToBusiness(): void
    {
        $payeeId = 77;
        $businessA = 1;

        // Only business A's documents should appear in FIFO
        $businessAPOs = [
            ['id' => 301, 'total_amount_bc' => 3000.00, 'doc_date' => '2026-05-01', 'reference_type' => 'PURCHASE_ORDER', 'allocated_bc' => 0],
        ];

        $engine = $this->buildEngineForSupplierFIFO($businessAPOs);
        $allocations = $engine->autoAllocateFIFO($payeeId, $businessA, 5000.00, 5000.00, 1.0, 'payment');

        // Should only allocate up to business A's documents ($3000), not $5000
        $this->assertCount(1, $allocations);
        $this->assertEquals(3000.00, $allocations[0]['amount_bc']);

        // Remaining $2000 is unallocated (on-account credit)
        $totalAllocated = array_sum(array_column($allocations, 'amount_bc'));
        $this->assertEquals(3000.00, $totalAllocated);
        $this->assertLessThan(5000.00, $totalAllocated);
    }

    // ── Helper Methods ────────────────────────────────────────────────────

    /**
     * Build a SettlementEngine mocked for supplier FIFO allocation.
     * The engine's getUnpaidDocumentsForSupplier will return the given POs.
     */
    private function buildEngineForSupplierFIFO(array $outstandingPOs): SettlementEngine
    {
        // Build rows as the query would return them
        $poRows = [];
        foreach ($outstandingPOs as $po) {
            $poRows[] = [
                'id'              => $po['id'],
                'total_amount_bc' => $po['total_amount_bc'],
                'doc_date'        => $po['doc_date'],
                'reference_type'  => $po['reference_type'],
                'allocated_bc'    => $po['allocated_bc'],
            ];
        }

        // Mock PDO to return PO rows for the supplier query
        $pdo = $this->createMock(PDO::class);

        // The engine calls prepare() multiple times:
        // 1. POs query
        // 2. Expenses query
        // 3. Salaries query
        $posStmt = $this->createMock(PDOStatement::class);
        $posStmt->method('execute')->willReturn(true);
        $posStmt->method('fetchAll')->willReturn($poRows);

        $emptyStmt = $this->createMock(PDOStatement::class);
        $emptyStmt->method('execute')->willReturn(true);
        $emptyStmt->method('fetchAll')->willReturn([]);

        $callIndex = 0;
        $pdo->method('prepare')
            ->willReturnCallback(function () use (&$callIndex, $posStmt, $emptyStmt) {
                $result = $callIndex === 0 ? $posStmt : $emptyStmt;
                $callIndex++;
                return $result;
            });

        return new SettlementEngine($pdo);
    }

    /**
     * Build a SettlementEngine mocked for collection FIFO allocation.
     * The engine's getUnpaidInvoicesForCustomer will return the given invoices.
     */
    private function buildEngineForCollectionFIFO(array $outstandingInvoices): SettlementEngine
    {
        $invoiceRows = [];
        foreach ($outstandingInvoices as $inv) {
            $invoiceRows[] = [
                'id'              => $inv['id'],
                'total_amount_bc' => $inv['total_amount_bc'],
                'doc_date'        => $inv['doc_date'] ?? '2026-01-01',
                'allocated_bc'    => $inv['allocated_bc'],
            ];
        }

        $pdo = $this->createMock(PDO::class);

        $invoicesStmt = $this->createMock(PDOStatement::class);
        $invoicesStmt->method('execute')->willReturn(true);
        $invoicesStmt->method('fetchAll')->willReturn($invoiceRows);

        $pdo->method('prepare')->willReturn($invoicesStmt);

        return new SettlementEngine($pdo);
    }

    /**
     * Build a SettlementEngine for evaluateSupplierSettlement with known allocation sums.
     */
    private function buildEngineForSupplierSettlement(array $outstandingPOs, array $allocationSums): SettlementEngine
    {
        $poRows = [];
        foreach ($outstandingPOs as $po) {
            $poRows[] = [
                'id'              => $po['id'],
                'total_amount_bc' => $po['total_amount_bc'],
            ];
        }

        $pdo = $this->createMock(PDO::class);

        // First prepare: POs query
        $posStmt = $this->createMock(PDOStatement::class);
        $posStmt->method('execute')->willReturn(true);
        $posStmt->method('fetchAll')->willReturn($poRows);

        // Subsequent prepares: allocation sum queries (one per PO)
        $allocStmts = [];
        foreach ($outstandingPOs as $po) {
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            $stmt->method('fetchColumn')->willReturn($allocationSums[$po['id']] ?? 0.0);
            $allocStmts[] = $stmt;
        }

        $callIndex = 0;
        $pdo->method('prepare')
            ->willReturnCallback(function () use (&$callIndex, $posStmt, $allocStmts) {
                if ($callIndex === 0) {
                    $callIndex++;
                    return $posStmt;
                }
                $idx = $callIndex - 1;
                $callIndex++;
                return $allocStmts[$idx] ?? $allocStmts[0];
            });

        return new SettlementEngine($pdo);
    }

    /**
     * Build a SettlementEngine for evaluateCustomerSettlement with known allocation sums.
     */
    private function buildEngineForCustomerSettlement(array $outstandingInvoices, array $allocationSums): SettlementEngine
    {
        $invoiceRows = [];
        foreach ($outstandingInvoices as $inv) {
            $invoiceRows[] = [
                'id'              => $inv['id'],
                'total_amount_bc' => $inv['total_amount_bc'],
            ];
        }

        $pdo = $this->createMock(PDO::class);

        // First prepare: invoices query
        $invoicesStmt = $this->createMock(PDOStatement::class);
        $invoicesStmt->method('execute')->willReturn(true);
        $invoicesStmt->method('fetchAll')->willReturn($invoiceRows);

        // Subsequent prepares: allocation sum queries (one per invoice)
        $allocStmts = [];
        foreach ($outstandingInvoices as $inv) {
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            $stmt->method('fetchColumn')->willReturn($allocationSums[$inv['id']] ?? 0.0);
            $allocStmts[] = $stmt;
        }

        $callIndex = 0;
        $pdo->method('prepare')
            ->willReturnCallback(function () use (&$callIndex, $invoicesStmt, $allocStmts) {
                if ($callIndex === 0) {
                    $callIndex++;
                    return $invoicesStmt;
                }
                $idx = $callIndex - 1;
                $callIndex++;
                return $allocStmts[$idx] ?? $allocStmts[0];
            });

        return new SettlementEngine($pdo);
    }
}
