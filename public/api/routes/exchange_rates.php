<?php

use App\Controllers\ExchangeRatesController;

/* ========= STATIC ROUTES (must be before /exchange-rates/{id}) ========= */

$router->get('/exchange-rates/latest',
    fn() => (new ExchangeRatesController())->latest(),
    []  // Any authenticated user can look up rates
);

/* ========= EXCHANGE RATES ========= */

$router->get('/exchange-rates',
    fn() => (new ExchangeRatesController())->index(),
    ['exchange-rates.manage']
);

$router->get('/exchange-rates/{id}',
    fn($id) => (new ExchangeRatesController())->show((int)$id),
    ['exchange-rates.manage']
);

$router->post('/exchange-rates',
    fn() => (new ExchangeRatesController())->store(),
    ['exchange-rates.manage']
);

$router->put('/exchange-rates/{id}',
    fn($id) => (new ExchangeRatesController())->update((int)$id),
    ['exchange-rates.manage']
);

$router->delete('/exchange-rates/{id}',
    fn($id) => (new ExchangeRatesController())->delete((int)$id),
    ['exchange-rates.manage']
);
