<?php

use App\Controllers\ItemsController;

/* ========= BATCHES (item batch management) ========= */

// GET /batches/{id}  — list batches for a specific item
$router->get('/batches/{id}',
    fn($id) => (new ItemsController())->stockHistory((int)$id),
    ['items.view']
);
