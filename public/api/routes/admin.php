<?php

use App\Controllers\AdminController;

/* =====================================================================
 * ADMIN ROUTES
 * ===================================================================== */

// POST /admin/recalculate-balances
$router->post('/admin/recalculate-balances',
    fn() => (new AdminController())->recalculateBalances(),
    ['admin']
);

// POST /admin/recalculate-balances/{accountId}
$router->post('/admin/recalculate-balances/{accountId}',
    fn($accountId) => (new AdminController())->recalculateAccountBalance((int)$accountId),
    ['admin']
);
