<?php
namespace App\Services;

use App\Repository\ItemRepository;
use App\Repository\PeopleRepository;
use PDO;

class ItemVisibilityService {
    private PDO $db;
    private ItemRepository $itemRepo;
    private PeopleRepository $peopleRepo;

    public function __construct(PDO $db) {
        $this->db         = $db;
        $this->itemRepo   = new ItemRepository($db);
        $this->peopleRepo = new PeopleRepository($db);
    }

    /**
     * Get items visible to a specific customer:
     * all global items (customer_id IS NULL) + items specific to this customer.
     *
     * @param int $customerId  The people.id of the customer
     * @param int $tenantId
     * @param int $businessId
     * @return array  List of items with type_name and stock_qty
     */
    public function getItemsForCustomer(int $customerId, int $tenantId, int $businessId): array
    {
        return $this->itemRepo->getItemsForCustomer($customerId, $tenantId, $businessId);
    }

    /**
     * Get only global items (customer_id IS NULL).
     * Used when no customer is selected on the invoice form.
     *
     * @param int $tenantId
     * @param int $businessId
     * @return array
     */
    public function getGlobalItems(int $tenantId, int $businessId): array
    {
        return $this->itemRepo->getGlobalItems($tenantId, $businessId);
    }

    /**
     * Get all items specific to a given customer.
     * Used in the customer insights view.
     *
     * @param int $customerId
     * @param int $tenantId
     * @param int $businessId
     * @return array  List of items (name, sku, type_name, sale_price)
     */
    public function getCustomerItems(int $customerId, int $tenantId, int $businessId): array
    {
        return $this->itemRepo->getCustomerSpecificItems($customerId, $tenantId, $businessId);
    }

    /**
     * Validate that a customer_id is valid for item assignment:
     * - Exists in people table
     * - Belongs to the same tenant
     * - Has the "Customer" payee type in the current business
     *
     * @param int $customerId
     * @param int $tenantId
     * @param int $businessId
     * @return array{valid: bool, error: ?string}
     */
    public function validateCustomerForItem(int $customerId, int $tenantId, int $businessId): array
    {
        // Step 1: Check person exists and belongs to the same tenant
        $person = $this->peopleRepo->findById($customerId, $tenantId);
        if (!$person) {
            return [
                'valid' => false,
                'error' => 'Invalid customer: the specified customer does not exist',
            ];
        }

        // Step 2: Check person has a payee record in this business
        $payee = $this->peopleRepo->findPayee($customerId, $businessId);
        if (!$payee) {
            return [
                'valid' => false,
                'error' => 'Invalid customer: the specified payee is not a customer',
            ];
        }

        // Step 3: Check payee has the "Customer" payee type
        $hasCustomerType = $this->hasCustomerPayeeType((int) $payee['id']);
        if (!$hasCustomerType) {
            return [
                'valid' => false,
                'error' => 'Invalid customer: the specified payee is not a customer',
            ];
        }

        return ['valid' => true];
    }

    /**
     * Check if a payee has the "Customer" payee type assigned.
     *
     * @param int $payeeId
     * @return bool
     */
    private function hasCustomerPayeeType(int $payeeId): bool
    {
        $stmt = $this->db->prepare("
            SELECT 1
            FROM people_types ptp
            JOIN payees py ON py.people_id = ptp.people_id
            JOIN payee_types pt ON pt.id = ptp.payee_type_id
            WHERE py.id = :payee_id
              AND (pt.code = 'CUSTOMER' OR pt.name = 'Customer')
            LIMIT 1
        ");
        $stmt->execute(['payee_id' => $payeeId]);
        return (bool) $stmt->fetchColumn();
    }
}
