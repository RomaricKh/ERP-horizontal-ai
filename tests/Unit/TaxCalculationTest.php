<?php

namespace Tests\Unit;

use App\Repository\SalesInvoiceRepository;
use App\Repository\PurchaseOrderRepository;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for tax calculation precision on invoices and POs.
 *
 * Validates: Correctness Property 8 — Tax calculation precision
 * For any invoice or PO line with a non-zero tax_rate, the stored tax_amount_bc
 * SHALL equal round(total_price_bc × (tax_rate / 100), 2).
 */
class TaxCalculationTest extends TestCase
{
    /**
     * Test: Invoice line with 10% tax rate calculates tax correctly.
     *
     * Given: qty=5, unit_price_fc=100.00, unit_price_bc=100.00, tax_rate=10
     * Expected: total_price_bc = 500.00, tax_amount_bc = round(500 * 0.10, 2) = 50.00
     */
    public function testInvoiceLineTaxCalculation10Percent(): void
    {
        $executedParams = [];

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturnCallback(function ($params) use (&$executedParams) {
            $executedParams = $params;
            return true;
        });

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);
        $pdo->method('lastInsertId')->willReturn('1');

        $repo = new SalesInvoiceRepository($pdo);

        $repo->addLine(1, [
            'item_id'       => 10,
            'quantity'      => 5,
            'unit_price_fc' => 100.00,
            'unit_price_bc' => 100.00,
            'tax_rate'      => 10.00,
        ]);

        // Verify tax calculation: total_price_bc = 5 * 100 = 500, tax = 500 * 0.10 = 50.00
        $this->assertEqualsWithDelta(10.00, $executedParams['tax_rate'], 0.001);
        $this->assertEqualsWithDelta(50.00, $executedParams['tax_amount_fc'], 0.001);
        $this->assertEqualsWithDelta(50.00, $executedParams['tax_amount_bc'], 0.001);
        $this->assertEqualsWithDelta(500.00, $executedParams['total_price_fc'], 0.001);
        $this->assertEqualsWithDelta(500.00, $executedParams['total_price_bc'], 0.001);
    }

    /**
     * Test: Invoice line with 0% tax rate stores zero tax amounts.
     */
    public function testInvoiceLineTaxCalculationZeroPercent(): void
    {
        $executedParams = [];

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturnCallback(function ($params) use (&$executedParams) {
            $executedParams = $params;
            return true;
        });

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);
        $pdo->method('lastInsertId')->willReturn('1');

        $repo = new SalesInvoiceRepository($pdo);

        $repo->addLine(1, [
            'item_id'       => 10,
            'quantity'      => 3,
            'unit_price_fc' => 200.00,
            'unit_price_bc' => 200.00,
            'tax_rate'      => 0,
        ]);

        $this->assertEqualsWithDelta(0.00, $executedParams['tax_rate'], 0.001);
        $this->assertEqualsWithDelta(0.00, $executedParams['tax_amount_fc'], 0.001);
        $this->assertEqualsWithDelta(0.00, $executedParams['tax_amount_bc'], 0.001);
    }

    /**
     * Test: Invoice line with no tax_rate provided defaults to 0.
     */
    public function testInvoiceLineTaxDefaultsToZero(): void
    {
        $executedParams = [];

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturnCallback(function ($params) use (&$executedParams) {
            $executedParams = $params;
            return true;
        });

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);
        $pdo->method('lastInsertId')->willReturn('1');

        $repo = new SalesInvoiceRepository($pdo);

        $repo->addLine(1, [
            'item_id'       => 10,
            'quantity'      => 2,
            'unit_price_fc' => 50.00,
            'unit_price_bc' => 50.00,
            // no tax_rate key
        ]);

        $this->assertEqualsWithDelta(0.00, $executedParams['tax_rate'], 0.001);
        $this->assertEqualsWithDelta(0.00, $executedParams['tax_amount_fc'], 0.001);
        $this->assertEqualsWithDelta(0.00, $executedParams['tax_amount_bc'], 0.001);
    }

    /**
     * Test: Tax calculation with fractional amounts rounds to 2 decimal places.
     *
     * Given: qty=3, unit_price_bc=33.33, tax_rate=7.5
     * total_price_bc = 3 * 33.33 = 99.99
     * tax_amount_bc = round(99.99 * 0.075, 2) = round(7.49925, 2) = 7.50
     */
    public function testInvoiceLineTaxRoundingPrecision(): void
    {
        $executedParams = [];

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturnCallback(function ($params) use (&$executedParams) {
            $executedParams = $params;
            return true;
        });

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);
        $pdo->method('lastInsertId')->willReturn('1');

        $repo = new SalesInvoiceRepository($pdo);

        $repo->addLine(1, [
            'item_id'       => 10,
            'quantity'      => 3,
            'unit_price_fc' => 33.33,
            'unit_price_bc' => 33.33,
            'tax_rate'      => 7.5,
        ]);

        // total_price_bc = round(3 * 33.33, 2) = 99.99
        // tax_amount_bc = round(99.99 * 0.075, 2) = 7.50
        $this->assertEqualsWithDelta(99.99, $executedParams['total_price_bc'], 0.001);
        $this->assertEqualsWithDelta(7.50, $executedParams['tax_amount_bc'], 0.001);
    }

    /**
     * Test: PO line with 10% tax rate calculates tax correctly.
     *
     * Given: qty=10, unit_price_fc=50.00, unit_price_bc=50.00, tax_rate=10
     * Expected: total_price_bc = 500.00, tax_amount_bc = 50.00
     */
    public function testPOLineTaxCalculation10Percent(): void
    {
        $executedParams = [];

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturnCallback(function ($params) use (&$executedParams) {
            $executedParams = $params;
            return true;
        });

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);
        $pdo->method('lastInsertId')->willReturn('1');

        $repo = new PurchaseOrderRepository($pdo);

        $repo->addLine(1, [
            'item_id'       => 20,
            'quantity'      => 10,
            'unit_price_fc' => 50.00,
            'unit_price_bc' => 50.00,
            'tax_rate'      => 10.00,
        ]);

        $this->assertEqualsWithDelta(10.00, $executedParams['tax_rate'], 0.001);
        $this->assertEqualsWithDelta(50.00, $executedParams['tax_amount_fc'], 0.001);
        $this->assertEqualsWithDelta(50.00, $executedParams['tax_amount_bc'], 0.001);
        $this->assertEqualsWithDelta(500.00, $executedParams['total_price_fc'], 0.001);
        $this->assertEqualsWithDelta(500.00, $executedParams['total_price_bc'], 0.001);
    }

    /**
     * Test: PO line with fractional tax rate.
     *
     * Given: qty=1, unit_price_bc=1000.00, tax_rate=15.5
     * Expected: total_price_bc = 1000.00, tax_amount_bc = round(1000 * 0.155, 2) = 155.00
     */
    public function testPOLineTaxCalculationFractionalRate(): void
    {
        $executedParams = [];

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturnCallback(function ($params) use (&$executedParams) {
            $executedParams = $params;
            return true;
        });

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);
        $pdo->method('lastInsertId')->willReturn('1');

        $repo = new PurchaseOrderRepository($pdo);

        $repo->addLine(1, [
            'item_id'       => 20,
            'quantity'      => 1,
            'unit_price_fc' => 1000.00,
            'unit_price_bc' => 1000.00,
            'tax_rate'      => 15.5,
        ]);

        $this->assertEqualsWithDelta(1000.00, $executedParams['total_price_bc'], 0.001);
        $this->assertEqualsWithDelta(155.00, $executedParams['tax_amount_bc'], 0.001);
    }

    /**
     * Test: Invoice recalculateTotals sums tax amounts into tax_total_fc and tax_total_bc.
     */
    public function testRecalculateTotalsSumsTaxAmounts(): void
    {
        $updateParams = [];

        // Mock the SELECT query that returns summed totals
        $selectStmt = $this->createMock(PDOStatement::class);
        $selectStmt->method('execute')->willReturn(true);
        $selectStmt->method('fetch')->willReturn([
            'total_fc' => '1000.00',
            'total_bc' => '1000.00',
            'tax_fc'   => '100.00',
            'tax_bc'   => '100.00',
        ]);

        // Mock the UPDATE query
        $updateStmt = $this->createMock(PDOStatement::class);
        $updateStmt->method('execute')->willReturnCallback(function ($params) use (&$updateParams) {
            $updateParams = $params;
            return true;
        });

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnOnConsecutiveCalls($selectStmt, $updateStmt);

        $repo = new SalesInvoiceRepository($pdo);
        $repo->recalculateTotals(1);

        $this->assertEqualsWithDelta(1000.00, (float) $updateParams['total_fc'], 0.001);
        $this->assertEqualsWithDelta(1000.00, (float) $updateParams['total_bc'], 0.001);
        $this->assertEqualsWithDelta(100.00, (float) $updateParams['tax_fc'], 0.001);
        $this->assertEqualsWithDelta(100.00, (float) $updateParams['tax_bc'], 0.001);
    }

    /**
     * Test: PO recalculateTotals sums tax amounts into tax_total_fc and tax_total_bc.
     */
    public function testPORecalculateTotalsSumsTaxAmounts(): void
    {
        $updateParams = [];

        $selectStmt = $this->createMock(PDOStatement::class);
        $selectStmt->method('execute')->willReturn(true);
        $selectStmt->method('fetch')->willReturn([
            'total_fc' => '2000.00',
            'total_bc' => '2000.00',
            'tax_fc'   => '200.00',
            'tax_bc'   => '200.00',
        ]);

        $updateStmt = $this->createMock(PDOStatement::class);
        $updateStmt->method('execute')->willReturnCallback(function ($params) use (&$updateParams) {
            $updateParams = $params;
            return true;
        });

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnOnConsecutiveCalls($selectStmt, $updateStmt);

        $repo = new PurchaseOrderRepository($pdo);
        $repo->recalculateTotals(1);

        $this->assertEqualsWithDelta(2000.00, (float) $updateParams['total_fc'], 0.001);
        $this->assertEqualsWithDelta(2000.00, (float) $updateParams['total_bc'], 0.001);
        $this->assertEqualsWithDelta(200.00, (float) $updateParams['tax_fc'], 0.001);
        $this->assertEqualsWithDelta(200.00, (float) $updateParams['tax_bc'], 0.001);
    }
}
