<?php

use App\Controllers\ManufacturingController;

/* =====================================================================
 * STATIC / NESTED ROUTES — must be registered BEFORE /{id} dynamic routes
 * ===================================================================== */

// POST /manufacturing/orders/{id}/reverse
$router->post('/manufacturing/orders/{id}/reverse',
    fn($id) => (new ManufacturingController())->reverseOrder((int)$id),
    ['manufacturing.create']
);

// POST /manufacturing/items/{id}/dismiss-cost-flag
$router->post('/manufacturing/items/{id}/dismiss-cost-flag',
    fn($id) => (new ManufacturingController())->dismissCostFlag((int)$id),
    ['manufacturing.edit']
);

// POST /manufacturing/items/{id}/auto-markup
$router->post('/manufacturing/items/{id}/auto-markup',
    fn($id) => (new ManufacturingController())->setAutoMarkup((int)$id),
    ['manufacturing.edit']
);

/* =====================================================================
 * RECIPES
 * ===================================================================== */

// GET /manufacturing/recipes
$router->get('/manufacturing/recipes',
    fn() => (new ManufacturingController())->indexRecipes(),
    ['manufacturing.view']
);

// GET /manufacturing/recipes/{id}
$router->get('/manufacturing/recipes/{id}',
    fn($id) => (new ManufacturingController())->viewRecipe((int)$id),
    ['manufacturing.view']
);

// POST /manufacturing/recipes
$router->post('/manufacturing/recipes',
    fn() => (new ManufacturingController())->storeRecipe(),
    ['manufacturing.create']
);

// PUT /manufacturing/recipes/{id}
$router->put('/manufacturing/recipes/{id}',
    fn($id) => (new ManufacturingController())->updateRecipe((int)$id),
    ['manufacturing.edit']
);

// DELETE /manufacturing/recipes/{id}
$router->delete('/manufacturing/recipes/{id}',
    fn($id) => (new ManufacturingController())->deleteRecipe((int)$id),
    ['manufacturing.delete']
);

/* =====================================================================
 * PRODUCTION ORDERS
 * ===================================================================== */

// GET /manufacturing/orders
$router->get('/manufacturing/orders',
    fn() => (new ManufacturingController())->indexOrders(),
    ['manufacturing.view']
);

// GET /manufacturing/orders/{id}
$router->get('/manufacturing/orders/{id}',
    fn($id) => (new ManufacturingController())->viewOrder((int)$id),
    ['manufacturing.view']
);

// POST /manufacturing/orders
$router->post('/manufacturing/orders',
    fn() => (new ManufacturingController())->storeOrder(),
    ['manufacturing.create']
);
