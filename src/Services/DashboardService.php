<?php
namespace App\Services;

use App\Repository\DashboardRepository;
use App\Support\AuthContext;
use App\Services\ReportService;
use PDO;

class DashboardService {
    private DashboardRepository $repo;
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->repo = new DashboardRepository($db);
    }

    /**
     * Aggregate all dashboard data for the current business.
     *
     * @return array{
     *   revenue: float,
     *   expenses: float,
     *   net_profit: float,
     *   account_balances: array,
     *   recent_transactions: array,
     *   low_stock_items: array,
     *   month: int,
     *   year: int
     * }
     */
    public function getSummary(): array {
        $ctx        = AuthContext::get();
        $businessId = $ctx->business_id;

        $year  = (int) date('Y');
        $month = (int) date('n');

        $start = sprintf('%04d-%02d-01', $year, $month);
        $end   = date('Y-m-d'); // Until today

        // Use ReportService to match P&L report exact calculations
        $reportService = new ReportService($this->db);
        $pl = $reportService->getProfitLoss($businessId, $start, $end);

        $revenue = $pl['revenue'];
        $expenses = $pl['total_operating_expenses'];
        $netProfit = $pl['net_profit'];

        return [
            'revenue'             => $revenue,
            'expenses'            => $expenses,
            'net_profit'          => $netProfit,
            'account_balances'    => $this->repo->getAccountBalances($businessId),
            'recent_transactions' => $this->repo->getRecentTransactions($businessId, 10),
            'low_stock_items'     => $this->repo->getLowStockItems($businessId, 0),
            'earnings_overview'   => $this->repo->getAnnualEarningsOverview($businessId, $year),
            'expense_breakdown'   => $this->repo->getExpenseBreakdown($businessId, $year, $month),
            'month'               => $month,
            'year'                => $year,
        ];
    }
}
