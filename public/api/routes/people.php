<?php

use App\Controllers\PeopleController;

/* ========= PEOPLE ========= */

$router->get('/people',
    fn() => (new PeopleController())->index(),
    ['people.view']
);

/* ========= PEOPLE INSIGHTS ========= */

$router->get('/people/{id}/insights/supplier',
    fn($id) => (new \App\Controllers\InsightsController())->supplier((int)$id),
    ['people.view']
);

$router->get('/people/{id}/insights/customer',
    fn($id) => (new \App\Controllers\InsightsController())->customer((int)$id),
    ['people.view']
);

$router->get('/people/{id}/insights/general',
    fn($id) => (new \App\Controllers\InsightsController())->general((int)$id),
    ['people.view']
);

$router->get('/people/{id}',
    fn($id) => (new PeopleController())->viewById((int)$id),
    ['people.view']
);

$router->post('/people',
    fn() => (new PeopleController())->store(),
    ['people.create']
);

$router->put('/people/{id}',
    fn($id) => (new PeopleController())->update((int)$id),
    ['people.edit']
);

$router->delete('/people/{id}',
    fn($id) => (new PeopleController())->delete((int)$id),
    ['people.delete']
);

/* ========= PAYEE TYPES ========= */

$router->get('/people/payee-types',
    fn() => (new PeopleController())->payeeTypes(),
    ['people.view']
);

$router->post('/people/payee-types',
    fn() => (new PeopleController())->storePayeeType(),
    ['people.create']
);

$router->put('/people/payee-types/{id}',
    fn($id) => (new PeopleController())->updatePayeeType((int)$id),
    ['people.edit']
);

$router->delete('/people/payee-types/{id}',
    fn($id) => (new PeopleController())->deletePayeeType((int)$id),
    ['people.delete']
);
