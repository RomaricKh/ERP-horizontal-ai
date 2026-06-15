<?php

namespace App\Services;

use App\Support\AuthContext;
use PDO;

class AccountingEntryService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Create a prepaid asset record linked to a bulk advance payment.
     * Also generates the initial accounting entry (Debit Prepaid Expenses, Credit Cash/Bank).
     *
     * @param int   $bulkAdvancePaymentId The linked bulk advance payment ID
     * @param array $data Expected keys: total_amount, total_amount_bc, per_period_amount,
     *                    per_period_amount_bc, currency, periods_total
     * @return int The created prepaid_asset ID
     */
    public function createPrepaidAsset(int $bulkAdvancePaymentId, array $data): int
    {
        $businessId = AuthContext::businessId();

        // 1. Insert the prepaid_assets record
        $stmt = $this->db->prepare("
            INSERT INTO prepaid_assets
              (business_id, bulk_advance_payment_id, total_amount, total_amount_bc,
               per_period_amount, per_period_amount_bc, currency, periods_total,
               periods_recognized, status, created_at, updated_at)
            VALUES
              (:business_id, :bulk_advance_payment_id, :total_amount, :total_amount_bc,
               :per_period_amount, :per_period_amount_bc, :currency, :periods_total,
               0, 'active', NOW(), NOW())
        ");
        $stmt->execute([
            'business_id'              => $businessId,
            'bulk_advance_payment_id'  => $bulkAdvancePaymentId,
            'total_amount'             => $data['total_amount'],
            'total_amount_bc'          => $data['total_amount_bc'],
            'per_period_amount'        => $data['per_period_amount'],
            'per_period_amount_bc'     => $data['per_period_amount_bc'],
            'currency'                 => $data['currency'],
            'periods_total'            => $data['periods_total'],
        ]);

        $prepaidAssetId = (int) $this->db->lastInsertId();

        // 2. Generate accounting entry: Debit "Prepaid Expenses" / Credit "Cash/Bank"
        $stmt = $this->db->prepare("
            INSERT INTO accounting_entries
              (business_id, entry_date, entry_type, debit_account, credit_account,
               amount_fc, amount_bc, currency, reference_type, reference_id, description, created_at)
            VALUES
              (:business_id, :entry_date, 'prepaid_asset_creation', 'Prepaid Expenses', 'Cash/Bank',
               :amount_fc, :amount_bc, :currency, 'prepaid_asset', :reference_id, :description, NOW())
        ");
        $stmt->execute([
            'business_id'  => $businessId,
            'entry_date'   => date('Y-m-d'),
            'amount_fc'    => $data['total_amount'],
            'amount_bc'    => $data['total_amount_bc'],
            'currency'     => $data['currency'],
            'reference_id' => $prepaidAssetId,
            'description'  => "Prepaid asset creation for bulk advance payment #{$bulkAdvancePaymentId}",
        ]);

        return $prepaidAssetId;
    }

    /**
     * Perform expense recognition for a single period.
     * Generates entry: Debit "Expense Account" / Credit "Prepaid Expenses" for the per-period amount.
     * Increments periods_recognized. If all recognized, marks as fully_amortized.
     *
     * @param int    $prepaidAssetId The prepaid asset being amortized
     * @param string $dueDate        The due date of the period being recognized
     * @param float  $amountFc       The per-period amount in foreign currency
     * @param float  $amountBc       The per-period amount in base currency
     */
    public function performExpenseRecognition(int $prepaidAssetId, string $dueDate, float $amountFc, float $amountBc): void
    {
        $businessId = AuthContext::businessId();

        // 1. Generate accounting entry: Debit "Expense Account" / Credit "Prepaid Expenses"
        $stmt = $this->db->prepare("
            INSERT INTO accounting_entries
              (business_id, entry_date, entry_type, debit_account, credit_account,
               amount_fc, amount_bc, currency, reference_type, reference_id, description, created_at)
            VALUES
              (:business_id, :entry_date, 'expense_recognition', 'Expense Account', 'Prepaid Expenses',
               :amount_fc, :amount_bc, :currency, 'prepaid_asset', :reference_id, :description, NOW())
        ");

        // Fetch the currency from the prepaid asset
        $assetStmt = $this->db->prepare("
            SELECT currency FROM prepaid_assets
            WHERE id = :id AND business_id = :business_id
        ");
        $assetStmt->execute(['id' => $prepaidAssetId, 'business_id' => $businessId]);
        $asset = $assetStmt->fetch(PDO::FETCH_ASSOC);

        $currency = $asset ? $asset['currency'] : 'USD';

        $stmt->execute([
            'business_id'  => $businessId,
            'entry_date'   => $dueDate,
            'amount_fc'    => $amountFc,
            'amount_bc'    => $amountBc,
            'currency'     => $currency,
            'reference_id' => $prepaidAssetId,
            'description'  => "Expense recognition for period due {$dueDate}",
        ]);

        // 2. Increment periods_recognized on the prepaid_assets record
        $stmt = $this->db->prepare("
            UPDATE prepaid_assets
            SET periods_recognized = periods_recognized + 1,
                updated_at = NOW()
            WHERE id = :id AND business_id = :business_id
        ");
        $stmt->execute(['id' => $prepaidAssetId, 'business_id' => $businessId]);

        // 3. Check if fully amortized (periods_recognized == periods_total)
        $stmt = $this->db->prepare("
            SELECT periods_recognized, periods_total
            FROM prepaid_assets
            WHERE id = :id AND business_id = :business_id
        ");
        $stmt->execute(['id' => $prepaidAssetId, 'business_id' => $businessId]);
        $updated = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($updated && (int) $updated['periods_recognized'] >= (int) $updated['periods_total']) {
            $stmt = $this->db->prepare("
                UPDATE prepaid_assets
                SET status = 'fully_amortized', updated_at = NOW()
                WHERE id = :id AND business_id = :business_id
            ");
            $stmt->execute(['id' => $prepaidAssetId, 'business_id' => $businessId]);
        }
    }

    /**
     * Reverse a prepaid asset by generating contra accounting entries
     * and updating the status to 'reversed'.
     *
     * Generates: Debit "Cash/Bank" / Credit "Prepaid Expenses" for the remaining unrecognized amount.
     *
     * @param int $prepaidAssetId The prepaid asset ID to reverse
     * @throws \RuntimeException If prepaid asset not found
     */
    public function reversePrepaidAsset(int $prepaidAssetId): void
    {
        $businessId = AuthContext::businessId();

        // Fetch the prepaid asset
        $stmt = $this->db->prepare("
            SELECT id, total_amount, total_amount_bc, per_period_amount, per_period_amount_bc,
                   currency, periods_total, periods_recognized, status
            FROM prepaid_assets
            WHERE id = :id AND business_id = :business_id
        ");
        $stmt->execute(['id' => $prepaidAssetId, 'business_id' => $businessId]);
        $asset = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$asset) {
            throw new \RuntimeException('Prepaid asset not found');
        }

        // Calculate remaining unrecognized amount
        $periodsRemaining = (int) $asset['periods_total'] - (int) $asset['periods_recognized'];
        $remainingAmountFc = round((float) $asset['per_period_amount'] * $periodsRemaining, 2);
        $remainingAmountBc = round((float) $asset['per_period_amount_bc'] * $periodsRemaining, 2);

        // Generate contra accounting entry: Debit "Cash/Bank" / Credit "Prepaid Expenses"
        $stmt = $this->db->prepare("
            INSERT INTO accounting_entries
              (business_id, entry_date, entry_type, debit_account, credit_account,
               amount_fc, amount_bc, currency, reference_type, reference_id, description, created_at)
            VALUES
              (:business_id, :entry_date, 'prepaid_asset_reversal', 'Cash/Bank', 'Prepaid Expenses',
               :amount_fc, :amount_bc, :currency, 'prepaid_asset', :reference_id, :description, NOW())
        ");
        $stmt->execute([
            'business_id'  => $businessId,
            'entry_date'   => date('Y-m-d'),
            'amount_fc'    => $remainingAmountFc,
            'amount_bc'    => $remainingAmountBc,
            'currency'     => $asset['currency'],
            'reference_id' => $prepaidAssetId,
            'description'  => "Prepaid asset reversal - {$periodsRemaining} periods remaining",
        ]);

        // Update prepaid_assets status to 'reversed'
        $stmt = $this->db->prepare("
            UPDATE prepaid_assets
            SET status = 'reversed', updated_at = NOW()
            WHERE id = :id AND business_id = :business_id
        ");
        $stmt->execute(['id' => $prepaidAssetId, 'business_id' => $businessId]);
    }

    /**
     * List prepaid assets for the current business with optional filters.
     *
     * @param array $filters Supported keys: status, from_date, to_date
     * @return array List of prepaid asset records
     */
    public function listPrepaidAssets(array $filters = []): array
    {
        $businessId = AuthContext::businessId();

        $sql = "
            SELECT id, business_id, bulk_advance_payment_id, total_amount, total_amount_bc,
                   per_period_amount, per_period_amount_bc, currency, periods_total,
                   periods_recognized, status, created_at, updated_at
            FROM prepaid_assets
            WHERE business_id = :business_id
        ";
        $params = ['business_id' => $businessId];

        if (!empty($filters['status'])) {
            $sql .= " AND status = :status";
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['from_date'])) {
            $sql .= " AND created_at >= :from_date";
            $params['from_date'] = $filters['from_date'] . ' 00:00:00';
        }

        if (!empty($filters['to_date'])) {
            $sql .= " AND created_at <= :to_date";
            $params['to_date'] = $filters['to_date'] . ' 23:59:59';
        }

        $sql .= " ORDER BY created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fetch a single prepaid asset by ID, scoped to the current business.
     * Includes the amortization schedule by joining accounting_entries.
     *
     * @param int $id The prepaid asset ID
     * @return array The prepaid asset record with amortization_schedule
     * @throws \RuntimeException If not found
     */
    public function getPrepaidAsset(int $id): ?array
    {
        $businessId = AuthContext::businessId();

        // Fetch the prepaid asset
        $stmt = $this->db->prepare("
            SELECT id, business_id, bulk_advance_payment_id, total_amount, total_amount_bc,
                   per_period_amount, per_period_amount_bc, currency, periods_total,
                   periods_recognized, status, created_at, updated_at
            FROM prepaid_assets
            WHERE id = :id AND business_id = :business_id
        ");
        $stmt->execute(['id' => $id, 'business_id' => $businessId]);
        $asset = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$asset) {
            throw new \RuntimeException('Prepaid asset not found');
        }

        // Fetch amortization schedule: all accounting entries linked to this prepaid asset
        $stmt = $this->db->prepare("
            SELECT id, entry_date, entry_type, debit_account, credit_account,
                   amount_fc, amount_bc, currency, description, created_at
            FROM accounting_entries
            WHERE reference_type = 'prepaid_asset'
              AND reference_id = :reference_id
              AND business_id = :business_id
            ORDER BY entry_date ASC, id ASC
        ");
        $stmt->execute(['reference_id' => $id, 'business_id' => $businessId]);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Build the amortization schedule showing recognized vs pending periods
        $periodsTotal = (int) $asset['periods_total'];
        $periodsRecognized = (int) $asset['periods_recognized'];
        $periodsPending = $periodsTotal - $periodsRecognized;

        $asset['amortization_schedule'] = $entries;
        $asset['periods_pending'] = $periodsPending;

        return $asset;
    }

    /**
     * List accounting entries for the current business with optional filters.
     *
     * @param array $filters Supported keys: entry_type, reference_type, reference_id, date_from, date_to
     * @return array List of accounting entry records
     */
    public function listAccountingEntries(array $filters = []): array
    {
        $businessId = AuthContext::businessId();

        $sql = "
            SELECT id, business_id, entry_date, entry_type, debit_account, credit_account,
                   amount_fc, amount_bc, currency, reference_type, reference_id,
                   description, created_at
            FROM accounting_entries
            WHERE business_id = :business_id
        ";
        $params = ['business_id' => $businessId];

        if (!empty($filters['entry_type'])) {
            $sql .= " AND entry_type = :entry_type";
            $params['entry_type'] = $filters['entry_type'];
        }

        if (!empty($filters['reference_type'])) {
            $sql .= " AND reference_type = :reference_type";
            $params['reference_type'] = $filters['reference_type'];
        }

        if (!empty($filters['reference_id'])) {
            $sql .= " AND reference_id = :reference_id";
            $params['reference_id'] = $filters['reference_id'];
        }

        if (!empty($filters['date_from'])) {
            $sql .= " AND entry_date >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND entry_date <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }

        $sql .= " ORDER BY entry_date DESC, id DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
