<?php

use App\Controllers\ItemsController;

/* ========= ITEMS ========= */

$router->get('/items',
    fn() => (new ItemsController())->index(),
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
    fn($id) => (new ItemsController())->destroy((int)$id),
    ['items.delete']
);
