<?php

use App\Controllers\SalesInvoicesController;
use App\Controllers\CreditNoteController;

/* =====================================================================
 * STATIC / NESTED ROUTES — must be registered BEFORE /{id} dynamic routes
 * ===================================================================== */

// GET /sales-invoices/aging
$router->get('/sales-invoices/aging',
    fn() => (new SalesInvoicesController())->aging(),
    ['invoices.view']
);

// GET /sales-invoices/{id}/payment-status
$router->get('/sales-invoices/{id}/payment-status',
    fn($id) => (new SalesInvoicesController())->paymentStatus((int)$id),
    ['invoices.view']
);

// GET /sales-invoices/{id}/pdf
$router->get('/sales-invoices/{id}/pdf',
    fn($id) => (new SalesInvoicesController())->downloadPdf((int)$id),
    ['invoices.view']
);

// POST /sales-invoices/{id}/status
$router->post('/sales-invoices/{id}/status',
    fn($id) => (new SalesInvoicesController())->updateStatus((int)$id),
    ['invoices.view']
);

// POST /sales-invoices/{id}/lines
$router->post('/sales-invoices/{id}/lines',
    fn($id) => (new SalesInvoicesController())->addLine((int)$id),
    ['invoices.edit']
);

// DELETE /sales-invoices/{id}/lines/{lineId}
$router->delete('/sales-invoices/{id}/lines/{lineId}',
    fn($id, $lineId) => (new SalesInvoicesController())->removeLine((int)$id, (int)$lineId),
    ['invoices.edit']
);

/* =====================================================================
 * SALES INVOICES
 * ===================================================================== */

// GET /sales-invoices
$router->get('/sales-invoices',
    fn() => (new SalesInvoicesController())->index(),
    ['invoices.view']
);

// POST /sales-invoices
$router->post('/sales-invoices',
    fn() => (new SalesInvoicesController())->store(),
    ['invoices.create']
);

// GET /sales-invoices/{id}
$router->get('/sales-invoices/{id}',
    fn($id) => (new SalesInvoicesController())->viewById((int)$id),
    ['invoices.view']
);

// POST /sales-invoices/{id}/duplicate
$router->post('/sales-invoices/{id}/duplicate',
    fn($id) => (new SalesInvoicesController())->duplicate((int)$id),
    ['invoices.create']
);

// PUT /sales-invoices/{id}
$router->put('/sales-invoices/{id}',
    fn($id) => (new SalesInvoicesController())->update((int)$id),
    ['invoices.edit']
);

// DELETE /sales-invoices/{id}
$router->delete('/sales-invoices/{id}',
    fn($id) => (new SalesInvoicesController())->delete((int)$id),
    ['invoices.delete']
);

/* =====================================================================
 * CREDIT NOTES
 * ===================================================================== */

// POST /credit-notes/{id}/issue — must be before /{id} GET
$router->post('/credit-notes/{id}/issue',
    fn($id) => (new CreditNoteController())->issue((int)$id),
    ['invoices.edit']
);

// GET /credit-notes
$router->get('/credit-notes',
    fn() => (new CreditNoteController())->index(),
    ['invoices.view']
);

// POST /credit-notes
$router->post('/credit-notes',
    fn() => (new CreditNoteController())->store(),
    ['invoices.edit']
);

// GET /credit-notes/{id}
$router->get('/credit-notes/{id}',
    fn($id) => (new CreditNoteController())->show((int)$id),
    ['invoices.view']
);

// DELETE /credit-notes/{id}
$router->delete('/credit-notes/{id}',
    fn($id) => (new CreditNoteController())->delete((int)$id),
    ['invoices.edit']
);
