<?php

use App\Controllers\PaymentsController;

/* ========= PAYMENTS ========= */

// GET /payments
$router->get('/payments',
    fn() => (new PaymentsController())->index(),
    ['payments.view']
);

// GET /payments/outstanding-documents?payee_id=X
$router->get('/payments/outstanding-documents',
    fn() => (new PaymentsController())->outstandingDocuments(),
    ['payments.view']
);

// GET /payments/{id}
$router->get('/payments/{id}',
    fn($id) => (new PaymentsController())->viewById((int)$id),
    ['payments.view']
);

// GET /payments/{id}/allocations
$router->get('/payments/{id}/allocations',
    fn($id) => (new PaymentsController())->allocations((int)$id),
    ['payments.view']
);

// POST /payments
$router->post('/payments',
    fn() => (new PaymentsController())->store(),
    ['payments.create']
);

// DELETE /payments/{id}
$router->delete('/payments/{id}',
    fn($id) => (new PaymentsController())->delete((int)$id),
    ['payments.delete']
);
