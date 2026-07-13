<?php

use App\Controllers\AccountTransfersController;

/* ========= ACCOUNT TRANSFERS ========= */

$router->get('/account-transfers',
    fn() => (new AccountTransfersController())->index(),
    ['accounts.view']
);

$router->post('/account-transfers',
    fn() => (new AccountTransfersController())->store(),
    ['accounts.transfer']
);
