<?php

use App\Controllers\RolesController;

/* =====================================================================
 * STATIC ROUTES — must be registered BEFORE /{id} dynamic routes
 * ===================================================================== */

// GET /roles/permissions
$router->get('/roles/permissions',
    fn() => (new RolesController())->permissions(),
    ['roles.view']
);

// POST /roles/clone/{id}
$router->post('/roles/clone/{id}',
    fn($id) => (new RolesController())->clone((int)$id),
    ['roles.create']
);

/* =====================================================================
 * ROLES
 * ===================================================================== */

// GET /roles
$router->get('/roles',
    fn() => (new RolesController())->index(),
    ['roles.view']
);

// POST /roles
$router->post('/roles',
    fn() => (new RolesController())->store(),
    ['roles.create']
);

// GET /roles/{id}
$router->get('/roles/{id}',
    fn($id) => (new RolesController())->show((int)$id),
    ['roles.view']
);

// PUT /roles/{id}
$router->put('/roles/{id}',
    fn($id) => (new RolesController())->update((int)$id),
    ['roles.edit']
);

// DELETE /roles/{id}
$router->delete('/roles/{id}',
    fn($id) => (new RolesController())->delete((int)$id),
    ['roles.delete']
);
