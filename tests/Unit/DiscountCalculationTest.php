<?php

namespace Tests\Unit;

use App\Repository\SalesInvoiceRepository;
use App\Repository\PurchaseOrderRepository;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for discount calculation on invoices and POs.
 *
 * Validates: Correctness Property 9 — Discount applied before tax
 * For any invoice or PO line with both a discount_percent and a tax_rate,
 * the line total SHALL be computed as unit_price × qty × (1 - discount_percent/100)
 * and tax SHALL be applied to that discounted total, not to the pre-discount amount.
 */
class DiscountCalculationTest extends TestCase
{
    /**
     * Test: Invoice line with 10% discount reduces line total correctly.
     *
     * Given: qty=5, unit_price_fc=100.00, unit_price_bc=100.00, discount_percent=10, tax_rate=10
     * Expected:
     *   total_price = 5 * 100 * (1 - 10/100) = 5 * 100 * 0.9 = 450.00
     *   tax_amount  = 450.00 * (10/100) = 45.00
     */
    public function testInvoiceLineWith10PercentDiscount(): void
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
            'item_id'          => 10,
            'quantity'         => 5,
            'unit_price_fc'    => 100.00,
            'unit_price_bc'    => 100.00,
            'tax_rate'         => 10.00,
            'discount_percent' => 10.00,
        ]);

        // Discounted total: 5 * 100 * 0.9 = 450.00
        $this->assertEqualsWithDelta(450.00, $executedParams['total_price_fc'], 0.001);
        $this->assertEqualsWithDelta(450.00, $executedParams['total_price_bc'], 0.001);

        // Tax on discounted total: 450 * 0.10 = 45.00
        $this->assertEqualsWithDelta(45.00, $executedParams['tax_amount_fc'], 0.001);
        $this->assertEqualsWithDelta(45.00, $executedParams['tax_amount_bc'], 0.001);

        // Discount percent stored
        $this->assertEqualsWithDelta(10.00, $executedParams['discount_percent'], 0.001);
    }

    /**
     * Test: Invoice line with 0% discount leaves total unchanged.
     *
     * Given: qty=5, unit_price_fc=100.00, unit_price_bc=100.00, discount_percent=0, tax_rate=10
     * Expected:
     *   total_price = 5 * 100 * 1.0 = 500.00
     *   tax_amount  = 500.00 * 0.10 = 50.00
     */
    public function testInvoiceLineWithZeroDiscountLeavesTotalUnchanged(): void
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
            'item_id'          => 10,
            'quantity'         => 5,
            'unit_price_fc'    => 100.00,
            'unit_price_bc'    => 100.00,
            'tax_rate'         => 10.00,
            'discount_percent' => 0.00,
        ]);

        // No discount: 5 * 100 * 1.0 = 500.00
        $this->assertEqualsWithDelta(500.00, $executedParams['total_price_fc'], 0.001);
        $this->assertEqualsWithDelta(500.00, $executedParams['total_price_bc'], 0.001);

        // Tax on full total: 500 * 0.10 = 50.00
        $this->assertEqualsWithDelta(50.00, $executedParams['tax_amount_fc'], 0.001);
        $this->assertEqualsWithDelta(50.00, $executedParams['tax_amount_bc'], 0.001);

        $this->assertEqualsWithDelta(0.00, $executedParams['discount_percent'], 0.001);
    }

    /**
     * Test: Invoice line with no discount_percent key defaults to 0 (no discount).
     */
    public function testInvoiceLineWithNoDiscountKeyDefaultsToZero(): void
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
            'tax_rate'      => 5.00,
            // no discount_percent key
        ]);

        // No discount: 2 * 50 = 100.00
        $this->assertEqualsWithDelta(100.00, $executedParams['total_price_fc'], 0.001);
        $this->assertEqualsWithDelta(100.00, $executedParams['total_price_bc'], 0.001);

        // Tax: 100 * 0.05 = 5.00
        $this->assertEqualsWithDelta(5.00, $executedParams['tax_amount_fc'], 0.001);
        $this->assertEqualsWithDelta(5.00, $executedParams['tax_amount_bc'], 0.001);

        $this->assertEqualsWithDelta(0.00, $executedParams['discount_percent'], 0.001);
    }

    /**
     * Test: PO line with 10% discount reduces line total correctly.
     *
     * Given: qty=10, unit_price_fc=50.00, unit_price_bc=50.00, discount_percent=10, tax_rate=15
     * Expected:
     *   total_price = 10 * 50 * 0.9 = 450.00
     *   tax_amount  = 450.00 * 0.15 = 67.50
     */
    public function testPOLineWith10PercentDiscount(): void
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
            'item_id'          => 20,
            'quantity'         => 10,
            'unit_price_fc'    => 50.00,
            'unit_price_bc'    => 50.00,
            'tax_rate'         => 15.00,
            'discount_percent' => 10.00,
        ]);

        // Discounted total: 10 * 50 * 0.9 = 450.00
        $this->assertEqualsWithDelta(450.00, $executedParams['total_price_fc'], 0.001);
        $this->assertEqualsWithDelta(450.00, $executedParams['total_price_bc'], 0.001);

        // Tax on discounted total: 450 * 0.15 = 67.50
        $this->assertEqualsWithDelta(67.50, $executedParams['tax_amount_fc'], 0.001);
        $this->assertEqualsWithDelta(67.50, $executedParams['tax_amount_bc'], 0.001);

        $this->assertEqualsWithDelta(10.00, $executedParams['discount_percent'], 0.001);
    }

    /**
     * Test: PO line with 0% discount leaves total unchanged.
     */
    public function testPOLineWithZeroDiscountLeavesTotalUnchanged(): void
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
            'item_id'          => 20,
            'quantity'         => 10,
            'unit_price_fc'    => 50.00,
            'unit_price_bc'    => 50.00,
            'tax_rate'         => 15.00,
            'discount_percent' => 0.00,
        ]);

        // No discount: 10 * 50 = 500.00
        $this->assertEqualsWithDelta(500.00, $executedParams['total_price_fc'], 0.001);
        $this->assertEqualsWithDelta(500.00, $executedParams['total_price_bc'], 0.001);

        // Tax on full total: 500 * 0.15 = 75.00
        $this->assertEqualsWithDelta(75.00, $executedParams['tax_amount_fc'], 0.001);
        $this->assertEqualsWithDelta(75.00, $executedParams['tax_amount_bc'], 0.001);

        $this->assertEqualsWithDelta(0.00, $executedParams['discount_percent'], 0.001);
    }

    /**
     * Test: Discount with fractional values rounds correctly.
     *
     * Given: qty=3, unit_price_fc=33.33, unit_price_bc=33.33, discount_percent=15.5, tax_rate=10
     * Expected:
     *   total_price = round(3 * 33.33 * (1 - 15.5/100), 2) = round(3 * 33.33 * 0.845, 2) = round(84.49, 2) = 84.49
     *   tax_amount  = round(84.49 * 0.10, 2) = 8.45
     */
    public function testDiscountWithFractionalValuesRoundsCorrectly(): void
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
            'item_id'          => 10,
            'quantity'         => 3,
            'unit_price_fc'    => 33.33,
            'unit_price_bc'    => 33.33,
            'tax_rate'         => 10.00,
            'discount_percent' => 15.50,
        ]);

        // total = round(3 * 33.33 * 0.845, 2) = round(84.4913..., 2) = 84.49
        $expectedTotal = round(3 * 33.33 * (1 - 15.50 / 100), 2);
        $expectedTax   = round($expectedTotal * 0.10, 2);

        $this->assertEqualsWithDelta($expectedTotal, $executedParams['total_price_fc'], 0.001);
        $this->assertEqualsWithDelta($expectedTax, $executedParams['tax_amount_fc'], 0.001);
        $this->assertEqualsWithDelta(15.50, $executedParams['discount_percent'], 0.001);
    }
}
