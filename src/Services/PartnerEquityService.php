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
                    'payee_id'      => $partnerId,
                    'slip_number'   => 'PC-' . $ctx->business_id . '-' . $partnerId . '-' . time(),
                    'slip_date'     => date('Y-m-d'),
                    'total_bc'      => $amountBc,
                    'notes'         => 'Partner contribution' . ($notes ? ': ' . $notes : ''),
                    'created_by'    => $ctx->user_id,
                ];
                $slipId   = $this->collectionRepo->create($slipData);
                $slipType = 'COLLECTION_SLIP';

                // Create the line
                $lineData = [
                    'account_id'    => $accountId,
                    'currency'      => $currency,
                    'exchange_rate' => $exchangeRate,
                    'amount_fc'     => $amount,
                    'amount_bc'     => $amountBc,
                    'method'        => 'TRANSFER',
                    'notes'         => null
                ];
                $this->collectionRepo->createLine($slipId, $lineData);

                // Credit the account (money IN = positive balance change)
                if ($accountId) {
                    $accountRepo = new \App\Repository\AccountRepository($this->db);
                    $accountRepo->updateBalance($accountId, +$amountBc, $this->db, +$amount);
                }
            } else {
                // Create a payment slip (money OUT)
                $slipData = [
                    'business_id'    => $ctx->business_id,
                    'payee_id'       => $partnerId,
                    'payment_number' => 'PW-' . $ctx->business_id . '-' . $partnerId . '-' . time(),
                    'payment_date'   => date('Y-m-d'),
                    'total_bc'       => $amountBc,
                    'notes'          => 'Partner withdrawal' . ($notes ? ': ' . $notes : ''),
                    'created_by'     => $ctx->user_id,
                ];
                $slipId   = $this->paymentRepo->create($slipData);
                $slipType = 'PAYMENT_SLIP';

                // Create the line
                $lineData = [
                    'account_id'    => $accountId,
                    'currency'      => $currency,
                    'exchange_rate' => $exchangeRate,
                    'amount_fc'     => $amount,
                    'amount_bc'     => $amountBc,
                    'method'        => 'TRANSFER',
                    'notes'         => null
                ];
                $this->paymentRepo->createLine($slipId, $lineData);

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

    /**
     * Reverse an existing partner equity entry.
     *
     * Creates an OPPOSITE entry (CONTRIBUTION → WITHDRAWAL, WITHDRAWAL → CONTRIBUTION)
     * for the same amount, with a corresponding opposite slip and capital adjustment.
     *
     * @param int      $equityId  The equity entry ID to reverse
     * @param int|null $accountId Optional account ID (uses original slip's account if null)
     * @return int The new reversal entry ID
     * @throws \InvalidArgumentException if entry not found
     * @throws \RuntimeException on processing errors
     */
    public function reverse(int $equityId, ?int $accountId = null): int {
        $ctx        = AuthContext::get();
        $businessId = $ctx->business_id;

        // Find the original equity entry
        $entries = $this->equityRepo->findAll($businessId);
        $original = null;
        foreach ($entries as $entry) {
            if ((int) $entry['id'] === $equityId) {
                $original = $entry;
                break;
            }
        }

        if (!$original) {
            throw new \InvalidArgumentException('Equity entry not found.');
        }

        $originalType = $original['equity_type'];
        $amount       = (float) $original['amount'];
        $partnerId    = (int) $original['partner_id'];

        // Determine opposite type
        $reverseType = ($originalType === 'CONTRIBUTION') ? 'WITHDRAWAL' : 'CONTRIBUTION';

        // Find the partner's share record
        $shareRecord = $this->findPartnerShare($partnerId, $businessId);
        if (!$shareRecord) {
            throw new \InvalidArgumentException(
                'Partner does not have an active share record in this business.'
            );
        }

        // For reversal of CONTRIBUTION (becomes WITHDRAWAL): check invested capital
        if ($reverseType === 'WITHDRAWAL') {
            $currentCapital = (float) $shareRecord['invested_capital'];
            if ($amount > $currentCapital) {
                throw new \InvalidArgumentException(
                    'Reversal amount (' . $amount . ') exceeds current invested capital (' . $currentCapital . ').'
                );
            }
        }

        // Determine account ID from original slip if not provided
        if ($accountId === null) {
            if ($original['slip_type'] === 'COLLECTION_SLIP' && $original['slip_id']) {
                $stmt = $this->db->prepare("SELECT account_id, currency, exchange_rate FROM collection_slips WHERE id = :id");
                $stmt->execute(['id' => $original['slip_id']]);
                $slipData = $stmt->fetch(\PDO::FETCH_ASSOC);
                $accountId    = $slipData ? (int) $slipData['account_id'] : null;
                $currency     = $slipData['currency'] ?? 'USD';
                $exchangeRate = (float) ($slipData['exchange_rate'] ?? 1.0);
            } elseif ($original['slip_type'] === 'PAYMENT_SLIP' && $original['slip_id']) {
                $stmt = $this->db->prepare("SELECT account_id, currency, exchange_rate FROM payment_slips WHERE id = :id");
                $stmt->execute(['id' => $original['slip_id']]);
                $slipData = $stmt->fetch(\PDO::FETCH_ASSOC);
                $accountId    = $slipData ? (int) $slipData['account_id'] : null;
                $currency     = $slipData['currency'] ?? 'USD';
                $exchangeRate = (float) ($slipData['exchange_rate'] ?? 1.0);
            }
        }

        // Defaults if we couldn't resolve from the original slip
        $currency     = $currency ?? 'USD';
        $exchangeRate = $exchangeRate ?? 1.0;
        $amountBc     = round($amount * $exchangeRate, 2);

        $notes = "Reversal of entry #{$equityId}";

        // Capital adjustment is opposite of the reversal type
        $capitalAdjustment = ($reverseType === 'CONTRIBUTION') ? $amount : -$amount;

        $this->db->beginTransaction();
        try {
            $slipType = null;
            $slipId   = null;

            if ($reverseType === 'CONTRIBUTION') {
                // Reversal creates a collection slip (money IN)
                $slipData = [
                    'business_id'   => $businessId,
                    'account_id'    => $accountId,
                    'payee_id'      => $partnerId,
                    'slip_number'   => 'PC-REV-' . $businessId . '-' . $partnerId . '-' . time(),
                    'slip_date'     => date('Y-m-d'),
                    'currency'      => $currency,
                    'exchange_rate' => $exchangeRate,
                    'amount_fc'     => $amount,
                    'amount_bc'     => $amountBc,
                    'method'        => 'TRANSFER',
                    'notes'         => $notes,
                    'created_by'    => $ctx->user_id,
                ];
                $slipId   = $this->collectionRepo->create($slipData);
                $slipType = 'COLLECTION_SLIP';

                if ($accountId) {
                    $accountRepo = new \App\Repository\AccountRepository($this->db);
                    $accountRepo->updateBalance($accountId, +$amountBc, $this->db, +$amount);
                }
            } else {
                // Reversal creates a payment slip (money OUT)
                $slipData = [
                    'business_id'    => $businessId,
                    'account_id'     => $accountId,
                    'payee_id'       => $partnerId,
                    'payment_number' => 'PW-REV-' . $businessId . '-' . $partnerId . '-' . time(),
                    'payment_date'   => date('Y-m-d'),
                    'currency'       => $currency,
                    'exchange_rate'  => $exchangeRate,
                    'amount_fc'      => $amount,
                    'amount_bc'      => $amountBc,
                    'method'         => 'TRANSFER',
                    'notes'          => $notes,
                    'created_by'     => $ctx->user_id,
                ];
                $slipId   = $this->paymentRepo->create($slipData);
                $slipType = 'PAYMENT_SLIP';

                if ($accountId) {
                    $accountRepo = new \App\Repository\AccountRepository($this->db);
                    $accountRepo->updateBalance($accountId, -$amountBc, $this->db, -$amount);
                }
            }

            // Insert the reversal equity record
            $record = [
                'business_id' => $businessId,
                'partner_id'  => $partnerId,
                'equity_type' => $reverseType,
                'amount'      => $amount,
                'slip_type'   => $slipType,
                'slip_id'     => $slipId,
                'notes'       => $notes,
            ];

            $id = $this->equityRepo->insert($record);

            // Update invested_capital on the partner's share record
            $this->shareRepo->updateInvestedCapital(
                (int) $shareRecord['id'],
                $businessId,
                $capitalAdjustment
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        // Audit log
        $this->audit->log(
            'CREATE',
            'partner_equity',
            $id,
            'REVERSE',
            ['reversed_entry_id' => $equityId],
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
