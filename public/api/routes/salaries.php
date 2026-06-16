<?php

use App\Controllers\SalariesController;

// GET /salaries
$router->get('/salaries',
    fn() => (new SalariesController())->index(),
    ['salaries.view']
);

// GET /salaries/{id}/payslip (must come before /salaries/{id} to avoid catch-all)
$router->get('/salaries/{id}/payslip',
    fn($id) => (new SalariesController())->payslip((int)$id),
    ['salaries.view']
);

// GET /salaries/{id}
$router->get('/salaries/{id}',
    fn($id) => (new SalariesController())->viewById((int)$id),
    ['salaries.view']
);

// POST /salaries/generate-payroll
$router->post('/salaries/generate-payroll',
    fn() => (new SalariesController())->generatePayroll(),
    ['salaries.create']
);

// POST /salaries
$router->post('/salaries',
    fn() => (new SalariesController())->store(),
    ['salaries.create']
);

// PUT /salaries/{id}
$router->put('/salaries/{id}',
    fn($id) => (new SalariesController())->update((int)$id),
    ['salaries.edit']
);

// DELETE /salaries/{id}
$router->delete('/salaries/{id}',
    fn($id) => (new SalariesController())->delete((int)$id),
    ['salaries.delete']
);
