<?php
namespace App\Services;

use App\Repository\BusinessRepository;
use App\Support\AuthContext;

class BusinessService {
    private BusinessRepository $businessRepo;

    public function __construct() {
        $this->businessRepo = new BusinessRepository();
    }
	
	public function getAllForCurrentUser(): array {
        $auth = AuthContext::get();

        if (!$auth || !$auth->user_id) {
            throw new \RuntimeException('Missing user context');
        }

        return $this->businessRepo->getAllForCurrentUser($auth->user_id);
    }
	
	public function getById($business_id) {
		return $this->businessRepo->findById($business_id);
	}
	
	public function getTenantIdForBusiness($business_id) {
		return $this->businessRepo->getTenantIdForBusiness($business_id);
	}
	
}