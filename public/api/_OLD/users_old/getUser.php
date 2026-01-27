<?php
// api/Users/getUser.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // adjust to your frontend domain in production
require_once __DIR__ . '/../../../bootstrap.php';

use App\Services\UserService;
use App\Middleware\AuthMiddleware;
use App\Support\AuthContext;

$usersS = new UserService();
$middleware = new AuthMiddleware($config['jwt_secret']);

// Read JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing user id']);
    exit;
}

try{
	$middleware->handle('users.view'); // throws 401 if not authorized and 403 if forbidden.

	$auth = AuthContext::get();	// current logged in user
	
	$user = $usersS->getById($input['user_id']);

    echo json_encode([
        'success' => true,
        'user' => $user
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch users: '.$e->getMessage()
    ]);
}