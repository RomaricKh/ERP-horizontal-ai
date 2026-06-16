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
     * Required keys: account_id, payment_number, payment_date,
     *                currency, exchange_rate, amount_fc, method
     * Optional keys: payee_id, allocations, notes
     *
     * allocations format: [{reference_type, reference_id, amount_fc, amount_bc}]
     *
     * @throws \InvalidArgumentException if required fields are missing or allocations don't sum correctly
     */
    public function create(array $data): int {
        $ctx = AuthContext::get();

        if (empty($data['account_id'] ?? '')) {
            throw new \InvalidArgumentException('Account is required');
        }
        if (empty(trim($data['payment_number'] ?? ''))) {
            throw new \InvalidArgumentException('Payment number is required');
        }
        if (empty($data['payment_date'] ?? '')) {
            throw new \InvalidArgumentException('Payment date is required');
        }
        if (empty(trim($data['currency'] ?? ''))) {
            throw new \InvalidArgumentException('Currency is required');
        }

        // Resolve exchange rate: use provided value or fall back to latest from exchange_rates table
        $providedRate = (!isset($data['exchange_rate']) || $data['exchange_rate'] === '' || $data['exchange_rate'] === null)
            ? null
            : (float) $data['exchange_rate'];
        $resolvedRate = $this->exchangeRateService->resolveRate(trim($data['currency']), $providedRate);

        if (!isset($data['amount_fc']) || $data['amount_fc'] === '') {
            throw new \InvalidArgumentException('Amount is required');
        }
        if (empty(trim($data['method'] ?? ''))) {
            throw new \InvalidArgumentException('Payment method is required');
        }

        $amountFc     = (float) $data['amount_fc'];
        $exchangeRate = $resolvedRate;
        $amountBc     = round($amountFc * $exchangeRate, 2);
        $accountId    = (int) $data['account_id'];

        // Currency validation: ensure transaction currency matches account currency
        $account = $this->accountRepo->findById($accountId, $ctx->business_id);
        if (!$account) {
            throw new \RuntimeException('Account not found');
        }
        $txCurrency   = strtolower(trim($data['currency']));
        $acctCurrency = strtolower($account['currency']);
        if ($txCurrency !== $acctCurrency) {
            throw new \InvalidArgumentException("Currency mismatch: transaction currency is '{$txCurrency}' but account currency is '{$acctCurrency}'");
        }

        $payeeId = isset($data['payee_id']) && $data['payee_id'] !== '' ? (int) $data['payee_id'] : null;

        // Determine allocations: use provided or auto-allocate via FIFO
        $allocations = $data['allocations'] ?? [];
        if (empty($allocations) && $payeeId) {
            $allocations = $this->settlementEngine->autoAllocateFIFO(
                $payeeId,
                $ctx->business_id,
                $amountBc,
                $amountFc,
                $exchangeRate,
                'payment'
            );
        }

        // Validate allocation sum equals slip amount (if allocations exist)
        if (!empty($allocations)) {
            $allocSumBc = 0.0;
            foreach ($allocations as $alloc) {
                $allocSumBc += (float) ($alloc['amount_bc'] ?? 0);
            }
            // Allow a small tolerance for rounding (0.01)
            if (abs($allocSumBc - $amountBc) > 0.01) {
                throw new \InvalidArgumentException(
                    "Allocation sum ({$allocSumBc}) does not match slip amount ({$amountBc})"
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
                'account_id'     => $accountId,
                'payee_id'       => $payeeId,
                'payment_number' => trim($data['payment_number']),
                'payment_date'   => $data['payment_date'],
                'currency'       => trim($data['currency']),
                'exchange_rate'  => $exchangeRate,
                'amount_fc'      => $amountFc,
                'amount_bc'      => $amountBc,
                'method'         => trim($data['method']),
                'notes'          => isset($data['notes']) && $data['notes'] !== '' ? $data['notes'] : null,
                'created_by'     => $ctx->user_id,
            ]);

            // Save allocations
            if (!empty($allocations)) {
                $this->allocationRepo->createBatch($id, $allocations);
            }

            // Outgoing payment: deduct from account balance (negative delta for both BC and FC)
            $this->accountRepo->updateBalance($accountId, -$amountBc, $this->db, -$amountFc);

            // Increase payee balance (payment reduces what business owes)
            if ($payeeId) {
                $this->peopleRepo->updatePayeeBalance($payeeId, $amountBc);
            }

            $this->audit->log(
                'CREATE',
                'payment_slips',
                $id,
                'CREATE',
                null,
                [
                    'account_id'     => $accountId,
                    'payment_number' => trim($data['payment_number']),
                    'payment_date'   => $data['payment_date'],
                    'currency'       => trim($data['currency']),
                    'exchange_rate'  => $exchangeRate,
                    'amount_fc'      => $amountFc,
                    'amount_bc'      => $amountBc,
                    'method'         => trim($data['method']),
                    'allocations'    => count($allocations),
                ],
                $ctx->user_id,
                $ctx->tenant_id,
                $ctx->business_id
            );

            // Update recurrence instances for allocated expenses/salaries that are now fully paid
            if (!empty($allocations)) {
                $this->updateRecurrenceInstancesForAllocations($allocations);
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

        $accountId = (int) $payment['account_id'];
        $amountBc  = (float) $payment['amount_bc'];
        $amountFc  = (float) $payment['amount_fc'];

        $this->db->beginTransaction();
        try {
            // Before deleting allocations, check which expenses/salaries will become unsettled
            // and revert their recurrence instances back to pending/overdue
            $existingAllocations = $this->allocationRepo->findBySlip($id);
            
            // Allocations are deleted via CASCADE, but explicit delete for audit clarity
            $this->allocationRepo->deleteBySlip($id);
            $this->repo->delete($id, $ctx->business_id);

            // Reverse the deduction: add back the amount (positive delta for both BC and FC)
            $this->accountRepo->updateBalance($accountId, +$amountBc, $this->db, +$amountFc);

            // Reverse payee balance (payment undone, business owes again)
            $payeeId = $payment['payee_id'] ?? null;
            if ($payeeId) {
                $this->peopleRepo->updatePayeeBalance((int) $payeeId, -$amountBc);
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
}
