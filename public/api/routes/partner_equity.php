<?php

use App\Config\Database;
use App\Services\PartnerEquityService;

/* ========= PARTNER EQUITY ========= */

// GET /partner-equity
$router->get('/partner-equity',
    function () {
        $service = new PartnerEquityService(Database::getConnection());
        $partnerId = isset($_GET['partner_id']) ? (int)$_GET['partner_id'] : null;
        $entries = $service->list($partnerId);
        echo json_encode(['success' => true, 'data' => ['entries' => $entries]]);
    },
    ['partners.manage']
);

// POST /partner-equity
$router->post('/partner-equity',
    function () {
        $input = json_decode(file_get_contents('php://input'), true);
        $service = new PartnerEquityService(Database::getConnection());
        try {
            $id = $service->create($input);
            http_response_code(201);
            echo json_encode(['success' => true, 'data' => ['id' => $id]]);
        } catch (\InvalidArgumentException $e) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    },
    ['partners.manage']
);
