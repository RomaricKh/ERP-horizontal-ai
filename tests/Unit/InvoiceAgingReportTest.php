<?php

namespace Tests\Unit;

use App\Repository\SalesInvoiceRepository;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the AR aging report.
 *
 * Validates: REQ-015 — Invoice due date and AR aging
 * Verifies that invoices with past due dates appear in the correct overdue bucket.
 */
class InvoiceAgingReportTest extends TestCase
{
    /**
     * Test: An invoice 15 days overdue appears in the 1-30 bucket.
     */
    public function testInvoice15DaysOverdueAppearsIn1To30Bucket(): void
    {
        $dueDate = date('Y-m-d', strtotime('-15 days'));
        $report = $this->runAgingWithInvoice($dueDate, 'DELIVERED');

        $this->assertCount(1, $report['buckets']['1_30']['invoices']);
        $this->assertCount(0, $report['buckets']['current']['invoices']);
        $this->assertCount(0, $report['buckets']['31_60']['invoices']);
        $this->assertCount(0, $report['buckets']['61_90']['invoices']);
        $this->assertCount(0, $report['buckets']['90_plus']['invoices']);
        $this->assertEqualsWithDelta(1000.00, $report['buckets']['1_30']['total_bc'], 0.01);
    }

    /**
     * Test: An invoice 45 days overdue appears in the 31-60 bucket.
     */
    public function testInvoice45DaysOverdueAppearsIn31To60Bucket(): void
    {
        $dueDate = date('Y-m-d', strtotime('-45 days'));
        $report = $this->runAgingWithInvoice($dueDate, 'DELIVERED');

        $this->assertCount(0, $report['buckets']['1_30']['invoices']);
        $this->assertCount(1, $report['buckets']['31_60']['invoices']);
        $this->assertCount(0, $report['buckets']['61_90']['invoices']);
        $this->assertCount(0, $report['buckets']['90_plus']['invoices']);
        $this->assertEqualsWithDelta(1000.00, $report['buckets']['31_60']['total_bc'], 0.01);
    }

    /**
     * Test: An invoice 75 days overdue appears in the 61-90 bucket.
     */
    public function testInvoice75DaysOverdueAppearsIn61To90Bucket(): void
    {
        $dueDate = date('Y-m-d', strtotime('-75 days'));
        $report = $this->runAgingWithInvoice($dueDate, 'DELIVERED');

        $this->assertCount(0, $report['buckets']['31_60']['invoices']);
        $this->assertCount(1, $report['buckets']['61_90']['invoices']);
        $this->assertCount(0, $report['buckets']['90_plus']['invoices']);
        $this->assertEqualsWithDelta(1000.00, $report['buckets']['61_90']['total_bc'], 0.01);
    }

    /**
     * Test: An invoice 100 days overdue appears in the 90+ bucket.
     */
    public function testInvoice100DaysOverdueAppearsIn90PlusBucket(): void
    {
        $dueDate = date('Y-m-d', strtotime('-100 days'));
        $report = $this->runAgingWithInvoice($dueDate, 'DELIVERED');

        $this->assertCount(0, $report['buckets']['61_90']['invoices']);
        $this->assertCount(1, $report['buckets']['90_plus']['invoices']);
        $this->assertEqualsWithDelta(1000.00, $report['buckets']['90_plus']['total_bc'], 0.01);
    }

    /**
     * Test: An invoice not yet due appears in the current bucket.
     */
    public function testInvoiceNotYetDueAppearsInCurrentBucket(): void
    {
        $dueDate = date('Y-m-d', strtotime('+10 days'));
        $report = $this->runAgingWithInvoice($dueDate, 'DELIVERED');

        $this->assertCount(1, $report['buckets']['current']['invoices']);
        $this->assertCount(0, $report['buckets']['1_30']['invoices']);
        $this->assertEqualsWithDelta(1000.00, $report['buckets']['current']['total_bc'], 0.01);
    }

    /**
     * Test: Per-customer breakdown aggregates correctly.
     */
    public function testPerCustomerBreakdownAggregatesCorrectly(): void
    {
        $dueDate = date('Y-m-d', strtotime('-20 days'));
        $report = $this->runAgingWithInvoice($dueDate, 'DELIVERED');

        $this->assertCount(1, $report['by_customer']);
        $customer = $report['by_customer'][0];
        $this->assertEquals('Test Customer', $customer['customer_name']);
        $this->assertEqualsWithDelta(1000.00, $customer['1_30'], 0.01);
        $this->assertEqualsWithDelta(1000.00, $customer['total'], 0.01);
    }

    /**
     * Test: due_date is included in the create method parameters.
     */
    public function testCreateIncludesDueDate(): void
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
        $repo->create([
            'business_id'    => 1,
            'customer_id'    => 5,
            'invoice_number' => 'INV-2025-001',
            'invoice_date'   => '2025-01-01',
            'due_date'       => '2025-01-31',
            'currency'       => 'USD',
            'exchange_rate'  => 1.0,
            'notes'          => null,
            'created_by'     => 1,
        ]);

        $this->assertEquals('2025-01-31', $executedParams['due_date']);
    }

    /**
     * Test: due_date defaults to null when not provided.
     */
    public function testCreateDueDateDefaultsToNull(): void
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
        $repo->create([
            'business_id'    => 1,
            'customer_id'    => 5,
            'invoice_number' => 'INV-2025-002',
            'invoice_date'   => '2025-01-01',
            'currency'       => 'USD',
            'exchange_rate'  => 1.0,
            'notes'          => null,
            'created_by'     => 1,
        ]);

        $this->assertNull($executedParams['due_date']);
    }

    /* ── Helper ─────────────────────────────────────────────────────── */

    /**
     * Creates a mock PDO that returns a single invoice row with the given due_date
     * and status, then calls getAgingReport and returns the result.
     */
    private function runAgingWithInvoice(string $dueDate, string $status): array
    {
        $daysOverdue = (int) ((time() - strtotime($dueDate)) / 86400);

        $invoiceRow = [
            'id'              => 1,
            'invoice_number'  => 'INV-2025-001',
            'invoice_date'    => '2025-01-01',
            'due_date'        => $dueDate,
            'total_amount_fc' => '1000.00',
            'total_amount_bc' => '1000.00',
            'customer_id'     => 5,
            'customer_name'   => 'Test Customer',
            'days_overdue'    => (string) $daysOverdue,
        ];

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn([$invoiceRow]);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $repo = new SalesInvoiceRepository($pdo);
        return $repo->getAgingReport(1);
    }
}
