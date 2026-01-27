<?php
require_once __DIR__ . '../../../bootstrap.php';

use App\Support\Router;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$router = new Router($config['jwt_secret']);

require __DIR__ . '/routes/auth.php';
require __DIR__ . '/routes/users.php';
require __DIR__ . '/routes/businesses.php';
require __DIR__ . '/routes/items.php';

$router->dispatch();
