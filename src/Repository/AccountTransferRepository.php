<?php
namespace App\Repository;

use PDO;

class AccountTransferRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Get all transfers for a business
     */
    public function findAllForBusiness(int $businessId): array {
        $stmt = $this->db->prepare("
            SELECT t.*, 
                   fa.name as from_account_name, fa.currency as from_currency,
                   ta.name as to_account_name, ta.currency as to_currency,
                   u.name as created_by_name
            FROM account_transfers t
            JOIN business_accounts fa ON t.from_account_id = fa.id
            JOIN business_accounts ta ON t.to_account_id = ta.id
            JOIN users u ON t.created_by = u.id
            WHERE t.business_id = :business
            ORDER BY t.transfer_date DESC, t.id DESC
        ");
        $stmt->execute(['business' => $businessId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get a single transfer
     */
    public function findById(int $id, int $businessId): ?array {
        $stmt = $this->db->prepare("
            SELECT t.*, 
                   fa.name as from_account_name, fa.currency as from_currency,
                   ta.name as to_account_name, ta.currency as to_currency
            FROM account_transfers t
            JOIN business_accounts fa ON t.from_account_id = fa.id
            JOIN business_accounts ta ON t.to_account_id = ta.id
            WHERE t.id = :id AND t.business_id = :business
        ");
        $stmt->execute(['id' => $id, 'business' => $businessId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Insert a new transfer record
     */
    public function insert(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO account_transfers
              (business_id, from_account_id, to_account_id, amount_from, amount_to, amount_bc, exchange_rate, transfer_date, reference, notes, created_by, created_at, updated_at)
            VALUES
              (:business_id, :from_account_id, :to_account_id, :amount_from, :amount_to, :amount_bc, :exchange_rate, :transfer_date, :reference, :notes, :created_by, NOW(), NOW())
        ");
        $stmt->execute([
            'business_id'     => $data['business_id'],
            'from_account_id' => $data['from_account_id'],
            'to_account_id'   => $data['to_account_id'],
            'amount_from'     => $data['amount_from'],
            'amount_to'       => $data['amount_to'],
            'amount_bc'       => $data['amount_bc'],
            'exchange_rate'   => $data['exchange_rate'],
            'transfer_date'   => $data['transfer_date'],
            'reference'       => $data['reference'] ?? null,
            'notes'           => $data['notes'] ?? null,
            'created_by'      => $data['created_by']
        ]);
        return (int) $this->db->lastInsertId();
    }
}
