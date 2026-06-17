<?php

use App\Config\Database;
use App\Services\BusinessProfitService;

/* ========= BUSINESS PROFIT ========= */

// GET /business-profit
$router->get('/business-profit',
    function () {
        $service = new BusinessProfitService(Database::getConnection());
        $list = $service->list();
        echo json_encode(['success' => true, 'data' => ['profits' => $list]]);
    },
    ['partners.manage']
);

// GET /business-profit/{id}
$router->get('/business-profit/{id}',
    function ($id) {
        $service = new BusinessProfitService(Database::getConnection());
        try {
            $record = $service->getById((int)$id);
            echo json_encode(['success' => true, 'data' => ['profit' => $record]]);
        } catch (\RuntimeException $e) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    },
    ['partners.manage']
);

// POST /business-profit
$router->post('/business-profit',
    function () {
        $input = json_decode(file_get_contents('php://input'), true);
        $service = new BusinessProfitService(Database::getConnection());
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

// PUT /business-profit/{id}
$router->put('/business-profit/{id}',
    function ($id) {
        $input = json_decode(file_get_contents('php://input'), true);
        $service = new BusinessProfitService(Database::getConnection());
        try {
            $service->update((int)$id, $input);
            echo json_encode(['success' => true, 'data' => ['id' => (int)$id]]);
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    },
    ['partners.manage']
);

// DELETE /business-profit/{id}
$router->delete('/business-profit/{id}',
    function ($id) {
        $service = new BusinessProfitService(Database::getConnection());
        try {
            $service->delete((int)$id);
            echo json_encode(['success' => true, 'data' => ['id' => (int)$id]]);
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    },
    ['partners.manage']
);

// POST /business-profit/{id}/finalize
$router->post('/business-profit/{id}/finalize',
    function ($id) {
        $service = new BusinessProfitService(Database::getConnection());
        try {
            $service->finalize((int)$id);
            echo json_encode(['success' => true, 'data' => ['id' => (int)$id]]);
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    },
    ['partners.manage']
);

// POST /business-profit/{id}/recalculate
$router->post('/business-profit/{id}/recalculate',
    function ($id) {
        $service = new BusinessProfitService(Database::getConnection());
        try {
            $service->recalculate((int)$id);
            echo json_encode(['success' => true, 'data' => ['id' => (int)$id]]);
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    },
    ['partners.manage']
);

// POST /business-profit/{id}/distribute
$router->post('/business-profit/{id}/distribute',
    function ($id) {
        $input = json_decode(file_get_contents('php://input'), true);
        $service = new BusinessProfitService(Database::getConnection());
        try {
            $accountId = isset($input['account_id']) ? (int) $input['account_id'] : null;
            $distributions = $service->distribute((int)$id, $accountId);
            echo json_encode(['success' => true, 'data' => ['distributions' => $distributions]]);
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    },
    ['partners.manage']
);
