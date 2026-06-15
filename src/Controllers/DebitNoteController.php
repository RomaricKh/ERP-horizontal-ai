<?php

namespace App\Controllers;

use App\Config\Database;
use App\Services\DebitNoteService;

class DebitNoteController extends BaseController
{
    private DebitNoteService $service;

    public function __construct()
    {
        $this->service = new DebitNoteService(Database::getConnection());
    }

    // GET /debit-notes
    public function index(): void
    {
        try {
            $this->auth();
            $notes = $this->service->list();
            $this->json(true, ['debit_notes' => $notes]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch debit notes');
        }
    }

    // GET /debit-notes/{id}
    public function show(int $id): void
    {
        try {
            $this->auth();
            $note = $this->service->getById($id);
            $this->json(true, ['debit_note' => $note]);
        } catch (\RuntimeException $e) {
            $this->notFound($e->getMessage());
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch debit note');
        }
    }

    // POST /debit-notes
    public function store(): void
    {
        try {
            $this->auth();
            $data  = $this->input(['purchase_order_id', 'debit_note_date']);
            $lines = $data['lines'] ?? [];
            unset($data['lines']);
            $id = $this->service->create($data, $lines);
            $this->json(true, ['id' => $id], null, 201);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to create debit note: ' . $e->getMessage());
        }
    }

    // POST /debit-notes/{id}/issue
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
            $this->serverError('Failed to issue debit note: ' . $e->getMessage());
        }
    }
}
