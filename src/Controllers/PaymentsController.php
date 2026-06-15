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
                'account_id', 'payment_number',
                'payment_date', 'currency', 'exchange_rate', 'amount_fc', 'method'
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
            $payeeId = isset($_GET['payee_id']) ? (int) $_GET['payee_id'] : null;
            if (!$payeeId) {
                $this->json(false, null, 'payee_id is required', 422);
                return;
            }
            $ctx = \App\Support\AuthContext::get();
            $db = \App\Config\Database::getConnection();
            $engine = new \App\Services\SettlementEngine($db);
            // Use reflection or direct call to get unpaid documents
            $documents = $this->getOutstandingDocumentsForPayee($payeeId, $ctx->business_id, $db);
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

    /**
     * Get outstanding (unpaid/partially-paid) documents for a supplier payee.
     */
    private function getOutstandingDocumentsForPayee(int $payeeId, int $businessId, \PDO $db): array {
        $documents = [];

        // RECEIVED Purchase Orders
        $stmt = $db->prepare("
            SELECT
                po.id,
                po.po_number AS doc_number,
                po.po_date AS doc_date,
                po.total_amount_bc,
                po.total_amount_fc,
                'PURCHASE_ORDER' AS reference_type,
                COALESCE(
                    (SELECT SUM(pa.amount_bc) FROM payment_allocations pa WHERE pa.reference_type = 'PURCHASE_ORDER' AND pa.reference_id = po.id),
                    0
                ) AS allocated_bc,
                COALESCE(
                    (SELECT SUM(pa.amount_fc) FROM payment_allocations pa WHERE pa.reference_type = 'PURCHASE_ORDER' AND pa.reference_id = po.id),
                    0
                ) AS allocated_fc
            FROM purchase_orders po
            WHERE po.supplier_id = :payee_id
              AND po.business_id = :business_id
              AND po.status = 'RECEIVED'
            ORDER BY po.po_date ASC, po.id ASC
        ");
        $stmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId]);
        $poRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($poRows as $row) {
            $remaining = (float) $row['total_amount_bc'] - (float) $row['allocated_bc'];
            if ($remaining > 0.001) {
                $documents[] = [
                    'id'             => (int) $row['id'],
                    'doc_number'     => $row['doc_number'],
                    'doc_date'       => $row['doc_date'],
                    'reference_type' => 'PURCHASE_ORDER',
                    'total_bc'       => (float) $row['total_amount_bc'],
                    'total_fc'       => (float) $row['total_amount_fc'],
                    'allocated_bc'   => (float) $row['allocated_bc'],
                    'allocated_fc'   => (float) $row['allocated_fc'],
                    'remaining_bc'   => round($remaining, 2),
                    'remaining_fc'   => round((float) $row['total_amount_fc'] - (float) $row['allocated_fc'], 2),
                ];
            }
        }

        // Unpaid Expenses
        $stmt = $db->prepare("
            SELECT
                e.id,
                COALESCE(e.reference_number, CONCAT('EXP-', e.id)) AS doc_number,
                e.expense_date AS doc_date,
                e.amount_bc AS total_amount_bc,
                e.amount_fc AS total_amount_fc,
                'EXPENSE' AS reference_type,
                COALESCE(
                    (SELECT SUM(pa.amount_bc) FROM payment_allocations pa WHERE pa.reference_type = 'EXPENSE' AND pa.reference_id = e.id),
                    0
                ) AS allocated_bc,
                COALESCE(
                    (SELECT SUM(pa.amount_fc) FROM payment_allocations pa WHERE pa.reference_type = 'EXPENSE' AND pa.reference_id = e.id),
                    0
                ) AS allocated_fc
            FROM expenses e
            WHERE e.payee_id = :payee_id
              AND e.business_id = :business_id
            ORDER BY e.expense_date ASC, e.id ASC
        ");
        $stmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId]);
        $expenseRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($expenseRows as $row) {
            $remaining = (float) $row['total_amount_bc'] - (float) $row['allocated_bc'];
            if ($remaining > 0.001) {
                $documents[] = [
                    'id'             => (int) $row['id'],
                    'doc_number'     => $row['doc_number'],
                    'doc_date'       => $row['doc_date'],
                    'reference_type' => 'EXPENSE',
                    'total_bc'       => (float) $row['total_amount_bc'],
                    'total_fc'       => (float) ($row['total_amount_fc'] ?? $row['total_amount_bc']),
                    'allocated_bc'   => (float) $row['allocated_bc'],
                    'allocated_fc'   => (float) $row['allocated_fc'],
                    'remaining_bc'   => round($remaining, 2),
                    'remaining_fc'   => round((float) ($row['total_amount_fc'] ?? $row['total_amount_bc']) - (float) $row['allocated_fc'], 2),
                ];
            }
        }

        // Unpaid Salaries
        $stmt = $db->prepare("
            SELECT
                s.id,
                CONCAT('SAL-', s.id) AS doc_number,
                s.period_start AS doc_date,
                s.net_amount_bc AS total_amount_bc,
                s.net_amount_fc AS total_amount_fc,
                'SALARY' AS reference_type,
                COALESCE(
                    (SELECT SUM(pa.amount_bc) FROM payment_allocations pa WHERE pa.reference_type = 'SALARY' AND pa.reference_id = s.id),
                    0
                ) AS allocated_bc,
                COALESCE(
                    (SELECT SUM(pa.amount_fc) FROM payment_allocations pa WHERE pa.reference_type = 'SALARY' AND pa.reference_id = s.id),
                    0
                ) AS allocated_fc
            FROM salaries s
            WHERE s.payee_id = :payee_id
              AND s.business_id = :business_id
            ORDER BY s.period_start ASC, s.id ASC
        ");
        $stmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId]);
        $salaryRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($salaryRows as $row) {
            $remaining = (float) $row['total_amount_bc'] - (float) $row['allocated_bc'];
            if ($remaining > 0.001) {
                $documents[] = [
                    'id'             => (int) $row['id'],
                    'doc_number'     => $row['doc_number'],
                    'doc_date'       => $row['doc_date'],
                    'reference_type' => 'SALARY',
                    'total_bc'       => (float) $row['total_amount_bc'],
                    'total_fc'       => (float) ($row['total_amount_fc'] ?? $row['total_amount_bc']),
                    'allocated_bc'   => (float) $row['allocated_bc'],
                    'allocated_fc'   => (float) $row['allocated_fc'],
                    'remaining_bc'   => round($remaining, 2),
                    'remaining_fc'   => round((float) ($row['total_amount_fc'] ?? $row['total_amount_bc']) - (float) $row['allocated_fc'], 2),
                ];
            }
        }

        // Sort by date ASC
        usort($documents, function ($a, $b) {
            $cmp = strcmp($a['doc_date'], $b['doc_date']);
            return $cmp === 0 ? $a['id'] - $b['id'] : $cmp;
        });

        return $documents;
    }
}
