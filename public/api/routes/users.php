<?php

use App\Controllers\UsersController;

/* ========= Users ========= */

$router->get('/users',
    fn() => (new UsersController())->index(),
    ['users.view']
);

$router->get('/users/{id}',
    fn($id) => (new UsersController())->viewById((int)$id),
    ['users.view']
);

$router->post('/users',
    fn() => (new UsersController())->store(),
    ['users.create']
);

$router->put('/users/{id}',
    fn($id) => (new UsersController())->update((int)$id),
    ['users.edit']
);

$router->patch('/users/{id}/status',
    fn($id) => (new UsersController())->toggleStatus((int)$id),
    ['users.edit']
);