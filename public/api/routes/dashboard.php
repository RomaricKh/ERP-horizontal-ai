<?php

use App\Controllers\DashboardController;

// GET /dashboard/summary
$router->get('/dashboard/summary',
    fn() => (new DashboardController())->summary(),
    ['dashboard.view']
);
