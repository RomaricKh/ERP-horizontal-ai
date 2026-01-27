<?php

use App\Controllers\BusinessesController;

/* ========= Users ========= */

$router->get('/businesses',
    fn() => (new BusinessesController())->index(),
    ['businesses.view']
);

$router->get('/businesses/{id}',
    fn($id) => (new BusinessesController())->viewById((int)$id),
    ['businesses.view']
);

$router->post('/businesses',
    fn() => (new BusinessesController())->store(),
    ['businesses.create']
);

$router->put('/businesses/{id}',
    fn($id) => (new BusinessesController())->update((int)$id),
    ['businesses.edit']
);

$router->delete('/businesses/{id}',
    fn($id) => (new BusinessesController())->delete((int)$id),
    ['businesses.delete']
);