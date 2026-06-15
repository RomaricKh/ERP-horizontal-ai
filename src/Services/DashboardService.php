<?php
namespace App\Services;

use App\Repository\DashboardRepository;
use App\Support\AuthContext;
use PDO;

class DashboardService {
    private DashboardRepository $repo;

    public function __construct(PDO $db) {
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

        $revenue  = $this->repo->getMonthlyRevenue($businessId, $year, $month);
        $expenses = $this->repo->getMonthlyExpenses($businessId, $year, $month);

        return [
            'revenue'             => $revenue,
            'expenses'            => $expenses,
            'net_profit'          => round($revenue - $expenses, 2),
            'account_balances'    => $this->repo->getAccountBalances($businessId),
            'recent_transactions' => $this->repo->getRecentTransactions($businessId, 10),
            'low_stock_items'     => $this->repo->getLowStockItems($businessId, 0),
            'month'               => $month,
            'year'                => $year,
        ];
    }
}
