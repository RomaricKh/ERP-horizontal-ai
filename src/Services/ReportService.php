<?php

namespace App\Services;

use App\Repository\BusinessRepository;
use App\Repository\DashboardRepository;
use App\Repository\ExchangeRateRepository;
use App\Support\AuthContext;
use InvalidArgumentException;
use RuntimeException;
use PDO;

class ReportService
{
    private PDO $db;
    private BusinessRepository $businessRepo;
    private DashboardRepository $dashboardRepo;
    private ExchangeRateRepository $exchangeRateRepo;

    /**
     * Supported report types (Req 8.3).
     */
    private const SUPPORTED_REPORT_TYPES = [
        'profit-and-loss',
        'balance-sheet',
        'cash-flow',
        'accounts-receivable-aging',
        'accounts-payable-aging',
        'user-activity-summary',
    ];

    /**
     * Supported export formats (Req 8.4).
     */
    private const SUPPORTED_FORMATS = ['pdf', 'csv'];

    /**
     * Maximum date range span in years (Req 8.11).
     */
    private const MAX_DATE_RANGE_YEARS = 5;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->businessRepo = new BusinessRepository($db);
        $this->dashboardRepo = new DashboardRepository($db);
        $this->exchangeRateRepo = new ExchangeRateRepository($db);
    }

    /**
     * Generate a tenant-level report aggregating data across all businesses.
     *
     * Converts all amounts to the tenant's base currency using the most recent
     * exchange rate per currency pair. Includes a per-business breakdown.
     *
     * @param int    $tenantId   The tenant to report on
     * @param string $reportType One of the supported report types
     * @param string $startDate  Start date in YYYY-MM-DD format
     * @param string $endDate    End date in YYYY-MM-DD format
     * @param string $format     Export format: pdf or csv
     *
     * @return array Report data with totals, breakdown, and download_url
     *
     * @throws InvalidArgumentException on validation failure
     * @throws RuntimeException on missing exchange rate
     *
     * Requirements: 8.1, 8.5, 8.6, 8.8, 8.10, 8.11
     */
    public function generateTenantReport(int $tenantId, string $reportType, string $startDate, string $endDate, string $format): array
    {
        // Validate inputs
        $this->validateReportType($reportType);
        $this->validateFormat($format);
        $this->validateDateRange($startDate, $endDate);

        // Get tenant base currency
        $baseCurrency = $this->getTenantBaseCurrency($tenantId);

        // Get all businesses under the tenant
        $businesses = $this->getBusinessesForTenant($tenantId);

        $breakdown = [];
        $totalRevenue = 0.0;
        $totalExpenses = 0.0;

        foreach ($businesses as $business) {
            $businessData = $this->getBusinessFinancialData(
                (int) $business['id'],
                $reportType,
                $startDate,
                $endDate
            );

            // Convert to base currency if needed
            $businessCurrency = $business['currency'] ?? 'USD';
            $rate = $this->getExchangeRate($businessCurrency, $baseCurrency, $tenantId);

            $convertedRevenue = round($businessData['revenue'] * $rate, 2);
            $convertedExpenses = round($businessData['expenses'] * $rate, 2);
            $convertedNet = round($convertedRevenue - $convertedExpenses, 2);

            $breakdown[] = [
                'business_id' => (int) $business['id'],
                'business_name' => $business['name'],
                'revenue' => $convertedRevenue,
                'expenses' => $convertedExpenses,
                'net' => $convertedNet,
            ];

            $totalRevenue += $convertedRevenue;
            $totalExpenses += $convertedExpenses;
        }

        $totalNet = round($totalRevenue - $totalExpenses, 2);

        // Generate a download token
        $downloadToken = bin2hex(random_bytes(16));

        return [
            'report_type' => $reportType,
            'scope' => 'tenant',
            'date_range' => [
                'start' => $startDate,
                'end' => $endDate,
            ],
            'currency' => $baseCurrency,
            'format' => $format,
            'totals' => [
                'revenue' => $totalRevenue,
                'expenses' => $totalExpenses,
                'net' => $totalNet,
            ],
            'breakdown' => $breakdown,
            'download_url' => '/api/tenant/reports/download/' . $downloadToken,
        ];
    }

    /**
     * Generate a per-business report scoped to a single business.
     *
     * Validates that the business belongs to the specified tenant.
     * Allows access for Tenant Owners (Req 8.8) and Business Owners
     * with "reports.export" permission (Req 8.9).
     *
     * @param int    $tenantId   The tenant context
     * @param int    $businessId The business to report on
     * @param string $reportType One of the supported report types
     * @param string $startDate  Start date in YYYY-MM-DD format
     * @param string $endDate    End date in YYYY-MM-DD format
     * @param string $format     Export format: pdf or csv
     *
     * @return array Report data with totals and download_url
     *
     * @throws InvalidArgumentException on validation failure
     * @throws RuntimeException on access denial
     *
     * Requirements: 8.2, 8.7, 8.9, 8.11
     */
    public function generateBusinessReport(int $tenantId, int $businessId, string $reportType, string $startDate, string $endDate, string $format): array
    {
        // Validate inputs
        $this->validateReportType($reportType);
        $this->validateFormat($format);
        $this->validateDateRange($startDate, $endDate);

        // Validate business belongs to tenant (Req 8.2, 8.7)
        $this->validateBusinessBelongsToTenant($businessId, $tenantId);

        // Check access: Tenant Owner or Business Owner with "reports.export" permission (Req 8.9)
        $this->validateReportAccess($businessId);

        // Get business financial data
        $businessData = $this->getBusinessFinancialData(
            $businessId,
            $reportType,
            $startDate,
            $endDate
        );

        // Get business details
        $business = $this->businessRepo->getBusinessByID($businessId);
        $businessCurrency = $business['currency'] ?? 'USD';

        // Generate a download token
        $downloadToken = bin2hex(random_bytes(16));

        return [
            'report_type' => $reportType,
            'scope' => 'business',
            'business_id' => $businessId,
            'business_name' => $business['name'],
            'date_range' => [
                'start' => $startDate,
                'end' => $endDate,
            ],
            'currency' => $businessCurrency,
            'format' => $format,
            'totals' => [
                'revenue' => $businessData['revenue'],
                'expenses' => $businessData['expenses'],
                'net' => round($businessData['revenue'] - $businessData['expenses'], 2),
            ],
            'download_url' => '/api/tenant/reports/download/' . $downloadToken,
        ];
    }

    /**
     * Return the list of supported report types (Req 8.3).
     *
     * @return array
     */
    public function getSupportedReportTypes(): array
    {
        return self::SUPPORTED_REPORT_TYPES;
    }

    /* =====================================================================
     * Validation helpers
     * ===================================================================== */

    /**
     * Validate that the report type is supported.
     *
     * @throws InvalidArgumentException if unsupported
     */
    private function validateReportType(string $reportType): void
    {
        if (!in_array($reportType, self::SUPPORTED_REPORT_TYPES, true)) {
            throw new InvalidArgumentException(
                'Invalid report type. Supported types: ' . implode(', ', self::SUPPORTED_REPORT_TYPES)
            );
        }
    }

    /**
     * Validate that the export format is supported.
     *
     * @throws InvalidArgumentException if unsupported
     */
    private function validateFormat(string $format): void
    {
        if (!in_array($format, self::SUPPORTED_FORMATS, true)) {
            throw new InvalidArgumentException(
                'Invalid format. Supported formats: ' . implode(', ', self::SUPPORTED_FORMATS)
            );
        }
    }

    /**
     * Validate the date range: start <= end, max 5 years span (Req 8.11).
     *
     * @throws InvalidArgumentException on invalid date range
     */
    private function validateDateRange(string $startDate, string $endDate): void
    {
        // Validate date format (YYYY-MM-DD)
        if (!$this->isValidDate($startDate)) {
            throw new InvalidArgumentException('Invalid start_date format. Expected YYYY-MM-DD');
        }
        if (!$this->isValidDate($endDate)) {
            throw new InvalidArgumentException('Invalid end_date format. Expected YYYY-MM-DD');
        }

        $start = new \DateTime($startDate);
        $end = new \DateTime($endDate);

        // start must be <= end
        if ($start > $end) {
            throw new InvalidArgumentException('Invalid date range: start_date must be on or before end_date');
        }

        // Max span of 5 years
        $diff = $start->diff($end);
        $totalDays = $diff->days;
        $maxDays = self::MAX_DATE_RANGE_YEARS * 365 + 1; // account for leap year

        if ($totalDays > $maxDays) {
            throw new InvalidArgumentException('Invalid date range: maximum span is 5 years');
        }
    }

    /**
     * Check if a date string is a valid YYYY-MM-DD date.
     */
    private function isValidDate(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        $parts = explode('-', $date);
        return checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);
    }

    /**
     * Validate that a business belongs to the specified tenant (Req 8.2, 8.7).
     *
     * @throws RuntimeException if business does not belong to tenant
     */
    private function validateBusinessBelongsToTenant(int $businessId, int $tenantId): void
    {
        $businessTenantId = $this->businessRepo->getTenantIdForBusiness($businessId);

        if ($businessTenantId === null || (int) $businessTenantId !== $tenantId) {
            throw new RuntimeException('Business does not belong to your tenant');
        }
    }

    /**
     * Validate report access: Tenant Owner always allowed; Business Owners need "reports.export" (Req 8.9).
     *
     * @throws RuntimeException if access denied
     */
    private function validateReportAccess(int $businessId): void
    {
        $ctx = AuthContext::get();

        // Tenant Owners always have access (Req 8.8)
        if ($ctx->is_tenant_owner) {
            return;
        }

        // Business Owners need "reports.export" permission (Req 8.9)
        if (in_array('reports.export', $ctx->permissions ?? [], true)) {
            return;
        }

        throw new RuntimeException('Access denied: reports.export permission required');
    }

    /* =====================================================================
     * Data retrieval helpers
     * ===================================================================== */

    /**
     * Get the tenant's base currency from the tenants table.
     */
    private function getTenantBaseCurrency(int $tenantId): string
    {
        $stmt = $this->db->prepare("SELECT base_currency FROM tenants WHERE id = :tenant_id LIMIT 1");
        $stmt->execute(['tenant_id' => $tenantId]);
        $result = $stmt->fetchColumn();

        return $result ?: 'USD';
    }

    /**
     * Get all businesses for a tenant.
     */
    private function getBusinessesForTenant(int $tenantId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, name, currency
            FROM businesses
            WHERE tenant_id = :tenant_id
            ORDER BY id ASC
        ");
        $stmt->execute(['tenant_id' => $tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get financial data for a single business within a date range.
     *
     * Returns revenue and expenses based on the report type.
     * For financial reports (profit-and-loss, balance-sheet, cash-flow),
     * aggregates from sales_invoices and expenses tables.
     * For aging reports, aggregates outstanding amounts.
     * For user-activity-summary, returns zero financial values.
     */
    private function getBusinessFinancialData(int $businessId, string $reportType, string $startDate, string $endDate): array
    {
        switch ($reportType) {
            case 'profit-and-loss':
            case 'balance-sheet':
            case 'cash-flow':
                return $this->getFinancialTotals($businessId, $startDate, $endDate);

            case 'accounts-receivable-aging':
                return $this->getReceivableAging($businessId, $startDate, $endDate);

            case 'accounts-payable-aging':
                return $this->getPayableAging($businessId, $startDate, $endDate);

            case 'user-activity-summary':
                return ['revenue' => 0.0, 'expenses' => 0.0];

            default:
                return ['revenue' => 0.0, 'expenses' => 0.0];
        }
    }

    /**
     * Get revenue and expenses totals for a business within a date range.
     * Revenue = sum of total_amount_bc from paid sales_invoices.
     * Expenses = sum of amount_bc from expenses.
     */
    private function getFinancialTotals(int $businessId, string $startDate, string $endDate): array
    {
        // Revenue from paid sales invoices
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(total_amount_bc), 0)
            FROM sales_invoices
            WHERE business_id = :business_id
              AND status = 'PAID'
              AND invoice_date >= :start_date
              AND invoice_date <= :end_date
        ");
        $stmt->execute([
            'business_id' => $businessId,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        $revenue = (float) $stmt->fetchColumn();

        // Expenses
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount_bc), 0)
            FROM expenses
            WHERE business_id = :business_id
              AND expense_date >= :start_date
              AND expense_date <= :end_date
        ");
        $stmt->execute([
            'business_id' => $businessId,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        $expenses = (float) $stmt->fetchColumn();

        return ['revenue' => $revenue, 'expenses' => $expenses];
    }

    /**
     * Get accounts receivable aging data for a business.
     * Revenue = total outstanding receivables (unpaid sales invoices).
     * Expenses = 0 (not applicable for this report type).
     */
    private function getReceivableAging(int $businessId, string $startDate, string $endDate): array
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(total_amount_bc), 0)
            FROM sales_invoices
            WHERE business_id = :business_id
              AND status != 'PAID'
              AND invoice_date >= :start_date
              AND invoice_date <= :end_date
        ");
        $stmt->execute([
            'business_id' => $businessId,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        $outstanding = (float) $stmt->fetchColumn();

        return ['revenue' => $outstanding, 'expenses' => 0.0];
    }

    /**
     * Get accounts payable aging data for a business.
     * Revenue = 0 (not applicable for this report type).
     * Expenses = total outstanding payables (unpaid purchase orders).
     */
    private function getPayableAging(int $businessId, string $startDate, string $endDate): array
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(total_amount_bc), 0)
            FROM purchase_orders
            WHERE business_id = :business_id
              AND status != 'PAID'
              AND order_date >= :start_date
              AND order_date <= :end_date
        ");
        $stmt->execute([
            'business_id' => $businessId,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        $outstanding = (float) $stmt->fetchColumn();

        return ['revenue' => 0.0, 'expenses' => $outstanding];
    }

    /**
     * Get the exchange rate to convert from a source currency to the base currency.
     *
     * If source and base are the same, returns 1.0.
     * Looks up the most recent exchange rate from the exchange_rates table
     * for the given tenant.
     *
     * @throws RuntimeException if exchange rate is missing (Req 8.10)
     */
    private function getExchangeRate(string $fromCurrency, string $baseCurrency, int $tenantId): float
    {
        // Same currency — no conversion needed
        if ($fromCurrency === $baseCurrency) {
            return 1.0;
        }

        // Look up the latest exchange rate for this currency pair within the tenant
        $stmt = $this->db->prepare("
            SELECT rate
            FROM exchange_rates
            WHERE tenant_id = :tenant_id
              AND currency = :currency
            ORDER BY rate_date DESC
            LIMIT 1
        ");
        $stmt->execute([
            'tenant_id' => $tenantId,
            'currency' => $fromCurrency,
        ]);
        $rate = $stmt->fetchColumn();

        if ($rate === false || $rate === null) {
            throw new RuntimeException(
                "Missing exchange rate for currency pair: {$fromCurrency}/{$baseCurrency}"
            );
        }

        return (float) $rate;
    }

    /* =====================================================================
     * Business-level Financial Statements (REQ-016)
     * ===================================================================== */

    /**
     * Generate a Profit & Loss statement for a business within a date range.
     *
     * Revenue = sum of total_amount_bc from PAID sales invoices in the period.
     * COGS = sum of po_details total_price_bc for RECEIVED/CLOSED POs in the period.
     * Gross Profit = Revenue - COGS.
     * Expenses = grouped by expense_category.
     * Net Profit = Gross Profit - Total Expenses.
     *
     * @param int    $businessId
     * @param string $start Start date (YYYY-MM-DD)
     * @param string $end   End date (YYYY-MM-DD)
     * @return array
     */
    public function getProfitLoss(int $businessId, string $start, string $end): array
    {
        // Revenue: PAID invoices in the period
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(total_amount_bc), 0) AS revenue
            FROM sales_invoices
            WHERE business_id = :business_id
              AND status = 'PAID'
              AND invoice_date >= :start_date
              AND invoice_date <= :end_date
        ");
        $stmt->execute([
            'business_id' => $businessId,
            'start_date'  => $start,
            'end_date'    => $end,
        ]);
        $revenue = (float) $stmt->fetchColumn();

        // COGS: sum of line totals from RECEIVED/CLOSED POs in the period
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(pd.total_price_bc), 0) AS cogs
            FROM po_details pd
            JOIN purchase_orders po ON po.id = pd.purchase_order_id
            WHERE po.business_id = :business_id
              AND po.status IN ('RECEIVED', 'CLOSED')
              AND po.po_date >= :start_date
              AND po.po_date <= :end_date
        ");
        $stmt->execute([
            'business_id' => $businessId,
            'start_date'  => $start,
            'end_date'    => $end,
        ]);
        $cogs = (float) $stmt->fetchColumn();

        $grossProfit = round($revenue - $cogs, 2);

        // Expenses by category
        $stmt = $this->db->prepare("
            SELECT ec.name AS category, COALESCE(SUM(e.amount_bc), 0) AS total
            FROM expenses e
            JOIN expense_categories ec ON ec.id = e.expense_category_id
            WHERE e.business_id = :business_id
              AND e.expense_date >= :start_date
              AND e.expense_date <= :end_date
            GROUP BY ec.id, ec.name
            ORDER BY total DESC
        ");
        $stmt->execute([
            'business_id' => $businessId,
            'start_date'  => $start,
            'end_date'    => $end,
        ]);
        $expensesByCategory = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalExpenses = 0.0;
        foreach ($expensesByCategory as &$row) {
            $row['total'] = (float) $row['total'];
            $totalExpenses += $row['total'];
        }
        unset($row);

        $netProfit = round($grossProfit - $totalExpenses, 2);

        return [
            'period'              => ['start' => $start, 'end' => $end],
            'revenue'             => $revenue,
            'cogs'                => $cogs,
            'gross_profit'        => $grossProfit,
            'expenses_by_category' => $expensesByCategory,
            'total_expenses'      => round($totalExpenses, 2),
            'net_profit'          => $netProfit,
        ];
    }

    /**
     * Generate a Cash Flow statement for a business within a date range.
     *
     * Inflows = sum of collection_slips.amount_bc in the period.
     * Outflows = payments + salaries + expenses paid in the period.
     * Net Movement = Inflows - Outflows.
     *
     * @param int    $businessId
     * @param string $start Start date (YYYY-MM-DD)
     * @param string $end   End date (YYYY-MM-DD)
     * @return array
     */
    public function getCashFlow(int $businessId, string $start, string $end): array
    {
        // Inflows: collections received
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount_bc), 0) AS total
            FROM collection_slips
            WHERE business_id = :business_id
              AND slip_date >= :start_date
              AND slip_date <= :end_date
        ");
        $stmt->execute([
            'business_id' => $businessId,
            'start_date'  => $start,
            'end_date'    => $end,
        ]);
        $collections = (float) $stmt->fetchColumn();

        // Outflows: payments made
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount_bc), 0) AS total
            FROM payment_slips
            WHERE business_id = :business_id
              AND payment_date >= :start_date
              AND payment_date <= :end_date
        ");
        $stmt->execute([
            'business_id' => $businessId,
            'start_date'  => $start,
            'end_date'    => $end,
        ]);
        $payments = (float) $stmt->fetchColumn();

        // Outflows: salaries paid
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(net_amount_bc), 0) AS total
            FROM salaries
            WHERE business_id = :business_id
              AND period_start >= :start_date
              AND period_end <= :end_date
        ");
        $stmt->execute([
            'business_id' => $businessId,
            'start_date'  => $start,
            'end_date'    => $end,
        ]);
        $salaries = (float) $stmt->fetchColumn();

        // Outflows: expenses
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount_bc), 0) AS total
            FROM expenses
            WHERE business_id = :business_id
              AND expense_date >= :start_date
              AND expense_date <= :end_date
        ");
        $stmt->execute([
            'business_id' => $businessId,
            'start_date'  => $start,
            'end_date'    => $end,
        ]);
        $expenses = (float) $stmt->fetchColumn();

        $totalOutflows = round($payments + $salaries + $expenses, 2);
        $netMovement   = round($collections - $totalOutflows, 2);

        return [
            'period'   => ['start' => $start, 'end' => $end],
            'inflows'  => [
                'collections' => $collections,
                'total'       => $collections,
            ],
            'outflows' => [
                'payments' => $payments,
                'salaries' => $salaries,
                'expenses' => $expenses,
                'total'    => $totalOutflows,
            ],
            'net_movement' => $netMovement,
        ];
    }

    /**
     * Generate a Balance Sheet for a business as of a given date.
     *
     * Assets = account balances + inventory value + fixed asset current values.
     * Liabilities = outstanding payables (POs not CLOSED/CANCELLED).
     * Equity = sum of partner invested_capital.
     *
     * @param int    $businessId
     * @param string $asOf Date (YYYY-MM-DD)
     * @return array
     */
    public function getBalanceSheet(int $businessId, string $asOf): array
    {
        // Assets: account balances
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(balance), 0) AS total
            FROM business_accounts
            WHERE business_id = :business_id
        ");
        $stmt->execute(['business_id' => $businessId]);
        $accountBalances = (float) $stmt->fetchColumn();

        // Assets: inventory value (stock qty × cost_price)
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(s.quantity * i.cost_price), 0) AS total
            FROM stock s
            JOIN items i ON i.id = s.item_id
            WHERE s.business_id = :business_id
              AND i.cost_price IS NOT NULL
        ");
        $stmt->execute(['business_id' => $businessId]);
        $inventoryValue = (float) $stmt->fetchColumn();

        // Assets: fixed assets (current_value_bc for assets purchased on or before asOf)
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(current_value_bc), 0) AS total
            FROM assets
            WHERE business_id = :business_id
              AND purchase_date <= :as_of
        ");
        $stmt->execute([
            'business_id' => $businessId,
            'as_of'       => $asOf,
        ]);
        $fixedAssets = (float) $stmt->fetchColumn();

        $totalAssets = round($accountBalances + $inventoryValue + $fixedAssets, 2);

        // Liabilities: outstanding payables (POs not CLOSED/CANCELLED, created on or before asOf)
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(total_amount_bc), 0) AS total
            FROM purchase_orders
            WHERE business_id = :business_id
              AND status NOT IN ('CLOSED', 'CANCELLED')
              AND po_date <= :as_of
        ");
        $stmt->execute([
            'business_id' => $businessId,
            'as_of'       => $asOf,
        ]);
        $outstandingPayables = (float) $stmt->fetchColumn();

        $totalLiabilities = round($outstandingPayables, 2);

        // Equity: partner shares invested capital
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(invested_capital), 0) AS total
            FROM partner_shares
            WHERE business_id = :business_id
        ");
        $stmt->execute(['business_id' => $businessId]);
        $partnerEquity = (float) $stmt->fetchColumn();

        $totalEquity = round($partnerEquity, 2);

        return [
            'as_of'       => $asOf,
            'assets'      => [
                'accounts'       => $accountBalances,
                'inventory'      => $inventoryValue,
                'fixed_assets'   => $fixedAssets,
                'total'          => $totalAssets,
            ],
            'liabilities' => [
                'outstanding_payables' => $outstandingPayables,
                'total'                => $totalLiabilities,
            ],
            'equity'      => [
                'partner_shares' => $partnerEquity,
                'total'          => $totalEquity,
            ],
        ];
    }
}
