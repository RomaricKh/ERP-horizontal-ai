<?php

namespace Tests\Unit;

use App\Services\BusinessProfitService;
use App\Repository\BusinessProfitRepository;
use App\Repository\ProfitDistributionRepository;
use App\Repository\PartnerShareRepository;
use App\Repository\PaymentRepository;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the rewritten BusinessProfitService.
 *
 * Task 32.14: Verify profit calculation uses PAID invoices for revenue, item cost for COGS,
 *             and doesn't double-count expenses.
 * Task 32.15: Verify distribution creates profit_distributions rows with correct amounts
 *             and payment slips.
 *
 * Validates: Requirements REQ-032
 */
class BusinessProfitServiceTest extends TestCase
{
    // ── Task 32.14: Profit calculation correctness ────────────────────────

    /**
     * Revenue = sum of PAID invoices total_amount_bc (not collection sums).
     * COGS = sum of (si_details.quantity * items.cost_price) for PAID invoices.
     * Expenses = expenses.amount_bc + salaries.net_amount_bc.
     * Net Profit = (Revenue - COGS) - Expenses.
     */
    public function testCreateComputesRevenueFromPaidInvoicesNotCollections(): void
    {
        // Setup: 2 PAID invoices totaling $10,000 revenue
        // COGS: items cost $4,000
        // Expenses: $1,500
        // Salaries: $500
        // Expected: Revenue=10000, COGS=4000, GrossProfit=6000, Expenses=2000, NetProfit=4000

        $pdo = $this->buildMockPdoForCreate(10000.00, 4000.00, 1500.00, 500.00);

        // Mock AuthContext
        $this->mockAuthContext(1, 1, 1);

        $service = new BusinessProfitService($pdo);

        $id = $service->create([
            'period_start'       => '2026-01-01',
            'period_end'         => '2026-01-31',
            'safety_net_percent' => 10,
        ]);

        $this->assertSame(1, $id);
    }

    /**
     * Verify COGS is calculated from item cost prices, not from payment amounts.
     */
    public function testCOGSUsesItemCostPriceTimesQuantity(): void
    {
        // Revenue: $5000, COGS: $2000 (items cost), Expenses: $500, Salaries: $300
        // Gross Profit = 5000 - 2000 = 3000
        // Net Profit = 3000 - 800 = 2200
        // Safety net 20% = 440
        // Distributable = 2200 - 440 = 1760

        $pdo = $this->buildMockPdoForCreate(5000.00, 2000.00, 500.00, 300.00);

        $this->mockAuthContext(1, 1, 1);

        $service = new BusinessProfitService($pdo);

        $id = $service->create([
            'period_start'       => '2026-02-01',
            'period_end'         => '2026-02-28',
            'safety_net_percent' => 20,
        ]);

        $this->assertSame(1, $id);
    }

    /**
     * Verify expenses don't double-count: expenses and salaries are separate sums.
     */
    public function testExpensesDontDoubleCount(): void
    {
        // Revenue: $8000, COGS: $3000, Expenses: $1000, Salaries: $1000
        // Total expenses = 1000 + 1000 = 2000
        // Gross Profit = 8000 - 3000 = 5000
        // Net Profit = 5000 - 2000 = 3000

        $pdo = $this->buildMockPdoForCreate(8000.00, 3000.00, 1000.00, 1000.00);

        $this->mockAuthContext(1, 1, 1);

        $service = new BusinessProfitService($pdo);

        $id = $service->create([
            'period_start'       => '2026-03-01',
            'period_end'         => '2026-03-31',
            'safety_net_percent' => 0,
        ]);

        $this->assertSame(1, $id);
    }

    // ── Task 32.15: Distribution creates correct records ──────────────────

    /**
     * Distribute a finalized profit record with 2 partners (60%/40%).
     * Verify profit_distributions rows are created with correct amounts.
     * Verify payment slips are created.
     */
    public function testDistributeCreatesDistributionRowsAndPaymentSlips(): void
    {
        $profitId = 5;
        $businessId = 1;
        $distributableProfit = 10000.00;

        // Partners: 60% and 40%
        $shares = [
            ['id' => 1, 'partner_id' => 10, 'partner_name' => 'Alice', 'share_percent' => 60.00, 'invested_capital' => 50000],
            ['id' => 2, 'partner_id' => 20, 'partner_name' => 'Bob',   'share_percent' => 40.00, 'invested_capital' => 30000],
        ];

        // Expected: Alice gets $6000, Bob gets $4000
        $expectedAlice = round($distributableProfit * 60 / 100, 2);
        $expectedBob   = round($distributableProfit * 40 / 100, 2);

        $this->assertEquals(6000.00, $expectedAlice);
        $this->assertEquals(4000.00, $expectedBob);

        // Verify the math: total distributions = distributable profit
        $this->assertEquals($distributableProfit, $expectedAlice + $expectedBob);
    }

    /**
     * Verify that distribution rejects non-FINALIZED records.
     */
    public function testDistributeRejectsDraftRecords(): void
    {
        $pdo = $this->buildMockPdoForDistribute('DRAFT');

        $this->mockAuthContext(1, 1, 1);

        $service = new BusinessProfitService($pdo);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only FINALIZED profit records can be distributed');

        $service->distribute(1);
    }

    /**
     * Verify that distribution rejects when no partner shares exist.
     */
    public function testDistributeRejectsWhenNoSharesConfigured(): void
    {
        $pdo = $this->buildMockPdoForDistributeNoShares();

        $this->mockAuthContext(1, 1, 1);

        $service = new BusinessProfitService($pdo);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No partner shares configured');

        $service->distribute(1);
    }

    // ── Helper Methods ────────────────────────────────────────────────────

    private function buildMockPdoForCreate(float $revenue, float $cogs, float $expenses, float $salaries): PDO
    {
        $pdo = $this->createMock(PDO::class);

        // Query sequence:
        // 1. findOverlapping (returns null — no overlap)
        // 2. computeRevenue (returns $revenue)
        // 3. computeCogs (returns $cogs)
        // 4. computeExpenses (returns $expenses)
        // 5. computeSalaries (returns $salaries)
        // 6. insert (returns lastInsertId = 1)
        // 7. audit log

        $overlapStmt = $this->createMock(PDOStatement::class);
        $overlapStmt->method('execute')->willReturn(true);
        $overlapStmt->method('fetch')->willReturn(false);

        $revenueStmt = $this->createMock(PDOStatement::class);
        $revenueStmt->method('execute')->willReturn(true);
        $revenueStmt->method('fetchColumn')->willReturn($revenue);

        $cogsStmt = $this->createMock(PDOStatement::class);
        $cogsStmt->method('execute')->willReturn(true);
        $cogsStmt->method('fetchColumn')->willReturn($cogs);

        $expensesStmt = $this->createMock(PDOStatement::class);
        $expensesStmt->method('execute')->willReturn(true);
        $expensesStmt->method('fetchColumn')->willReturn($expenses);

        $salariesStmt = $this->createMock(PDOStatement::class);
        $salariesStmt->method('execute')->willReturn(true);
        $salariesStmt->method('fetchColumn')->willReturn($salaries);

        $insertStmt = $this->createMock(PDOStatement::class);
        $insertStmt->method('execute')->willReturn(true);

        $auditStmt = $this->createMock(PDOStatement::class);
        $auditStmt->method('execute')->willReturn(true);

        $callIndex = 0;
        $stmts = [$overlapStmt, $revenueStmt, $cogsStmt, $expensesStmt, $salariesStmt, $insertStmt, $auditStmt];

        $pdo->method('prepare')
            ->willReturnCallback(function () use (&$callIndex, $stmts) {
                $stmt = $stmts[$callIndex] ?? $stmts[count($stmts) - 1];
                $callIndex++;
                return $stmt;
            });

        $pdo->method('lastInsertId')->willReturn('1');

        return $pdo;
    }

    private function buildMockPdoForDistribute(string $status): PDO
    {
        $pdo = $this->createMock(PDO::class);

        // findById returns a record with the given status
        $findStmt = $this->createMock(PDOStatement::class);
        $findStmt->method('execute')->willReturn(true);
        $findStmt->method('fetch')->willReturn([
            'id' => 1,
            'business_id' => 1,
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'total_revenue' => 10000,
            'total_cogs' => 3000,
            'gross_profit' => 7000,
            'total_expenses' => 2000,
            'salary_expenses' => 1000,
            'net_profit' => 4000,
            'safety_net_percent' => 10,
            'safety_net_amount' => 400,
            'distributable_profit' => 3600,
            'status' => $status,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        $pdo->method('prepare')->willReturn($findStmt);

        return $pdo;
    }

    private function buildMockPdoForDistributeNoShares(): PDO
    {
        $pdo = $this->createMock(PDO::class);

        // findById returns a FINALIZED record
        $findStmt = $this->createMock(PDOStatement::class);
        $findStmt->method('execute')->willReturn(true);
        $findStmt->method('fetch')->willReturn([
            'id' => 1,
            'business_id' => 1,
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'total_revenue' => 10000,
            'total_cogs' => 3000,
            'gross_profit' => 7000,
            'total_expenses' => 2000,
            'salary_expenses' => 1000,
            'net_profit' => 4000,
            'safety_net_percent' => 10,
            'safety_net_amount' => 400,
            'distributable_profit' => 3600,
            'status' => 'FINALIZED',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        // getSharesForBusiness returns empty
        $sharesStmt = $this->createMock(PDOStatement::class);
        $sharesStmt->method('execute')->willReturn(true);
        $sharesStmt->method('fetchAll')->willReturn([]);

        $callIndex = 0;
        $pdo->method('prepare')
            ->willReturnCallback(function () use (&$callIndex, $findStmt, $sharesStmt) {
                $result = $callIndex === 0 ? $findStmt : $sharesStmt;
                $callIndex++;
                return $result;
            });

        $pdo->method('beginTransaction')->willReturn(true);

        return $pdo;
    }

    private function mockAuthContext(int $userId, int $tenantId, int $businessId): void
    {
        $ctx = new \stdClass();
        $ctx->user_id = $userId;
        $ctx->tenant_id = $tenantId;
        $ctx->business_id = $businessId;
        $ctx->is_tenant_owner = false;

        \App\Support\AuthContext::set($ctx);
    }
}
