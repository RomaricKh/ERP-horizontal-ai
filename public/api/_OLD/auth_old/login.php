<?php
// api/Auth/login.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // adjust to your frontend domain in production
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../../../bootstrap.php';

use App\Services\AuthService;

// Read JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['username'], $input['password'])) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit;
}

$username = trim($input['username']);
$password = $input['password'];
$remember = isset($input['remember']) ? (bool)$input['remember'] : false;

// Initialize AuthService
$auth = new AuthService($config['jwt_secret']);
$result = $auth->login($username, $password, $remember);

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
