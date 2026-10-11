<?php
namespace App\Repository;

use PDO;

class PeopleRepository {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    // region Person CRUD

    /**
     * List all people for a tenant who have a payee record in the current
     * business AND at least one payee type assigned via people_types junction.
     * Returns payee_types as comma-separated names and payee_type_ids as JSON array.
     */
    public function findAllByBusiness(int $tenantId, int $businessId): array {
        $stmt = $this->db->prepare("
            SELECT
                p.id,
                p.name,
                p.phone,
                p.email,
                p.address,
                p.tax_number,
                p.iban,
                p.created_at,
                py.id          AS payee_id,
                py.balance,
                py.notes       AS payee_notes,
                GROUP_CONCAT(pt.name ORDER BY pt.name SEPARATOR ', ') AS payee_types,
                CONCAT('[', GROUP_CONCAT(ptp.payee_type_id ORDER BY ptp.payee_type_id), ']') AS payee_type_ids,
                (SELECT COUNT(*) FROM items i WHERE i.customer_id = p.id AND i.business_id = :business_id2) AS has_customer_items
            FROM people p
            INNER JOIN payees py
                ON py.people_id = p.id AND py.business_id = :business_id
            LEFT JOIN people_types ptp
                ON ptp.people_id = p.id AND ptp.business_id = :business_id3
            LEFT JOIN payee_types pt
                ON pt.id = ptp.payee_type_id
                AND (
                    pt.module_id IS NULL
                    OR pt.module_id IN (
                        SELECT btm.module_id
                        FROM business_type_modules btm
                        JOIN businesses b ON b.business_type_id = btm.business_type_id
                        WHERE b.id = :business_id4
                    )
                )
            WHERE p.tenant_id = :tenant_id
            GROUP BY p.id, p.name, p.phone, p.email, p.address, p.tax_number,
                     p.iban, p.created_at, py.id, py.balance, py.notes
            HAVING COUNT(ptp.payee_type_id) > 0
            ORDER BY p.name
        ");
        $stmt->execute([
            'tenant_id'    => $tenantId,
            'business_id'  => $businessId,
            'business_id2' => $businessId,
            'business_id3' => $businessId,
            'business_id4' => $businessId,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(int $id, int $tenantId): ?array {
        $stmt = $this->db->prepare("
            SELECT id, name, phone, email, address, tax_number, iban, created_at
            FROM people
            WHERE id = :id AND tenant_id = :tenant_id
            LIMIT 1
        ");
        $stmt->execute(['id' => $id, 'tenant_id' => $tenantId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function create(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO people (tenant_id, name, phone, normalized_phone, email, address, tax_number, iban, created_at, created_by)
            VALUES (:tenant_id, :name, :phone, :normalized_phone, :email, :address, :tax_number, :iban, NOW(), :created_by)
        ");
        $stmt->execute([
            'tenant_id'        => $data['tenant_id'],
            'name'             => $data['name'],
            'phone'            => $data['phone']             ?? null,
            'normalized_phone' => $data['normalized_phone']  ?? null,
            'email'            => $data['email']             ?? null,
            'address'          => $data['address']           ?? null,
            'tax_number'       => $data['tax_number']        ?? null,
            'iban'             => $data['iban']              ?? null,
            'created_by'       => $data['created_by'],
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, int $tenantId, array $data): void {
        $allowed = ['name', 'phone', 'normalized_phone', 'email', 'address', 'tax_number', 'iban'];
        $fields  = [];
        $params  = ['id' => $id, 'tenant_id' => $tenantId];

        foreach ($data as $key => $value) {
            if (in_array($key, $allowed, true)) {
                $fields[]    = "$key = :$key";
                $params[$key] = $value;
            }
        }

        if (empty($fields)) return;

        $fields[] = "updated_at = NOW()";

        $sql = "UPDATE people SET " . implode(', ', $fields) . " WHERE id = :id AND tenant_id = :tenant_id";
        $this->db->prepare($sql)->execute($params);
    }

    public function delete(int $id, int $tenantId): void {
        $stmt = $this->db->prepare(
            "DELETE FROM people WHERE id = :id AND tenant_id = :tenant_id"
        );
        $stmt->execute(['id' => $id, 'tenant_id' => $tenantId]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Person not found or not allowed');
        }
    }

    /**
     * Find a person by normalized phone number within a tenant.
     * Used for phone uniqueness checks during create/update.
     *
     * @param string   $normalizedPhone Digits-only phone to search for
     * @param int      $tenantId        Tenant scope
     * @param int|null $excludeId       Person ID to exclude (for update scenarios)
     * @return array|null Person data (id, name, phone) if found, null otherwise
     */
    public function findByPhoneInTenant(string $normalizedPhone, int $tenantId, ?int $excludeId = null): ?array
    {
        $sql = "SELECT id, name, phone FROM people WHERE normalized_phone = :normalized_phone AND tenant_id = :tenant_id";
        $params = [
            'normalized_phone' => $normalizedPhone,
            'tenant_id'        => $tenantId,
        ];

        if ($excludeId !== null) {
            $sql .= " AND id != :exclude_id";
            $params['exclude_id'] = $excludeId;
        }

        $sql .= " LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Search all people in a tenant across all businesses */
    public function searchTenantPeople(int $tenantId, string $query, int $limit = 20): array {
        if (trim($query) === '') {
            return [];
        }
        $searchTerm = '%' . trim($query) . '%';
        $stmt = $this->db->prepare("
            SELECT id, name, phone, email, tax_number 
            FROM people 
            WHERE tenant_id = :tenant_id 
              AND (name LIKE :q1 OR phone LIKE :q2 OR email LIKE :q3)
            ORDER BY name ASC 
            LIMIT " . (int)$limit . "
        ");
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':q1', $searchTerm, PDO::PARAM_STR);
        $stmt->bindValue(':q2', $searchTerm, PDO::PARAM_STR);
        $stmt->bindValue(':q3', $searchTerm, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // endregion

    // region Payee Lifecycle

    /** Check if a payee record exists for this person in this business */
    public function findPayee(int $peopleId, int $businessId): ?array {
        $stmt = $this->db->prepare("
            SELECT id, balance, notes FROM payees
            WHERE people_id = :people_id AND business_id = :business_id
            LIMIT 1
        ");
        $stmt->execute(['people_id' => $peopleId, 'business_id' => $businessId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Create a payee record linking a person to a business */
    public function createPayee(int $peopleId, int $businessId, ?string $notes = null): int {
        $stmt = $this->db->prepare("
            INSERT INTO payees (business_id, people_id, balance, notes, created_at)
            VALUES (:business_id, :people_id, 0.00, :notes, NOW())
        ");
        $stmt->execute([
            'business_id' => $businessId,
            'people_id'   => $peopleId,
            'notes'       => $notes,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** Count payees linked to a person across all businesses */
    public function countPayeesForPerson(int $peopleId): int {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM payees WHERE people_id = ?"
        );
        $stmt->execute([$peopleId]);
        return (int) $stmt->fetchColumn();
    }

    /** Delete a payee record entirely */
    public function deletePayee(int $payeeId): void {
        $stmt = $this->db->prepare("SELECT people_id, business_id FROM payees WHERE id = ? LIMIT 1");
        $stmt->execute([$payeeId]);
        $payee = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($payee) {
            $this->db->prepare("
                DELETE FROM people_types 
                WHERE people_id = ? AND business_id = ?
            ")->execute([$payee['people_id'], $payee['business_id']]);
        }

        $this->db->prepare("DELETE FROM payees WHERE id = ?")->execute([$payeeId]);
    }

    /** Update notes for a specific payee record */
    public function updatePayeeNotes(int $payeeId, ?string $notes): void {
        $stmt = $this->db->prepare("UPDATE payees SET notes = :notes, updated_at = NOW() WHERE id = :id");
        $stmt->execute(['notes' => $notes, 'id' => $payeeId]);
    }

    /**
     * Verify that a payee belongs to the specified business.
     * Returns the payee record (id, people_id, balance, payee_name) or null if not found.
     *
     * @param int $payeeId   The payee ID to verify
     * @param int $businessId The business ID to check against
     * @return array|null Payee data if found, null otherwise
     */
    public function verifyPayeeBelongsToBusiness(int $payeeId, int $businessId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT p.id, p.people_id, p.balance, pe.name AS payee_name
             FROM payees p
             JOIN people pe ON pe.id = p.people_id
             WHERE p.id = :id AND p.business_id = :bid
             LIMIT 1"
        );
        $stmt->execute(['id' => $payeeId, 'bid' => $businessId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Ensure that a payee belongs to the specified business.
     * Throws InvalidArgumentException if the payee is not found for the given business.
     *
     * @param int $payeeId   The payee ID to verify
     * @param int $businessId The business ID to check against
     * @throws \InvalidArgumentException If payee not found in this business
     */
    public function ensurePayeeBelongsToBusiness(int $payeeId, int $businessId): void
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM payees WHERE id = ? AND business_id = ?"
        );
        $stmt->execute([$payeeId, $businessId]);
        if (!$stmt->fetch()) {
            throw new \InvalidArgumentException("Employee not found in this business");
        }
    }

    // endregion

    // region Junction Management

    /** Assign payee types to a person via people_types junction (replaces existing) */
    public function setPayeeTypes(int $payeeId, array $typeIds): void {
        // Get the people_id, business_id, and tenant_id from the payee
        $stmt = $this->db->prepare("
            SELECT py.people_id, py.business_id, p.tenant_id 
            FROM payees py
            JOIN people p ON p.id = py.people_id
            WHERE py.id = ? LIMIT 1
        ");
        $stmt->execute([$payeeId]);
        $payee = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$payee) return;
        
        $peopleId = $payee['people_id'];
        $businessId = $payee['business_id'];
        $tenantId = $payee['tenant_id'];

        // Remove existing from people_types for this business only
        $this->db->prepare("
            DELETE FROM people_types 
            WHERE people_id = ? AND business_id = ?
        ")->execute([$peopleId, $businessId]);

        if (empty($typeIds)) return;

        $insertStmt = $this->db->prepare(
            "INSERT IGNORE INTO people_types (tenant_id, business_id, people_id, payee_type_id, created_at) VALUES (?, ?, ?, ?, NOW())"
        );
        foreach ($typeIds as $typeId) {
            $insertStmt->execute([$tenantId, $businessId, $peopleId, (int)$typeId]);
        }
    }

    /** Get payee type IDs for a payee (via people_types junction) */
    public function getPayeeTypeIds(int $payeeId): array {
        $stmt = $this->db->prepare("
            SELECT pt.payee_type_id
            FROM people_types pt
            JOIN payees py ON py.people_id = pt.people_id AND py.business_id = pt.business_id
            WHERE py.id = ?
        ");
        $stmt->execute([$payeeId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // endregion

    // region Transaction Queries

    /**
     * Atomically adjust the payee balance by delta (positive or negative).
     * Positive delta = payee is owed more money (e.g., PO received, expense created).
     * Negative delta = payee was paid (e.g., payment slip created).
     */
    public function updatePayeeBalance(int $payeeId, float $delta): void {
        $stmt = $this->db->prepare("
            UPDATE payees
            SET balance    = balance + :delta,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute(['delta' => $delta, 'id' => $payeeId]);
    }

    /**
     * Count transactions for a payee across all transaction tables.
     * Returns an associative array of [table_label => count] for tables with records,
     * or an empty array if no transactions exist.
     *
     * Tables checked: expenses, sales_invoices, purchase_orders, payment_slips,
     * collection_slips, salaries, profit_distributions
     */
    public function countTransactionsForPayee(int $payeeId): array {
        $stmt = $this->db->prepare("
            SELECT 'expenses' AS source, COUNT(*) AS cnt FROM expenses WHERE payee_id = :id1
            UNION ALL
            SELECT 'sales_invoices', COUNT(*) FROM sales_invoices WHERE customer_id = :id2
            UNION ALL
            SELECT 'purchase_orders', COUNT(*) FROM purchase_orders WHERE supplier_id = :id3
            UNION ALL
            SELECT 'payment_slips', COUNT(*) FROM payment_slips WHERE payee_id = :id4
            UNION ALL
            SELECT 'collection_slips', COUNT(*) FROM collection_slips WHERE payee_id = :id5
            UNION ALL
            SELECT 'salaries', COUNT(*) FROM salaries WHERE payee_id = :id6
            UNION ALL
            SELECT 'profit_distributions', COUNT(*) FROM profit_distributions WHERE partner_id = :id7
        ");
        $stmt->execute([
            'id1' => $payeeId,
            'id2' => $payeeId,
            'id3' => $payeeId,
            'id4' => $payeeId,
            'id5' => $payeeId,
            'id6' => $payeeId,
            'id7' => $payeeId,
        ]);

        $results = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            if ((int) $row['cnt'] > 0) {
                $results[$row['source']] = (int) $row['cnt'];
            }
        }
        return $results;
    }

    // endregion
}
