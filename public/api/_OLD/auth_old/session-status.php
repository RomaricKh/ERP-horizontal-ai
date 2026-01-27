<?php
// api/Auth/session-status.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../../bootstrap.php';

use App\Services\AuthService;

// Access token from Authorization header
$headers = getallheaders();
$token = null;

$auth = new AuthService($config['jwt_secret']);

try{

	if (isset($headers['Authorization'])) {
		if (preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
			$token = $matches[1];
		}
	}

	$refreshToken = $_COOKIE['refresh_token'] ?? '';

	$result = $auth->getSessionStatus($token, $refreshToken);
	
	echo json_encode([
		'success' => true,
		'access_expires_in' => $result['access_expires_in'],
		'refresh_expires_in' => $result['refresh_expires_in'],
		'is_last_window' => $result['is_last_window']
	]);
	
} catch (Throwable $e) {

    echo json_encode([
        'success' => false,
        'message' => 'Session status unkonwn'.$e->getMessage(),
    ]);
}