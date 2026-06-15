<?php

use App\Controllers\SettingsController;
use App\Controllers\ModulesController;

/* ========= SETTINGS ========= */

// GET /settings
$router->get('/settings',
    fn() => (new SettingsController())->show(),
    ['settings.view']
);

// PUT /settings
$router->put('/settings',
    fn() => (new SettingsController())->save(),
    ['settings.view']
);

// GET /settings/smtp
$router->get('/settings/smtp',
    fn() => (new SettingsController())->showSmtp(),
    ['settings.view']
);

// PUT /settings/smtp
$router->put('/settings/smtp',
    fn() => (new SettingsController())->saveSmtp(),
    ['settings.view']
);

/* ========= MODULE MANAGEMENT ========= */

// GET /settings/modules
$router->get('/settings/modules',
    fn() => (new ModulesController())->index(),
    ['settings.modules']
);

// DELETE /settings/modules/override/{code}
$router->delete('/settings/modules/override/{code}',
    fn($code) => (new ModulesController())->removeOverride($code),
    ['settings.modules']
);
