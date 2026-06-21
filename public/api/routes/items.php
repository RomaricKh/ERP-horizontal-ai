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

// Pending Prices
$router->get('/items/pending-prices', fn() => (new \App\Controllers\PendingPricesController(App\Config\Database::getConnection()))->index(), ['items.view']);
$router->post('/items/pending-prices/apply', fn() => (new \App\Controllers\PendingPricesController(App\Config\Database::getConnection()))->apply(), ['items.edit']);
$router->post('/items/pending-prices/cancel', fn() => (new \App\Controllers\PendingPricesController(App\Config\Database::getConnection()))->cancel(), ['items.edit']);


$router->get('/items/stock',
    fn() => (new ItemsController())->stock(),
    ['stock.view']
);

/* ========= ITEMS ========= */

$router->get('/items/for-payee/{payeeId}',
    fn($payeeId) => (new ItemsController())->indexForPayee((int)$payeeId),
    ['items.view']
);

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

// Price History & Batches
$router->get('/items/{id}/price-history', fn($id) => (new ItemsController())->priceHistory((int)$id));
$router->get('/items/{id}/batches', fn($id) => (new ItemsController())->batches((int)$id));
$router->get('/items/{id}/batch-transactions', fn($id) => (new ItemsController())->batchTransactions((int)$id));

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
