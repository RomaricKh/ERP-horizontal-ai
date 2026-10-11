<?php
namespace App\Controllers;

use App\Config\Database;
use App\Services\SalaryService;

class SalariesController extends BaseController {

    private SalaryService $service;

    public function __construct() {
        $this->service = new SalaryService(Database::getConnection());
    }

    // GET /salaries
    public function index(): void {
        try {
            $this->auth();
            $salaries = $this->service->list();
            $this->json(true, ['salaries' => $salaries]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch salaries');
        }
    }

    // POST /salaries
    public function store(): void {
        try {
            $this->auth();
            $data = $this->input([
                'payee_id', 'period_start', 'period_end',
                'currency', 'exchange_rate', 'gross_amount_fc', 'tax_fc', 'notes'
            ]);
            $id = $this->service->create($data);
            $this->json(true, ['id' => $id], null, 201);
        } catch (\InvalidArgumentException $e) {
            $this->logError('Salaries', 'store', $e);
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->logError('Salaries', 'store', $e);
            $code = str_contains($e->getMessage(), 'not found') ? 404 : 422;
            $this->json(false, null, $e->getMessage(), $code);
        } catch (\Throwable $e) {
            $this->logError('Salaries', 'store', $e);
            $this->serverError('Failed to create salary record');
        }
    }

    // GET /salaries/{id}
    public function viewById(int $id): void {
        try {
            $this->auth();
            $salary = $this->service->getById($id);
            $this->json(true, ['salary' => $salary]);
        } catch (\RuntimeException $e) {
            $this->notFound();
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch salary record');
        }
    }

    // PUT /salaries/{id}
    public function update(int $id): void {
        try {
            $this->auth();
            $data = $this->input([]);
            $this->service->update($id, $data);
            $this->json(true, ['id' => $id]);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to update salary record');
        }
    }

    // DELETE /salaries/{id}
    public function delete(int $id): void {
        try {
            $this->auth();
            $this->service->delete($id);
            $this->json(true, ['id' => $id]);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to delete salary record');
        }
    }

    // POST /salaries/generate-payroll
    public function generatePayroll(): void {
        try {
            $this->auth();
            $data = $this->input(['period_start', 'period_end', 'payee_ids']);
            $summary = $this->service->generatePayroll($data);
            $this->json(true, $summary);
        } catch (\InvalidArgumentException $e) {
            $this->logError('Salaries', 'generatePayroll', $e);
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->logError('Salaries', 'generatePayroll', $e);
            $code = str_contains($e->getMessage(), 'not found') ? 404 : 422;
            $this->json(false, null, $e->getMessage(), $code);
        } catch (\Throwable $e) {
            $this->logError('Salaries', 'generatePayroll', $e);
            $this->serverError('Failed to generate payroll');
        }
    }

    // GET /salaries/{id}/payslip
    public function payslip(int $id): void {
        try {
            $this->auth();
            $pdf = $this->service->generatePayslipPdf($id);
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . $pdf['filename'] . '"');
            echo $pdf['content'];
            exit;
        } catch (\InvalidArgumentException $e) {
            $this->logError('Salaries', 'payslip', $e);
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            $this->logError('Salaries', 'payslip', $e);
            $code = str_contains($e->getMessage(), 'not found') ? 404 : 422;
            $this->json(false, null, $e->getMessage(), $code);
        } catch (\Throwable $e) {
            $this->logError('Salaries', 'payslip', $e);
            $this->serverError('Failed to generate payslip');
        }
    }
}
