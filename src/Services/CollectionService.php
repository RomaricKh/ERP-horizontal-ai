<?php
namespace App\Services;

use App\Repository\CollectionRepository;
use App\Repository\CollectionAllocationRepository;
use App\Repository\AccountRepository;
use App\Repository\PeopleRepository;
use App\Repository\SalesInvoiceRepository;
use App\Services\ExchangeRateService;
use App\Services\NotificationService;
use App\Services\SettlementEngine;
use App\Support\AuthContext;
use PDO;

class CollectionService {
    private CollectionRepository           $repo;
    private CollectionAllocationRepository $allocationRepo;
    private AccountRepository              $accountRepo;
    private PeopleRepository               $peopleRepo;
    private SalesInvoiceRepository         $invoiceRepo;
    private ExchangeRateService            $exchangeRateService;
    private AuditService                   $audit;
    private SettlementEngine               $settlementEngine;
    private NotificationService            $notificationService;
    private PDO                            $db;

    public function __construct(PDO $db) {
        $this->db                  = $db;
        $this->repo                = new CollectionRepository($db);
        $this->allocationRepo      = new CollectionAllocationRepository($db);
        $this->accountRepo         = new AccountRepository($db);
        $this->peopleRepo          = new PeopleRepository($db);
        $this->invoiceRepo         = new SalesInvoiceRepository($db);
        $this->exchangeRateService = new ExchangeRateService($db);
        $this->audit               = new AuditService($db);
        $this->settlementEngine    = new SettlementEngine($db);
        $this->notificationService = new NotificationService($db);
    }

    /**
     * Return all collection slips for the current business.
     */
    public function list(): array {
        $ctx = AuthContext::get();
        return $this->repo->findAllForBusiness($ctx->business_id);
    }

    /**
     * Return a single collection slip by id.
     *
     * @throws \RuntimeException if not found
     */
    public function getById(int $id): array {
        $ctx        = AuthContext::get();
        $collection = $this->repo->findById($id, $ctx->business_id);
        if (!$collection) {
            throw new \RuntimeException('Collection not found');
        }
        $collection['lines'] = $this->repo->findLinesBySlipId($id);
        return $collection;
    }

    /**
     * Return allocations for a given collection slip.
     */
    public function getAllocations(int $slipId): array {
        return $this->allocationRepo->findBySlip($slipId);
    }

    /**
     * Create a new collection slip and add the amount to the account balance.
     * Accepts an optional allocations array; if empty, uses FIFO auto-allocation.
     * Validates that sum of allocations equals slip amount.
     * After saving, evaluates invoice statuses and marks fully-paid invoices as PAID.
     *
     * Required keys: slip_number, slip_date, lines (array of objects)
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
        if (empty(trim($data['slip_number'] ?? ''))) {
            $data['slip_number'] = 'CS-' . $ctx->business_id . '-' . time();
        }
        if (empty($data['slip_date'] ?? '')) {
            throw new \InvalidArgumentException('Slip date is required');
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
            // For auto-allocation with multiple currencies, we pass totalBc and an effective rate of 1.
            $allocations = $this->settlementEngine->autoAllocateFIFO(
                $payeeId,
                $ctx->business_id,
                $totalBc,
                $totalBc,
                1.0,
                'collection'
            );
        }

        // Validate allocation sum does not exceed slip amount (under-allocation is OK — surplus becomes customer credit)
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

            // Validate no over-allocation on any invoice
            foreach ($allocations as $alloc) {
                $refId = (int) $alloc['reference_id'];
                $allocAmount = (float) $alloc['amount_bc'];

                $existingAllocated = $this->allocationRepo->sumForDocument('SALES_INVOICE', $refId);
                $docTotal = $this->getInvoiceTotal($refId);

                if ($docTotal > 0 && ($existingAllocated + $allocAmount) > $docTotal + 0.01) {
                    throw new \InvalidArgumentException(
                        "Over-allocation on SALES_INVOICE #{$refId}: invoice total is {$docTotal}, already allocated {$existingAllocated}, attempting to add {$allocAmount}"
                    );
                }
            }
        }

        $this->db->beginTransaction();
        try {
            $id = $this->repo->create([
                'business_id'    => $ctx->business_id,
                'payee_id'       => $payeeId,
                'slip_number'    => trim($data['slip_number']),
                'slip_date'      => $data['slip_date'],
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

            // Incoming collection: add to account balance (positive delta for both BC and FC)
            foreach ($processedLines as $line) {
                $deltaFc = $line['amount_fc'];
                if ($line['account_currency'] !== strtolower(trim($line['currency']))) {
                    $deltaFc = $line['amount_bc']; 
                }
                $this->accountRepo->updateBalance($line['account_id'], +$line['amount_bc'], $this->db, +$deltaFc);
            }

            // Decrease payee balance (collection reduces what they owe)
            if ($payeeId) {
                $this->peopleRepo->updatePayeeBalance($payeeId, -$totalBc);
            }

            $this->audit->log(
                'CREATE',
                'collection_slips',
                $id,
                'CREATE',
                null,
                [
                    'total_bc'      => $totalBc,
                    'lines_count'   => count($processedLines),
                    'allocations'   => count($allocations),
                ],
                $ctx->user_id,
                $ctx->tenant_id,
                $ctx->business_id
            );

            // Evaluate customer settlement after collection creation — mark fully-paid invoices as PAID
            if ($payeeId) {
                $settledIds = $this->settlementEngine->evaluateCustomerSettlement($payeeId, $ctx->business_id);
                if (!empty($settledIds)) {
                    foreach ($settledIds as $invoiceId) {
                        $this->invoiceRepo->updateStatus($invoiceId, $ctx->business_id, 'PAID');
                    }
                    $this->audit->log('UPDATE', 'sales_invoices', implode(',', $settledIds), 'SETTLEMENT', null, ['settled_invoice_ids' => $settledIds, 'triggering_collection_id' => $id], $ctx->user_id, $ctx->tenant_id, $ctx->business_id);
                }
            }

            $this->db->commit();

            // Notify users with "finance.view" permission about new collection
            try {
                $recipientIds = $this->resolveRecipientsByPermission('finance.view', $ctx->business_id);
                if (!empty($recipientIds)) {
                    $this->notificationService->notify(
                        'PAYMENT_RECEIVED',
                        'New Collection Created',
                        'Collection ' . trim($data['slip_number']) . ' has been created',
                        $recipientIds,
                        'collections',
                        $id,
                        $ctx->user_id
                    );
                }
            } catch (\Throwable $e) {
                error_log("[CollectionService::create] Notification error: " . $e->getMessage());
            }

            return $id;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Delete a collection slip and reverse the account balance addition.
     * Also deletes associated allocations (CASCADE handles this, but we do it explicitly for clarity).
     *
     * @throws \RuntimeException if not found
     */
    public function delete(int $id): void {
        $ctx        = AuthContext::get();
        $collection = $this->repo->findById($id, $ctx->business_id);

        if (!$collection) {
            throw new \RuntimeException('Collection not found');
        }

        $lines = $this->repo->findLinesBySlipId($id);
        $totalBc = (float) $collection['total_bc'];

        $this->db->beginTransaction();
        try {
            // Allocations are deleted via CASCADE, but explicit delete for audit clarity
            $this->allocationRepo->deleteBySlip($id);
            $this->repo->delete($id, $ctx->business_id);

            // Reverse the addition: deduct the amount (negative delta for both BC and FC)
            foreach ($lines as $line) {
                $amountBc = (float) $line['amount_bc'];
                $amountFc = (float) $line['amount_fc'];
                $this->accountRepo->updateBalance($line['account_id'], -$amountBc, $this->db, -$amountFc);
            }

            // Reverse payee balance reduction (they owe again)
            $payeeId = $collection['payee_id'] ?? null;
            if ($payeeId) {
                $this->peopleRepo->updatePayeeBalance((int) $payeeId, +$totalBc);
            }

            $this->audit->log(
                'DELETE',
                'collection_slips',
                $id,
                'DELETE',
                $collection,
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
     * Get the total amount for an invoice by ID.
     */
    private function getInvoiceTotal(int $invoiceId): float {
        $stmt = $this->db->prepare("SELECT total_amount_bc FROM sales_invoices WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $invoiceId]);
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
