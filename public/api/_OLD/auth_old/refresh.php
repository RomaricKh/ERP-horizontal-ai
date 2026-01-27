<?php
// api/Auth/refresh.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../../bootstrap.php';

use App\Services\AuthService;

$auth = new AuthService($config['jwt_secret']);
try{
	if (!isset($_COOKIE['refresh_token'])) {
		http_response_code(401);
		echo json_encode(['success' => false, 'message' => 'No refresh token']);
		exit;
	}

	$refreshToken = $_COOKIE['refresh_token'];

	$result = $auth->refreshAccessToken($refreshToken);

	if (!$result['success']){
		throw new Exception('refreshAccessToken crashed', 0);
	}
    // result MUST contain:
    // - access_token
    // - refresh_token (rotated)
    // - expires_in

    setcookie(
        'refresh_token',
        $result['refresh_token'],
        [
            'expires'  => $result['refresh_expires'],
            'path'     => '/',
			'secure' => false,		//Dev ONLY
            //'secure' => true,        // HTTPS only
            'httponly' => true,
            'samesite' => 'Strict',
        ]
    );

    echo json_encode([
        'success'      => true,
        'accessToken' => $result['access_token'],
        'expiresIn'   => $result['expires_in'],
    ]);

} catch (Throwable $e) {
    http_response_code(401);

    // Kill refresh token on failure
   // setcookie('refresh_token', '', time() - 3600, '/');

    echo json_encode([
        'success' => false,
        'message' => 'Session expired'.$e->getMessage(),
    ]);
}