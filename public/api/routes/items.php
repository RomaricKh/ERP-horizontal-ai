<?php

use App\Controllers\ItemsController;

/* ========= ITEM TYPES (must be before /items/{id}) ========= */

$router->get('/items/types',
    fn() => (new ItemsController())->indexTypes(),
    ['items.view']
);

$router->post('/items/types',
    fn() => (new ItemsController())->storeType(),
    ['item-types.manage']
);

$router->put('/items/types/{id}',
    fn($id) => (new ItemsController())->updateType((int)$id),
    ['item-types.manage']
);

$router->delete('/items/types/{id}',
    fn($id) => (new ItemsController())->deleteType((int)$id),
    ['item-types.manage']
);

/* ========= STOCK (must be before /items/{id}) ========= */

$router->get('/items/stock',
    fn() => (new ItemsController())->stock(),
    ['stock.view']
);

/* ========= ITEMS ========= */

$router->get('/items',
    fn() => (new ItemsController())->index(),
    ['items.view']
);

$router->get('/items/{id}',
    fn($id) => (new ItemsController())->viewById((int)$id),
    ['items.view']
);

$router->post('/items',
    fn() => (new ItemsController())->store(),
    ['items.create']
);

$router->put('/items/{id}',
    fn($id) => (new ItemsController())->update((int)$id),
    ['items.edit']
);

$router->delete('/items/{id}',
    fn($id) => (new ItemsController())->delete((int)$id),
    ['items.delete']
);

$router->post('/items/{id}/reactivate',
    fn($id) => (new ItemsController())->reactivate((int)$id),
    ['items.edit']
);

/* ========= STOCK PER ITEM ========= */

// GET /items/{id}/price-history
$router->get('/items/{id}/price-history',
    fn($id) => (new ItemsController())->priceHistory((int)$id),
    ['items.view']
);

$router->get('/items/{id}/stock-check',
    fn($id) => (new ItemsController())->stockCheck((int)$id),
    ['items.view']
);

$router->post('/items/{id}/stock',
    fn($id) => (new ItemsController())->adjustStock((int)$id),
    ['stock.adjust']
);

$router->get('/items/{id}/stock/history',
    fn($id) => (new ItemsController())->stockHistory((int)$id),
    ['stock.view']
);
