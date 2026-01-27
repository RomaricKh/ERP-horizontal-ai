<?php
// api/Auth/me.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // adjust in prod
header('Access-Control-Allow-Credentials: true'); // allow cookie
header('Access-Control-Allow-Methods: GET, POST');

require_once __DIR__ . '/../../../bootstrap.php';

use App\Services\AuthService;

$auth = new AuthService($config['jwt_secret']);

// Access token from Authorization header
$headers = getallheaders();
$token = null;

if (isset($headers['Authorization'])) {
    if (preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
        $token = $matches[1];
    }
}

$user = null;

// Try verify token
if ($token) $user = $auth->verifyToken($token);

if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// return user info
echo json_encode(['success' => true, 'user' => $user]);
