<?php

namespace App\Controllers;

use App\Config\Database;
use App\Services\CreditNoteService;

class CreditNoteController extends BaseController
{
    private CreditNoteService $service;

    public function __construct()
    {
        $this->service = new CreditNoteService(Database::getConnection());
    }

    // GET /credit-notes
    public function index(): void
    {
        try {
            $this->auth();
            $notes = $this->service->list();
            $this->json(true, ['credit_notes' => $notes]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch credit notes');
        }
    }

    // GET /credit-notes/{id}
    public function show(int $id): void
    {
        try {
            $this->auth();
            $note = $this->service->getById($id);
            $this->json(true, ['credit_note' => $note]);
        } catch (\RuntimeException $e) {
            $this->notFound($e->getMessage());
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch credit note');
        }
    }

    // POST /credit-notes
    public function store(): void
    {
        try {
            $this->auth();
            $data  = $this->input(['sales_invoice_id', 'credit_note_date']);
            $lines = $data['lines'] ?? [];
            unset($data['lines']);
            $id = $this->service->create($data, $lines);
            $this->json(true, ['id' => $id], null, 201);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to create credit note: ' . $e->getMessage());
        }
    }

    // POST /credit-notes/{id}/issue
    public function issue(int $id): void
    {
        try {
            $this->auth();
            $this->service->issue($id);
            $this->json(true, ['id' => $id, 'status' => 'ISSUED']);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to issue credit note: ' . $e->getMessage());
        }
    }
}
