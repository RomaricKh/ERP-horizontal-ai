<?php

use App\Controllers\TranslationController;

/* ========= TRANSLATIONS ========= */

// GET /translations/{lang}
$router->get('/translations/{lang}',
    fn($lang) => (new TranslationController())->index($lang)
);
