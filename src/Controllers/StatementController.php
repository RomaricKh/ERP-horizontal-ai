<?php

namespace App\Controllers;

use App\Config\Database;
use App\Services\ReportService;
use App\Support\AuthContext;

class StatementController extends BaseController
{
    private ReportService $reportService;

    public function __construct()
    {
        $this->reportService = new ReportService(Database::getConnection());
    }

    /**
     * GET /reports/profit-loss?start=YYYY-MM-DD&end=YYYY-MM-DD
     */
    public function profitLoss(): void
    {
        try {
            $this->auth();
            $ctx = AuthContext::get();

            $start = $_GET['start'] ?? null;
            $end   = $_GET['end'] ?? null;

            if (!$start || !$end) {
                $this->json(false, null, 'Missing required query parameters: start, end', 400);
                return;
            }

            $report = $this->reportService->getProfitLoss($ctx->business_id, $start, $end);
            $this->json(true, ['report' => $report]);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to generate Profit & Loss report');
        }
    }

    /**
     * GET /reports/cash-flow?start=YYYY-MM-DD&end=YYYY-MM-DD
     */
    public function cashFlow(): void
    {
        try {
            $this->auth();
            $ctx = AuthContext::get();

            $start = $_GET['start'] ?? null;
            $end   = $_GET['end'] ?? null;

            if (!$start || !$end) {
                $this->json(false, null, 'Missing required query parameters: start, end', 400);
                return;
            }

            $report = $this->reportService->getCashFlow($ctx->business_id, $start, $end);
            $this->json(true, ['report' => $report]);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to generate Cash Flow report');
        }
    }

    /**
     * GET /reports/balance-sheet?as_of=YYYY-MM-DD
     */
    public function balanceSheet(): void
    {
        try {
            $this->auth();
            $ctx = AuthContext::get();

            $asOf = $_GET['as_of'] ?? date('Y-m-d');

            $report = $this->reportService->getBalanceSheet($ctx->business_id, $asOf);
            $this->json(true, ['report' => $report]);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to generate Balance Sheet report');
        }
    }
}
