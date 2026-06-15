<?php

use App\Controllers\AccountsController;

/* ========= ACCOUNTS ========= */

$router->get('/accounts',
    fn() => (new AccountsController())->index(),
    ['accounts.manage']
);

$router->get('/accounts/{id}',
    fn($id) => (new AccountsController())->viewById((int)$id),
    ['accounts.manage']
);

$router->post('/accounts',
    fn() => (new AccountsController())->store(),
    ['accounts.manage']
);

$router->put('/accounts/{id}',
    fn($id) => (new AccountsController())->update((int)$id),
    ['accounts.manage']
);

$router->delete('/accounts/{id}',
    fn($id) => (new AccountsController())->delete((int)$id),
    ['accounts.manage']
);
