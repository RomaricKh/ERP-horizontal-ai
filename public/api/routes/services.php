<?php

use App\Config\Database;
use App\Services\ServiceService;
use App\Support\AuthContext;

/* ========= SERVICE CATEGORIES (must be before /services/{id}) ========= */

$router->get('/services/categories',
    function () {
        $service = new ServiceService(Database::getConnection());
        $categories = $service->listCategories();
        http_response_code(200);
        echo json_encode(['success' => true, 'data' => ['categories' => $categories]]);
    },
    ['services.view']
);

$router->post('/services/categories',
    function () {
        $input = json_decode(file_get_contents('php://input'), true);
        $service = new ServiceService(Database::getConnection());
        try {
            $id = $service->createCategory($input['name'] ?? '');
            http_response_code(201);
            echo json_encode(['success' => true, 'data' => ['id' => $id]]);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    },
    ['services.manage']
);

$router->put('/services/categories/{id}',
    function ($id) {
        $input = json_decode(file_get_contents('php://input'), true);
        $service = new ServiceService(Database::getConnection());
        try {
            $service->updateCategory((int)$id, $input['name'] ?? '');
            echo json_encode(['success' => true, 'data' => ['id' => (int)$id]]);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    },
    ['services.manage']
);

$router->delete('/services/categories/{id}',
    function ($id) {
        $service = new ServiceService(Database::getConnection());
        try {
            $service->deleteCategory((int)$id);
            echo json_encode(['success' => true, 'data' => ['id' => (int)$id]]);
        } catch (\RuntimeException $e) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    },
    ['services.manage']
);

/* ========= SERVICES ========= */

$router->get('/services',
    function () {
        $service = new ServiceService(Database::getConnection());
        $list = $service->list(true); // Include inactive for full management view
        echo json_encode(['success' => true, 'data' => ['services' => $list]]);
    },
    ['services.view']
);

$router->get('/services/{id}',
    function ($id) {
        $service = new ServiceService(Database::getConnection());
        try {
            $item = $service->getById((int)$id);
            echo json_encode(['success' => true, 'data' => ['service' => $item]]);
        } catch (\RuntimeException $e) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    },
    ['services.view']
);

$router->post('/services',
    function () {
        $input = json_decode(file_get_contents('php://input'), true);
        $service = new ServiceService(Database::getConnection());
        try {
            $id = $service->create($input);
            http_response_code(201);
            echo json_encode(['success' => true, 'data' => ['id' => $id]]);
        } catch (\InvalidArgumentException $e) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    },
    ['services.create']
);

$router->put('/services/{id}',
    function ($id) {
        $input = json_decode(file_get_contents('php://input'), true);
        $service = new ServiceService(Database::getConnection());
        try {
            $service->update((int)$id, $input);
            echo json_encode(['success' => true, 'data' => ['id' => (int)$id]]);
        } catch (\RuntimeException $e) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    },
    ['services.edit']
);

$router->delete('/services/{id}',
    function ($id) {
        $service = new ServiceService(Database::getConnection());
        try {
            $service->deactivate((int)$id);
            echo json_encode(['success' => true, 'data' => ['id' => (int)$id]]);
        } catch (\RuntimeException $e) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    },
    ['services.delete']
);
