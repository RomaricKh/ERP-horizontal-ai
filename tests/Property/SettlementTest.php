<?php

namespace Tests\Property;

use App\Services\SettlementEngine;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Feature: operations-financial-integrity
 *
 * Property 12: Customer Settlement on Zero Balance
 * Property 13: Supplier Settlement on Zero Balance
 *
 * **Validates: Requirements 9.2, 9.3, 10.2, 10.3**
 */
class SettlementTest extends TestCase
{
    private const ITERATIONS = 100;

    /**
     * Data provider generating 100 random scenarios for customer settlement.
     *
     * Each case generates:
     * - A random customer balance (positive, zero, or negative)
     * - A random set of DELIVERED invoices with various payment statuses (UNPAID, PARTIALLY_PAID, PAID)
     *
     * Returns: [balance, invoices, expectedSettledIds]
     */
    public static function customerSettlementProvider(): array
    {
        $cases = [];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Generate customer balance: range from -5000 to 5000
            // Negative or zero means customer has paid enough (settlement triggers)
            // Positive means customer still owes (no settlement)
            $balance = round(mt_rand(-500000, 500000) / 100, 2);

            // Generate between 0 and 10 DELIVERED invoices
            $numInvoices = mt_rand(0, 10);
            $invoices = [];
            $expectedSettledIds = [];

            for ($j = 0; $j < $numInvoices; $j++) {
                $invoiceId = 1000 + ($i * 10) + $j;
                $totalAmountBc = round(mt_rand(100, 100000) / 100, 2);

                // Random payment status: UNPAID, PARTIALLY_PAID, or PAID
                $statusRoll = mt_rand(1, 3);
                if ($statusRoll === 1) {
                    // UNPAID: no collections
                    $totalCollected = 0.0;
                } elseif ($statusRoll === 2) {
                    // PARTIALLY_PAID: some collections but less than total
                    $totalCollected = round(mt_rand(1, (int)($totalAmountBc * 100) - 1) / 100, 2);
                    // Ensure it's actually less than total
                    if ($totalCollected >= $totalAmountBc) {
                        $totalCollected = round($totalAmountBc * 0.5, 2);
                    }
                } else {
                    // PAID: collections >= total
                    $totalCollected = $totalAmountBc + round(mt_rand(0, 1000) / 100, 2);
                }

                $invoices[] = [
                    'id'              => $invoiceId,
                    'total_amount_bc' => $totalAmountBc,
                    'total_collected' => $totalCollected,
                ];

                // When balance <= 0, UNPAID and PARTIALLY_PAID invoices should be settled
                if ($balance <= 0 && $totalCollected < $totalAmountBc) {
                    $expectedSettledIds[] = $invoiceId;
                }
            }

            $cases["iter_{$i}_balance_{$balance}_invoices_{$numInvoices}"] = [
                $balance,
                $invoices,
                $expectedSettledIds,
            ];
        }

        return $cases;
    }

    /**
     * Property 12: Customer Settlement on Zero Balance
     *
     * For any customer whose payee balance reaches zero or below after a collection slip is created,
     * all DELIVERED invoices for that customer with Payment_Status UNPAID or PARTIALLY_PAID are
     * marked as settled (returned as settled IDs).
     *
     * When balance > 0, no invoices are settled (empty array returned).
     *
     * **Validates: Requirements 9.2, 9.3**
     *
     * @dataProvider customerSettlementProvider
     */
    public function testCustomerSettlementOnZeroBalance(
        float $balance,
        array $invoices,
        array $expectedSettledIds
    ): void {
        $payeeId = 42;
        $businessId = 1;

        $engine = $this->buildSettlementEngine($payeeId, $balance, $invoices, $businessId);

        $settledIds = $engine->evaluateCustomerSettlement($payeeId, $businessId);

        if ($balance > 0) {
            // When balance is positive, customer still owes — no settlement
            $this->assertEmpty(
                $settledIds,
                sprintf(
                    'Expected NO settlement when balance is positive (%.2f), but got settled IDs: %s',
                    $balance,
                    json_encode($settledIds)
                )
            );
        } else {
            // When balance <= 0, all UNPAID/PARTIALLY_PAID invoices should be settled
            sort($settledIds);
            sort($expectedSettledIds);

            $this->assertSame(
                $expectedSettledIds,
                $settledIds,
                sprintf(
                    'Settlement mismatch for balance %.2f with %d invoices. ' .
                    'Expected settled: %s, Got: %s. Invoices: %s',
                    $balance,
                    count($invoices),
                    json_encode($expectedSettledIds),
                    json_encode($settledIds),
                    json_encode($invoices)
                )
            );
        }
    }

    /**
     * Build a SettlementEngine with mocked PDO for testing customer settlement.
     *
     * Mocks:
     * - Balance query: returns the given balance for the payee
     * - Sales invoices query: returns DELIVERED invoices for the customer
     * - Collection slips query: returns total collected for each invoice
     */
    private function buildSettlementEngine(
        int $payeeId,
        float $balance,
        array $invoices,
        int $businessId
    ): SettlementEngine {
        // Mock PDOStatement for balance query
        $balanceStmt = $this->createMock(PDOStatement::class);
        $balanceStmt->method('execute')->willReturn(true);
        $balanceStmt->method('fetch')->willReturn(['balance' => $balance]);

        // Mock PDOStatement for sales_invoices query
        $invoiceRows = array_map(function ($inv) {
            return [
                'id'              => $inv['id'],
                'total_amount_bc' => $inv['total_amount_bc'],
            ];
        }, $invoices);

        $invoicesStmt = $this->createMock(PDOStatement::class);
        $invoicesStmt->method('execute')->willReturn(true);
        $invoicesStmt->method('fetchAll')->willReturn($invoiceRows);

        // Build collection amount stubs for each invoice
        // The SettlementEngine calls computeInvoicePaymentStatus for each invoice,
        // which queries collection_slips for that invoice's total collected amount
        $collectionStmts = [];
        foreach ($invoices as $inv) {
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            $stmt->method('fetchColumn')->willReturn($inv['total_collected']);
            $collectionStmts[$inv['id']] = $stmt;
        }

        // Mock PDO to return appropriate statements based on query content
        $pdo = $this->createMock(PDO::class);

        $callIndex = 0;
        $prepareResults = [];

        // First prepare call: balance query (SELECT balance FROM payees)
        $prepareResults[] = $balanceStmt;

        // Second prepare call: invoices query (SELECT id, total_amount_bc FROM sales_invoices)
        $prepareResults[] = $invoicesStmt;

        // Subsequent prepare calls: one per invoice for collection_slips query
        foreach ($invoices as $inv) {
            $prepareResults[] = $collectionStmts[$inv['id']];
        }

        $pdo->method('prepare')
            ->willReturnCallback(function (string $sql) use (&$callIndex, $prepareResults) {
                $result = $prepareResults[$callIndex] ?? $prepareResults[0];
                $callIndex++;
                return $result;
            });

        return new SettlementEngine($pdo);
    }

    // =========================================================================
    // Property 13: Supplier Settlement on Zero Balance
    // =========================================================================

    /**
     * Data provider generating 100 random scenarios for supplier settlement.
     *
     * Each case generates:
     * - A random supplier balance (positive, zero, or negative)
     * - A random set of RECEIVED purchase orders with various payment statuses (UNPAID, PARTIALLY_PAID, PAID)
     *
     * Returns: [balance, purchaseOrders, expectedSettledIds]
     */
    public static function supplierSettlementProvider(): array
    {
        $cases = [];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Generate supplier balance: range from -5000 to 5000
            // Zero or positive means supplier is settled (settlement triggers)
            // Negative means business still owes supplier (no settlement)
            $balance = round(mt_rand(-500000, 500000) / 100, 2);

            // Generate between 0 and 10 RECEIVED purchase orders
            $numPOs = mt_rand(0, 10);
            $purchaseOrders = [];
            $expectedSettledIds = [];

            for ($j = 0; $j < $numPOs; $j++) {
                $poId = 2000 + ($i * 10) + $j;
                $totalAmountBc = round(mt_rand(100, 100000) / 100, 2);

                // Random payment status: UNPAID, PARTIALLY_PAID, or PAID
                $statusRoll = mt_rand(1, 3);
                if ($statusRoll === 1) {
                    // UNPAID: no payments
                    $totalPaid = 0.0;
                } elseif ($statusRoll === 2) {
                    // PARTIALLY_PAID: some payments but less than total
                    $totalPaid = round(mt_rand(1, (int)($totalAmountBc * 100) - 1) / 100, 2);
                    // Ensure it's actually less than total
                    if ($totalPaid >= $totalAmountBc) {
                        $totalPaid = round($totalAmountBc * 0.5, 2);
                    }
                } else {
                    // PAID: payments >= total
                    $totalPaid = $totalAmountBc + round(mt_rand(0, 1000) / 100, 2);
                }

                $purchaseOrders[] = [
                    'id'              => $poId,
                    'total_amount_bc' => $totalAmountBc,
                    'total_paid'      => $totalPaid,
                ];

                // When balance >= 0, UNPAID and PARTIALLY_PAID POs should be settled
                if ($balance >= 0 && $totalPaid < $totalAmountBc) {
                    $expectedSettledIds[] = $poId;
                }
            }

            $cases["iter_{$i}_balance_{$balance}_pos_{$numPOs}"] = [
                $balance,
                $purchaseOrders,
                $expectedSettledIds,
            ];
        }

        return $cases;
    }

    /**
     * Property 13: Supplier Settlement on Zero Balance
     *
     * For any supplier whose payee balance reaches zero or above after a payment slip is created,
     * all RECEIVED purchase orders for that supplier with Payment_Status UNPAID or PARTIALLY_PAID
     * are marked as settled (returned as settled IDs).
     *
     * When balance < 0, no purchase orders are settled (empty array returned).
     *
     * **Validates: Requirements 10.2, 10.3**
     *
     * @dataProvider supplierSettlementProvider
     */
    public function testSupplierSettlementOnZeroBalance(
        float $balance,
        array $purchaseOrders,
        array $expectedSettledIds
    ): void {
        $payeeId = 55;
        $businessId = 1;

        $engine = $this->buildSupplierSettlementEngine($payeeId, $balance, $purchaseOrders, $businessId);

        $settledIds = $engine->evaluateSupplierSettlement($payeeId, $businessId);

        if ($balance < 0) {
            // When balance is negative, business still owes supplier — no settlement
            $this->assertEmpty(
                $settledIds,
                sprintf(
                    'Expected NO settlement when balance is negative (%.2f), but got settled IDs: %s',
                    $balance,
                    json_encode($settledIds)
                )
            );
        } else {
            // When balance >= 0, all UNPAID/PARTIALLY_PAID POs should be settled
            sort($settledIds);
            sort($expectedSettledIds);

            $this->assertSame(
                $expectedSettledIds,
                $settledIds,
                sprintf(
                    'Settlement mismatch for balance %.2f with %d POs. ' .
                    'Expected settled: %s, Got: %s. POs: %s',
                    $balance,
                    count($purchaseOrders),
                    json_encode($expectedSettledIds),
                    json_encode($settledIds),
                    json_encode($purchaseOrders)
                )
            );
        }
    }

    /**
     * Build a SettlementEngine with mocked PDO for testing supplier settlement.
     *
     * Mocks:
     * - Balance query: returns the given balance for the payee
     * - Purchase orders query: returns RECEIVED POs for the supplier
     * - Payment slips query: returns total paid for each PO
     */
    private function buildSupplierSettlementEngine(
        int $payeeId,
        float $balance,
        array $purchaseOrders,
        int $businessId
    ): SettlementEngine {
        // Mock PDOStatement for balance query
        $balanceStmt = $this->createMock(PDOStatement::class);
        $balanceStmt->method('execute')->willReturn(true);
        $balanceStmt->method('fetch')->willReturn(['balance' => $balance]);

        // Mock PDOStatement for purchase_orders query
        $poRows = array_map(function ($po) {
            return [
                'id'              => $po['id'],
                'total_amount_bc' => $po['total_amount_bc'],
            ];
        }, $purchaseOrders);

        $posStmt = $this->createMock(PDOStatement::class);
        $posStmt->method('execute')->willReturn(true);
        $posStmt->method('fetchAll')->willReturn($poRows);

        // Build payment amount stubs for each PO
        // The SettlementEngine calls computePoPaymentStatus for each PO,
        // which queries payment_slips for that PO's total paid amount
        $paymentStmts = [];
        foreach ($purchaseOrders as $po) {
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            $stmt->method('fetchColumn')->willReturn($po['total_paid']);
            $paymentStmts[$po['id']] = $stmt;
        }

        // Mock PDO to return appropriate statements based on query content
        $pdo = $this->createMock(PDO::class);

        $callIndex = 0;
        $prepareResults = [];

        // First prepare call: balance query (SELECT balance FROM payees)
        $prepareResults[] = $balanceStmt;

        // Second prepare call: POs query (SELECT id, total_amount_bc FROM purchase_orders)
        $prepareResults[] = $posStmt;

        // Subsequent prepare calls: one per PO for payment_slips query
        foreach ($purchaseOrders as $po) {
            $prepareResults[] = $paymentStmts[$po['id']];
        }

        $pdo->method('prepare')
            ->willReturnCallback(function (string $sql) use (&$callIndex, $prepareResults) {
                $result = $prepareResults[$callIndex] ?? $prepareResults[0];
                $callIndex++;
                return $result;
            });

        return new SettlementEngine($pdo);
    }
}
