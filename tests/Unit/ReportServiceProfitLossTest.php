<?php

namespace Tests\Unit;

use App\Services\ReportService;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ReportService::getProfitLoss().
 *
 * Validates: REQ-016 — Financial reports (P&L)
 * Verifies that revenue, COGS, expenses by category, and net profit
 * are calculated correctly from known data.
 */
class ReportServiceProfitLossTest extends TestCase
{
    /**
     * Test: P&L report with known invoices and expenses produces correct totals.
     *
     * Scenario:
     * - Revenue (PAID invoices): 5000 + 3000 = 8000
     * - COGS (RECEIVED/CLOSED POs): 2000
     * - Gross Profit: 8000 - 2000 = 6000
     * - Expenses: Office Supplies 500, Utilities 300 = 800
     * - Net Profit: 6000 - 800 = 5200
     */
    public function testProfitLossCalculation(): void
    {
        // Gross Revenue query returns 8000
        $revenueStmt = $this->createMock(PDOStatement::class);
        $revenueStmt->method('execute')->willReturn(true);
        $revenueStmt->method('fetchColumn')->willReturn('8000.00');

        // Credit notes query returns 0
        $creditNotesStmt = $this->createMock(PDOStatement::class);
        $creditNotesStmt->method('execute')->willReturn(true);
        $creditNotesStmt->method('fetchColumn')->willReturn('0.00');

        // COGS query returns 2000
        $cogsStmt = $this->createMock(PDOStatement::class);
        $cogsStmt->method('execute')->willReturn(true);
        $cogsStmt->method('fetchColumn')->willReturn('2000.00');

        // Expenses by category query returns two categories
        $expensesStmt = $this->createMock(PDOStatement::class);
        $expensesStmt->method('execute')->willReturn(true);
        $expensesStmt->method('fetchAll')->willReturn([
            ['category' => 'Office Supplies', 'total' => '500.00'],
            ['category' => 'Utilities', 'total' => '300.00'],
        ]);

        // Salaries query returns 0
        $salariesStmt = $this->createMock(PDOStatement::class);
        $salariesStmt->method('execute')->willReturn(true);
        $salariesStmt->method('fetchColumn')->willReturn('0.00');

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnOnConsecutiveCalls(
            $revenueStmt,
            $creditNotesStmt,
            $cogsStmt,
            $expensesStmt,
            $salariesStmt
        );

        $service = new ReportService($pdo);
        $result = $service->getProfitLoss(1, '2024-01-01', '2024-12-31');

        $this->assertEqualsWithDelta(8000.00, $result['revenue'], 0.001);
        $this->assertEqualsWithDelta(2000.00, $result['cogs'], 0.001);
        $this->assertEqualsWithDelta(6000.00, $result['gross_profit'], 0.001);
        $this->assertEqualsWithDelta(800.00, $result['total_expenses'], 0.001);
        $this->assertEqualsWithDelta(5200.00, $result['net_profit'], 0.001);
        $this->assertCount(2, $result['expenses_by_category']);
        $this->assertEquals('Office Supplies', $result['expenses_by_category'][0]['category']);
        $this->assertEqualsWithDelta(500.00, $result['expenses_by_category'][0]['total'], 0.001);
        $this->assertEquals('Utilities', $result['expenses_by_category'][1]['category']);
        $this->assertEqualsWithDelta(300.00, $result['expenses_by_category'][1]['total'], 0.001);
    }

    /**
     * Test: P&L report with zero revenue and zero expenses returns all zeros.
     */
    public function testProfitLossZeroValues(): void
    {
        $revenueStmt = $this->createMock(PDOStatement::class);
        $revenueStmt->method('execute')->willReturn(true);
        $revenueStmt->method('fetchColumn')->willReturn('0');

        $creditNotesStmt = $this->createMock(PDOStatement::class);
        $creditNotesStmt->method('execute')->willReturn(true);
        $creditNotesStmt->method('fetchColumn')->willReturn('0');

        $cogsStmt = $this->createMock(PDOStatement::class);
        $cogsStmt->method('execute')->willReturn(true);
        $cogsStmt->method('fetchColumn')->willReturn('0');

        $expensesStmt = $this->createMock(PDOStatement::class);
        $expensesStmt->method('execute')->willReturn(true);
        $expensesStmt->method('fetchAll')->willReturn([]);

        $salariesStmt = $this->createMock(PDOStatement::class);
        $salariesStmt->method('execute')->willReturn(true);
        $salariesStmt->method('fetchColumn')->willReturn('0');

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnOnConsecutiveCalls(
            $revenueStmt,
            $creditNotesStmt,
            $cogsStmt,
            $expensesStmt,
            $salariesStmt
        );

        $service = new ReportService($pdo);
        $result = $service->getProfitLoss(1, '2024-01-01', '2024-01-31');

        $this->assertEqualsWithDelta(0.00, $result['revenue'], 0.001);
        $this->assertEqualsWithDelta(0.00, $result['cogs'], 0.001);
        $this->assertEqualsWithDelta(0.00, $result['gross_profit'], 0.001);
        $this->assertEqualsWithDelta(0.00, $result['total_expenses'], 0.001);
        $this->assertEqualsWithDelta(0.00, $result['net_profit'], 0.001);
        $this->assertCount(0, $result['expenses_by_category']);
    }

    /**
     * Test: P&L report where COGS exceeds revenue results in negative gross profit.
     *
     * Scenario:
     * - Revenue: 1000
     * - COGS: 1500
     * - Gross Profit: 1000 - 1500 = -500
     * - Expenses: Rent 200
     * - Net Profit: -500 - 200 = -700
     */
    public function testProfitLossNegativeProfit(): void
    {
        $revenueStmt = $this->createMock(PDOStatement::class);
        $revenueStmt->method('execute')->willReturn(true);
        $revenueStmt->method('fetchColumn')->willReturn('1000.00');

        $creditNotesStmt = $this->createMock(PDOStatement::class);
        $creditNotesStmt->method('execute')->willReturn(true);
        $creditNotesStmt->method('fetchColumn')->willReturn('0');

        $cogsStmt = $this->createMock(PDOStatement::class);
        $cogsStmt->method('execute')->willReturn(true);
        $cogsStmt->method('fetchColumn')->willReturn('1500.00');

        $expensesStmt = $this->createMock(PDOStatement::class);
        $expensesStmt->method('execute')->willReturn(true);
        $expensesStmt->method('fetchAll')->willReturn([
            ['category' => 'Rent', 'total' => '200.00'],
        ]);

        $salariesStmt = $this->createMock(PDOStatement::class);
        $salariesStmt->method('execute')->willReturn(true);
        $salariesStmt->method('fetchColumn')->willReturn('0');

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnOnConsecutiveCalls(
            $revenueStmt,
            $creditNotesStmt,
            $cogsStmt,
            $expensesStmt,
            $salariesStmt
        );

        $service = new ReportService($pdo);
        $result = $service->getProfitLoss(1, '2024-06-01', '2024-06-30');

        $this->assertEqualsWithDelta(1000.00, $result['revenue'], 0.001);
        $this->assertEqualsWithDelta(1500.00, $result['cogs'], 0.001);
        $this->assertEqualsWithDelta(-500.00, $result['gross_profit'], 0.001);
        $this->assertEqualsWithDelta(200.00, $result['total_expenses'], 0.001);
        $this->assertEqualsWithDelta(-700.00, $result['net_profit'], 0.001);
    }

    /**
     * Test: P&L report returns correct period metadata.
     */
    public function testProfitLossReturnsPeriodMetadata(): void
    {
        $revenueStmt = $this->createMock(PDOStatement::class);
        $revenueStmt->method('execute')->willReturn(true);
        $revenueStmt->method('fetchColumn')->willReturn('0');

        $creditNotesStmt = $this->createMock(PDOStatement::class);
        $creditNotesStmt->method('execute')->willReturn(true);
        $creditNotesStmt->method('fetchColumn')->willReturn('0');

        $cogsStmt = $this->createMock(PDOStatement::class);
        $cogsStmt->method('execute')->willReturn(true);
        $cogsStmt->method('fetchColumn')->willReturn('0');

        $expensesStmt = $this->createMock(PDOStatement::class);
        $expensesStmt->method('execute')->willReturn(true);
        $expensesStmt->method('fetchAll')->willReturn([]);

        $salariesStmt = $this->createMock(PDOStatement::class);
        $salariesStmt->method('execute')->willReturn(true);
        $salariesStmt->method('fetchColumn')->willReturn('0');

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnOnConsecutiveCalls(
            $revenueStmt,
            $creditNotesStmt,
            $cogsStmt,
            $expensesStmt,
            $salariesStmt
        );

        $service = new ReportService($pdo);
        $result = $service->getProfitLoss(1, '2024-03-01', '2024-03-31');

        $this->assertEquals('2024-03-01', $result['period']['start']);
        $this->assertEquals('2024-03-31', $result['period']['end']);
    }
}
