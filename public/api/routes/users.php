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

$router->get('/users/lookup',
    fn() => (new UsersController())->lookup(),
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

$router->delete('/users/{id}',
    fn($id) => (new UsersController())->delete((int)$id),
    ['users.delete']
);

$router->patch('/users/me',
    fn() => (new UsersController())->updateMe(),
    [] // authenticated user only, no specific permission needed
);

$router->post('/users/me/password',
    fn() => (new UsersController())->changePassword(),
    [] // authenticated user only, no specific permission needed
);

$router->patch('/users/{id}/status',
    fn($id) => (new UsersController())->toggleStatus((int)$id),
    ['users.edit']
);

$router->patch('/users/{id}/active',
    fn($id) => (new UsersController())->toggleActive((int)$id),
    ['users.edit']
);