<?php
namespace App\Controllers;

use App\Config\Database;
use App\Services\PaymentService;

class PaymentsController extends BaseController {

    private PaymentService $service;

    public function __construct() {
        $this->service = new PaymentService(Database::getConnection());
    }

    // GET /payments
    public function index(): void {
        try {
            $this->auth();
            $payments = $this->service->list();
            $this->json(true, ['payments' => $payments]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch payments');
        }
    }

    // GET /payments/{id}
    public function viewById(int $id): void {
        try {
            $this->auth();
            $payment = $this->service->getById($id);
            $this->json(true, ['payment' => $payment]);
        } catch (\RuntimeException $e) {
            $this->notFound();
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch payment');
        }
    }

    // POST /payments
    public function store(): void {
        try {
            $this->auth();
            $data = $this->input([
                'payee_id', 'payment_number', 'payment_date', 'notes', 'lines', 'allocations'
            ]);
            $id = $this->service->create($data);
            $this->json(true, ['id' => $id], null, 201);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to create payment: ' . $e->getMessage());
        }
    }

    // GET /payments/{id}/allocations
    public function allocations(int $id): void {
        try {
            $this->auth();
            $allocations = $this->service->getAllocations($id);
            $this->json(true, ['allocations' => $allocations]);
        } catch (\RuntimeException $e) {
            $this->notFound();
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch allocations');
        }
    }

    // GET /payments/outstanding-documents?payee_id=X
    public function outstandingDocuments(): void {
        try {
            $this->auth();
            $payeeId = isset($_GET['payee_id']) && $_GET['payee_id'] !== '' ? (int) $_GET['payee_id'] : null;
            if (!$payeeId) {
                $this->json(false, null, 'payee_id is required', 422);
                return;
            }
            $ctx = \App\Support\AuthContext::get();
            
            $documents = $this->service->getOutstandingDocumentsForPayee($payeeId, $ctx->business_id);
            $this->json(true, ['documents' => $documents]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch outstanding documents: ' . $e->getMessage());
        }
    }

    // DELETE /payments/{id}
    public function delete(int $id): void {
        try {
            $this->auth();
            $this->service->delete($id);
            $this->json(true, ['id' => $id]);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to delete payment');
        }
    }

}
