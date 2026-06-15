<?php
namespace App\Services;

use App\Repository\PartnerEquityRepository;
use App\Repository\PartnerShareRepository;
use App\Repository\PaymentRepository;
use App\Repository\CollectionRepository;
use App\Support\AuthContext;
use PDO;

class PartnerEquityService {
    private PartnerEquityRepository $equityRepo;
    private PartnerShareRepository  $shareRepo;
    private PaymentRepository       $paymentRepo;
    private CollectionRepository    $collectionRepo;
    private AuditService            $audit;
    private PDO                     $db;

    public function __construct(PDO $db) {
        $this->db             = $db;
        $this->equityRepo     = new PartnerEquityRepository($db);
        $this->shareRepo      = new PartnerShareRepository($db);
        $this->paymentRepo    = new PaymentRepository($db);
        $this->collectionRepo = new CollectionRepository($db);
        $this->audit          = new AuditService($db);
    }

    /**
     * Return all partner equity entries for the current business with partner names.
     * Optionally filtered by partner_id.
     */
    public function list(?int $partnerId = null): array {
        $businessId = AuthContext::businessId();
        return $this->equityRepo->findAll($businessId, $partnerId);
    }

    /**
     * Create a new partner equity entry (CONTRIBUTION or WITHDRAWAL).
     *
     * For CONTRIBUTION:
     *   - Creates a collection slip (money IN to selected account)
     *   - Creates equity record with slip_type = 'COLLECTION_SLIP', slip_id = new_slip_id
     *   - Updates invested_capital (increase)
     *
     * For WITHDRAWAL:
     *   - Validates amount does not exceed invested_capital
     *   - Creates a payment slip (money OUT from selected account)
     *   - Creates equity record with slip_type = 'PAYMENT_SLIP', slip_id = new_slip_id
     *   - Updates invested_capital (decrease)
     *
     * @return int The new partner equity ID
     * @throws \InvalidArgumentException if validation fails
     */
    public function create(array $data): int {
        $ctx        = AuthContext::get();
        $partnerId  = (int) $data['partner_id'];
        $equityType = strtoupper(trim($data['equity_type']));
        $amount     = (float) $data['amount'];
        $accountId  = isset($data['account_id']) ? (int) $data['account_id'] : null;
        $currency   = isset($data['currency']) ? trim($data['currency']) : 'USD';
        $exchangeRate = isset($data['exchange_rate']) ? (float) $data['exchange_rate'] : 1.0000;
        $notes      = $data['notes'] ?? null;

        // Calculate BC amount
        $amountBc = round($amount * $exchangeRate, 2);

        // Validate equity_type
        if (!in_array($equityType, ['CONTRIBUTION', 'WITHDRAWAL'], true)) {
            throw new \InvalidArgumentException('Invalid equity_type. Must be CONTRIBUTION or WITHDRAWAL.');
        }

        // Validate amount is positive
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than zero.');
        }

        // Validate partner has an active share record in this business
        $shareRecord = $this->findPartnerShare($partnerId, $ctx->business_id);
        if (!$shareRecord) {
            throw new \InvalidArgumentException(
                'Partner does not have an active share record in this business.'
            );
        }

        // For WITHDRAWAL: check that invested_capital won't go below zero
        if ($equityType === 'WITHDRAWAL') {
            $currentCapital = (float) $shareRecord['invested_capital'];
            if ($amount > $currentCapital) {
                throw new \InvalidArgumentException(
                    'Withdrawal amount (' . $amount . ') exceeds current invested capital (' . $currentCapital . ').'
                );
            }
        }

        // Determine the capital adjustment amount
        $capitalAdjustment = ($equityType === 'CONTRIBUTION') ? $amount : -$amount;

        // Use transaction for atomicity
        $this->db->beginTransaction();
        try {
            $slipType = null;
            $slipId   = null;

            if ($equityType === 'CONTRIBUTION') {
                // Create a collection slip (money IN)
                $slipData = [
                    'business_id'   => $ctx->business_id,
                    'account_id'    => $accountId,
                    'payee_id'      => $partnerId,
                    'slip_number'   => 'PC-' . $ctx->business_id . '-' . $partnerId . '-' . time(),
                    'slip_date'     => date('Y-m-d'),
                    'currency'      => $currency,
                    'exchange_rate' => $exchangeRate,
                    'amount_fc'     => $amount,
                    'amount_bc'     => $amountBc,
                    'method'        => 'TRANSFER',
                    'notes'         => 'Partner contribution' . ($notes ? ': ' . $notes : ''),
                    'created_by'    => $ctx->user_id,
                ];
                $slipId   = $this->collectionRepo->create($slipData);
                $slipType = 'COLLECTION_SLIP';

                // Credit the account (money IN = positive balance change)
                if ($accountId) {
                    $accountRepo = new \App\Repository\AccountRepository($this->db);
                    $accountRepo->updateBalance($accountId, +$amountBc, $this->db, +$amount);
                }
            } else {
                // Create a payment slip (money OUT)
                $slipData = [
                    'business_id'    => $ctx->business_id,
                    'account_id'     => $accountId,
                    'payee_id'       => $partnerId,
                    'payment_number' => 'PW-' . $ctx->business_id . '-' . $partnerId . '-' . time(),
                    'payment_date'   => date('Y-m-d'),
                    'currency'       => $currency,
                    'exchange_rate'  => $exchangeRate,
                    'amount_fc'      => $amount,
                    'amount_bc'      => $amountBc,
                    'method'         => 'TRANSFER',
                    'notes'          => 'Partner withdrawal' . ($notes ? ': ' . $notes : ''),
                    'created_by'     => $ctx->user_id,
                ];
                $slipId   = $this->paymentRepo->create($slipData);
                $slipType = 'PAYMENT_SLIP';

                // Debit the account (money OUT = negative balance change)
                if ($accountId) {
                    $accountRepo = new \App\Repository\AccountRepository($this->db);
                    $accountRepo->updateBalance($accountId, -$amountBc, $this->db, -$amount);
                }
            }

            // Insert the equity record
            $record = [
                'business_id' => $ctx->business_id,
                'partner_id'  => $partnerId,
                'equity_type' => $equityType,
                'amount'      => $amount,
                'slip_type'   => $slipType,
                'slip_id'     => $slipId,
                'notes'       => $notes,
            ];

            $id = $this->equityRepo->insert($record);

            // Update invested_capital on the partner's share record
            $this->shareRepo->updateInvestedCapital(
                (int) $shareRecord['id'],
                $ctx->business_id,
                $capitalAdjustment
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        // Audit log (outside transaction — non-critical)
        $this->audit->log(
            'CREATE',
            'partner_equity',
            $id,
            'CREATE',
            null,
            $record,
            $ctx->user_id,
            $ctx->tenant_id,
            $ctx->business_id
        );

        return $id;
    }

    /* =====================================================================
     * Private helpers
     * ===================================================================== */

    /**
     * Find the partner's share record in the given business.
     */
    private function findPartnerShare(int $partnerId, int $businessId): ?array {
        $shares = $this->shareRepo->getSharesForBusiness($businessId);
        foreach ($shares as $share) {
            if ((int) $share['partner_id'] === $partnerId) {
                return $share;
            }
        }
        return null;
    }
}
