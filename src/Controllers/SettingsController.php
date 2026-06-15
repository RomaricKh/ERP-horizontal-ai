<?php
namespace App\Controllers;

use App\Config\Database;
use App\Services\SettingsService;
use App\Services\EmailService;

class SettingsController extends BaseController {

    private SettingsService $service;

    public function __construct() {
        $this->service = new SettingsService(Database::getConnection());
    }

    // GET /settings
    public function show(): void {
        try {
            $this->auth();
            $settings = $this->service->get();
            $this->json(true, $settings);
        } catch (\Throwable $e) {
            $this->serverError('Failed to load settings');
        }
    }

    // PUT /settings
    public function save(): void {
        try {
            $this->auth();
            $data = $this->input(['language', 'timezone', 'date_format', 'number_format', 'currency_format', 'phone_format']);
            $this->service->save($data);
            $this->json(true, null, 'Settings saved');
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to save settings');
        }
    }

    // GET /settings/smtp
    public function showSmtp(): void {
        try {
            $ctx = $this->auth();
            $db = Database::getConnection();
            $emailService = new EmailService($db, $ctx->business_id);
            $settings = $emailService->getSmtpSettings($ctx->business_id);
            $this->json(true, $settings);
        } catch (\Throwable $e) {
            $this->serverError('Failed to load SMTP settings');
        }
    }

    // PUT /settings/smtp
    public function saveSmtp(): void {
        try {
            $ctx = $this->auth();
            $input = $this->input(['host', 'port', 'username', 'password', 'from_address', 'from_name']);
            $input['is_active'] = $input['is_active'] ?? 0;

            $db = Database::getConnection();
            $emailService = new EmailService($db, $ctx->business_id);
            $emailService->saveSmtpSettings($ctx->business_id, $input);
            $this->json(true, null, 'SMTP settings saved');
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to save SMTP settings');
        }
    }
}
