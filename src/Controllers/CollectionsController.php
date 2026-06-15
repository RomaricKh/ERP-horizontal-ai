<?php
namespace App\Controllers;

use App\Config\Database;
use App\Services\CollectionService;

class CollectionsController extends BaseController {

    private CollectionService $service;

    public function __construct() {
        $this->service = new CollectionService(Database::getConnection());
    }

    // GET /collections
    public function index(): void {
        try {
            $this->auth();
            $collections = $this->service->list();
            $this->json(true, ['collections' => $collections]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch collections');
        }
    }

    // GET /collections/{id}
    public function viewById(int $id): void {
        try {
            $this->auth();
            $collection = $this->service->getById($id);
            $this->json(true, ['collection' => $collection]);
        } catch (\RuntimeException $e) {
            $this->notFound();
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch collection');
        }
    }

    // POST /collections
    public function store(): void {
        try {
            $this->auth();
            $data = $this->input([
                'account_id', 'slip_number',
                'slip_date', 'currency', 'exchange_rate', 'amount_fc', 'method'
            ]);
            $id = $this->service->create($data);
            $this->json(true, ['id' => $id], null, 201);
        } catch (\InvalidArgumentException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to create collection: ' . $e->getMessage());
        }
    }

    // GET /collections/{id}/allocations
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

    // GET /collections/outstanding-invoices?payee_id=X
    public function outstandingInvoices(): void {
        try {
            $this->auth();
            $payeeId = isset($_GET['payee_id']) ? (int) $_GET['payee_id'] : null;
            if (!$payeeId) {
                $this->json(false, null, 'payee_id is required', 422);
                return;
            }
            $ctx = \App\Support\AuthContext::get();
            $db = \App\Config\Database::getConnection();
            $documents = $this->getOutstandingInvoicesForCustomer($payeeId, $ctx->business_id, $db);
            $this->json(true, ['documents' => $documents]);
        } catch (\Throwable $e) {
            $this->serverError('Failed to fetch outstanding invoices: ' . $e->getMessage());
        }
    }

    // DELETE /collections/{id}
    public function delete(int $id): void {
        try {
            $this->auth();
            $this->service->delete($id);
            $this->json(true, ['id' => $id]);
        } catch (\RuntimeException $e) {
            $this->json(false, null, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->serverError('Failed to delete collection');
        }
    }

    /**
     * Get outstanding (unpaid/partially-paid) invoices for a customer payee.
     */
    private function getOutstandingInvoicesForCustomer(int $payeeId, int $businessId, \PDO $db): array {
        $stmt = $db->prepare("
            SELECT
                si.id,
                si.invoice_number AS doc_number,
                si.invoice_date AS doc_date,
                si.total_amount_bc,
                si.total_amount_fc,
                'SALES_INVOICE' AS reference_type,
                COALESCE(
                    (SELECT SUM(ca.amount_bc) FROM collection_allocations ca WHERE ca.reference_type = 'SALES_INVOICE' AND ca.reference_id = si.id),
                    0
                ) AS allocated_bc,
                COALESCE(
                    (SELECT SUM(ca.amount_fc) FROM collection_allocations ca WHERE ca.reference_type = 'SALES_INVOICE' AND ca.reference_id = si.id),
                    0
                ) AS allocated_fc
            FROM sales_invoices si
            WHERE si.customer_id = :payee_id
              AND si.business_id = :business_id
              AND si.status = 'DELIVERED'
            ORDER BY si.invoice_date ASC, si.id ASC
        ");
        $stmt->execute(['payee_id' => $payeeId, 'business_id' => $businessId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $documents = [];
        foreach ($rows as $row) {
            $remaining = (float) $row['total_amount_bc'] - (float) $row['allocated_bc'];
            if ($remaining > 0.001) {
                $documents[] = [
                    'id'             => (int) $row['id'],
                    'doc_number'     => $row['doc_number'],
                    'doc_date'       => $row['doc_date'],
                    'reference_type' => 'SALES_INVOICE',
                    'total_bc'       => (float) $row['total_amount_bc'],
                    'total_fc'       => (float) $row['total_amount_fc'],
                    'allocated_bc'   => (float) $row['allocated_bc'],
                    'allocated_fc'   => (float) $row['allocated_fc'],
                    'remaining_bc'   => round($remaining, 2),
                    'remaining_fc'   => round((float) $row['total_amount_fc'] - (float) $row['allocated_fc'], 2),
                ];
            }
        }

        return $documents;
    }
}
