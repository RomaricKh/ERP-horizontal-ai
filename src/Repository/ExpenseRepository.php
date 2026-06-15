<?php
namespace App\Repository;

use PDO;

class ExpenseRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /* =====================================================================
     * EXPENSE CATEGORIES
     * ===================================================================== */

    /**
     * Return all expense categories for a tenant/business, ordered by name.
     */
    public function findAllCategories(int $tenantId, int $businessId): array {
        $stmt = $this->db->prepare("
            SELECT id, tenant_id, business_id, name, is_fixed, created_at, updated_at
            FROM expense_categories
            WHERE tenant_id   = :tenant
              AND business_id = :business
            ORDER BY name
        ");
        $stmt->execute(['tenant' => $tenantId, 'business' => $businessId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return a single category by id, scoped to tenant.
     */
    public function findCategoryById(int $id, int $tenantId): ?array {
        $stmt = $this->db->prepare("
            SELECT id, tenant_id, business_id, name, is_fixed, created_at, updated_at
            FROM expense_categories
            WHERE id        = :id
              AND tenant_id = :tenant
            LIMIT 1
        ");
        $stmt->execute(['id' => $id, 'tenant' => $tenantId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Insert a new expense category and return the new id.
     *
     * Expected keys: tenant_id, business_id, name, is_fixed, created_by
     */
    public function createCategory(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO expense_categories
              (tenant_id, business_id, name, is_fixed, created_by, created_at, updated_at)
            VALUES
              (:tenant_id, :business_id, :name, :is_fixed, :created_by, NOW(), NOW())
        ");
        $stmt->execute([
            'tenant_id'   => $data['tenant_id'],
            'business_id' => $data['business_id'],
            'name'        => $data['name'],
            'is_fixed'    => $data['is_fixed'] ? 1 : 0,
            'created_by'  => $data['created_by'],
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Update an existing expense category.
     */
    public function updateCategory(int $id, int $tenantId, array $data): void {
        $stmt = $this->db->prepare("
            UPDATE expense_categories
            SET name       = :name,
                is_fixed   = :is_fixed,
                updated_at = NOW()
            WHERE id        = :id
              AND tenant_id = :tenant
        ");
        $stmt->execute([
            'name'     => $data['name'],
            'is_fixed' => $data['is_fixed'] ? 1 : 0,
            'id'       => $id,
            'tenant'   => $tenantId,
        ]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Category not found or not allowed');
        }
    }

    /**
     * Delete an expense category scoped to tenant.
     */
    public function deleteCategory(int $id, int $tenantId): void {
        $stmt = $this->db->prepare("
            DELETE FROM expense_categories
            WHERE id        = :id
              AND tenant_id = :tenant
        ");
        $stmt->execute(['id' => $id, 'tenant' => $tenantId]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Category not found or not allowed');
        }
    }

    /**
     * Count how many expenses reference a given category.
     * Used to guard against deleting categories that are in use.
     */
    public function countExpensesForCategory(int $categoryId): int {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM expenses
            WHERE expense_category_id = :category_id
        ");
        $stmt->execute(['category_id' => $categoryId]);
        return (int) $stmt->fetchColumn();
    }

    /* =====================================================================
     * EXPENSES
     * ===================================================================== */

    /**
     * Return all expenses for a business with payee name and category name.
     * JOINs: payees → people (for name), expense_categories (for name).
     */
    public function findAllForBusiness(int $businessId): array {
        $stmt = $this->db->prepare("
            SELECT
                e.id,
                e.business_id,
                e.payee_id,
                p.name          AS payee_name,
                e.expense_category_id,
                ec.name         AS category_name,
                e.expense_date,
                e.currency,
                e.exchange_rate,
                e.amount_fc,
                e.amount_bc,
                e.notes,
                e.reference_number,
                e.reference_date,
                e.attachment_path,
                e.created_at,
                e.updated_at,
                COALESCE(
                    (SELECT SUM(pa.amount_bc) FROM payment_allocations pa WHERE pa.reference_type = 'EXPENSE' AND pa.reference_id = e.id),
                    0
                ) AS paid_amount_bc,
                COALESCE(
                    (SELECT SUM(pa.amount_fc) FROM payment_allocations pa WHERE pa.reference_type = 'EXPENSE' AND pa.reference_id = e.id),
                    0
                ) AS paid_amount_fc
            FROM expenses e
            LEFT JOIN payees py
                ON py.id = e.payee_id
            LEFT JOIN people p
                ON p.id = py.people_id
            LEFT JOIN expense_categories ec
                ON ec.id = e.expense_category_id
            WHERE e.business_id = :business
            ORDER BY e.expense_date DESC, e.id DESC
        ");
        $stmt->execute(['business' => $businessId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Return a single expense by id, scoped to business.
     */
    public function findById(int $id, int $businessId): ?array {
        $stmt = $this->db->prepare("
            SELECT
                e.id,
                e.business_id,
                e.payee_id,
                p.name          AS payee_name,
                e.expense_category_id,
                ec.name         AS category_name,
                e.expense_date,
                e.currency,
                e.exchange_rate,
                e.amount_fc,
                e.amount_bc,
                e.notes,
                e.reference_number,
                e.reference_date,
                e.attachment_path,
                e.created_at,
                e.updated_at
            FROM expenses e
            LEFT JOIN payees py
                ON py.id = e.payee_id
            LEFT JOIN people p
                ON p.id = py.people_id
            LEFT JOIN expense_categories ec
                ON ec.id = e.expense_category_id
            WHERE e.id          = :id
              AND e.business_id = :business
            LIMIT 1
        ");
        $stmt->execute(['id' => $id, 'business' => $businessId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Insert a new expense and return the new id.
     *
     * Expected keys: business_id, payee_id, expense_category_id, expense_date,
     *                currency, exchange_rate, amount_fc, amount_bc, notes
     */
    public function create(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO expenses
              (business_id, payee_id, expense_category_id, expense_date,
               currency, exchange_rate, amount_fc, amount_bc, notes,
               reference_number, reference_date, attachment_path,
               created_at, updated_at)
            VALUES
              (:business_id, :payee_id, :expense_category_id, :expense_date,
               :currency, :exchange_rate, :amount_fc, :amount_bc, :notes,
               :reference_number, :reference_date, :attachment_path,
               NOW(), NOW())
        ");
        $stmt->execute([
            'business_id'          => $data['business_id'],
            'payee_id'             => $data['payee_id'] ?? null,
            'expense_category_id'  => $data['expense_category_id'] ?? null,
            'expense_date'         => $data['expense_date'],
            'currency'             => $data['currency'],
            'exchange_rate'        => $data['exchange_rate'],
            'amount_fc'            => $data['amount_fc'],
            'amount_bc'            => $data['amount_bc'],
            'notes'                => $data['notes'] ?? null,
            'reference_number'     => $data['reference_number'] ?? null,
            'reference_date'       => $data['reference_date'] ?? null,
            'attachment_path'      => $data['attachment_path'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Update an existing expense.
     */
    public function update(int $id, int $businessId, array $data): void {
        $stmt = $this->db->prepare("
            UPDATE expenses
            SET payee_id            = :payee_id,
                expense_category_id = :expense_category_id,
                expense_date        = :expense_date,
                currency            = :currency,
                exchange_rate       = :exchange_rate,
                amount_fc           = :amount_fc,
                amount_bc           = :amount_bc,
                notes               = :notes,
                reference_number    = :reference_number,
                reference_date      = :reference_date,
                attachment_path     = :attachment_path,
                updated_at          = NOW()
            WHERE id          = :id
              AND business_id = :business
        ");
        $stmt->execute([
            'payee_id'             => $data['payee_id'] ?? null,
            'expense_category_id'  => $data['expense_category_id'] ?? null,
            'expense_date'         => $data['expense_date'],
            'currency'             => $data['currency'],
            'exchange_rate'        => $data['exchange_rate'],
            'amount_fc'            => $data['amount_fc'],
            'amount_bc'            => $data['amount_bc'],
            'notes'                => $data['notes'] ?? null,
            'reference_number'     => $data['reference_number'] ?? null,
            'reference_date'       => $data['reference_date'] ?? null,
            'attachment_path'      => $data['attachment_path'] ?? null,
            'id'                   => $id,
            'business'             => $businessId,
        ]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Expense not found or not allowed');
        }
    }

    /**
     * Delete an expense scoped to business.
     */
    public function delete(int $id, int $businessId): void {
        $stmt = $this->db->prepare("
            DELETE FROM expenses
            WHERE id          = :id
              AND business_id = :business
        ");
        $stmt->execute(['id' => $id, 'business' => $businessId]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Expense not found or not allowed');
        }
    }

    /**
     * Sum the amount_bc of expenses for a business within a date period.
     * Returns 0 when no records match.
     */
    public function sumAmountForPeriod(int $businessId, string $start, string $end): float {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount_bc), 0)
            FROM expenses
            WHERE business_id = :business
              AND expense_date >= :start
              AND expense_date <= :end
        ");
        $stmt->execute([
            'business' => $businessId,
            'start'    => $start,
            'end'      => $end,
        ]);
        return (float) $stmt->fetchColumn();
    }
}
