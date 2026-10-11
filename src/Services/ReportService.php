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
            $rate = $this->getExchangeRate($businessCurrency, $baseCurrency, (int) $business['id']);

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
        if (!empty($ctx->is_tenant_owner)) {
            return;
        }

        // Business Owners need "reports.export" permission AND must be requesting for their active business (Req 8.9)
        if ($ctx->business_id === $businessId && in_array('reports.export', $ctx->permissions ?? [], true)) {
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
        // Revenue from DELIVERED + PAID invoices (minus credit notes)
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(total_amount_bc), 0) FROM sales_invoices
            WHERE business_id = :bid AND status IN ('DELIVERED', 'PAID')
              AND invoice_date >= :s AND invoice_date <= :e
        ");
        $stmt->execute(['bid' => $businessId, 's' => $startDate, 'e' => $endDate]);
        $revenue = (float) $stmt->fetchColumn();

        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(cn.total_amount_bc), 0) FROM credit_notes cn
            JOIN sales_invoices si ON si.id = cn.sales_invoice_id
            WHERE cn.business_id = :bid AND cn.status = 'ISSUED'
              AND cn.credit_note_date >= :s AND cn.credit_note_date <= :e
        ");
        $stmt->execute(['bid' => $businessId, 's' => $startDate, 'e' => $endDate]);
        $revenue -= (float) $stmt->fetchColumn();

        // Expenses (operating costs — not payments)
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount_bc), 0) FROM expenses
            WHERE business_id = :bid AND expense_date >= :s AND expense_date <= :e
        ");
        $stmt->execute(['bid' => $businessId, 's' => $startDate, 'e' => $endDate]);
        $expenses = (float) $stmt->fetchColumn();

        // Add salaries
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(net_amount_bc), 0) FROM salaries
            WHERE business_id = :bid AND period_start >= :s AND period_start <= :e
        ");
        $stmt->execute(['bid' => $businessId, 's' => $startDate, 'e' => $endDate]);
        $expenses += (float) $stmt->fetchColumn();

        return ['revenue' => round($revenue, 2), 'expenses' => round($expenses, 2)];
    }

    /**
     * Get accounts receivable aging data for a business.
     * Revenue = total outstanding receivables (unpaid sales invoices).
     * Expenses = 0 (not applicable for this report type).
     */
    private function getReceivableAging(int $businessId, string $startDate, string $endDate): array
    {
        // Outstanding receivables: DELIVERED invoices not fully collected (net of credit notes)
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(total_amount_bc), 0)
            FROM sales_invoices
            WHERE business_id = :business_id
              AND status = 'DELIVERED'
              AND invoice_date >= :start_date
              AND invoice_date <= :end_date
        ");
        $stmt->execute([
            'business_id' => $businessId,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        $invTotal = (float) $stmt->fetchColumn();

        // Subtract credit notes
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(cn.total_amount_bc), 0)
            FROM credit_notes cn
            JOIN sales_invoices si ON si.id = cn.sales_invoice_id
            WHERE cn.business_id = :business_id AND cn.status = 'ISSUED'
              AND si.invoice_date >= :start_date AND si.invoice_date <= :end_date
        ");
        $stmt->execute(['business_id' => $businessId, 'start_date' => $startDate, 'end_date' => $endDate]);
        $cnTotal = (float) $stmt->fetchColumn();

        // Subtract collections allocated to invoices in this period
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(ca.amount_bc), 0)
            FROM collection_allocations ca
            JOIN collection_slips cs ON cs.id = ca.collection_slip_id
            JOIN sales_invoices si ON si.id = ca.reference_id
            WHERE cs.business_id = :business_id AND ca.reference_type = 'SALES_INVOICE'
              AND si.invoice_date >= :start_date AND si.invoice_date <= :end_date
        ");
        $stmt->execute(['business_id' => $businessId, 'start_date' => $startDate, 'end_date' => $endDate]);
        $collTotal = (float) $stmt->fetchColumn();

        $outstanding = round(max(0, $invTotal - $cnTotal - $collTotal), 2);

        return ['revenue' => $outstanding, 'expenses' => 0.0];
    }

    /**
     * Get accounts payable aging data for a business.
     * Revenue = 0 (not applicable for this report type).
     * Expenses = total outstanding payables (unpaid purchase orders).
     */
    private function getPayableAging(int $businessId, string $startDate, string $endDate): array
    {
        // Outstanding payables: RECEIVED POs not fully paid (net of debit notes and payments)
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(po.total_amount_bc), 0)
            FROM purchase_orders po
            WHERE po.business_id = :business_id
              AND po.status = 'RECEIVED'
              AND po.po_date >= :start_date
              AND po.po_date <= :end_date
        ");
        $stmt->execute([
            'business_id' => $businessId,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        $poTotal = (float) $stmt->fetchColumn();

        // Subtract debit notes
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(dn.total_amount_bc), 0)
            FROM debit_notes dn
            JOIN purchase_orders po ON po.id = dn.purchase_order_id
            WHERE dn.business_id = :business_id AND dn.status = 'ISSUED'
              AND po.po_date >= :start_date AND po.po_date <= :end_date
        ");
        $stmt->execute(['business_id' => $businessId, 'start_date' => $startDate, 'end_date' => $endDate]);
        $dnTotal = (float) $stmt->fetchColumn();

        // Subtract payments allocated to POs
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(pa.amount_bc), 0)
            FROM payment_allocations pa
            JOIN payment_slips ps ON ps.id = pa.payment_slip_id
            JOIN purchase_orders po ON po.id = pa.reference_id
            WHERE ps.business_id = :business_id AND pa.reference_type = 'PURCHASE_ORDER'
              AND po.po_date >= :start_date AND po.po_date <= :end_date
        ");
        $stmt->execute(['business_id' => $businessId, 'start_date' => $startDate, 'end_date' => $endDate]);
        $paidTotal = (float) $stmt->fetchColumn();

        $outstanding = round(max(0, $poTotal - $dnTotal - $paidTotal), 2);

        return ['revenue' => 0.0, 'expenses' => $outstanding];
    }

    /**
     * Get the exchange rate to convert from a source currency to the base currency.
     *
     * If source and base are the same, returns 1.0.
     * Looks up the most recent exchange rate from the exchange_rates table
     * scoped to the specific business (Req 8.10).
     *
     * @throws RuntimeException if exchange rate is missing
     */
    private function getExchangeRate(string $fromCurrency, string $baseCurrency, int $businessId): float
    {
        // Same currency — no conversion needed
        if ($fromCurrency === $baseCurrency) {
            return 1.0;
        }

        // Look up the latest exchange rate for this currency scoped to the business
        $row = $this->exchangeRateRepo->getLatestRate($fromCurrency, $businessId);

        if (!$row) {
            throw new RuntimeException(
                "Missing exchange rate for currency pair: {$fromCurrency}/{$baseCurrency}"
            );
        }

        return (float) $row['rate'];
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
        // Revenue: DELIVERED + PAID invoices
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(total_amount_bc), 0)
            FROM sales_invoices
            WHERE business_id = :bid AND status IN ('DELIVERED', 'PAID')
              AND invoice_date >= :s AND invoice_date <= :e
        ");
        $stmt->execute(['bid' => $businessId, 's' => $start, 'e' => $end]);
        $grossRevenue = (float) $stmt->fetchColumn();

        // Subtract credit notes
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(cn.total_amount_bc), 0)
            FROM credit_notes cn JOIN sales_invoices si ON si.id = cn.sales_invoice_id
            WHERE cn.business_id = :bid AND cn.status = 'ISSUED'
              AND cn.credit_note_date >= :s AND cn.credit_note_date <= :e
        ");
        $stmt->execute(['bid' => $businessId, 's' => $start, 'e' => $end]);
        $creditNotes = (float) $stmt->fetchColumn();
        $revenue = round($grossRevenue - $creditNotes, 2);

        // COGS from delivered invoice items
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(sd.quantity * COALESCE(i.cost_price, 0)), 0)
            FROM si_details sd JOIN sales_invoices si ON si.id = sd.sales_invoice_id
            LEFT JOIN items i ON i.id = sd.item_id
            WHERE si.business_id = :bid AND si.status IN ('DELIVERED', 'PAID')
              AND si.invoice_date >= :s AND si.invoice_date <= :e AND sd.item_id IS NOT NULL
        ");
        $stmt->execute(['bid' => $businessId, 's' => $start, 'e' => $end]);
        $cogs = (float) $stmt->fetchColumn();
        $grossProfit = round($revenue - $cogs, 2);

        // Expenses by category
        $stmt = $this->db->prepare("
            SELECT ec.name AS category, COALESCE(SUM(e.amount_bc), 0) AS total
            FROM expenses e JOIN expense_categories ec ON ec.id = e.expense_category_id
            WHERE e.business_id = :bid AND e.expense_date >= :s AND e.expense_date <= :e
            GROUP BY ec.id, ec.name ORDER BY total DESC
        ");
        $stmt->execute(['bid' => $businessId, 's' => $start, 'e' => $end]);
        $expensesByCategory = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $totalExpenses = 0.0;
        foreach ($expensesByCategory as &$row) { $row['total'] = (float) $row['total']; $totalExpenses += $row['total']; }
        unset($row);

        // Salaries
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(net_amount_bc), 0) FROM salaries
            WHERE business_id = :bid AND period_start >= :s AND period_start <= :e
        ");
        $stmt->execute(['bid' => $businessId, 's' => $start, 'e' => $end]);
        $salaryExpenses = (float) $stmt->fetchColumn();

        $totalOperating = round($totalExpenses + $salaryExpenses, 2);
        $netProfit = round($grossProfit - $totalOperating, 2);

        return [
            'period' => ['start' => $start, 'end' => $end],
            'gross_revenue' => $grossRevenue, 'credit_notes' => $creditNotes,
            'revenue' => $revenue, 'cogs' => $cogs, 'gross_profit' => $grossProfit,
            'expenses_by_category' => $expensesByCategory,
            'total_expenses' => round($totalExpenses, 2), 'salary_expenses' => $salaryExpenses,
            'total_operating_expenses' => $totalOperating, 'net_profit' => $netProfit,
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
        $exchangeRateService = new ExchangeRateService($this->db);

        // Inflows: collection slips (dynamically converted)
        $stmtIn = $this->db->prepare("
            SELECT csl.currency, COALESCE(SUM(csl.amount_fc), 0) as total_fc
            FROM collection_slip_lines csl
            JOIN collection_slips cs ON cs.id = csl.collection_slip_id
            WHERE cs.business_id = :bid AND cs.slip_date >= :s AND cs.slip_date <= :e
            GROUP BY csl.currency
        ");
        $stmtIn->execute(['bid' => $businessId, 's' => $start, 'e' => $end]);
        $inByCurrency = $stmtIn->fetchAll(\PDO::FETCH_ASSOC);

        $collections = 0.0;
        foreach ($inByCurrency as $row) {
            if (empty($row['currency'])) {
                $collections += (float) $row['total_fc'];
                continue;
            }
            $rateLookup = $exchangeRateService->getLatestForLookupAsOf($row['currency'], $end);
            $rate = $rateLookup['rate'] !== null ? (float) $rateLookup['rate'] : 1.0;
            $collections += round((float) $row['total_fc'] * $rate, 2);
        }

        // Outflows: total payment slips (dynamically converted)
        $stmtOut = $this->db->prepare("
            SELECT psl.currency, COALESCE(SUM(psl.amount_fc), 0) as total_fc
            FROM payment_slip_lines psl
            JOIN payment_slips ps ON ps.id = psl.payment_slip_id
            WHERE ps.business_id = :bid AND ps.payment_date >= :s AND ps.payment_date <= :e
            GROUP BY psl.currency
        ");
        $stmtOut->execute(['bid' => $businessId, 's' => $start, 'e' => $end]);
        $outByCurrency = $stmtOut->fetchAll(\PDO::FETCH_ASSOC);

        $payments = 0.0;
        foreach ($outByCurrency as $row) {
            if (empty($row['currency'])) {
                $payments += (float) $row['total_fc'];
                continue;
            }
            $rateLookup = $exchangeRateService->getLatestForLookupAsOf($row['currency'], $end);
            $rate = $rateLookup['rate'] !== null ? (float) $rateLookup['rate'] : 1.0;
            $payments += round((float) $row['total_fc'] * $rate, 2);
        }

        // We need historical payments to calculate the conversion ratio for allocations
        $stmtHistOut = $this->db->prepare("
            SELECT COALESCE(SUM(total_bc), 0) FROM payment_slips
            WHERE business_id = :bid AND payment_date >= :s AND payment_date <= :e
        ");
        $stmtHistOut->execute(['bid' => $businessId, 's' => $start, 'e' => $end]);
        $historicalPayments = (float) $stmtHistOut->fetchColumn();

        $conversionRatio = $historicalPayments > 0 ? ($payments / $historicalPayments) : 1.0;

        // Categorize payments based on payment_allocations
        $stmt = $this->db->prepare("
            SELECT pa.reference_type, COALESCE(SUM(pa.amount_bc), 0) as allocated_amount
            FROM payment_allocations pa
            JOIN payment_slips ps ON ps.id = pa.payment_slip_id
            WHERE ps.business_id = :bid AND ps.payment_date >= :s AND ps.payment_date <= :e
            GROUP BY pa.reference_type
        ");
        $stmt->execute(['bid' => $businessId, 's' => $start, 'e' => $end]);
        $allocations = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $outflows = [
            'expenses' => 0.0,
            'purchase_orders' => 0.0,
            'salaries' => 0.0,
            'other_allocations' => 0.0,
            'unallocated' => $payments,
            'total' => $payments
        ];

        $totalAllocated = 0.0;
        foreach ($allocations as $alloc) {
            $amt = round((float) $alloc['allocated_amount'] * $conversionRatio, 2);
            $totalAllocated += $amt;
            switch ($alloc['reference_type']) {
                case 'EXPENSE':
                    $outflows['expenses'] += $amt;
                    break;
                case 'PURCHASE_ORDER':
                    $outflows['purchase_orders'] += $amt;
                    break;
                case 'SALARY':
                    $outflows['salaries'] += $amt;
                    break;
                default:
                    $outflows['other_allocations'] += $amt;
                    break;
            }
        }

        $outflows['unallocated'] = round($payments - $totalAllocated, 2);
        if ($outflows['unallocated'] < 0) {
            $outflows['unallocated'] = 0.0; // Avoid rounding errors showing -0.00
        }

        $netMovement = round($collections - $payments, 2);

        return [
            'period' => ['start' => $start, 'end' => $end],
            'inflows' => ['collections' => $collections, 'total' => $collections],
            'outflows' => $outflows,
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
        // ASSETS: Cash at Bank (dynamically evaluated using market value as of $asOf)
        $cashBank = 0.0;
        $stmtAccounts = $this->db->prepare("SELECT id, currency FROM business_accounts WHERE business_id = :bid");
        $stmtAccounts->execute(['bid' => $businessId]);
        $accounts = $stmtAccounts->fetchAll(\PDO::FETCH_ASSOC);

        $exchangeRateService = new ExchangeRateService($this->db);
        foreach ($accounts as $account) {
            // 1. Inflows
            $stmtIn = $this->db->prepare("SELECT COALESCE(SUM(csl.amount_fc), 0) FROM collection_slip_lines csl JOIN collection_slips cs ON cs.id = csl.collection_slip_id WHERE csl.account_id = :aid AND cs.slip_date <= :d");
            $stmtIn->execute(['aid' => $account['id'], 'd' => $asOf]);
            $in = (float)$stmtIn->fetchColumn();

            // 2. Outflows
            $stmtOut = $this->db->prepare("SELECT COALESCE(SUM(psl.amount_fc), 0) FROM payment_slip_lines psl JOIN payment_slips ps ON ps.id = psl.payment_slip_id WHERE psl.account_id = :aid AND ps.payment_date <= :d");
            $stmtOut->execute(['aid' => $account['id'], 'd' => $asOf]);
            $out = (float)$stmtOut->fetchColumn();
            
            // 3. Transfers In
            $stmtTransIn = $this->db->prepare("SELECT COALESCE(SUM(amount_to), 0) FROM account_transfers WHERE to_account_id = :aid AND transfer_date <= :d");
            $stmtTransIn->execute(['aid' => $account['id'], 'd' => $asOf]);
            $transIn = (float)$stmtTransIn->fetchColumn();
            
            // 4. Transfers Out
            $stmtTransOut = $this->db->prepare("SELECT COALESCE(SUM(amount_from), 0) FROM account_transfers WHERE from_account_id = :aid AND transfer_date <= :d");
            $stmtTransOut->execute(['aid' => $account['id'], 'd' => $asOf]);
            $transOut = (float)$stmtTransOut->fetchColumn();

            $historicalFC = $in - $out + $transIn - $transOut;

            // Convert to BC using the exchange rate active on the $asOf date
            $lookup = $exchangeRateService->getLatestForLookupAsOf($account['currency'], $asOf);
            $rate = $lookup['rate'] !== null ? (float)$lookup['rate'] : 1.0;
            
            if (!$lookup['is_base'] && $rate > 0) {
                $cashBank += round($historicalFC * $rate, 2);
            } else {
                $cashBank += round($historicalFC, 2);
            }
        }

        // AR: delivered invoices - credit notes - collections received
        $ar = 0.0;
        $arByCurrency = [];
        $stmt = $this->db->prepare("SELECT currency, COALESCE(SUM(total_amount_fc), 0) as fc FROM sales_invoices WHERE business_id = :bid AND status = 'DELIVERED' AND invoice_date <= :d GROUP BY currency");
        $stmt->execute(['bid' => $businessId, 'd' => $asOf]);
        foreach ($stmt->fetchAll() as $row) { $arByCurrency[$row['currency']] = (float)$row['fc']; }
        
        $stmt = $this->db->prepare("SELECT si.currency, COALESCE(SUM(cn.total_amount_fc), 0) as fc FROM credit_notes cn JOIN sales_invoices si ON si.id = cn.sales_invoice_id WHERE cn.business_id = :bid AND cn.status = 'ISSUED' AND cn.credit_note_date <= :d GROUP BY si.currency");
        $stmt->execute(['bid' => $businessId, 'd' => $asOf]);
        foreach ($stmt->fetchAll() as $row) { $arByCurrency[$row['currency']] = ($arByCurrency[$row['currency']] ?? 0.0) - (float)$row['fc']; }
        
        $stmt = $this->db->prepare("SELECT si.currency, COALESCE(SUM(ca.amount_fc), 0) as fc FROM collection_allocations ca JOIN collection_slips cs ON cs.id = ca.collection_slip_id JOIN sales_invoices si ON si.id = ca.reference_id WHERE cs.business_id = :bid AND ca.reference_type = 'SALES_INVOICE' AND cs.slip_date <= :d GROUP BY si.currency");
        $stmt->execute(['bid' => $businessId, 'd' => $asOf]);
        foreach ($stmt->fetchAll() as $row) { $arByCurrency[$row['currency']] = ($arByCurrency[$row['currency']] ?? 0.0) - (float)$row['fc']; }
        
        foreach ($arByCurrency as $currency => $fcAmount) {
            $lookup = $exchangeRateService->getLatestForLookupAsOf($currency, $asOf);
            $rate = $lookup['rate'] !== null ? (float)$lookup['rate'] : 1.0;
            $ar += $lookup['is_base'] ? round($fcAmount, 2) : round($fcAmount * $rate, 2);
        }
        $ar = max(0, $ar);

        $stmt = $this->db->prepare("SELECT COALESCE(SUM(s.quantity * COALESCE(i.cost_price, 0)), 0) FROM stock s JOIN items i ON i.id = s.item_id WHERE s.business_id = :bid");
        $stmt->execute(['bid' => $businessId]);
        $inventory = (float) $stmt->fetchColumn();

        $stmt = $this->db->prepare("SELECT COALESCE(SUM(current_value_bc), 0) FROM assets WHERE business_id = :bid AND purchase_date <= :d AND (status IS NULL OR status = 'ACTIVE')");
        $stmt->execute(['bid' => $businessId, 'd' => $asOf]);
        $fixedAssets = (float) $stmt->fetchColumn();

        $totalAssets = round($cashBank + $ar + $inventory + $fixedAssets, 2);

        // LIABILITIES: AP = received POs - debit notes - payments allocated to POs
        $ap = 0.0;
        $apByCurrency = [];
        $stmt = $this->db->prepare("SELECT currency, COALESCE(SUM(total_amount_fc), 0) as fc FROM purchase_orders WHERE business_id = :bid AND status = 'RECEIVED' AND po_date <= :d GROUP BY currency");
        $stmt->execute(['bid' => $businessId, 'd' => $asOf]);
        foreach ($stmt->fetchAll() as $row) { $apByCurrency[$row['currency']] = (float)$row['fc']; }

        $stmt = $this->db->prepare("SELECT po.currency, COALESCE(SUM(dn.total_amount_fc), 0) as fc FROM debit_notes dn JOIN purchase_orders po ON po.id = dn.purchase_order_id WHERE dn.business_id = :bid AND dn.status = 'ISSUED' AND dn.debit_note_date <= :d GROUP BY po.currency");
        $stmt->execute(['bid' => $businessId, 'd' => $asOf]);
        foreach ($stmt->fetchAll() as $row) { $apByCurrency[$row['currency']] = ($apByCurrency[$row['currency']] ?? 0.0) - (float)$row['fc']; }

        $stmt = $this->db->prepare("SELECT po.currency, COALESCE(SUM(pa.amount_fc), 0) as fc FROM payment_allocations pa JOIN payment_slips ps ON ps.id = pa.payment_slip_id JOIN purchase_orders po ON po.id = pa.reference_id WHERE ps.business_id = :bid AND pa.reference_type = 'PURCHASE_ORDER' AND ps.payment_date <= :d GROUP BY po.currency");
        $stmt->execute(['bid' => $businessId, 'd' => $asOf]);
        foreach ($stmt->fetchAll() as $row) { $apByCurrency[$row['currency']] = ($apByCurrency[$row['currency']] ?? 0.0) - (float)$row['fc']; }

        foreach ($apByCurrency as $currency => $fcAmount) {
            $lookup = $exchangeRateService->getLatestForLookupAsOf($currency, $asOf);
            $rate = $lookup['rate'] !== null ? (float)$lookup['rate'] : 1.0;
            $ap += $lookup['is_base'] ? round($fcAmount, 2) : round($fcAmount * $rate, 2);
        }
        $ap = max(0, $ap);

        // Unpaid expenses
        $unpaidExp = 0.0;
        $expByCurrency = [];
        $stmt = $this->db->prepare("SELECT currency, COALESCE(SUM(amount_fc), 0) as fc FROM expenses WHERE business_id = :bid AND expense_date <= :d GROUP BY currency");
        $stmt->execute(['bid' => $businessId, 'd' => $asOf]);
        foreach ($stmt->fetchAll() as $row) { $expByCurrency[$row['currency']] = (float)$row['fc']; }

        $stmt = $this->db->prepare("SELECT e.currency, COALESCE(SUM(pa.amount_fc), 0) as fc FROM payment_allocations pa JOIN payment_slips ps ON ps.id = pa.payment_slip_id JOIN expenses e ON e.id = pa.reference_id WHERE ps.business_id = :bid AND pa.reference_type = 'EXPENSE' AND ps.payment_date <= :d GROUP BY e.currency");
        $stmt->execute(['bid' => $businessId, 'd' => $asOf]);
        foreach ($stmt->fetchAll() as $row) { $expByCurrency[$row['currency']] = ($expByCurrency[$row['currency']] ?? 0.0) - (float)$row['fc']; }

        foreach ($expByCurrency as $currency => $fcAmount) {
            $lookup = $exchangeRateService->getLatestForLookupAsOf($currency, $asOf);
            $rate = $lookup['rate'] !== null ? (float)$lookup['rate'] : 1.0;
            $unpaidExp += $lookup['is_base'] ? round($fcAmount, 2) : round($fcAmount * $rate, 2);
        }
        $unpaidExp = max(0, $unpaidExp);

        // Unpaid salaries
        $unpaidSal = 0.0;
        $salByCurrency = [];
        $stmt = $this->db->prepare("SELECT currency, COALESCE(SUM(net_amount_fc), 0) as fc FROM salaries WHERE business_id = :bid AND period_start <= :d GROUP BY currency");
        $stmt->execute(['bid' => $businessId, 'd' => $asOf]);
        foreach ($stmt->fetchAll() as $row) { $salByCurrency[$row['currency']] = (float)$row['fc']; }

        $stmt = $this->db->prepare("SELECT s.currency, COALESCE(SUM(pa.amount_fc), 0) as fc FROM payment_allocations pa JOIN payment_slips ps ON ps.id = pa.payment_slip_id JOIN salaries s ON s.id = pa.reference_id WHERE ps.business_id = :bid AND pa.reference_type = 'SALARY' AND ps.payment_date <= :d GROUP BY s.currency");
        $stmt->execute(['bid' => $businessId, 'd' => $asOf]);
        foreach ($stmt->fetchAll() as $row) { $salByCurrency[$row['currency']] = ($salByCurrency[$row['currency']] ?? 0.0) - (float)$row['fc']; }

        foreach ($salByCurrency as $currency => $fcAmount) {
            $lookup = $exchangeRateService->getLatestForLookupAsOf($currency, $asOf);
            $rate = $lookup['rate'] !== null ? (float)$lookup['rate'] : 1.0;
            $unpaidSal += $lookup['is_base'] ? round($fcAmount, 2) : round($fcAmount * $rate, 2);
        }
        $unpaidSal = max(0, $unpaidSal);

        $totalLiab = round($ap + $unpaidExp + $unpaidSal, 2);

        // EQUITY
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(invested_capital), 0) FROM partner_shares WHERE business_id = :bid");
        $stmt->execute(['bid' => $businessId]);
        $partnerCap = (float) $stmt->fetchColumn();
        $retained = round($totalAssets - $totalLiab - $partnerCap, 2);
        $totalEquity = round($partnerCap + $retained, 2);

        return [
            'as_of' => $asOf,
            'assets' => ['cash_bank' => $cashBank, 'accounts_receivable' => $ar, 'inventory' => $inventory, 'fixed_assets' => $fixedAssets, 'total' => $totalAssets],
            'liabilities' => ['accounts_payable' => $ap, 'unpaid_expenses' => $unpaidExp, 'unpaid_salaries' => $unpaidSal, 'total' => $totalLiab],
            'equity' => ['partner_capital' => $partnerCap, 'retained_earnings' => $retained, 'total' => $totalEquity],
        ];
    }
}
