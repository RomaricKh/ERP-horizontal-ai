<?php
namespace App\Controllers;

use App\Config\Database;
use App\Services\ActivityLogService;

class ActivityLogController extends BaseController {

    private ActivityLogService $service;

    public function __construct() {
        $this->service = new ActivityLogService(Database::getConnection());
    }

    // GET /activity-log?page=1&event_type=&from=&to=
    public function index(): void {
        try {
            $this->auth();

            $filters = [
                'page'       => $_GET['page']       ?? 1,
                'event_type' => $_GET['event_type'] ?? '',
                'from_date'  => $_GET['from']       ?? '',
                'to_date'    => $_GET['to']         ?? '',
            ];

            $result = $this->service->getLog($filters);
            $this->json(true, $result);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch activity log');
        }
    }
}
