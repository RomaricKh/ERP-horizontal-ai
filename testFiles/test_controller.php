<?php
require __DIR__ . '/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

\App\Config\Database::connect();
// Mock AuthContext
\App\Support\AuthContext::set([
    'user_id' => 1,
    'business_id' => 3,
    'tenant_id' => 1,
    'permissions' => []
]);

$controller = new \App\Controllers\ItemsController();
$controller->getInsights(9);
