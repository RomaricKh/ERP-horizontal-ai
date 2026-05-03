<?php
namespace App\Services;

use App\Repository\BusinessRepository;
use App\Support\AuthContext;
use PDO;


class BusinessService {
    private BusinessRepository $businessRepo;
    private AuditService $audit;
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->businessRepo = new BusinessRepository($this->db);
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

        $this->db->beginTransaction();

        try {

            $business_id = $this->repo->create([
                ...$data,
                'tenant_id' => $ctx->tenant_id
            ]);

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

    public function update(int $business_id, int $editing_user_id, array $data): void {
        $ctx = AuthContext::get();

        $this->db->beginTransaction();

        try {

            $before = $this->repo->findById($business_id);

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

                $this->repo->update(
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

        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
	
	public function getTenantIdForBusiness($business_id) {
		return $this->businessRepo->getTenantIdForBusiness($business_id);
	}
	
}