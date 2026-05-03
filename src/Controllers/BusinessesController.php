<?php
namespace App\Controllers;

use App\Config\Database;
use App\Controllers\BaseController;
use App\Services\BusinessService;

class BusinessesController extends BaseController {

    private BusinessService $service;

    public function __construct() {
        $this->service = new BusinessService(Database::getConnection());
    }	

    /* =========================
       PROTECTED ENDPOINTS
       (AuthMiddleware required)
       ========================= */

    // GET /businesses
    public function index(): void {
		try{
			$ctx = $this->auth();	// current logged in user
			
			$businesses = $this->service->getAllForCurrentUser($ctx->user_id);

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
			$ctx = $this->auth();	// current logged in user
			
			$business = $this->service->getById($business_id);
			
			$this->json(true, [
				'business' => $business
			]);

		} catch (Throwable $e) {			
			$this->serverError('Failed to fetch business');
		}
    }
	
}
