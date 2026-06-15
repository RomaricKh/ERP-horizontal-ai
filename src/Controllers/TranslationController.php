<?php

namespace App\Controllers;

use App\Config\Database;
use App\Services\TranslationService;

class TranslationController extends BaseController
{
    private TranslationService $service;

    public function __construct()
    {
        $this->service = new TranslationService(Database::getConnection());
    }

    // GET /translations/{lang}
    public function index(string $lang): void
    {
        try {
            $this->auth();
            $translations = $this->service->getByLanguage($lang);
            $this->json(true, $translations);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, 'Language not found', 404);
        } catch (\Throwable $e) {
            $this->serverError('Failed to load translations');
        }
    }
}
