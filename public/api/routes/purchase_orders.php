<?php

use App\Controllers\PurchaseOrdersController;
use App\Controllers\DebitNoteController;

/* =====================================================================
 * STATIC / NESTED ROUTES — must be registered BEFORE /{id} dynamic routes
 * ===================================================================== */

// GET /purchase-orders/{id}/payment-status
$router->get('/purchase-orders/{id}/payment-status',
    fn($id) => (new PurchaseOrdersController())->paymentStatus((int)$id),
    ['po.view']
);

// GET /purchase-orders/{id}/pdf
$router->get('/purchase-orders/{id}/pdf',
    fn($id) => (new PurchaseOrdersController())->downloadPdf((int)$id),
    ['po.view']
);

// GET /purchase-orders/{id}/payment-shortcut
$router->get('/purchase-orders/{id}/payment-shortcut',
    fn($id) => (new PurchaseOrdersController())->paymentShortcut((int)$id),
    ['po.view']
);

// POST /purchase-orders/{id}/status
$router->post('/purchase-orders/{id}/status',
    fn($id) => (new PurchaseOrdersController())->updateStatus((int)$id),
    ['po.view']
);

// POST /purchase-orders/{id}/duplicate
$router->post('/purchase-orders/{id}/duplicate',
    fn($id) => (new PurchaseOrdersController())->duplicate((int)$id),
    ['po.create']
);

// POST /purchase-orders/{id}/lines
$router->post('/purchase-orders/{id}/lines',
    fn($id) => (new PurchaseOrdersController())->addLine((int)$id),
    ['po.edit']
);

// DELETE /purchase-orders/{id}/lines/{lineId}
$router->delete('/purchase-orders/{id}/lines/{lineId}',
    fn($id, $lineId) => (new PurchaseOrdersController())->removeLine((int)$id, (int)$lineId),
    ['po.edit']
);

/* =====================================================================
 * PURCHASE ORDERS
 * ===================================================================== */

// GET /purchase-orders
$router->get('/purchase-orders',
    fn() => (new PurchaseOrdersController())->index(),
    ['po.view']
);

// POST /purchase-orders
$router->post('/purchase-orders',
    fn() => (new PurchaseOrdersController())->store(),
    ['po.create']
);

// GET /purchase-orders/{id}
$router->get('/purchase-orders/{id}',
    fn($id) => (new PurchaseOrdersController())->viewById((int)$id),
    ['po.view']
);

// PUT /purchase-orders/{id}
$router->put('/purchase-orders/{id}',
    fn($id) => (new PurchaseOrdersController())->update((int)$id),
    ['po.edit']
);

// DELETE /purchase-orders/{id}
$router->delete('/purchase-orders/{id}',
    fn($id) => (new PurchaseOrdersController())->delete((int)$id),
    ['po.delete']
);

/* =====================================================================
 * DEBIT NOTES
 * ===================================================================== */

// POST /debit-notes/{id}/issue — must be before /{id} GET
$router->post('/debit-notes/{id}/issue',
    fn($id) => (new DebitNoteController())->issue((int)$id),
    ['purchase_orders.manage']
);

// GET /debit-notes
$router->get('/debit-notes',
    fn() => (new DebitNoteController())->index(),
    ['po.view']
);

// POST /debit-notes
$router->post('/debit-notes',
    fn() => (new DebitNoteController())->store(),
    ['purchase_orders.manage']
);

// GET /debit-notes/{id}
$router->get('/debit-notes/{id}',
    fn($id) => (new DebitNoteController())->show((int)$id),
    ['po.view']
);
