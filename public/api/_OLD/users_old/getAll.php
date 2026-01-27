<?php
// api/Users/getAll.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // adjust to your frontend domain in production
require_once __DIR__ . '/../../../bootstrap.php';

use App\Services\UserService;
use App\Middleware\AuthMiddleware;
use App\Support\AuthContext;

$usersS = new UserService();
$middleware = new AuthMiddleware($config['jwt_secret']);


try{
	$middleware->handle('users.view'); // throws 401 if not authorized and 403 if forbidden.

	$auth = AuthContext::get();	// current logged in user
	
	$users = $usersS->getAllForCurrentBusiness();

    echo json_encode([
        'success' => true,
        'users' => $users
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch users: '.$e->getMessage()
    ]);
}