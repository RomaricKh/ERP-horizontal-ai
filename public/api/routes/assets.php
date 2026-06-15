<?php

use App\Controllers\AssetsController;

/* =====================================================================
 * STATIC / NESTED ROUTES — must be registered BEFORE /{id} dynamic routes
 * ===================================================================== */

// GET /assets/categories (module-gated)
$router->get('/assets/categories',
    fn() => (new AssetsController())->indexCategories(),
    ['assets.manage']
);

// POST /assets/{id}/depreciate
$router->post('/assets/{id}/depreciate',
    fn($id) => (new AssetsController())->depreciate((int)$id),
    ['assets.manage']
);

/* =====================================================================
 * ASSETS
 * ===================================================================== */

// GET /assets
$router->get('/assets',
    fn() => (new AssetsController())->index(),
    ['assets.manage']
);

// GET /assets/{id}
$router->get('/assets/{id}',
    fn($id) => (new AssetsController())->show((int)$id),
    ['assets.manage']
);

// POST /assets
$router->post('/assets',
    fn() => (new AssetsController())->store(),
    ['assets.manage']
);

// PUT /assets/{id}
$router->put('/assets/{id}',
    fn($id) => (new AssetsController())->update((int)$id),
    ['assets.manage']
);

// DELETE /assets/{id}
$router->delete('/assets/{id}',
    fn($id) => (new AssetsController())->destroy((int)$id),
    ['assets.manage']
);
