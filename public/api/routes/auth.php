<?php

use App\Controllers\AuthController;

/* ========= AUTH ========= */

$router->post('/auth/login',
    fn() => (new AuthController($config['jwt_secret']))->login(), [], false
);

$router->post('/auth/refresh',
    fn() => (new AuthController($config['jwt_secret']))->refresh(), [], false
);

$router->get('/auth/me',
    fn() => (new AuthController($config['jwt_secret']))->me()
);

$router->post('/auth/logout',
    fn() => (new AuthController($config['jwt_secret']))->logout()
);

$router->post('/auth/reauth',
    fn() => (new AuthController($config['jwt_secret']))->reauth()
);

$router->get('/auth/session-status',
    fn() => (new AuthController($config['jwt_secret']))->sessionStatus()
);

$router->post('/auth/forgot-password',
    fn() => (new AuthController($config['jwt_secret']))->forgotPassword(), [], false
);

$router->post('/auth/reset-password',
    fn() => (new AuthController($config['jwt_secret']))->resetPassword(), [], false
);
