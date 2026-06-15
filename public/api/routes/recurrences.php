<?php

use App\Controllers\RecurrenceController;

/* =====================================================================
 * SCHEDULE MANAGEMENT
 * ===================================================================== */

// GET /recurrences
$router->get('/recurrences',
    fn() => (new RecurrenceController())->listSchedules(),
    ['expenses.view']
);

// POST /recurrences/evaluate
$router->post('/recurrences/evaluate',
    fn() => (new RecurrenceController())->evaluate(),
    ['expenses.manage']
);

/* =====================================================================
 * EXPENSE RECURRENCE
 * ===================================================================== */

// POST /expenses/{id}/recurrence — per-expense recurrence
$router->post('/expenses/{id}/recurrence',
    fn($id) => (new RecurrenceController())->createExpenseRecurrence((int)$id),
    ['expenses.manage']
);

// GET /expenses/{id}/recurrence — get recurrence for a specific expense
$router->get('/expenses/{id}/recurrence',
    fn($id) => (new RecurrenceController())->getExpenseRecurrence((int)$id),
    ['expenses.view']
);

// DELETE /expenses/{id}/recurrence — delete recurrence for a specific expense
$router->delete('/expenses/{id}/recurrence',
    fn($id) => (new RecurrenceController())->deleteExpenseRecurrence((int)$id),
    ['expenses.manage']
);

/* =====================================================================
 * SALARY RECURRENCE
 * ===================================================================== */

// POST /salaries/employees/{id}/recurrence
$router->post('/salaries/employees/{id}/recurrence',
    fn($id) => (new RecurrenceController())->createSalaryRecurrence((int)$id),
    ['salaries.edit']
);

// GET /salaries/employees/{id}/recurrence
$router->get('/salaries/employees/{id}/recurrence',
    fn($id) => (new RecurrenceController())->getSalaryRecurrence((int)$id),
    ['salaries.view']
);

// DELETE /salaries/employees/{id}/recurrence
$router->delete('/salaries/employees/{id}/recurrence',
    fn($id) => (new RecurrenceController())->deleteSalaryRecurrence((int)$id),
    ['salaries.edit']
);

/* =====================================================================
 * INSTANCES
 * ===================================================================== */

// GET /recurring-instances
$router->get('/recurring-instances',
    fn() => (new RecurrenceController())->listInstances(),
    ['expenses.view']
);

// PUT /recurring-instances/{id}/status
$router->put('/recurring-instances/{id}/status',
    fn($id) => (new RecurrenceController())->updateInstanceStatus((int)$id),
    ['expenses.edit']
);

// GET /recurring-instances/{id}/payment
$router->get('/recurring-instances/{id}/payment',
    fn($id) => (new RecurrenceController())->getInstancePayment((int)$id),
    ['payments.view']
);

// POST /recurring-instances/bulk-advance
$router->post('/recurring-instances/bulk-advance',
    fn() => (new RecurrenceController())->bulkAdvancePayment(),
    ['expenses.manage']
);

/* =====================================================================
 * ADVANCE PAYMENTS
 * ===================================================================== */

// GET /recurring-instances/advance-payments
$router->get('/recurring-instances/advance-payments',
    fn() => (new RecurrenceController())->listAdvancePayments(),
    ['expenses.view']
);

// GET /recurring-instances/advance-payments/{id}
$router->get('/recurring-instances/advance-payments/{id}',
    fn($id) => (new RecurrenceController())->getAdvancePayment((int)$id),
    ['expenses.view']
);

/* =====================================================================
 * PREPAID ASSETS & ACCOUNTING
 * ===================================================================== */

// GET /prepaid-assets
$router->get('/prepaid-assets',
    fn() => (new RecurrenceController())->listPrepaidAssets(),
    ['expenses.view']
);

// GET /prepaid-assets/{id}
$router->get('/prepaid-assets/{id}',
    fn($id) => (new RecurrenceController())->getPrepaidAsset((int)$id),
    ['expenses.view']
);

// GET /accounting-entries
$router->get('/accounting-entries',
    fn() => (new RecurrenceController())->listAccountingEntries(),
    ['expenses.view']
);

/* =====================================================================
 * AUDIT LOG
 * ===================================================================== */

// GET /recurring-audit-log
$router->get('/recurring-audit-log',
    fn() => (new RecurrenceController())->listAuditLog(),
    ['expenses.view']
);
