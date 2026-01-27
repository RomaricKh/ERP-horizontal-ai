<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Credentials: true'); // allow cookie

require_once __DIR__ . '/../../../bootstrap.php';

use App\Services\AuthService;

try {
    $authService = new AuthService($config['jwt_secret']);

    if (!empty($_COOKIE['refresh_token'])) {
        $authService->logout($_COOKIE['refresh_token']);
    }

    // Kill cookie regardless
    setcookie(
        'refresh_token',
        '',
        [
            'expires'  => time() - 3600,
            'path'     => '/',
			'secure' => false,		//Dev ONLY
            //'secure' => true,        // HTTPS only
            'httponly' => true,
            'samesite' => 'Strict',
        ]
    );

    echo json_encode([
        'success' => true,
    ]);

} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
