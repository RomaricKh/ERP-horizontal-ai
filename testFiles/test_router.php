<?php
if (!function_exists('getallheaders')) {
    function getallheaders() {
        return ['Authorization' => 'Bearer fake'];
    }
}
$_SERVER['REQUEST_URI'] = '/api/items/9/insights';
$_SERVER['REQUEST_METHOD'] = 'GET';

require __DIR__ . '/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

\App\Config\Database::connect();
// We'll replace AuthContext with our mock
\App\Support\AuthContext::set([
    'user_id' => 1,
    'business_id' => 3,
    'tenant_id' => 1,
    'permissions' => ['items.view']
]);

// Run the router
$router = new \App\Support\Router('fake_secret');
require __DIR__ . '/public/api/routes/items.php';
$router->dispatch();
