<?php
namespace App\Controllers;

use App\Config\Database;
use App\Services\ExpenseService;

class ExpensesController extends BaseController {

    private ExpenseService $service;

    public function __construct() {
        $this->service = new ExpenseService(Database::getConnection());
    }

    /* =====================================================================
     * EXPENSES
     * ===================================================================== */

    // GET /expenses
    public function index(): void {
        try {
            $this->auth();
            $expenses = $this->service->list();
            $this->json(true, ['expenses' => $expenses]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch expenses');
        }
    }

    // GET /expenses/{id}
    public function show(int $id): void {
        try {
            $this->auth();
            $expense = $this->service->getById($id);
            $this->json(true, ['expense' => $expense]);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 404);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch expense');
        }
    }

    // POST /expenses
    public function store(): void {
        try {
            $this->auth();
            $data = $this->input(['expense_date', 'currency', 'exchange_rate', 'amount_fc']);
            $id   = $this->service->create($data);
            $this->json(true, ['id' => $id], null, 201);
        } catch (\InvalidArgumentException $e) {
            $this->logError('Expenses', 'store', $e);
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->logError('Expenses', 'store', $e);
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->logError('Expenses', 'store', $e);
            $this->serverError('Failed to create expense');
        }
    }

    // PUT /expenses/{id}
    public function update(int $id): void {
        try {
            $this->auth();
            $data = $this->input([]);
            $this->service->update($id, $data);
            $this->json(true, ['id' => $id]);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to update expense');
        }
    }

    // DELETE /expenses/{id}
    public function delete(int $id): void {
        try {
            $this->auth();
            $this->service->delete($id);
            $this->json(true, ['id' => $id]);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to delete expense');
        }
    }

    // POST /uploads/expenses — handle file upload for expense attachments
    public function uploadAttachment(): void {
        try {
            $this->auth();

            if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                $this->json(false, null, 'No file uploaded or upload error', 422);
                return;
            }

            $file = $_FILES['file'];
            $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'application/msword',
                             'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
            $allowedExts = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];

            // Sanitize original filename before extension extraction
            $originalName = $file['name'];
            // Remove path traversal sequences and null bytes
            $sanitized = str_replace(['../', '..\\', "\0"], '', $originalName);
            // Keep only allowed characters for safe extension extraction
            $sanitized = preg_replace('/[^a-zA-Z0-9_\-.]/', '', $sanitized);
            $ext = strtolower(pathinfo($sanitized, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExts)) {
                $this->json(false, null, 'File type not allowed. Accepted: pdf, jpg, png, doc, docx', 422);
                return;
            }

            // Max 10MB
            if ($file['size'] > 10 * 1024 * 1024) {
                $this->json(false, null, 'File too large. Maximum size: 10MB', 422);
                return;
            }

            // MIME type verification using finfo_file()
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $detectedMime = $finfo->file($file['tmp_name']);
            if (!in_array($detectedMime, $allowedTypes, true)) {
                $this->json(false, null, 'File content does not match expected type', 422);
                return;
            }

            $uploadDir = __DIR__ . '/../../uploads/expenses/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $filename = uniqid('exp_') . '_' . time() . '.' . $ext;
            $destPath = $uploadDir . $filename;

            if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                $this->json(false, null, 'Failed to save uploaded file', 500);
                return;
            }

            $publicPath = '/uploads/expenses/' . $filename;
            $this->json(true, ['path' => $publicPath], null, 201);
        } catch (\InvalidArgumentException $e) {
            $this->logError('Expenses', 'uploadAttachment', $e);
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->logError('Expenses', 'uploadAttachment', $e);
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->logError('Expenses', 'uploadAttachment', $e);
            $this->serverError('Upload failed');
        }
    }

    /* =====================================================================
     * EXPENSE CATEGORIES
     * ===================================================================== */

    // GET /expenses/categories
    public function indexCategories(): void {
        try {
            $this->auth();
            $categories = $this->service->listCategories();
            $this->json(true, ['categories' => $categories]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch expense categories');
        }
    }

    // POST /expenses/categories
    public function storeCategory(): void {
        try {
            $this->auth();
            $data     = $this->input(['name']);
            $name     = trim($data['name'] ?? '');
            $isFixed  = !empty($data['is_fixed']);
            $id       = $this->service->createCategory($name, $isFixed);
            $this->json(true, ['id' => $id], null, 201);
        } catch (\InvalidArgumentException $e) {
            $this->logError('Expenses', 'storeCategory', $e);
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->logError('Expenses', 'storeCategory', $e);
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->logError('Expenses', 'storeCategory', $e);
            $this->serverError('Failed to create expense category');
        }
    }

    // PUT /expenses/categories/{id}
    public function updateCategory(int $id): void {
        try {
            $this->auth();
            $data    = $this->input([]);
            $name    = trim($data['name'] ?? '');
            $isFixed = !empty($data['is_fixed']);
            $this->service->updateCategory($id, $name, $isFixed);
            $this->json(true, ['id' => $id]);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to update expense category');
        }
    }

    // DELETE /expenses/categories/{id}
    public function deleteCategory(int $id): void {
        try {
            $this->auth();
            $this->service->deleteCategory($id);
            $this->json(true, ['id' => $id]);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to delete expense category');
        }
    }
}
