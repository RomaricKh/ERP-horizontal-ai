<?php
// api/Auth/reauth.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // adjust to your frontend domain in production
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../../../bootstrap.php';

use App\Services\AuthService;
use App\Middleware\AuthMiddleware;
use App\Support\AuthContext;

// Read JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['password'])) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit;
}

// Initialize AuthService & AuthMiddleware
$auth = new AuthService($config['jwt_secret']);
$mid = new AuthMiddleware($config['jwt_secret']);

$mid->handle();

$user = AuthContext::get();

$user_id = trim($user->user_id);
$password = $input['password'];


$result = $auth->reauth($user_id, $password);

if ($result['success']) {
    // Successful login, return JWT + user info
    setcookie(
        'refresh_token',
        $result['refresh_token'],
        [
            'expires' => $result['refresh_expires'],
            'path' => '/',
			'secure' => false,		//Dev ONLY
            //'secure' => true,        // HTTPS only
            'httponly' => true,      // JS cannot read
            'samesite' => 'Strict'
        ]
    );

    echo json_encode([
        'success' => true,
        'token' => $result['access_token'],
        'user' => $result['user']
    ]);
} else {
    // Failed login
    echo json_encode([
        'success' => false,
        'message' => $result['message']
    ]);
}
