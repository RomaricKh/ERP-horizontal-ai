<?php
namespace App\Controllers;

use App\Config\Database;
use App\Services\DashboardService;

class DashboardController extends BaseController {

    private DashboardService $service;

    public function __construct() {
        $this->service = new DashboardService(Database::getConnection());
    }

    // GET /dashboard/summary
    public function summary(): void {
        try {
            $this->auth();
            $summary = $this->service->getSummary();
            $this->json(true, $summary);
        } catch (\Throwable $e) {
            $this->serverError('Failed to load dashboard summary');
        }
    }
}
