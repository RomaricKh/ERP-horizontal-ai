<?php

use App\Controllers\ExpensesController;

/* =====================================================================
 * STATIC ROUTES — must be registered BEFORE /{id} dynamic routes
 * ===================================================================== */

// GET /expenses/categories
$router->get('/expenses/categories',
    fn() => (new ExpensesController())->indexCategories(),
    ['expenses.view']
);

// POST /expenses/categories
$router->post('/expenses/categories',
    fn() => (new ExpensesController())->storeCategory(),
    ['expenses.manage']
);

// PUT /expenses/categories/{id}
$router->put('/expenses/categories/{id}',
    fn($id) => (new ExpensesController())->updateCategory((int)$id),
    ['expenses.manage']
);

// DELETE /expenses/categories/{id}
$router->delete('/expenses/categories/{id}',
    fn($id) => (new ExpensesController())->deleteCategory((int)$id),
    ['expenses.manage']
);

/* =====================================================================
 * EXPENSES
 * ===================================================================== */

// POST /uploads/expenses — file upload for expense attachments
$router->post('/uploads/expenses',
    fn() => (new ExpensesController())->uploadAttachment(),
    ['expenses.create']
);

// GET /expenses
$router->get('/expenses',
    fn() => (new ExpensesController())->index(),
    ['expenses.view']
);

// GET /expenses/{id}
$router->get('/expenses/{id}',
    fn($id) => (new ExpensesController())->show((int)$id),
    ['expenses.view']
);

// POST /expenses
$router->post('/expenses',
    fn() => (new ExpensesController())->store(),
    ['expenses.create']
);

// PUT /expenses/{id}
$router->put('/expenses/{id}',
    fn($id) => (new ExpensesController())->update((int)$id),
    ['expenses.edit']
);

// DELETE /expenses/{id}
$router->delete('/expenses/{id}',
    fn($id) => (new ExpensesController())->delete((int)$id),
    ['expenses.delete']
);
