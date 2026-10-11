<?php
namespace App\Services;

use App\Repository\PaymentRepository;
use App\Repository\PaymentAllocationRepository;
use App\Repository\AccountRepository;
use App\Repository\PeopleRepository;
use App\Services\ExchangeRateService;
use App\Services\NotificationService;
use App\Services\SettlementEngine;
use App\Support\AuthContext;
use PDO;

class PaymentService {
    private PaymentRepository           $repo;
    private PaymentAllocationRepository $allocationRepo;
    private AccountRepository           $accountRepo;
    private PeopleRepository            $peopleRepo;
    private ExchangeRateService         $exchangeRateService;
    private AuditService                $audit;
    private SettlementEngine            $settlementEngine;
    private NotificationService         $notificationService;
    private PDO                         $db;

    public function __construct(PDO $db) {
        $this->db                  = $db;
        $this->repo                = new PaymentRepository($db);
        $this->allocationRepo      = new PaymentAllocationRepository($db);
        $this->accountRepo         = new AccountRepository($db);
        $this->peopleRepo          = new PeopleRepository($db);
        $this->exchangeRateService = new ExchangeRateService($db);
        $this->audit               = new AuditService($db);
        $this->settlementEngine    = new SettlementEngine($db);
        $this->notificationService = new NotificationService($db);
    }

    /**
     * Return all payment slips for the current business.
     */
    public function list(): array {
        $ctx = AuthContext::get();
        return $this->repo->findAllForBusiness($ctx->business_id);
    }

    /**
     * Return a single payment slip by id.
     *
     * @throws \RuntimeException if not found
     */
    public function getById(int $id): array {
        $ctx     = AuthContext::get();
        $payment = $this->repo->findById($id, $ctx->business_id);
        if (!$payment) {
            throw new \RuntimeException('Payment not found');
        }
        $payment['lines'] = $this->repo->findLinesBySlipId($id);
        return $payment;
    }

    /**
     * Return allocations for a given payment slip.
     */
    public function getAllocations(int $slipId): array {
        return $this->allocationRepo->findBySlip($slipId);
    }

    /**
     * Create a new payment slip and deduct the amount from the account balance.
     * Accepts an optional allocations array; if empty, uses FIFO auto-allocation.
     * Validates that sum of allocations equals slip amount.
     * After saving, evaluates document statuses (PO→CLOSED, expense→paid, salary→paid).
     *
     * Required keys: payment_number, payment_date, lines (array of objects)
     * Optional keys: payee_id, allocations, notes
     *
     * lines format: [{account_id, currency, exchange_rate, amount_fc, method, notes}]
     * allocations format: [{reference_type, reference_id, amount_fc, amount_bc}]
     *
     * @throws \InvalidArgumentException if required fields are missing or allocations don't sum correctly
     */
    public function create(array $data): int {
        $ctx = AuthContext::get();

        if (empty($data['lines']) || !is_array($data['lines'])) {
            throw new \InvalidArgumentException('At least one payment line is required');
        }
        if (empty(trim($data['payment_number'] ?? ''))) {
            $data['payment_number'] = 'PS-' . $ctx->business_id . '-' . time();
        }
        if (empty($data['payment_date'] ?? '')) {
            throw new \InvalidArgumentException('Payment date is required');
        }

        $totalBc = 0.0;
        $processedLines = [];

        foreach ($data['lines'] as $line) {
            if (empty($line['account_id'])) {
                throw new \InvalidArgumentException('Account is required for all lines');
            }
            if (empty(trim($line['currency'] ?? ''))) {
                throw new \InvalidArgumentException('Currency is required for all lines');
            }
            if (!isset($line['amount_fc']) || $line['amount_fc'] === '') {
                throw new \InvalidArgumentException('Amount is required for all lines');
            }
            if (empty(trim($line['method'] ?? ''))) {
                throw new \InvalidArgumentException('Payment method is required for all lines');
            }

            $accountId = (int) $line['account_id'];
            $account = $this->accountRepo->findById($accountId, $ctx->business_id);
            if (!$account) {
                throw new \RuntimeException('Account not found');
            }

            $txCurrency   = strtolower(trim($line['currency']));
            $acctCurrency = strtolower($account['currency']);
            if ($txCurrency !== $acctCurrency) {
                throw new \InvalidArgumentException("Currency mismatch: transaction currency is '{$txCurrency}' but account currency is '{$acctCurrency}'");
            }

            $providedRate = (!isset($line['exchange_rate']) || $line['exchange_rate'] === '' || $line['exchange_rate'] === null)
                ? null
                : (float) $line['exchange_rate'];
            $resolvedRate = $this->exchangeRateService->resolveRate(trim($line['currency']), $providedRate);

            $amountFc = (float) $line['amount_fc'];
            $amountBc = round($amountFc * $resolvedRate, 2);

            $totalBc += $amountBc;

            $processedLines[] = [
                'account_id'    => $accountId,
                'currency'      => trim($line['currency']),
                'exchange_rate' => $resolvedRate,
                'amount_fc'     => $amountFc,
                'amount_bc'     => $amountBc,
                'method'        => trim($line['method']),
                'notes'         => $line['notes'] ?? null,
                'account_currency'=> $acctCurrency,
            ];
        }

        $payeeId = isset($data['payee_id']) && $data['payee_id'] !== '' ? (int) $data['payee_id'] : null;

        // Determine allocations: use provided or auto-allocate via FIFO
        $allocations = $data['allocations'] ?? [];
        if (empty($allocations) && $payeeId) {
            $allocations = $this->settlementEngine->autoAllocateFIFO(
                $payeeId,
                $ctx->business_id,
                $totalBc,
                $totalBc,
                1.0,
                'payment'
            );
        }

        // Validate allocation sum does not exceed slip amount (under-allocation is OK — surplus becomes supplier credit)
        if (!empty($allocations)) {
            $allocSumBc = 0.0;
            foreach ($allocations as $alloc) {
                $allocSumBc += (float) ($alloc['amount_bc'] ?? 0);
            }
            // Allocations cannot exceed slip amount
            if ($allocSumBc > $totalBc + 0.01) {
                throw new \InvalidArgumentException(
                    "Allocation sum ({$allocSumBc}) exceeds slip amount ({$totalBc})"
                );
            }

            // Validate no over-allocation on any document
            foreach ($allocations as $alloc) {
                $refType = $alloc['reference_type'];
                $refId   = (int) $alloc['reference_id'];
                $allocAmount = (float) $alloc['amount_bc'];

                $existingAllocated = $this->allocationRepo->sumForDocument($refType, $refId);
                $docTotal = $this->getDocumentTotal($refType, $refId);

                if ($docTotal > 0 && ($existingAllocated + $allocAmount) > $docTotal + 0.01) {
                    throw new \InvalidArgumentException(
                        "Over-allocation on {$refType} #{$refId}: document total is {$docTotal}, already allocated {$existingAllocated}, attempting to add {$allocAmount}"
                    );
                }
            }
        }

        $this->db->beginTransaction();
        try {
            $id = $this->repo->create([
                'business_id'    => $ctx->business_id,
                'payee_id'       => $payeeId,
                'payment_number' => trim($data['payment_number']),
                'payment_date'   => $data['payment_date'],
                'total_bc'       => $totalBc,
                'notes'          => isset($data['notes']) && $data['notes'] !== '' ? $data['notes'] : null,
                'created_by'     => $ctx->user_id,
            ]);

            foreach ($processedLines as $line) {
                $this->repo->createLine($id, $line);
            }

            // Save allocations
            if (!empty($allocations)) {
                $this->allocationRepo->createBatch($id, $allocations);
            }

            // Outgoing payment: deduct from account balance (negative delta for both BC and FC)
            foreach ($processedLines as $line) {
                $deltaFc = $line['amount_fc'];
                if ($line['account_currency'] !== strtolower(trim($line['currency']))) {
                    $deltaFc = $line['amount_bc']; 
                }
                $this->accountRepo->updateBalance($line['account_id'], -$line['amount_bc'], $this->db, -$deltaFc);
            }

            // Increase payee balance (payment reduces what business owes)
            if ($payeeId) {
                $this->peopleRepo->updatePayeeBalance($payeeId, $totalBc);
            }

            $this->audit->log(
                'CREATE',
                'payment_slips',
                $id,
                'CREATE',
                null,
                [
                    'total_bc'       => $totalBc,
                    'lines_count'    => count($processedLines),
                    'allocations'    => count($allocations),
                ],
                $ctx->user_id,
                $ctx->tenant_id,
                $ctx->business_id
            );

            // Update recurrence instances for allocated expenses/salaries that are now fully paid
            if (!empty($allocations)) {
                $this->updateRecurrenceInstancesForAllocations($allocations);
                
                // Mark Partner Profits as DISTRIBUTED and deduct payee balances
                foreach ($allocations as $alloc) {
                    if ($alloc['reference_type'] === 'PARTNER_PROFIT') {
                        $profitId = (int) $alloc['reference_id'];
                        
                        $stmtCheck = $this->db->prepare("SELECT status FROM business_profit WHERE id = :id FOR UPDATE");
                        $stmtCheck->execute(['id' => $profitId]);
                        $status = $stmtCheck->fetchColumn();
                        
                        if ($status !== 'DISTRIBUTED') {
                            $stmt = $this->db->prepare("UPDATE business_profit SET status = 'DISTRIBUTED' WHERE id = :id");
                            $stmt->execute(['id' => $profitId]);
                            
                            $stmtDist = $this->db->prepare("SELECT partner_id, amount_bc FROM profit_distributions WHERE business_profit_id = :id");
                            $stmtDist->execute(['id' => $profitId]);
                            $dists = $stmtDist->fetchAll();
                            foreach ($dists as $dist) {
                                $this->peopleRepo->updatePayeeBalance((int)$dist['partner_id'], -(float)$dist['amount_bc']);
                            }
                        }
                    }
                }
            }

            $this->db->commit();

            // Notify users with "finance.view" permission about new payment
            try {
                $recipientIds = $this->resolveRecipientsByPermission('finance.view', $ctx->business_id);
                if (!empty($recipientIds)) {
                    $this->notificationService->notify(
                        'PAYMENT_RECEIVED',
                        'New Payment Created',
                        'Payment ' . trim($data['payment_number']) . ' has been created',
                        $recipientIds,
                        'payments',
                        $id,
                        $ctx->user_id
                    );
                }
            } catch (\Throwable $e) {
                error_log("[PaymentService::create] Notification error: " . $e->getMessage());
            }

            return $id;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Delete a payment slip and reverse the account balance deduction.
     * Also deletes associated allocations (CASCADE handles this, but we do it explicitly for clarity).
     *
     * @throws \RuntimeException if not found
     */
    public function delete(int $id): void {
        $ctx     = AuthContext::get();
        $payment = $this->repo->findById($id, $ctx->business_id);

        if (!$payment) {
            throw new \RuntimeException('Payment not found');
        }

        $lines = $this->repo->findLinesBySlipId($id);
        $totalBc = (float) $payment['total_bc'];

        $this->db->beginTransaction();
        try {
            // Before deleting allocations, check which expenses/salaries will become unsettled
            // and revert their recurrence instances back to pending/overdue
            $existingAllocations = $this->allocationRepo->findBySlip($id);
            
            // Allocations are deleted via CASCADE, but explicit delete for audit clarity
            $this->allocationRepo->deleteBySlip($id);
            $this->repo->delete($id, $ctx->business_id);

            // Reverse the deduction: add back the amount (positive delta for both BC and FC)
            foreach ($lines as $line) {
                $amountBc = (float) $line['amount_bc'];
                $amountFc = (float) $line['amount_fc'];
                $this->accountRepo->updateBalance($line['account_id'], +$amountBc, $this->db, +$amountFc);
            }

            // Reverse payee balance (payment undone, business owes again)
            $payeeId = $payment['payee_id'] ?? null;
            if ($payeeId) {
                $this->peopleRepo->updatePayeeBalance((int) $payeeId, -$totalBc);
            }

            // Revert recurrence instances that are no longer fully settled
            if (!empty($existingAllocations)) {
                $this->revertRecurrenceInstancesForDeletedAllocations($existingAllocations);
            }

            $this->audit->log(
                'DELETE',
                'payment_slips',
                $id,
                'DELETE',
                $payment,
                null,
                $ctx->user_id,
                $ctx->tenant_id,
                $ctx->business_id
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * After allocations are saved, check if any allocated expenses or salaries
     * are linked to recurrence_instances. If the expense/salary is now fully settled,
     * mark the corresponding recurrence instance as 'paid'.
     *
     * @param array $allocations Array of allocation rows with reference_type and reference_id
     */
    private function updateRecurrenceInstancesForAllocations(array $allocations): void {
        foreach ($allocations as $alloc) {
            $refType = $alloc['reference_type'] ?? '';
            $refId   = (int) ($alloc['reference_id'] ?? 0);

            if ($refType === 'EXPENSE') {
                // Check if this expense is fully settled (total allocations >= expense amount)
                $expenseTotal = $this->getDocumentTotal('EXPENSE', $refId);
                $totalAllocated = $this->sumPaymentAllocationsForDocument('EXPENSE', $refId);

                if ($expenseTotal > 0 && $totalAllocated >= $expenseTotal) {
                    // Update any recurrence_instances linked to this expense
                    $stmt = $this->db->prepare("
                        UPDATE recurrence_instances
                        SET status = 'paid', paid_date = CURDATE(), updated_at = NOW()
                        WHERE payment_record_id = :expense_id
                          AND status IN ('pending', 'overdue')
                    ");
                    $stmt->execute(['expense_id' => $refId]);
                }
            } elseif ($refType === 'SALARY') {
                // Check if this salary is fully settled
                $salaryTotal = $this->getDocumentTotal('SALARY', $refId);
                $totalAllocated = $this->sumPaymentAllocationsForDocument('SALARY', $refId);

                if ($salaryTotal > 0 && $totalAllocated >= $salaryTotal) {
                    // Update any recurrence_instances linked to this salary
                    $stmt = $this->db->prepare("
                        UPDATE recurrence_instances
                        SET status = 'paid', paid_date = CURDATE(), updated_at = NOW()
                        WHERE payment_record_id = :salary_id
                          AND status IN ('pending', 'overdue')
                    ");
                    $stmt->execute(['salary_id' => $refId]);
                }
            }
        }
    }

    /**
     * When a payment is deleted, check if any of its allocations were keeping
     * recurrence instances in 'paid' status. If the expense/salary is no longer
     * fully settled after removal, revert the instance to 'pending' or 'overdue'.
     *
     * @param array $allocations The allocations that were just deleted
     */
    private function revertRecurrenceInstancesForDeletedAllocations(array $allocations): void {
        foreach ($allocations as $alloc) {
            $refType = $alloc['reference_type'] ?? '';
            $refId   = (int) ($alloc['reference_id'] ?? 0);

            if ($refType === 'EXPENSE' || $refType === 'SALARY') {
                // After deletion, check if expense/salary is still fully settled
                $docTotal = $this->getDocumentTotal($refType, $refId);
                $totalAllocated = $this->sumPaymentAllocationsForDocument($refType, $refId);

                if ($docTotal > 0 && $totalAllocated < $docTotal) {
                    // No longer fully settled — revert the recurrence instance
                    $today = date('Y-m-d');
                    $stmt = $this->db->prepare("
                        UPDATE recurrence_instances
                        SET status = CASE
                            WHEN due_date < :today THEN 'overdue'
                            ELSE 'pending'
                        END,
                        paid_date = NULL,
                        updated_at = NOW()
                        WHERE payment_record_id = :record_id
                          AND status = 'paid'
                    ");
                    $stmt->execute([
                        'today'     => $today,
                        'record_id' => $refId,
                    ]);
                }
            }
        }
    }

    /**
     * Sum all payment allocations for a given document (by reference_type + reference_id).
     */
    private function sumPaymentAllocationsForDocument(string $referenceType, int $referenceId): float {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount_bc), 0)
            FROM payment_allocations
            WHERE reference_type = :reference_type
              AND reference_id = :reference_id
        ");
        $stmt->execute([
            'reference_type' => $referenceType,
            'reference_id'   => $referenceId,
        ]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Get the total amount for a document by type and ID.
     */
    private function getDocumentTotal(string $referenceType, int $referenceId): float {
        switch ($referenceType) {
            case 'PURCHASE_ORDER':
                $stmt = $this->db->prepare("SELECT total_amount_bc FROM purchase_orders WHERE id = :id LIMIT 1");
                break;
            case 'EXPENSE':
                $stmt = $this->db->prepare("SELECT amount_bc AS total_amount_bc FROM expenses WHERE id = :id LIMIT 1");
                break;
            case 'SALARY':
                $stmt = $this->db->prepare("SELECT net_amount_bc AS total_amount_bc FROM salaries WHERE id = :id LIMIT 1");
                break;
            case 'PARTNER_PROFIT':
                $stmt = $this->db->prepare("SELECT amount_bc AS total_amount_bc FROM profit_distributions WHERE id = :id LIMIT 1");
                break;
            default:
                return 0.0;
        }
        $stmt->execute(['id' => $referenceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (float) $row['total_amount_bc'] : 0.0;
    }

    /**
     * Resolve all user IDs that hold a given permission in a business.
     * Users with role_id = 1 (Business Owner) have ALL permissions.
     *
     * @param string $permissionCode The permission code to look up
     * @param int    $businessId     The business to scope the lookup to
     * @return int[] Array of user IDs holding the permission
     */
    private function resolveRecipientsByPermission(string $permissionCode, int $businessId): array {
        $stmt = $this->db->prepare("
            SELECT DISTINCT ur.user_id
            FROM user_roles ur
            WHERE ur.business_id = :business_id
              AND ur.is_active = 1
              AND (
                  ur.role_id = 1
                  OR ur.role_id IN (
                      SELECT rp.role_id
                      FROM role_permissions rp
                      JOIN permissions p ON p.id = rp.permission_id
                      WHERE p.code = :permission_code
                  )
              )
        ");
        $stmt->execute([
            'business_id'     => $businessId,
            'permission_code' => $permissionCode,
        ]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Get outstanding (unpaid/partially-paid) documents for a supplier payee.
     */
    public function getOutstandingDocumentsForPayee(int $payeeId, int $businessId): array {
        $documents = [];

        // RECEIVED Purchase Orders (accounting for debit notes)
        $stmt = $this->db->prepare("
            SELECT
                po.id,
                po.po_number AS doc_number,
                po.po_date AS doc_date,
                po.total_amount_bc,
                po.total_amount_fc,
                'PURCHASE_ORDER' AS reference_type,
                COALESCE(
                    (SELECT SUM(pa.amount_bc) FROM payment_allocations pa WHERE pa.reference_type = 'PURCHASE_ORDER' AND pa.reference_id = po.id),
                    0
                ) AS allocated_bc,
                COALESCE(
                    (SELECT SUM(pa.amount_fc) FROM payment_allocations pa WHERE pa.reference_type = 'PURCHASE_ORDER' AND pa.reference_id = po.id),
                    0
                ) AS allocated_fc,
                COALESCE(
                    (SELECT SUM(dn.total_amount_bc) FROM debit_notes dn WHERE dn.purchase_order_id = po.id AND dn.status = 'ISSUED'),
                    0
                ) AS debit_notes_bc,
                COALESCE(
                    (SELECT SUM(dn.total_amount_fc) FROM debit_notes dn WHERE dn.purchase_order_id = po.id AND dn.status = 'ISSUED'),
                    0
                ) AS debit_notes_fc
            FROM purchase_orders po
            WHERE po.supplier_id = :payee_id
              AND po.business_id = :business_id
              AND po.status = 'RECEIVED'
            ORDER BY po.po_date ASC, po.id ASC
        ");
        $stmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId]);
        $poRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($poRows as $row) {
            $effectiveTotalBc = (float) $row['total_amount_bc'] - (float) $row['debit_notes_bc'];
            $effectiveTotalFc = (float) $row['total_amount_fc'] - (float) $row['debit_notes_fc'];
            $remaining = $effectiveTotalBc - (float) $row['allocated_bc'];
            if ($remaining > 0.001) {
                $documents[] = [
                    'id'             => (int) $row['id'],
                    'doc_number'     => $row['doc_number'],
                    'doc_date'       => $row['doc_date'],
                    'reference_type' => 'PURCHASE_ORDER',
                    'total_bc'       => round($effectiveTotalBc, 2),
                    'total_fc'       => round($effectiveTotalFc, 2),
                    'allocated_bc'   => (float) $row['allocated_bc'],
                    'allocated_fc'   => (float) $row['allocated_fc'],
                    'remaining_bc'   => round($remaining, 2),
                    'remaining_fc'   => round($effectiveTotalFc - (float) $row['allocated_fc'], 2),
                ];
            }
        }

        // Unpaid Expenses
        $stmt = $this->db->prepare("
            SELECT
                e.id,
                COALESCE(e.reference_number, CONCAT('EXP-', e.id)) AS doc_number,
                e.expense_date AS doc_date,
                e.amount_bc AS total_amount_bc,
                e.amount_fc AS total_amount_fc,
                'EXPENSE' AS reference_type,
                COALESCE(
                    (SELECT SUM(pa.amount_bc) FROM payment_allocations pa WHERE pa.reference_type = 'EXPENSE' AND pa.reference_id = e.id),
                    0
                ) AS allocated_bc,
                COALESCE(
                    (SELECT SUM(pa.amount_fc) FROM payment_allocations pa WHERE pa.reference_type = 'EXPENSE' AND pa.reference_id = e.id),
                    0
                ) AS allocated_fc
            FROM expenses e
            WHERE e.payee_id = :payee_id
              AND e.business_id = :business_id
            ORDER BY e.expense_date ASC, e.id ASC
        ");
        $stmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId]);
        $expenseRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($expenseRows as $row) {
            $remaining = (float) $row['total_amount_bc'] - (float) $row['allocated_bc'];
            if ($remaining > 0.001) {
                $documents[] = [
                    'id'             => (int) $row['id'],
                    'doc_number'     => $row['doc_number'],
                    'doc_date'       => $row['doc_date'],
                    'reference_type' => 'EXPENSE',
                    'total_bc'       => (float) $row['total_amount_bc'],
                    'total_fc'       => (float) ($row['total_amount_fc'] ?? $row['total_amount_bc']),
                    'allocated_bc'   => (float) $row['allocated_bc'],
                    'allocated_fc'   => (float) $row['allocated_fc'],
                    'remaining_bc'   => round($remaining, 2),
                    'remaining_fc'   => round((float) ($row['total_amount_fc'] ?? $row['total_amount_bc']) - (float) $row['allocated_fc'], 2),
                ];
            }
        }

        // Unpaid Salaries
        $stmt = $this->db->prepare("
            SELECT
                s.id,
                CONCAT('SAL-', s.id) AS doc_number,
                s.period_start AS doc_date,
                s.net_amount_bc AS total_amount_bc,
                s.net_amount_fc AS total_amount_fc,
                'SALARY' AS reference_type,
                COALESCE(
                    (SELECT SUM(pa.amount_bc) FROM payment_allocations pa WHERE pa.reference_type = 'SALARY' AND pa.reference_id = s.id),
                    0
                ) AS allocated_bc,
                COALESCE(
                    (SELECT SUM(pa.amount_fc) FROM payment_allocations pa WHERE pa.reference_type = 'SALARY' AND pa.reference_id = s.id),
                    0
                ) AS allocated_fc
            FROM salaries s
            WHERE s.payee_id = :payee_id
              AND s.business_id = :business_id
            ORDER BY s.period_start ASC, s.id ASC
        ");
        $stmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId]);
        $salaryRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($salaryRows as $row) {
            $remaining = (float) $row['total_amount_bc'] - (float) $row['allocated_bc'];
            if ($remaining > 0.001) {
                $documents[] = [
                    'id'             => (int) $row['id'],
                    'doc_number'     => $row['doc_number'],
                    'doc_date'       => $row['doc_date'],
                    'reference_type' => 'SALARY',
                    'total_bc'       => (float) $row['total_amount_bc'],
                    'total_fc'       => (float) ($row['total_amount_fc'] ?? $row['total_amount_bc']),
                    'allocated_bc'   => (float) $row['allocated_bc'],
                    'allocated_fc'   => (float) $row['allocated_fc'],
                    'remaining_bc'   => round($remaining, 2),
                    'remaining_fc'   => round((float) ($row['total_amount_fc'] ?? $row['total_amount_bc']) - (float) $row['allocated_fc'], 2),
                ];
            }
        }

        // Sort by date ASC
        usort($documents, function ($a, $b) {
            $cmp = strcmp($a['doc_date'], $b['doc_date']);
            return $cmp === 0 ? $a['id'] - $b['id'] : $cmp;
        });

        // Unpaid Profit Distributions
        $stmt = $this->db->prepare("
            SELECT
                pd.id,
                pd.business_profit_id,
                CONCAT('PROFIT-', pd.business_profit_id) AS doc_number,
                bp.period_end AS doc_date,
                pd.amount_bc AS total_amount_bc,
                'PARTNER_PROFIT' AS reference_type,
                COALESCE(
                    (SELECT SUM(pa.amount_bc) FROM payment_allocations pa WHERE pa.reference_type = 'PARTNER_PROFIT' AND pa.reference_id = pd.business_profit_id),
                    0
                ) AS allocated_bc
            FROM profit_distributions pd
            JOIN business_profit bp ON bp.id = pd.business_profit_id
            WHERE pd.partner_id = :payee_id
              AND bp.business_id = :business_id
              AND bp.status IN ('FINALIZED', 'DISTRIBUTED')
        ");
        $stmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId]);
        $profitRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($profitRows as $row) {
            $remaining = (float) $row['total_amount_bc'] - (float) $row['allocated_bc'];
            if ($remaining > 0) {
                $documents[] = [
                    'reference_type' => 'PARTNER_PROFIT',
                    'id'             => (int) $row['business_profit_id'],
                    'doc_number'     => $row['doc_number'],
                    'doc_date'       => $row['doc_date'],
                    'total_amount_bc'=> (float) $row['total_amount_bc'],
                    'total_bc'       => (float) $row['total_amount_bc'],
                    'total_fc'       => (float) $row['total_amount_bc'],
                    'allocated_bc'   => (float) $row['allocated_bc'],
                    'allocated_fc'   => (float) $row['allocated_bc'],
                    'remaining_bc'   => round($remaining, 2),
                    'remaining_fc'   => round($remaining, 2),
                ];
            }
        }

        return $documents;
    }
}
