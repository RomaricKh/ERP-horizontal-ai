<?php
namespace App\Services;

use App\Repository\AccountRepository;
use App\Repository\BusinessRepository;
use App\Repository\BusinessTypeRepository;
use App\Repository\UserRepository;
use App\Support\AuthContext;
use PDO;


class BusinessService {
    private AccountRepository $accountRepo;
    private BusinessRepository $businessRepo;
    private BusinessTypeRepository $businessTypeRepo;
    private BusinessTypeService $businessTypeService;
    private RoleService $roleService;
    private UserRepository $userRepo;
    private AuditService $audit;
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->accountRepo = new AccountRepository($this->db);
        $this->businessRepo = new BusinessRepository($this->db);
        $this->businessTypeRepo = new BusinessTypeRepository($this->db);
        $this->businessTypeService = new BusinessTypeService($this->db);
        $this->roleService = new RoleService($this->db);
        $this->userRepo = new UserRepository($this->db);
        $this->audit = new AuditService($this->db);
    }
	
	public function getAllForCurrentUser(int $user_id): array {

        return $this->businessRepo->getAllForCurrentUser($user_id);
    }
	
	public function getById($business_id) {
		$business = $this->businessRepo->getBusinessByID($business_id);

        if (!$business) {
            throw new \RuntimeException('business not found');
        }

        return $business;
	}
	
	
    public function create(int $editing_user_id, array $data): int {
        $ctx = AuthContext::get();

        // Resolve business_type_id: default to "general" if not provided
        $typeId = $data['business_type_id'] ?? null;
        if ($typeId === null) {
            $typeId = $this->businessTypeRepo->getDefault()['id'];
        } else {
            $type = $this->businessTypeRepo->getById($typeId);
            if (!$type) {
                throw new \InvalidArgumentException('Invalid business type');
            }
        }

        $this->db->beginTransaction();

        try {

            $business_id = $this->businessRepo->create([
                ...$data,
                'tenant_id' => $ctx->tenant_id,
                'business_type_id' => $typeId
            ]);

            $currency = $data['currency'] ?? 'USD';
            $accountName = $data['name'] . ' ' . $currency;
            $this->accountRepo->create([
                'business_id' => $business_id,
                'name'        => $accountName,
                'currency'    => $currency,
            ]);

            // Auto-create SYSTEM_OWNER role and assign to the creating user
            $ownerRoleId = $this->roleService->createSystemOwnerRole($business_id, $ctx->tenant_id);
            $this->userRepo->assignRole($editing_user_id, $business_id, $ownerRoleId, 1);

            $this->audit->log(
                'BUSINESS',
                'businesses',
                $business_id,
                'CREATE',
                null,
                [
                    'name'            => $data['name'] ?? null,
                    'legal_name'      => $data['legal_name'] ?? null,
                    'registration_no' => $data['registration_no'] ?? null,
                    'currency'        => $data['currency'] ?? null
                ],
                $editing_user_id,
                null,
                $business_id
            );

            $this->db->commit();

            return $business_id;

        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function update(int $business_id, int $editing_user_id, array $data): ?array {
        $ctx = AuthContext::get();

        // Handle business type change if business_type_id is present
        if (isset($data['business_type_id'])) {
            $force = !empty($data['force']);
            $result = $this->businessTypeService->changeBusinessType(
                $business_id,
                (int) $data['business_type_id'],
                $force,
                $editing_user_id
            );

            if (!$result['success']) {
                // Return warning or error without committing other changes
                return $result;
            }
        }

        $this->db->beginTransaction();

        try {

            $before = $this->businessRepo->getBusinessByID($business_id);

            if (!$before) {
                throw new \RuntimeException('Business not found');
            }

            $changes = [];
            foreach ([
                'name',
                'legal_name',
                'registration_no',
                'address',
                'phone',
                'email',
                'currency'
            ] as $field) {

                if (isset($data[$field]) && $data[$field] !== $before[$field]) {
                    $changes[$field] = $data[$field];
                }
            }

            if (!empty($changes)) {

                $this->businessRepo->update(
                    $business_id,
                    $ctx->tenant_id,
                    $changes
                );

                $this->audit->log(
                    'BUSINESS',
                    'businesses',
                    $business_id,
                    'UPDATE',
                    array_intersect_key($before, $changes),
                    $changes,
                    $editing_user_id,
                    null,
                    $business_id
                );
            }

            $this->db->commit();

            return null;

        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function delete(int $business_id, int $editing_user_id): void {
        $ctx = AuthContext::get();

        $userCount = $this->businessRepo->countUsersForBusiness($business_id);
        if ($userCount > 0) {
            throw new \RuntimeException('Cannot delete a business that still has users assigned to it');
        }

        $this->db->beginTransaction();

        try {
            $before = $this->businessRepo->getBusinessByID($business_id);

            if (!$before) {
                throw new \RuntimeException('Business not found');
            }

            $this->businessRepo->delete($business_id, $ctx->tenant_id);

            $this->audit->log(
                'BUSINESS',
                'businesses',
                $business_id,
                'DELETE',
                $before,
                null,
                $editing_user_id,
                null,
                $business_id
            );

            $this->db->commit();

        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    public function getTenantIdForBusiness(int $business_id): ?int {
        return $this->businessRepo->getTenantIdForBusiness($business_id);
    }

}