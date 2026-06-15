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
     * Required keys: account_id, slip_number, slip_date,
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
        if (empty(trim($data['slip_number'] ?? ''))) {
            throw new \InvalidArgumentException('Slip number is required');
        }
        if (empty($data['slip_date'] ?? '')) {
            throw new \InvalidArgumentException('Slip date is required');
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
                'collection'
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
                'account_id'     => $accountId,
                'payee_id'       => $payeeId,
                'slip_number'    => trim($data['slip_number']),
                'slip_date'      => $data['slip_date'],
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

            // Incoming collection: add to account balance (positive delta for both BC and FC)
            $this->accountRepo->updateBalance($accountId, +$amountBc, $this->db, +$amountFc);

            // Decrease payee balance (collection reduces what they owe)
            if ($payeeId) {
                $this->peopleRepo->updatePayeeBalance($payeeId, -$amountBc);
            }

            $this->audit->log(
                'CREATE',
                'collection_slips',
                $id,
                'CREATE',
                null,
                [
                    'account_id'    => $accountId,
                    'slip_number'   => trim($data['slip_number']),
                    'slip_date'     => $data['slip_date'],
                    'currency'      => trim($data['currency']),
                    'exchange_rate' => $exchangeRate,
                    'amount_fc'     => $amountFc,
                    'amount_bc'     => $amountBc,
                    'method'        => trim($data['method']),
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

        $accountId = (int) $collection['account_id'];
        $amountBc  = (float) $collection['amount_bc'];
        $amountFc  = (float) $collection['amount_fc'];

        $this->db->beginTransaction();
        try {
            // Allocations are deleted via CASCADE, but explicit delete for audit clarity
            $this->allocationRepo->deleteBySlip($id);
            $this->repo->delete($id, $ctx->business_id);

            // Reverse the addition: deduct the amount (negative delta for both BC and FC)
            $this->accountRepo->updateBalance($accountId, -$amountBc, $this->db, -$amountFc);

            // Reverse payee balance reduction (they owe again)
            $payeeId = $collection['payee_id'] ?? null;
            if ($payeeId) {
                $this->peopleRepo->updatePayeeBalance((int) $payeeId, +$amountBc);
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
