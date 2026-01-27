<?php
namespace App\Controllers;

use App\Controllers\BaseController;
use App\Services\BusinessService;
use App\Support\AuthContext;

class BusinessesController extends BaseController {

    private BusinessService $service;

    public function __construct() {
        $this->service = new BusinessService();
    }	

    /* =========================
       PROTECTED ENDPOINTS
       (AuthMiddleware required)
       ========================= */

    // GET /businesses
    public function index(): void {
		try{
			$auth = AuthContext::get();	// current logged in user
			
			$businesses = $this->service->getAllForCurrentUser();

			$this->json(true, [
				'businesses' => $businesses
			]);

		} catch (Throwable $e) {			
			$this->serverError('Failed to fetch businesses');
		}
    }    
	
	// GET /businesses/{id}
    public function viewById(int $business_id): void {
		try{
			$auth = AuthContext::get();	// current logged in user
			
			$business = $this->service->getById($business_id);
			
			$this->json(true, [
				'business' => $business
			]);

		} catch (Throwable $e) {			
			$this->serverError('Failed to fetch business');
		}
    }
	
}
