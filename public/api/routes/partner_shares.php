<?php

use App\Controllers\PartnerSharesController;

/* ========= PARTNER SHARES ========= */

// GET /partner-shares
$router->get('/partner-shares',
    fn() => (new PartnerSharesController())->index(),
    ['partners.manage']
);

// POST /partner-shares
$router->post('/partner-shares',
    fn() => (new PartnerSharesController())->store(),
    ['partners.manage']
);

// PUT /partner-shares/{id}
$router->put('/partner-shares/{id}',
    fn($id) => (new PartnerSharesController())->update((int)$id),
    ['partners.manage']
);

// DELETE /partner-shares/{id}
$router->delete('/partner-shares/{id}',
    fn($id) => (new PartnerSharesController())->destroy((int)$id),
    ['partners.manage']
);
