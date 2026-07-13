<?php
namespace App\Services;

use App\Repository\AccountTransferRepository;
use App\Repository\AccountRepository;
use App\Support\AuthContext;
use PDO;

class AccountTransferService {
    private PDO $db;
    private AccountTransferRepository $transferRepo;
    private AccountRepository $accountRepo;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->transferRepo = new AccountTransferRepository($db);
        $this->accountRepo = new AccountRepository($db);
    }

    /**
     * Get all transfers for the business.
     */
    public function getAllForBusiness(): array {
        $businessId = AuthContext::businessId();
        return $this->transferRepo->findAllForBusiness($businessId);
    }

    /**
     * Process an inter-account transfer.
     */
    public function transferFunds(array $data): int {
        $businessId = AuthContext::businessId();
        $userId = AuthContext::userId();

        $fromAccountId = (int) $data['from_account_id'];
        $toAccountId   = (int) $data['to_account_id'];
        
        if ($fromAccountId === $toAccountId) {
            throw new \InvalidArgumentException("Cannot transfer to the same account.");
        }

        $amountFrom = (float) $data['amount_from'];
        $amountTo   = (float) $data['amount_to'];
        $amountBc   = (float) $data['amount_bc'];
        $exchangeRate = (float) $data['exchange_rate'];

        if ($amountFrom <= 0 || $amountTo <= 0 || $amountBc <= 0) {
            throw new \InvalidArgumentException("Transfer amounts must be greater than zero.");
        }

        $fromAccount = $this->accountRepo->findById($fromAccountId, $businessId);
        $toAccount   = $this->accountRepo->findById($toAccountId, $businessId);

        if (!$fromAccount || !$toAccount) {
            throw new \RuntimeException("One or both accounts not found.");
        }

        $this->db->beginTransaction();
        try {
            // 1. Insert transfer record
            $transferId = $this->transferRepo->insert([
                'business_id'     => $businessId,
                'from_account_id' => $fromAccountId,
                'to_account_id'   => $toAccountId,
                'amount_from'     => $amountFrom,
                'amount_to'       => $amountTo,
                'amount_bc'       => $amountBc,
                'exchange_rate'   => $exchangeRate,
                'transfer_date'   => $data['transfer_date'],
                'reference'       => $data['reference'] ?? null,
                'notes'           => $data['notes'] ?? null,
                'created_by'      => $userId
            ]);

            // 2. Adjust Account Balances
            // Deduct from source (-amountFrom FC, -amountBc BC)
            $this->accountRepo->updateBalance($fromAccountId, -$amountBc, $this->db, -$amountFrom);
            
            // Add to destination (+amountTo FC, +amountBc BC)
            $this->accountRepo->updateBalance($toAccountId, $amountBc, $this->db, $amountTo);

            // 3. Log Accounting Entry
            // Debit the destination account, Credit the source account.
            // Since accounting_entries stores a single amount, we'll store the BC amount and rate.
            $stmt = $this->db->prepare("
                INSERT INTO accounting_entries
                  (business_id, entry_date, entry_type, debit_account, credit_account,
                   amount_fc, amount_bc, currency, reference_type, reference_id, description, created_at)
                VALUES
                  (:business_id, :entry_date, 'account_transfer', :debit_account, :credit_account,
                   :amount_fc, :amount_bc, :currency, 'account_transfer', :reference_id, :description, NOW())
            ");
            $stmt->execute([
                'business_id'    => $businessId,
                'entry_date'     => $data['transfer_date'],
                'debit_account'  => $toAccount['name'],
                'credit_account' => $fromAccount['name'],
                // Storing from currency as FC
                'amount_fc'      => $amountFrom,
                'amount_bc'      => $amountBc,
                'currency'       => $fromAccount['currency'],
                'reference_id'   => $transferId,
                'description'    => "Transfer from {$fromAccount['name']} to {$toAccount['name']}" . 
                                    (empty($data['reference']) ? "" : " Ref: {$data['reference']}")
            ]);

            $this->db->commit();
            return $transferId;
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
