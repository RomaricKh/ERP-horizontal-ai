<?php
namespace App\Services;

use App\Repository\BusinessProfitRepository;
use App\Repository\ProfitDistributionRepository;
use App\Repository\PartnerShareRepository;
use App\Repository\PaymentRepository;
use App\Support\AuthContext;
use PDO;

class BusinessProfitService {
    private BusinessProfitRepository      $profitRepo;
    private ProfitDistributionRepository  $distRepo;
    private PartnerShareRepository        $shareRepo;
    private PaymentRepository             $paymentRepo;
    private AuditService                  $audit;
    private PDO                           $db;

    public function __construct(PDO $db) {
        $this->db          = $db;
        $this->profitRepo  = new BusinessProfitRepository($db);
        $this->distRepo    = new ProfitDistributionRepository($db);
        $this->shareRepo   = new PartnerShareRepository($db);
        $this->paymentRepo = new PaymentRepository($db);
        $this->audit       = new AuditService($db);
    }

    /**
     * Return all profit records for the current business.
     */
    public function list(): array {
        $businessId = AuthContext::businessId();
        return $this->profitRepo->findAll($businessId);
    }

    /**
     * Return a single profit record by ID.
     * If status is DISTRIBUTED, includes partner distribution breakdown from profit_distributions.
     */
    public function getById(int $id): array {
        $businessId = AuthContext::businessId();
        $record = $this->profitRepo->findById($id, $businessId);

        if (!$record) {
            throw new \RuntimeException('Profit record not found');
        }

        // If DISTRIBUTED, fetch actual distribution records
        if ($record['status'] === 'DISTRIBUTED') {
            $record['distributions'] = $this->distRepo->findByProfitId($id);
        }

        return $record;
    }

    /**
     * Create a new profit record for a period.
     *
     * Revenue = sum of PAID invoices total_amount_bc in period
     * COGS = sum of (si_details.quantity * items.cost_price) for PAID invoices in period
     *        Note: This is an approximation. True perpetual inventory COGS is handled via stock ledgers.
     * Gross Profit = Revenue - COGS
     * Expenses = sum of expenses.amount_bc in period
     * Salaries = sum of salaries.net_amount_bc in period (This attributes net salary payout as an expense)
     * Net Profit = Gross Profit - Expenses - Salaries
     * Apply safety_net_percent to get distributable_profit.
     *
     * @return int The new profit record ID
     * @throws \InvalidArgumentException if period overlaps with existing record
     */
    public function create(array $data): int {
        $ctx        = AuthContext::get();
        $businessId = $ctx->business_id;

        $periodStart      = $data['period_start'];
        $periodEnd        = $data['period_end'];
        $safetyNetPercent = (float) ($data['safety_net_percent'] ?? 0);

        // Check for overlapping periods
        $overlap = $this->profitRepo->findOverlapping($businessId, $periodStart, $periodEnd);
        if ($overlap) {
            throw new \InvalidArgumentException(
                'A profit record already exists for an overlapping period (ID: ' . $overlap['id'] . ', ' .
                $overlap['period_start'] . ' to ' . $overlap['period_end'] . ').'
            );
        }

        // Revenue: sum of PAID invoices total_amount_bc in period
        $totalRevenue = $this->computeRevenue($businessId, $periodStart, $periodEnd);

        // COGS: sum of si_details cost for PAID invoices
        $totalCogs = $this->computeCogs($businessId, $periodStart, $periodEnd);

        // Gross Profit
        $grossProfit = round($totalRevenue - $totalCogs, 2);

        // Expenses: sum of expenses.amount_bc in period
        $expenseAmount = $this->computeExpenses($businessId, $periodStart, $periodEnd);

        // Salary expenses: sum of salaries.net_amount_bc in period
        $salaryExpenses = $this->computeSalaries($businessId, $periodStart, $periodEnd);

        $totalExpenses = round($expenseAmount + $salaryExpenses, 2);

        // Net Profit
        $netProfit = round($grossProfit - $totalExpenses, 2);

        // Safety net
        $safetyNetAmount     = round($netProfit * $safetyNetPercent / 100, 2);
        $distributableProfit = round($netProfit - $safetyNetAmount, 2);

        $record = [
            'business_id'        => $businessId,
            'period_start'       => $periodStart,
            'period_end'         => $periodEnd,
            'total_revenue'      => $totalRevenue,
            'total_cogs'         => $totalCogs,
            'gross_profit'       => $grossProfit,
            'total_expenses'     => $totalExpenses,
            'salary_expenses'    => $salaryExpenses,
            'net_profit'         => $netProfit,
            'safety_net_percent' => $safetyNetPercent,
            'safety_net_amount'  => $safetyNetAmount,
            'distributable_profit' => $distributableProfit,
            'status'             => 'DRAFT',
        ];

        $this->db->beginTransaction();
        try {
            $id = $this->profitRepo->insert($record);

            $this->audit->log(
                'CREATE',
                'business_profit',
                $id,
                'CREATE',
                null,
                $record,
                $ctx->user_id,
                $ctx->tenant_id,
                $ctx->business_id
            );
            $this->db->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Update a profit record (only allowed on DRAFT records).
     * If safety_net_percent changes, recalculates safety_net_amount and distributable_profit.
     */
    public function update(int $id, array $data): void {
        $ctx        = AuthContext::get();
        $businessId = $ctx->business_id;

        $before = $this->profitRepo->findById($id, $businessId);
        if (!$before) {
            throw new \RuntimeException('Profit record not found');
        }

        if ($before['status'] !== 'DRAFT') {
            throw new \InvalidArgumentException('Only DRAFT profit records can be updated');
        }

        $safetyNetPercent = isset($data['safety_net_percent'])
            ? (float) $data['safety_net_percent']
            : (float) $before['safety_net_percent'];

        $netProfit           = (float) $before['net_profit'];
        $safetyNetAmount     = round($netProfit * $safetyNetPercent / 100, 2);
        $distributableProfit = round($netProfit - $safetyNetAmount, 2);

        $updateData = [
            'safety_net_percent'   => $safetyNetPercent,
            'safety_net_amount'    => $safetyNetAmount,
            'distributable_profit' => $distributableProfit,
            'status'               => 'DRAFT',
        ];

        $this->profitRepo->update($id, $businessId, $updateData);

        $this->audit->log(
            'UPDATE',
            'business_profit',
            $id,
            'UPDATE',
            $before,
            $updateData,
            $ctx->user_id,
            $ctx->tenant_id,
            $ctx->business_id
        );
    }

    /**
     * Recalculate a DRAFT profit record using its existing period dates.
     *
     * Re-computes revenue, cogs, expenses, salaries, net profit,
     * safety_net_amount and distributable_profit using the stored safety_net_percent.
     *
     * @throws \RuntimeException if record not found
     * @throws \InvalidArgumentException if record is not DRAFT
     */
    public function recalculate(int $id): void {
        $ctx        = AuthContext::get();
        $businessId = $ctx->business_id;

        $before = $this->profitRepo->findById($id, $businessId);
        if (!$before) {
            throw new \RuntimeException('Profit record not found');
        }

        if ($before['status'] !== 'DRAFT') {
            throw new \InvalidArgumentException('Only DRAFT profit records can be recalculated');
        }

        $periodStart      = $before['period_start'];
        $periodEnd        = $before['period_end'];
        $safetyNetPercent = (float) $before['safety_net_percent'];

        // Re-compute all values
        $totalRevenue   = $this->computeRevenue($businessId, $periodStart, $periodEnd);
        $totalCogs      = $this->computeCogs($businessId, $periodStart, $periodEnd);
        $grossProfit    = round($totalRevenue - $totalCogs, 2);
        $expenseAmount  = $this->computeExpenses($businessId, $periodStart, $periodEnd);
        $salaryExpenses = $this->computeSalaries($businessId, $periodStart, $periodEnd);
        $totalExpenses  = round($expenseAmount + $salaryExpenses, 2);
        $netProfit      = round($grossProfit - $totalExpenses, 2);

        // Safety net
        $safetyNetAmount     = round($netProfit * $safetyNetPercent / 100, 2);
        $distributableProfit = round($netProfit - $safetyNetAmount, 2);

        $updateData = [
            'total_revenue'        => $totalRevenue,
            'total_cogs'           => $totalCogs,
            'gross_profit'         => $grossProfit,
            'total_expenses'       => $totalExpenses,
            'salary_expenses'      => $salaryExpenses,
            'net_profit'           => $netProfit,
            'safety_net_percent'   => $safetyNetPercent,
            'safety_net_amount'    => $safetyNetAmount,
            'distributable_profit' => $distributableProfit,
            'status'               => 'DRAFT',
        ];

        $this->profitRepo->update($id, $businessId, $updateData);

        $this->audit->log(
            'UPDATE',
            'business_profit',
            $id,
            'RECALCULATE',
            $before,
            $updateData,
            $ctx->user_id,
            $ctx->tenant_id,
            $ctx->business_id
        );
    }

    /**
     * Delete a profit record (only allowed on DRAFT records).
     */
    public function delete(int $id): void {
        $ctx        = AuthContext::get();
        $businessId = $ctx->business_id;

        $before = $this->profitRepo->findById($id, $businessId);
        if (!$before) {
            throw new \RuntimeException('Profit record not found');
        }

        if ($before['status'] !== 'DRAFT') {
            throw new \InvalidArgumentException('Only DRAFT profit records can be deleted');
        }

        $this->profitRepo->delete($id, $businessId);

        $this->audit->log(
            'DELETE',
            'business_profit',
            $id,
            'DELETE',
            $before,
            null,
            $ctx->user_id,
            $ctx->tenant_id,
            $ctx->business_id
        );
    }

    /**
     * Finalize a profit record (DRAFT → FINALIZED).
     */
    public function finalize(int $id): void {
        $ctx        = AuthContext::get();
        $businessId = $ctx->business_id;

        $before = $this->profitRepo->findById($id, $businessId);
        if (!$before) {
            throw new \RuntimeException('Profit record not found');
        }

        if ($before['status'] !== 'DRAFT') {
            throw new \InvalidArgumentException('Only DRAFT profit records can be finalized');
        }

        $updateData = [
            'safety_net_percent'   => $before['safety_net_percent'],
            'safety_net_amount'    => $before['safety_net_amount'],
            'distributable_profit' => $before['distributable_profit'],
            'status'               => 'FINALIZED',
        ];

        $this->profitRepo->update($id, $businessId, $updateData);

        $this->audit->log(
            'UPDATE',
            'business_profit',
            $id,
            'FINALIZE',
            $before,
            $updateData,
            $ctx->user_id,
            $ctx->tenant_id,
            $ctx->business_id
        );
    }

    /**
     * Distribute a finalized profit record to partners (FINALIZED → DISTRIBUTED).
     *
     * Creates profit_distributions rows with ownership snapshot.
     * Creates payment slips via the allocation system (reference_type = 'PARTNER_PROFIT').
     * Links profit_distributions.payment_slip_id to each created slip.
     *
     * @param int $id The profit record ID
     * @param int|null $accountId The account to pay partners from (required)
     * @return array Distribution details
     */
    public function distribute(int $id, ?int $accountId = null): array {
        $ctx        = AuthContext::get();
        $businessId = $ctx->business_id;

        if (!$accountId) {
            throw new \InvalidArgumentException('Account ID is required to distribute profit. Select the source account.');
        }

        $before = $this->profitRepo->findById($id, $businessId);
        if (!$before) {
            throw new \RuntimeException('Profit record not found');
        }

        if ($before['status'] !== 'FINALIZED' && $before['status'] !== 'DISTRIBUTED') {
            throw new \InvalidArgumentException('Only FINALIZED or DISTRIBUTED profit records can be distributed');
        }

        // BP1: Guard against re-distribution when payment slips are already linked
        if ($before['status'] === 'DISTRIBUTED') {
            $existingDists = $this->distRepo->findByProfitId($id);
            $hasLinkedPayments = false;
            foreach ($existingDists as $dist) {
                if (!empty($dist['payment_slip_id'])) {
                    $hasLinkedPayments = true;
                    break;
                }
            }
            if ($hasLinkedPayments) {
                throw new \InvalidArgumentException(
                    'This profit record has already been distributed with linked payment slips. Cannot re-distribute.'
                );
            }
        }

        // Verify account exists
        $accountRepo = new \App\Repository\AccountRepository($this->db);
        $account = $accountRepo->findById($accountId, $businessId);
        if (!$account) {
            throw new \InvalidArgumentException('Selected account not found');
        }

        // Get partner shares for this business
        $shares = $this->shareRepo->getSharesForBusiness($businessId);
        if (empty($shares)) {
            throw new \InvalidArgumentException(
                'No partner shares configured for this business. Cannot distribute profit.'
            );
        }

        $distributableProfit = (float) $before['distributable_profit'];
        $distributions = [];

        $exchangeRateService = new \App\Services\ExchangeRateService($this->db);

        $this->db->beginTransaction();
        try {
            foreach ($shares as $share) {
                $sharePercent       = (float) $share['share_percent'];
                $distributionAmount = round($distributableProfit * ($sharePercent / 100), 2); // BC amount

                $distributions[] = [
                    'partner_id'      => $share['partner_id'],
                    'partner_name'    => $share['partner_name'],
                    'share_percent'   => $sharePercent,
                    'amount_bc'       => $distributionAmount,
                    'payment_slip_id' => null,
                ];
            }

            // Delete any existing distributions if user clicked distribute before but cancelled payment
            $stmtDel = $this->db->prepare("DELETE FROM profit_distributions WHERE business_profit_id = :id");
            $stmtDel->execute(['id' => $id]);

            // Store distributions in profit_distributions table
            $this->distRepo->createBatch($id, $distributions);

            // BP2: Mark the profit record as DISTRIBUTED
            $this->profitRepo->update($id, $businessId, ['status' => 'DISTRIBUTED']);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $this->audit->log(
            'UPDATE',
            'business_profit',
            $id,
            'DISTRIBUTE_PREVIEW',
            $before,
            ['distributions' => $distributions],
            $ctx->user_id,
            $ctx->tenant_id,
            $ctx->business_id
        );

        return $distributions;
    }

    /* =====================================================================
     * Private computation helpers
     * ===================================================================== */

    /**
     * Revenue = sum of DELIVERED and PAID invoices total_amount_bc in period.
     * (DELIVERED is the terminal fulfillment state; PAID is set by collection settlement)
     */
    private function computeRevenue(int $businessId, string $start, string $end): float {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(total_amount_bc), 0)
            FROM sales_invoices
            WHERE business_id = :business
              AND status IN ('DELIVERED', 'PAID')
              AND invoice_date >= :start
              AND invoice_date <= :end
        ");
        $stmt->execute(['business' => $businessId, 'start' => $start, 'end' => $end]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * COGS = sum of (si_details.quantity * items.cost_price) for DELIVERED/PAID invoices in period.
     */
    private function computeCogs(int $businessId, string $start, string $end): float {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(sd.quantity * COALESCE(i.cost_price, 0)), 0)
            FROM si_details sd
            JOIN sales_invoices si ON si.id = sd.sales_invoice_id
            LEFT JOIN items i ON i.id = sd.item_id
            WHERE si.business_id = :business
              AND si.status IN ('DELIVERED', 'PAID')
              AND si.invoice_date >= :start
              AND si.invoice_date <= :end
              AND sd.item_id IS NOT NULL
        ");
        $stmt->execute(['business' => $businessId, 'start' => $start, 'end' => $end]);
        return round((float) $stmt->fetchColumn(), 2);
    }

    /**
     * Expenses = sum of expenses.amount_bc in period.
     */
    private function computeExpenses(int $businessId, string $start, string $end): float {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount_bc), 0)
            FROM expenses
            WHERE business_id = :business
              AND expense_date >= :start
              AND expense_date <= :end
        ");
        $stmt->execute(['business' => $businessId, 'start' => $start, 'end' => $end]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Salary expenses = sum of salaries.net_amount_bc in period.
     */
    private function computeSalaries(int $businessId, string $start, string $end): float {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(net_amount_bc), 0)
            FROM salaries
            WHERE business_id = :business
              AND period_start >= :start
              AND period_start <= :end
        ");
        $stmt->execute(['business' => $businessId, 'start' => $start, 'end' => $end]);
        return (float) $stmt->fetchColumn();
    }


}
