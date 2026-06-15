<?php

use App\Controllers\StatementController;

// GET /reports/profit-loss
$router->get('/reports/profit-loss',
    fn() => (new StatementController())->profitLoss(),
    ['reports.view']
);

// GET /reports/cash-flow
$router->get('/reports/cash-flow',
    fn() => (new StatementController())->cashFlow(),
    ['reports.view']
);

// GET /reports/balance-sheet
$router->get('/reports/balance-sheet',
    fn() => (new StatementController())->balanceSheet(),
    ['reports.view']
);
