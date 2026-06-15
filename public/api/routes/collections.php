<?php

use App\Controllers\CollectionsController;

/* ========= COLLECTIONS ========= */

// GET /collections
$router->get('/collections',
    fn() => (new CollectionsController())->index(),
    ['collections.view']
);

// GET /collections/outstanding-invoices?payee_id=X
$router->get('/collections/outstanding-invoices',
    fn() => (new CollectionsController())->outstandingInvoices(),
    ['collections.view']
);

// GET /collections/{id}
$router->get('/collections/{id}',
    fn($id) => (new CollectionsController())->viewById((int)$id),
    ['collections.view']
);

// GET /collections/{id}/allocations
$router->get('/collections/{id}/allocations',
    fn($id) => (new CollectionsController())->allocations((int)$id),
    ['collections.view']
);

// POST /collections
$router->post('/collections',
    fn() => (new CollectionsController())->store(),
    ['collections.create']
);

// DELETE /collections/{id}
$router->delete('/collections/{id}',
    fn($id) => (new CollectionsController())->delete((int)$id),
    ['collections.delete']
);
