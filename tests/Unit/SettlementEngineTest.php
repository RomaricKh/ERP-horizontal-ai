<?php

namespace Tests\Unit;

use App\Services\SettlementEngine;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SettlementEngine service (allocation-based architecture).
 *
 * Tests evaluateSupplierSettlement and evaluateCustomerSettlement methods
 * using the new allocation-based logic where documents are settled when
 * SUM(allocations.amount_bc) >= document.total_amount_bc.
 *
 * Validates: Requirements 21.5
 */
class SettlementEngineTest extends TestCase
{
    private int $businessId = 1;
    private int $payeeId = 42;

    // ── Supplier Settlement Tests ─────────────────────────────────────────

    /**
     * evaluateSupplierSettlement returns PO IDs when allocations fully cover them.
     *
     * Validates: Requirements 21.5
     */
    public function testSupplierSettlementWhenFullyAllocated(): void
    {
        $purchaseOrders = [
            ['id' => 101, 'total_amount_bc' => 1000.00, 'allocated' => 1000.00],
            ['id' => 102, 'total_amount_bc' => 800.00, 'allocated' => 800.00],
            ['id' => 103, 'total_amount_bc' => 600.00, 'allocated' => 600.00],
        ];

        $engine = $this->buildSupplierSettlementEngine($purchaseOrders);
        $settledIds = $engine->evaluateSupplierSettlement($this->payeeId, $this->businessId);

        sort($settledIds);
        $this->assertSame([101, 102, 103], $settledIds);
    }

    /**
     * evaluateSupplierSettlement returns only fully-allocated PO IDs.
     *
     * Validates: Requirements 21.5
     */
    public function testSupplierSettlementPartiallyAllocated(): void
    {
        $purchaseOrders = [
            ['id' => 201, 'total_amount_bc' => 500.00, 'allocated' => 500.00],  // Fully allocated
            ['id' => 202, 'total_amount_bc' => 300.00, 'allocated' => 150.00],  // Partially allocated
        ];

        $engine = $this->buildSupplierSettlementEngine($purchaseOrders);
        $settledIds = $engine->evaluateSupplierSettlement($this->payeeId, $this->businessId);

        $this->assertSame([201], $settledIds);
    }

    /**
     * evaluateSupplierSettlement returns empty array when no POs are fully allocated.
     *
     * Validates: Requirements 21.5
     */
    public function testSupplierSettlementWhenNoneFullyAllocated(): void
    {
        $purchaseOrders = [
            ['id' => 301, 'total_amount_bc' => 1000.00, 'allocated' => 500.00],
        ];

        $engine = $this->buildSupplierSettlementEngine($purchaseOrders);
        $settledIds = $engine->evaluateSupplierSettlement($this->payeeId, $this->businessId);

        $this->assertEmpty($settledIds);
    }

    /**
     * evaluateSupplierSettlement returns empty array when no POs exist.
     *
     * Validates: Requirements 21.5
     */
    public function testSupplierSettlementWithNoPurchaseOrders(): void
    {
        $purchaseOrders = [];

        $engine = $this->buildSupplierSettlementEngine($purchaseOrders);
        $settledIds = $engine->evaluateSupplierSettlement($this->payeeId, $this->businessId);

        $this->assertEmpty($settledIds);
    }

    /**
     * evaluateSupplierSettlement settles zero-amount POs immediately.
     *
     * Validates: Requirements 21.5
     */
    public function testSupplierSettlementZeroAmountPO(): void
    {
        $purchaseOrders = [
            ['id' => 401, 'total_amount_bc' => 0.00, 'allocated' => 0.00],
        ];

        $engine = $this->buildSupplierSettlementEngine($purchaseOrders);
        $settledIds = $engine->evaluateSupplierSettlement($this->payeeId, $this->businessId);

        $this->assertSame([401], $settledIds);
    }

    // ── Customer Settlement Tests ─────────────────────────────────────────

    /**
     * evaluateCustomerSettlement returns invoice IDs when fully allocated.
     *
     * Validates: Requirements 21.5
     */
    public function testCustomerSettlementWhenFullyAllocated(): void
    {
        $invoices = [
            ['id' => 501, 'total_amount_bc' => 1000.00, 'allocated' => 1000.00],
            ['id' => 502, 'total_amount_bc' => 800.00, 'allocated' => 900.00],  // Over-allocated
        ];

        $engine = $this->buildCustomerSettlementEngine($invoices);
        $settledIds = $engine->evaluateCustomerSettlement($this->payeeId, $this->businessId);

        sort($settledIds);
        $this->assertSame([501, 502], $settledIds);
    }

    /**
     * evaluateCustomerSettlement returns only fully-allocated invoice IDs.
     *
     * Validates: Requirements 21.5
     */
    public function testCustomerSettlementPartiallyAllocated(): void
    {
        $invoices = [
            ['id' => 601, 'total_amount_bc' => 500.00, 'allocated' => 500.00],  // Fully allocated
            ['id' => 602, 'total_amount_bc' => 300.00, 'allocated' => 100.00],  // Partially allocated
        ];

        $engine = $this->buildCustomerSettlementEngine($invoices);
        $settledIds = $engine->evaluateCustomerSettlement($this->payeeId, $this->businessId);

        $this->assertSame([601], $settledIds);
    }

    /**
     * evaluateCustomerSettlement returns empty array when no invoices are fully allocated.
     *
     * Validates: Requirements 21.5
     */
    public function testCustomerSettlementWhenNoneFullyAllocated(): void
    {
        $invoices = [
            ['id' => 701, 'total_amount_bc' => 1000.00, 'allocated' => 500.00],
        ];

        $engine = $this->buildCustomerSettlementEngine($invoices);
        $settledIds = $engine->evaluateCustomerSettlement($this->payeeId, $this->businessId);

        $this->assertEmpty($settledIds);
    }

    /**
     * evaluateCustomerSettlement returns empty array when no invoices exist.
     *
     * Validates: Requirements 21.5
     */
    public function testCustomerSettlementWithNoInvoices(): void
    {
        $invoices = [];

        $engine = $this->buildCustomerSettlementEngine($invoices);
        $settledIds = $engine->evaluateCustomerSettlement($this->payeeId, $this->businessId);

        $this->assertEmpty($settledIds);
    }

    /**
     * evaluateCustomerSettlement settles zero-amount invoices immediately.
     *
     * Validates: Requirements 21.5
     */
    public function testCustomerSettlementZeroAmountInvoice(): void
    {
        $invoices = [
            ['id' => 801, 'total_amount_bc' => 0.00, 'allocated' => 0.00],
        ];

        $engine = $this->buildCustomerSettlementEngine($invoices);
        $settledIds = $engine->evaluateCustomerSettlement($this->payeeId, $this->businessId);

        $this->assertSame([801], $settledIds);
    }

    // ── Helper Methods ────────────────────────────────────────────────────

    /**
     * Build a SettlementEngine with mocked PDO for testing supplier settlement.
     * The new engine uses allocation-based logic:
     * 1. Query RECEIVED POs for the supplier
     * 2. For each PO, sum payment_allocations to determine if fully paid
     */
    private function buildSupplierSettlementEngine(array $purchaseOrders): SettlementEngine
    {
        $poRows = array_map(function ($po) {
            return [
                'id'              => $po['id'],
                'total_amount_bc' => $po['total_amount_bc'],
            ];
        }, $purchaseOrders);

        // Mock PDOStatement for POs query
        $posStmt = $this->createMock(PDOStatement::class);
        $posStmt->method('execute')->willReturn(true);
        $posStmt->method('fetchAll')->willReturn($poRows);

        // Build allocation sum stubs for each PO
        $allocStmts = [];
        foreach ($purchaseOrders as $po) {
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            $stmt->method('fetchColumn')->willReturn($po['allocated']);
            $allocStmts[] = $stmt;
        }

        // Mock PDO
        $pdo = $this->createMock(PDO::class);

        $callIndex = 0;
        $pdo->method('prepare')
            ->willReturnCallback(function () use (&$callIndex, $posStmt, $allocStmts) {
                if ($callIndex === 0) {
                    $callIndex++;
                    return $posStmt;
                }
                $idx = $callIndex - 1;
                $callIndex++;
                return $allocStmts[$idx] ?? $allocStmts[0] ?? $posStmt;
            });

        return new SettlementEngine($pdo);
    }

    /**
     * Build a SettlementEngine with mocked PDO for testing customer settlement.
     * The new engine uses allocation-based logic:
     * 1. Query DELIVERED invoices for the customer
     * 2. For each invoice, sum collection_allocations to determine if fully paid
     */
    private function buildCustomerSettlementEngine(array $invoices): SettlementEngine
    {
        $invoiceRows = array_map(function ($inv) {
            return [
                'id'              => $inv['id'],
                'total_amount_bc' => $inv['total_amount_bc'],
            ];
        }, $invoices);

        // Mock PDOStatement for invoices query
        $invoicesStmt = $this->createMock(PDOStatement::class);
        $invoicesStmt->method('execute')->willReturn(true);
        $invoicesStmt->method('fetchAll')->willReturn($invoiceRows);

        // Build allocation sum stubs for each invoice
        $allocStmts = [];
        foreach ($invoices as $inv) {
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            $stmt->method('fetchColumn')->willReturn($inv['allocated']);
            $allocStmts[] = $stmt;
        }

        // Mock PDO
        $pdo = $this->createMock(PDO::class);

        $callIndex = 0;
        $pdo->method('prepare')
            ->willReturnCallback(function () use (&$callIndex, $invoicesStmt, $allocStmts) {
                if ($callIndex === 0) {
                    $callIndex++;
                    return $invoicesStmt;
                }
                $idx = $callIndex - 1;
                $callIndex++;
                return $allocStmts[$idx] ?? $allocStmts[0] ?? $invoicesStmt;
            });

        return new SettlementEngine($pdo);
    }
}
