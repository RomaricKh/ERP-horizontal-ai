<?php
namespace App\Services;

use App\Repository\PeopleRepository;
use App\Repository\PayeeTypeRepository;
use App\Support\AuthContext;
use PDO;

class PeopleService {
    private PeopleRepository    $peopleRepo;
    private PayeeTypeRepository $typeRepo;
    private AuditService        $audit;
    private PDO                 $db;

    public function __construct(PDO $db) {
        $this->db         = $db;
        $this->peopleRepo = new PeopleRepository($db);
        $this->typeRepo   = new PayeeTypeRepository($db);
        $this->audit      = new AuditService($db);
    }

    // ── LIST ──────────────────────────────────────────────────────────────────

    public function listForBusiness(int $tenantId, int $businessId): array {
        return $this->peopleRepo->findAllByBusiness($tenantId, $businessId);
    }

    public function getById(int $id, int $tenantId): array {
        $person = $this->peopleRepo->findById($id, $tenantId);
        if (!$person) throw new \RuntimeException('Person not found');
        return $person;
    }

    // ── SEARCH ────────────────────────────────────────────────────────────────

    public function searchTenant(int $tenantId, string $query): array {
        return $this->peopleRepo->searchTenantPeople($tenantId, $query);
    }

    // ── CREATE ────────────────────────────────────────────────────────────────

    public function create(int $tenantId, int $businessId, int $editorId, array $data): int {
        $this->db->beginTransaction();
        try {
            $this->validatePersonInput($data, true);

            if (empty($data['payee_type_ids']) || !is_array($data['payee_type_ids'])) {
                throw new \InvalidArgumentException('At least one payee type must be selected.');
            }

            $personId = $this->peopleRepo->create([
                'tenant_id'  => $tenantId,
                'name'       => trim($data['name']),
                'phone'      => $data['phone']      ?? null,
                'email'      => $data['email']      ?? null,
                'address'    => $data['address']    ?? null,
                'tax_number' => $data['tax_number'] ?? null,
                'iban'       => $data['iban']       ?? null,
                'created_by' => $editorId,
            ]);

            // Always create a payee record for this business
            $payeeId = $this->peopleRepo->createPayee($personId, $businessId, $data['payee_notes'] ?? null);

            $this->validatePayeeTypeOwnership($data['payee_type_ids'], $tenantId);
            $this->peopleRepo->setPayeeTypes($payeeId, $data['payee_type_ids']);

            $this->audit->log('PEOPLE', 'people', $personId, 'CREATE', null,
                ['name' => $data['name'], 'business_id' => $businessId],
                $editorId, $tenantId, $businessId);

            $this->db->commit();
            return $personId;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // ── UPDATE ────────────────────────────────────────────────────────────────

    public function update(int $id, int $tenantId, int $businessId, int $editorId, array $data): void {
        $this->db->beginTransaction();
        try {
            $this->validatePersonInput($data, false);

            if (empty($data['payee_type_ids']) || !is_array($data['payee_type_ids'])) {
                throw new \InvalidArgumentException('At least one payee type must be selected.');
            }

            $before = $this->peopleRepo->findById($id, $tenantId);
            if (!$before) throw new \RuntimeException('Person not found');

            $changes = [];
            foreach (['name','phone','email','address','tax_number','iban'] as $f) {
                if (array_key_exists($f, $data) && $data[$f] !== $before[$f]) {
                    $changes[$f] = $data[$f];
                }
            }

            if (!empty($changes)) {
                $this->peopleRepo->update($id, $tenantId, $changes);
                $this->audit->log('PEOPLE', 'people', $id, 'UPDATE',
                    array_intersect_key($before, $changes), $changes,
                    $editorId, $tenantId, $businessId);
            }

            // Update payee types if provided
            if (array_key_exists('payee_type_ids', $data) || array_key_exists('payee_notes', $data)) {
                $payee = $this->peopleRepo->findPayee($id, $businessId);
                if (!$payee) {
                    // Create payee record if it doesn't exist yet
                    $payeeId = $this->peopleRepo->createPayee($id, $businessId, $data['payee_notes'] ?? null);
                } else {
                    $payeeId = $payee['id'];
                    if (array_key_exists('payee_notes', $data)) {
                        $this->peopleRepo->updatePayeeNotes($payeeId, $data['payee_notes']);
                    }
                }
                
                if (array_key_exists('payee_type_ids', $data)) {
                    $typeIds = $data['payee_type_ids'] ?? [];
                    if (!empty($typeIds)) {
                        $this->validatePayeeTypeOwnership($typeIds, $tenantId);
                    }
                    $this->peopleRepo->setPayeeTypes($payeeId, $typeIds);
                }
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // ── DELETE ────────────────────────────────────────────────────────────────

    public function delete(int $id, int $tenantId, int $businessId, int $editorId): void {
        $this->db->beginTransaction();
        try {
            $before = $this->peopleRepo->findById($id, $tenantId);
            if (!$before) throw new \RuntimeException('Person not found');

            // Check if payee has transactions (inside transaction to prevent TOCTOU)
            $payee = $this->peopleRepo->findPayee($id, $businessId);
            if ($payee) {
                $transactions = $this->peopleRepo->countTransactionsForPayee($payee['id']);
                if (!empty($transactions)) {
                    $blocking = implode(', ', array_keys($transactions));
                    throw new \RuntimeException(
                        "Cannot delete: this person has transactions in this business (linked to: {$blocking})"
                    );
                }
                
                // Safe to delete the payee record for this business
                $this->peopleRepo->deletePayee($payee['id']);
            }

            // If no more payee records exist for this person across ANY business, delete the person entirely
            $remainingPayees = $this->peopleRepo->countPayeesForPerson($id);
            if ($remainingPayees === 0) {
                $this->peopleRepo->delete($id, $tenantId);
            }

            $this->audit->log('PEOPLE', 'people', $id, 'DELETE', $before, null,
                $editorId, $tenantId, $businessId);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // ── PAYEE TYPE OWNERSHIP VERIFICATION ─────────────────────────────────────

    /**
     * Verify that each payee type ID exists and belongs to the current tenant
     * (either is_system=1 or tenant_id matches).
     *
     * @param array $typeIds  Array of payee_type IDs to validate
     * @param int   $tenantId The authenticated tenant ID
     * @throws \InvalidArgumentException if any ID is invalid or belongs to another tenant
     */
    private function validatePayeeTypeOwnership(array $typeIds, int $tenantId): void
    {
        if (empty($typeIds)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($typeIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT id FROM payee_types
             WHERE id IN ({$placeholders})
               AND (is_system = 1 OR tenant_id = ?)"
        );
        $params = array_merge(array_map('intval', $typeIds), [$tenantId]);
        $stmt->execute($params);
        $validIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $invalid = array_diff(array_map('intval', $typeIds), $validIds);
        if (!empty($invalid)) {
            throw new \InvalidArgumentException(
                'Invalid payee type IDs: ' . implode(', ', $invalid)
            );
        }
    }

    // ── PAYEE TYPES ───────────────────────────────────────────────────────────

    public function listPayeeTypes(int $tenantId, int $businessId): array {
        return $this->typeRepo->findAllForBusiness($tenantId, $businessId);
    }

    public function createPayeeType(int $tenantId, int $businessId, int $editorId, string $name): int {
        $id = $this->typeRepo->create($tenantId, $businessId, trim($name), $editorId);
        $this->audit->log('PEOPLE', 'payee_types', $id, 'CREATE', null,
            ['name' => $name], $editorId, $tenantId, $businessId);
        return $id;
    }

    public function updatePayeeType(int $id, int $tenantId, int $businessId, int $editorId, string $name): void {
        $before = $this->typeRepo->findById($id, $tenantId);
        if (!$before) throw new \RuntimeException('Payee type not found');
        $this->typeRepo->update($id, $tenantId, trim($name));
        $this->audit->log('PEOPLE', 'payee_types', $id, 'UPDATE',
            ['name' => $before['name']], ['name' => $name],
            $editorId, $tenantId, $businessId);
    }

    public function deletePayeeType(int $id, int $tenantId, int $businessId, int $editorId): void {
        $usage = $this->typeRepo->countUsage($id);
        if ($usage > 0) {
            throw new \RuntimeException('Cannot delete: payee type is in use');
        }
        $before = $this->typeRepo->findById($id, $tenantId);
        if (!$before) throw new \RuntimeException('Payee type not found');
        $this->typeRepo->delete($id, $tenantId);
        $this->audit->log('PEOPLE', 'payee_types', $id, 'DELETE', $before, null,
            $editorId, $tenantId, $businessId);
    }

    // ── PAYEE DETAIL ──────────────────────────────────────────────────────────

    public function getPayeeDetail(int $personId, int $businessId): array {
        $payee = $this->peopleRepo->findPayee($personId, $businessId);
        $typeIds = $payee ? $this->peopleRepo->getPayeeTypeIds($payee['id']) : [];
        return [
            'payee'        => $payee,
            'payee_type_ids' => array_map('intval', $typeIds),
        ];
    }

    // ── INPUT VALIDATION ──────────────────────────────────────────────────────

    private function validatePersonInput(array $data, bool $isCreate = true): void
    {
        // Name: required on create, non-empty after trim, max 255
        $name = trim($data['name'] ?? '');
        if ($name === '' && $isCreate) {
            throw new \InvalidArgumentException('Name is required.');
        }
        if ($name !== '' && mb_strlen($name) > 255) {
            throw new \InvalidArgumentException('Name must not exceed 255 characters.');
        }

        // Email: optional, but must be valid format if provided
        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid email format.');
        }

        // Phone: optional, allowed chars only, max 50
        if (!empty($data['phone'])) {
            if (!preg_match('/^[\d\s\-\+\(\)]+$/', $data['phone'])) {
                throw new \InvalidArgumentException('Phone contains invalid characters.');
            }
            if (mb_strlen($data['phone']) > 50) {
                throw new \InvalidArgumentException('Phone must not exceed 50 characters.');
            }
        }

        // Payee type IDs: each must be positive integer
        if (isset($data['payee_type_ids']) && is_array($data['payee_type_ids'])) {
            foreach ($data['payee_type_ids'] as $typeId) {
                if (!is_int($typeId) && !ctype_digit((string)$typeId) || (int)$typeId <= 0) {
                    throw new \InvalidArgumentException("Invalid payee type ID: {$typeId}");
                }
            }
        }
    }
}
