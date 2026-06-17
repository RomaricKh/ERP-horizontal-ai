<?php
namespace App\Services;

use App\Repository\SalesInvoiceRepository;
use App\Repository\AccountRepository;
use App\Repository\PeopleRepository;
use App\Repository\ServiceRepository;
use App\Services\ExchangeRateService;
use App\Services\NotificationService;
use App\Services\StockValidator;
use App\Support\AuthContext;
use PDO;

class SalesInvoiceService {
    private SalesInvoiceRepository $repo;
    private AuditService           $audit;
    private ItemService            $itemService;
    private AccountRepository      $accountRepo;
    private PeopleRepository       $peopleRepo;
    private ExchangeRateService    $exchangeRateService;
    private StockValidator         $stockValidator;
    private NotificationService    $notificationService;
    private ServiceRepository      $serviceRepo;
    private PDO                    $db;

    public function __construct(PDO $db) {
        $this->db                  = $db;
        $this->repo                = new SalesInvoiceRepository($db);
        $this->audit               = new AuditService($db);
        $this->itemService         = new ItemService($db);
        $this->accountRepo         = new AccountRepository($db);
        $this->peopleRepo          = new PeopleRepository($db);
        $this->exchangeRateService = new ExchangeRateService($db);
        $this->stockValidator      = new StockValidator($db);
        $this->notificationService = new NotificationService($db);
        $this->serviceRepo         = new ServiceRepository($db);
    }

    /**
     * Return all sales invoices for the current business.
     */
    public function list(): array {
        $ctx = AuthContext::get();
        return $this->repo->findAllForBusiness($ctx->business_id);
    }

    /**
     * Return a single sales invoice with its lines.
     *
     * @throws \RuntimeException if not found
     */
    public function getById(int $id): array {
        $ctx     = AuthContext::get();
        $invoice = $this->repo->findById($id, $ctx->business_id);
        if (!$invoice) {
            throw new \RuntimeException('Sales invoice not found');
        }
        return $invoice;
    }

    /**
     * Create a new sales invoice with optional line items.
     * Runs in a transaction: insert header + lines + recalculate totals.
     *
     * Required keys in $data: invoice_date, currency, exchange_rate
     * Optional keys: customer_id, invoice_number, notes
     * $lines is an array of line arrays: item_id, quantity, unit_price_fc, unit_price_bc
     *
     * @throws \InvalidArgumentException if required fields are missing
     */
    public function create(array $data, array $lines = []): int {
        $ctx = AuthContext::get();

        if (empty($data['invoice_date'] ?? '')) {
            throw new \InvalidArgumentException('Invoice date is required');
        }
        if (empty(trim($data['currency'] ?? ''))) {
            throw new \InvalidArgumentException('Currency is required');
        }

        // Resolve exchange rate: use provided value or fall back to latest from exchange_rates table
        $providedRate = (!isset($data['exchange_rate']) || $data['exchange_rate'] === '' || $data['exchange_rate'] === null)
            ? null
            : (float) $data['exchange_rate'];
        $resolvedRate = $this->exchangeRateService->resolveRate(trim($data['currency']), $providedRate);

        // Auto-generate invoice number if not provided
        $invoiceNumber = trim($data['invoice_number'] ?? '');
        if ($invoiceNumber === '') {
            $invoiceNumber = $this->repo->generateInvoiceNumber($ctx->business_id);
        }

        $this->db->beginTransaction();
        try {
            $invoiceId = $this->repo->create([
                'business_id'      => $ctx->business_id,
                'customer_id'      => isset($data['customer_id']) && $data['customer_id'] !== '' ? (int) $data['customer_id'] : null,
                'invoice_number'   => $invoiceNumber,
                'invoice_date'     => $data['invoice_date'],
                'due_date'         => $data['due_date'] ?? null,
                'currency'         => trim($data['currency']),
                'exchange_rate'    => $resolvedRate,
                'discount_percent' => isset($data['discount_percent']) ? (float) $data['discount_percent'] : 0,
                'notes'            => $data['notes'] ?? null,
                'created_by'       => $ctx->user_id,
            ]);

            foreach ($lines as $line) {
                $this->repo->addLine($invoiceId, $line);
            }

            $this->repo->recalculateTotals($invoiceId, $this->db);

            $this->audit->log(
                'CREATE',
                'sales_invoices',
                $invoiceId,
                'CREATE',
                null,
                [
                    'invoice_number' => $invoiceNumber,
                    'invoice_date'   => $data['invoice_date'],
                    'currency'       => trim($data['currency']),
                    'exchange_rate'  => $resolvedRate,
                    'lines_count'    => count($lines),
                ],
                $ctx->user_id,
                $ctx->tenant_id,
                $ctx->business_id
            );

            $this->db->commit();

            // Check if the invoice due date is within 3 days and send notification
            $this->checkDueDateNotification(
                $invoiceId,
                $data['due_date'] ?? $data['invoice_date'],
                $invoiceNumber,
                $ctx->user_id
            );

            return $invoiceId;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Add a line item to an existing sales invoice.
     * Validates stock availability before adding (for item lines only).
     * Validates service existence and active status (for service lines).
     * Recalculates totals after adding.
     *
     * Required keys in $data: quantity, unit_price_fc, unit_price_bc
     * Exactly one of item_id or service_id must be provided (mutually exclusive).
     *
     * @throws \RuntimeException if invoice not found or insufficient stock
     * @throws \InvalidArgumentException if line exclusivity validation fails
     */
    public function addLine(int $invoiceId, array $data): int {
        $ctx     = AuthContext::get();
        $invoice = $this->repo->findById($invoiceId, $ctx->business_id);
        if (!$invoice) {
            throw new \RuntimeException('Sales invoice not found');
        }

        // Validate mutual exclusivity of item_id and service_id
        $hasItemId    = isset($data['item_id']) && $data['item_id'] !== '' && $data['item_id'] !== null;
        $hasServiceId = isset($data['service_id']) && $data['service_id'] !== '' && $data['service_id'] !== null;

        if ($hasItemId && $hasServiceId) {
            throw new \InvalidArgumentException('A line must reference either an item or a service, not both');
        }
        if (!$hasItemId && !$hasServiceId) {
            throw new \InvalidArgumentException('A line must reference either an item or a service');
        }

        if ($hasServiceId) {
            // Validate service exists and is active
            $service = $this->serviceRepo->findById((int) $data['service_id'], $ctx->business_id, $ctx->tenant_id);
            if (!$service) {
                throw new \InvalidArgumentException('Service not found');
            }
            if (empty($service['is_active'])) {
                throw new \InvalidArgumentException('Service is inactive and cannot be invoiced');
            }
            // No stock validation for service lines
        } else {
            // Stock validation: check availability before adding item line
            $this->stockValidator->assertAvailable(
                (int) $data['item_id'],
                (float) $data['quantity'],
                $ctx->business_id
            );
        }

        $this->db->beginTransaction();
        try {
            $lineId = $this->repo->addLine($invoiceId, $data);
            $this->repo->recalculateTotals($invoiceId, $this->db);

            $auditData = ['sales_invoice_id' => $invoiceId, 'quantity' => $data['quantity']];
            if ($hasItemId) {
                $auditData['item_id'] = $data['item_id'];
            } else {
                $auditData['service_id'] = $data['service_id'];
            }

            $this->audit->log(
                'CREATE',
                'si_details',
                $lineId,
                'CREATE',
                null,
                $auditData,
                $ctx->user_id,
                $ctx->tenant_id,
                $ctx->business_id
            );

            $this->db->commit();
            return $lineId;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Update a line item on an existing sales invoice.
     * Validates stock availability before updating (for item lines only).
     * Validates service existence and active status (for service lines).
     * Recalculates totals after updating.
     *
     * Required keys in $data: quantity, unit_price_fc, unit_price_bc
     * Exactly one of item_id or service_id must be provided (mutually exclusive).
     *
     * @throws \RuntimeException if invoice not found or insufficient stock
     * @throws \InvalidArgumentException if line exclusivity validation fails
     */
    public function updateLine(int $invoiceId, int $lineId, array $data): void {
        $ctx     = AuthContext::get();
        $invoice = $this->repo->findById($invoiceId, $ctx->business_id);
        if (!$invoice) {
            throw new \RuntimeException('Sales invoice not found');
        }

        // Validate mutual exclusivity of item_id and service_id
        $hasItemId    = isset($data['item_id']) && $data['item_id'] !== '' && $data['item_id'] !== null;
        $hasServiceId = isset($data['service_id']) && $data['service_id'] !== '' && $data['service_id'] !== null;

        if ($hasItemId && $hasServiceId) {
            throw new \InvalidArgumentException('A line must reference either an item or a service, not both');
        }
        if (!$hasItemId && !$hasServiceId) {
            throw new \InvalidArgumentException('A line must reference either an item or a service');
        }

        if ($hasServiceId) {
            // Validate service exists and is active
            $service = $this->serviceRepo->findById((int) $data['service_id'], $ctx->business_id, $ctx->tenant_id);
            if (!$service) {
                throw new \InvalidArgumentException('Service not found');
            }
            if (empty($service['is_active'])) {
                throw new \InvalidArgumentException('Service is inactive and cannot be invoiced');
            }
            // No stock validation for service lines
        } else {
            // Stock validation: check availability before updating item line
            $this->stockValidator->assertAvailable(
                (int) $data['item_id'],
                (float) $data['quantity'],
                $ctx->business_id
            );
        }

        $this->db->beginTransaction();
        try {
            // Remove old line and add new one (simple update pattern)
            $this->repo->removeLine($lineId, $invoiceId);
            $this->repo->addLine($invoiceId, $data);
            $this->repo->recalculateTotals($invoiceId, $this->db);

            $auditData = ['sales_invoice_id' => $invoiceId, 'quantity' => $data['quantity']];
            if ($hasItemId) {
                $auditData['item_id'] = $data['item_id'];
            } else {
                $auditData['service_id'] = $data['service_id'];
            }

            $this->audit->log(
                'UPDATE',
                'si_details',
                $lineId,
                'UPDATE',
                null,
                $auditData,
                $ctx->user_id,
                $ctx->tenant_id,
                $ctx->business_id
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Remove a line item from a sales invoice.
     * Recalculates totals after removing.
     *
     * @throws \RuntimeException if invoice or line not found
     */
    public function removeLine(int $invoiceId, int $lineId): void {
        $ctx     = AuthContext::get();
        $invoice = $this->repo->findById($invoiceId, $ctx->business_id);
        if (!$invoice) {
            throw new \RuntimeException('Sales invoice not found');
        }

        $this->db->beginTransaction();
        try {
            $this->repo->removeLine($lineId, $invoiceId);
            $this->repo->recalculateTotals($invoiceId, $this->db);

            $this->audit->log(
                'DELETE',
                'si_details',
                $lineId,
                'DELETE',
                ['sales_invoice_id' => $invoiceId, 'line_id' => $lineId],
                null,
                $ctx->user_id,
                $ctx->tenant_id,
                $ctx->business_id
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Update the status of a sales invoice.
     *
     * Valid transitions:
     *   DRAFT     → DELIVERED | CANCELLED
     *   DELIVERED → CANCELLED (with guards — must be UNPAID)
     *
     * APPROVED and PAID are no longer valid manual statuses.
     * Payment status is computed from collection slips.
     *
     * When transitioning to DELIVERED:
     *   - Stock OUT for each line item via ItemService
     *   - Increase customer payee balance by total_amount_bc
     *
     * When cancelling a DELIVERED invoice (UNPAID only):
     *   - Reverse stock OUT (increase stock by line quantities)
     *   - Reverse customer payee balance increase
     *
     * @param int    $id
     * @param string $newStatus
     * @param array  $extra     Optional extra data (unused in new flow)
     *
     * @throws \RuntimeException if invoice not found or transition is invalid
     */
    public function updateStatus(int $id, string $newStatus, array $extra = []): void {
        $ctx     = AuthContext::get();
        $invoice = $this->repo->findById($id, $ctx->business_id);
        if (!$invoice) {
            throw new \RuntimeException('Sales invoice not found');
        }

        $current = $invoice['status'];

        // Reject PAID as a manual target — payment status is computed
        if ($newStatus === 'PAID') {
            throw new \RuntimeException('Payment status is computed from collection slips and cannot be set manually.');
        }

        // Reject APPROVED — no longer a valid status
        if ($newStatus === 'APPROVED') {
            throw new \RuntimeException(
                'Invalid status transition from ' . $current . ' to ' . $newStatus
            );
        }

        $allowed = [
            'DRAFT'     => ['DELIVERED', 'CANCELLED'],
            'DELIVERED' => ['CANCELLED'],
        ];

        if (!isset($allowed[$current]) || !in_array($newStatus, $allowed[$current], true)) {
            throw new \RuntimeException(
                'Invalid status transition from ' . $current . ' to ' . $newStatus
            );
        }

        $this->db->beginTransaction();
        try {
            // Cancellation guard for DELIVERED invoices
            if ($newStatus === 'CANCELLED' && $current === 'DELIVERED') {
                $paymentStatus = $this->computePaymentStatusInternal($id, $ctx->business_id);
                if ($paymentStatus === 'PARTIALLY_PAID' || $paymentStatus === 'PAID') {
                    throw new \RuntimeException('Cannot cancel: invoice has associated collections. Reverse collections first.');
                }
            }

            $this->repo->updateStatus($id, $ctx->business_id, $newStatus);

            // DELIVERED: validate stock sufficiency for each item line, then trigger stock OUT + increase customer balance
            if ($newStatus === 'DELIVERED') {
                $lines = $this->repo->getLines($id);

                // Pre-validate stock for all item lines before any deductions
                foreach ($lines as $line) {
                    if (!empty($line['service_id']) || empty($line['item_id'])) {
                        continue;
                    }
                    $this->stockValidator->assertSufficientStock(
                        (int) $line['item_id'],
                        $ctx->business_id,
                        (float) $line['quantity']
                    );
                }

                foreach ($lines as $line) {
                    // Skip stock OUT for service lines (no stock tracking)
                    if (!empty($line['service_id']) || empty($line['item_id'])) {
                        continue;
                    }
                    $this->itemService->adjustStock(
                        (int) $line['item_id'],
                        'OUT',
                        (float) $line['quantity'],
                        'INV-' . $invoice['invoice_number'],
                        'Stock OUT from Sales Invoice #' . $invoice['invoice_number']
                    );
                }

                // Increase customer payee balance (customer now owes for delivered goods)
                $customerId = $invoice['customer_id'] ?? null;
                if ($customerId) {
                    $totalBc = (float) ($invoice['total_amount_bc'] ?? 0);
                    if ($totalBc > 0) {
                        $this->peopleRepo->updatePayeeBalance((int) $customerId, $totalBc);
                    }
                }
            }

            // CANCEL from DELIVERED (UNPAID): reverse stock OUT and customer balance
            if ($newStatus === 'CANCELLED' && $current === 'DELIVERED') {
                // Reverse stock OUT for each item line (increase stock) — skip service lines
                $lines = $this->repo->getLines($id);
                foreach ($lines as $line) {
                    // Skip stock IN reversal for service lines (no stock tracking)
                    if (!empty($line['service_id']) || empty($line['item_id'])) {
                        continue;
                    }
                    $this->itemService->adjustStock(
                        (int) $line['item_id'],
                        'IN',
                        (float) $line['quantity'],
                        'INV-' . $invoice['invoice_number'],
                        'Stock IN from cancelled Sales Invoice #' . $invoice['invoice_number']
                    );
                }

                // Reverse customer payee balance increase
                $customerId = $invoice['customer_id'] ?? null;
                if ($customerId) {
                    $totalBc = (float) ($invoice['total_amount_bc'] ?? 0);
                    if ($totalBc > 0) {
                        $this->peopleRepo->updatePayeeBalance((int) $customerId, -$totalBc);
                    }
                }
            }

            $this->audit->log(
                'UPDATE',
                'sales_invoices',
                $id,
                'STATUS_CHANGE',
                ['status' => $current],
                ['status' => $newStatus],
                $ctx->user_id,
                $ctx->tenant_id,
                $ctx->business_id
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Compute the payment status of a sales invoice based on linked collection slips.
     *
     * Returns:
     *   'PAID'           — sum of collection slip amounts >= invoice total_amount_bc
     *   'PARTIALLY_PAID' — sum > 0 but < total_amount_bc
     *   'UNPAID'         — no collections (sum = 0)
     *
     * @throws \RuntimeException if invoice not found
     */
    public function computePaymentStatus(int $invoiceId): string {
        $ctx = AuthContext::get();
        $invoice = $this->repo->findById($invoiceId, $ctx->business_id);
        if (!$invoice) {
            throw new \RuntimeException('Sales invoice not found');
        }

        return $this->computePaymentStatusInternal($invoiceId, $ctx->business_id);
    }

    /**
     * Internal method to compute payment status without requiring AuthContext.
     * Used by updateStatus within the same transaction.
     */
    private function computePaymentStatusInternal(int $invoiceId, int $businessId): string {
        // Get the invoice total and customer_id
        $stmtInv = $this->db->prepare("
            SELECT total_amount_bc, customer_id FROM sales_invoices
            WHERE id = :invoice_id AND business_id = :business_id
        ");
        $stmtInv->execute(['invoice_id' => $invoiceId, 'business_id' => $businessId]);
        $invRow = $stmtInv->fetch(\PDO::FETCH_ASSOC);
        if (!$invRow) {
            return 'UNPAID';
        }
        $totalAmountBc = (float) $invRow['total_amount_bc'];
        $customerId    = $invRow['customer_id'] ?? null;

        // Sum collection allocations directly referencing this invoice
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(ca.amount_bc), 0) AS total_collected
            FROM collection_allocations ca
            JOIN collection_slips cs ON cs.id = ca.collection_slip_id
            WHERE ca.reference_type = 'SALES_INVOICE'
              AND ca.reference_id = :invoice_id
              AND cs.business_id = :business_id
        ");
        $stmt->execute(['invoice_id' => $invoiceId, 'business_id' => $businessId]);
        $directCollected = (float) $stmt->fetchColumn();

        if ($directCollected >= $totalAmountBc) {
            return 'PAID';
        }

        // Check for FIFO allocation from unlinked CUSTOMER-type collections
        if ($customerId) {
            $allocated = $this->computeFifoAllocation($invoiceId, $customerId, $businessId);
            $totalCovered = $directCollected + $allocated;

            if ($totalCovered >= $totalAmountBc) {
                return 'PAID';
            }
            if ($totalCovered > 0) {
                return 'PARTIALLY_PAID';
            }
        }

        if ($directCollected > 0) {
            return 'PARTIALLY_PAID';
        }

        return 'UNPAID';
    }

    /**
     * Compute how much of the unlinked (CUSTOMER-type) collections are allocated
     * to a specific invoice using FIFO (oldest invoice first).
     */
    private function computeFifoAllocation(int $targetInvoiceId, int $customerId, int $businessId): float {
        // Get total unlinked collections for this customer (slip amount not yet allocated to invoices)
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(cs.amount_bc - COALESCE(alloc.allocated_bc, 0)), 0) AS total_unlinked
            FROM collection_slips cs
            LEFT JOIN (
                SELECT collection_slip_id, SUM(amount_bc) AS allocated_bc
                FROM collection_allocations
                GROUP BY collection_slip_id
            ) alloc ON alloc.collection_slip_id = cs.id
            WHERE cs.payee_id = :customer_id
              AND cs.business_id = :business_id
              AND (cs.amount_bc - COALESCE(alloc.allocated_bc, 0)) > 0
        ");
        $stmt->execute(['customer_id' => $customerId, 'business_id' => $businessId]);
        $totalUnlinked = (float) $stmt->fetchColumn();

        if ($totalUnlinked <= 0) {
            return 0.0;
        }

        // Get all DELIVERED invoices for this customer, ordered by date (FIFO)
        $invStmt = $this->db->prepare("
            SELECT si.id, si.total_amount_bc
            FROM sales_invoices si
            WHERE si.customer_id = :customer_id
              AND si.business_id = :business_id
              AND si.status = 'DELIVERED'
            ORDER BY si.invoice_date ASC, si.id ASC
        ");
        $invStmt->execute(['customer_id' => $customerId, 'business_id' => $businessId]);
        $invoices = $invStmt->fetchAll(\PDO::FETCH_ASSOC);

        // For each invoice, compute how much is still needed after direct collections
        $pool = $totalUnlinked;
        foreach ($invoices as $inv) {
            if ($pool <= 0) break;

            $invId = (int) $inv['id'];
            $invTotal = (float) $inv['total_amount_bc'];

            // Get direct collection allocations for this invoice
            $dcStmt = $this->db->prepare("
                SELECT COALESCE(SUM(ca.amount_bc), 0)
                FROM collection_allocations ca
                JOIN collection_slips cs ON cs.id = ca.collection_slip_id
                WHERE ca.reference_type = 'SALES_INVOICE'
                  AND ca.reference_id = :inv_id
                  AND cs.business_id = :business_id
            ");
            $dcStmt->execute(['inv_id' => $invId, 'business_id' => $businessId]);
            $directForThis = (float) $dcStmt->fetchColumn();

            $remaining = $invTotal - $directForThis;
            if ($remaining <= 0) continue; // Already fully paid by direct collections

            // Allocate from pool
            $allocation = min($pool, $remaining);
            $pool -= $allocation;

            if ($invId === $targetInvoiceId) {
                return $allocation;
            }
        }

        return 0.0;
    }

    /**
     * Compute the remaining amount (in FC) for a sales invoice.
     * Considers both direct collection slips AND FIFO allocation from CUSTOMER-type collections.
     */
    public function computeRemainingAmountFc(int $invoiceId): float {
        $ctx = AuthContext::get();
        $invoice = $this->repo->findById($invoiceId, $ctx->business_id);
        if (!$invoice) {
            return 0.0;
        }

        $totalFc = (float) ($invoice['total_amount_fc'] ?? 0);
        $totalBc = (float) ($invoice['total_amount_bc'] ?? 0);

        // Direct collections against this invoice (in FC)
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(ca.amount_fc), 0) AS total_collected_fc
            FROM collection_allocations ca
            JOIN collection_slips cs ON cs.id = ca.collection_slip_id
            WHERE ca.reference_type = 'SALES_INVOICE'
              AND ca.reference_id = :invoice_id
              AND cs.business_id = :business_id
        ");
        $stmt->execute(['invoice_id' => $invoiceId, 'business_id' => $ctx->business_id]);
        $directCollectedFc = (float) $stmt->fetchColumn();

        if ($directCollectedFc >= $totalFc) {
            return 0.0;
        }

        // Check FIFO allocation from unlinked collections
        $customerId = $invoice['customer_id'] ?? null;
        $fifoAllocBc = 0.0;
        if ($customerId) {
            $fifoAllocBc = $this->computeFifoAllocation($invoiceId, (int) $customerId, $ctx->business_id);
        }

        // Convert FIFO allocation from BC to FC using the invoice's exchange rate
        $exchangeRate = (float) ($invoice['exchange_rate'] ?? 1);
        $fifoAllocFc = $exchangeRate > 0 ? round($fifoAllocBc / $exchangeRate, 2) : 0.0;

        $totalPaidFc = $directCollectedFc + $fifoAllocFc;
        $remaining = round($totalFc - $totalPaidFc, 2);
        return $remaining > 0 ? $remaining : 0.0;
    }

    /**
     * Delete a sales invoice.
     * Only DRAFT status is allowed.
     * Deletes line items first, then the invoice header, within a single transaction.
     *
     * @throws \RuntimeException if not found or not in DRAFT status
     */
    public function delete(int $id): void {
        $ctx     = AuthContext::get();
        $invoice = $this->repo->findById($id, $ctx->business_id);
        if (!$invoice) {
            throw new \RuntimeException('Sales invoice not found');
        }
        if ($invoice['status'] !== 'DRAFT') {
            throw new \RuntimeException('Only DRAFT sales invoices can be deleted.');
        }

        $this->db->beginTransaction();
        try {
            // Delete line items first to avoid orphaned records
            $this->repo->deleteLines($id);
            // Then delete the invoice header
            $this->repo->delete($id, $ctx->business_id);

            $this->audit->log(
                'DELETE',
                'sales_invoices',
                $id,
                'DELETE',
                $invoice,
                null,
                $ctx->user_id,
                $ctx->tenant_id,
                $ctx->business_id
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Generate a suggested invoice number for the current business.
     */
    public function generateInvoiceNumber(): string {
        $ctx = AuthContext::get();
        return $this->repo->generateInvoiceNumber($ctx->business_id);
    }

    /**
     * Duplicate an existing sales invoice.
     * Creates a new DRAFT invoice with the same header data and lines, today's date, and a new number.
     *
     * @param int $sourceId The ID of the invoice to duplicate
     * @return int The new invoice ID
     * @throws \RuntimeException if the source invoice is not found
     */
    public function duplicate(int $sourceId): int {
        $source = $this->getById($sourceId);
        $lines  = $source['lines'] ?? [];

        $duplicateLines = [];
        foreach ($lines as $line) {
            $duplicateLines[] = [
                'item_id'          => $line['item_id'] ?? null,
                'service_id'       => $line['service_id'] ?? null,
                'quantity'         => $line['quantity'],
                'unit_price_fc'    => $line['unit_price_fc'],
                'unit_price_bc'    => $line['unit_price_bc'],
                'tax_rate'         => $line['tax_rate'] ?? 0,
                'discount_percent' => $line['discount_percent'] ?? 0,
            ];
        }

        $newData = [
            'customer_id'      => $source['customer_id'] ?? null,
            'invoice_date'     => date('Y-m-d'),
            'due_date'         => null,
            'currency'         => $source['currency'],
            'exchange_rate'    => $source['exchange_rate'],
            'discount_percent' => $source['discount_percent'] ?? 0,
            'notes'            => ($source['notes'] ?? '') . ' (duplicated)',
        ];

        return $this->create($newData, $duplicateLines);
    }

    /**
     * Check if a single invoice's due date is within 3 days (inclusive) of the current date.
     * If so, send an INVOICE_DUE notification to the invoice creator.
     *
     * @param int    $invoiceId     The invoice ID
     * @param string $dueDate       The due date (Y-m-d format)
     * @param string $invoiceNumber The invoice number for the notification message
     * @param int    $creatorId     The user ID of the invoice creator
     */
    public function checkDueDateNotification(int $invoiceId, string $dueDate, string $invoiceNumber, int $creatorId): void {
        if (!$this->isWithinDueDateWindow($dueDate)) {
            return;
        }

        $this->notificationService->notify(
            'INVOICE_DUE',
            'Invoice Due Soon',
            'Sales Invoice ' . $invoiceNumber . ' is due on ' . $dueDate,
            $creatorId,
            'sales_invoices',
            $invoiceId
        );
    }

    /**
     * Check all invoices in the current business for upcoming due dates
     * and send INVOICE_DUE notifications for those within 3 days.
     * Intended to be called by a scheduled job or manual trigger.
     */
    public function checkDueDateNotifications(): void {
        $ctx = AuthContext::get();
        $today = date('Y-m-d');
        $threeDaysFromNow = date('Y-m-d', strtotime('+3 days'));

        // Find invoices with due dates (invoice_date) within the 3-day window
        // that are not cancelled and not yet fully paid
        $stmt = $this->db->prepare("
            SELECT id, invoice_number, invoice_date, created_by
            FROM sales_invoices
            WHERE business_id = :business_id
              AND status != 'CANCELLED'
              AND invoice_date >= :today
              AND invoice_date <= :three_days
        ");
        $stmt->execute([
            'business_id' => $ctx->business_id,
            'today'       => $today,
            'three_days'  => $threeDaysFromNow,
        ]);

        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($invoices as $invoice) {
            if ($invoice['created_by']) {
                $this->notificationService->notify(
                    'INVOICE_DUE',
                    'Invoice Due Soon',
                    'Sales Invoice ' . $invoice['invoice_number'] . ' is due on ' . $invoice['invoice_date'],
                    (int) $invoice['created_by'],
                    'sales_invoices',
                    (int) $invoice['id']
                );
            }
        }
    }

    /**
     * Determine if a due date is within 3 days (inclusive) of the current date.
     * The due date must be today or up to 3 days in the future.
     * Past due dates do not trigger notifications.
     *
     * @param string $dueDate The due date in Y-m-d format
     * @return bool True if the due date is within the 3-day window
     */
    public function isWithinDueDateWindow(string $dueDate): bool {
        $today = new \DateTime('today');
        $due = new \DateTime($dueDate);
        $due->setTime(0, 0, 0);

        // Due date must not be in the past
        if ($due < $today) {
            return false;
        }

        // Due date must be within 3 days (inclusive)
        $diff = $today->diff($due)->days;
        return $diff <= 3;
    }
}
