<?php
namespace App\Services;

use App\Repository\PurchaseOrderRepository;
use App\Repository\PeopleRepository;
use App\Services\ExchangeRateService;
use App\Services\NotificationService;
use App\Support\AuthContext;
use PDO;

class PurchaseOrderService {
    private PurchaseOrderRepository $repo;
    private PeopleRepository        $peopleRepo;
    private ExchangeRateService     $exchangeRateService;
    private AuditService            $audit;
    private ItemService             $itemService;
    private BatchService            $batchService;
    private NotificationService     $notificationService;
    private SettlementEngine        $settlementEngine;
    private PDO                     $db;

    public function __construct(PDO $db) {
        $this->db                  = $db;
        $this->repo                = new PurchaseOrderRepository($db);
        $this->peopleRepo          = new PeopleRepository($db);
        $this->exchangeRateService = new ExchangeRateService($db);
        $this->audit               = new AuditService($db);
        $this->itemService         = new ItemService($db);
        $this->batchService        = new BatchService($db);
        $this->notificationService = new NotificationService($db);
        $this->settlementEngine    = new SettlementEngine($db);
    }

    /**
     * Return all purchase orders for the current business.
     */
    public function list(): array {
        $ctx = AuthContext::get();
        return $this->repo->findAllForBusiness($ctx->business_id);
    }

    /**
     * Return a single purchase order with its lines.
     *
     * @throws \RuntimeException if not found
     */
    public function getById(int $id): array {
        $ctx = AuthContext::get();
        $po  = $this->repo->findById($id, $ctx->business_id);
        if (!$po) {
            throw new \RuntimeException('Purchase order not found');
        }
        return $po;
    }

    /**
     * Create a new purchase order with optional line items.
     * Runs in a transaction: insert header + lines + recalculate totals.
     *
     * Required keys in $data: po_date, currency, exchange_rate
     * Optional keys: supplier_id, po_number, notes
     * $lines is an array of line arrays: item_id, quantity, unit_price_fc, unit_price_bc
     *
     * @throws \InvalidArgumentException if required fields are missing
     */
    public function create(array $data, array $lines = []): int {
        $ctx = AuthContext::get();

        if (empty($data['po_date'] ?? '')) {
            throw new \InvalidArgumentException('PO date is required');
        }
        if (empty(trim($data['currency'] ?? ''))) {
            throw new \InvalidArgumentException('Currency is required');
        }

        // Resolve exchange rate: use provided value or fall back to latest from exchange_rates table
        $providedRate = (!isset($data['exchange_rate']) || $data['exchange_rate'] === '' || $data['exchange_rate'] === null)
            ? null
            : (float) $data['exchange_rate'];
        $resolvedRate = $this->exchangeRateService->resolveRate(trim($data['currency']), $providedRate);

        // Auto-generate PO number if not provided
        $poNumber = trim($data['po_number'] ?? '');
        if ($poNumber === '') {
            $poNumber = $this->repo->generatePoNumber($ctx->business_id);
        }

        $this->db->beginTransaction();
        try {
            $poId = $this->repo->create([
                'business_id'      => $ctx->business_id,
                'supplier_id'      => isset($data['supplier_id']) && $data['supplier_id'] !== '' ? (int) $data['supplier_id'] : null,
                'po_number'        => $poNumber,
                'po_date'          => $data['po_date'],
                'currency'         => trim($data['currency']),
                'exchange_rate'    => $resolvedRate,
                'discount_percent' => isset($data['discount_percent']) ? (float) $data['discount_percent'] : 0,
                'notes'            => $data['notes'] ?? null,
                'created_by'       => $ctx->user_id,
            ]);

            foreach ($lines as $line) {
                $this->repo->addLine($poId, $line);
            }

            $this->repo->recalculateTotals($poId, $this->db);

            $this->audit->log(
                'CREATE',
                'purchase_orders',
                $poId,
                'CREATE',
                null,
                [
                    'po_number'     => $poNumber,
                    'po_date'       => $data['po_date'],
                    'currency'      => trim($data['currency']),
                    'exchange_rate' => $resolvedRate,
                    'lines_count'   => count($lines),
                ],
                $ctx->user_id,
                $ctx->tenant_id,
                $ctx->business_id
            );

            $this->db->commit();

            // Notify users with "purchaseOrders.approve" permission about new PO
            try {
                $recipientIds = $this->resolveRecipientsByPermission('purchaseOrders.approve', $ctx->business_id);
                if (!empty($recipientIds)) {
                    $this->notificationService->notify(
                        'PO_STATUS_CHANGE',
                        'New Purchase Order Created',
                        'Purchase Order ' . $poNumber . ' has been created',
                        $recipientIds,
                        'purchase_orders',
                        $poId,
                        $ctx->user_id
                    );
                }
            } catch (\Throwable $e) {
                error_log("[PurchaseOrderService::create] Notification error: " . $e->getMessage());
            }

            return $poId;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Update an existing purchase order (DRAFT only).
     * Allows header edits and full line replacement.
     *
     * @param int        $id    PO ID
     * @param array      $data  Header data (supplier_id, po_date, currency, exchange_rate, notes)
     * @param array|null $lines If provided, replaces all existing lines
     * @throws \RuntimeException if not found or not DRAFT
     */
    public function update(int $id, array $data, ?array $lines = null): void {
        $ctx = AuthContext::get();
        $po  = $this->repo->findById($id, $ctx->business_id);
        if (!$po) {
            throw new \RuntimeException('Purchase order not found');
        }
        if ($po['status'] !== 'DRAFT') {
            throw new \RuntimeException('Only DRAFT purchase orders can be edited');
        }

        // Resolve exchange rate if currency/rate provided
        $currency = isset($data['currency']) ? trim($data['currency']) : $po['currency'];
        $providedRate = (!isset($data['exchange_rate']) || $data['exchange_rate'] === '' || $data['exchange_rate'] === null)
            ? null
            : (float) $data['exchange_rate'];
        $resolvedRate = $this->exchangeRateService->resolveRate($currency, $providedRate);

        $this->db->beginTransaction();
        try {
            // Update header
            $stmt = $this->db->prepare("
                UPDATE purchase_orders
                SET supplier_id      = :supplier_id,
                    po_number        = :po_number,
                    po_date          = :po_date,
                    currency         = :currency,
                    exchange_rate    = :exchange_rate,
                    discount_percent = :discount_percent,
                    notes            = :notes,
                    updated_at       = NOW()
                WHERE id = :id AND business_id = :business_id AND status = 'DRAFT'
            ");
            $stmt->execute([
                'supplier_id'      => isset($data['supplier_id']) && $data['supplier_id'] !== '' ? (int) $data['supplier_id'] : ($po['supplier_id'] ?? null),
                'po_number'        => $data['po_number'] ?? $po['po_number'],
                'po_date'          => $data['po_date'] ?? $po['po_date'],
                'currency'         => $currency,
                'exchange_rate'    => $resolvedRate,
                'discount_percent' => isset($data['discount_percent']) ? (float) $data['discount_percent'] : ($po['discount_percent'] ?? 0),
                'notes'            => array_key_exists('notes', $data) ? ($data['notes'] ?: null) : $po['notes'],
                'id'               => $id,
                'business_id'      => $ctx->business_id,
            ]);

            // Replace lines if provided
            if ($lines !== null) {
                $this->repo->deleteLines($id);
                foreach ($lines as $line) {
                    $this->repo->addLine($id, $line);
                }
                $this->repo->recalculateTotals($id, $this->db);
            }

            $this->audit->log(
                'UPDATE',
                'purchase_orders',
                $id,
                'UPDATE',
                $po,
                ['po_date' => $data['po_date'] ?? $po['po_date'], 'lines_replaced' => $lines !== null],
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
     * Update the PO number and synchronize batch/stock transactions.
     */
    public function updateNumber(int $id, string $newNumber): void {
        $ctx = AuthContext::get();
        $po  = $this->repo->findById($id, $ctx->business_id);
        if (!$po) {
            throw new \RuntimeException('Purchase order not found');
        }

        $oldNumber = $po['po_number'];
        if ($oldNumber === $newNumber) {
            return; // Nothing to do
        }

        $this->db->beginTransaction();
        try {
            // Update the header
            $this->repo->updatePoNumber($id, $newNumber, $this->db);

            // Sync stock transactions notes
            $stmtStock = $this->db->prepare("
                UPDATE stock_transactions
                SET notes = REPLACE(notes, :oldStr, :newStr)
                WHERE reference_type = 'PURCHASE_ORDER'
                  AND notes LIKE :likeStr
            ");
            $stmtStock->execute([
                'oldStr'  => '#' . $oldNumber,
                'newStr'  => '#' . $newNumber,
                'likeStr' => '%#' . $oldNumber . '%'
            ]);

            // Sync batch transactions notes and reference (if any exist for PO)
            $stmtBatchNotes = $this->db->prepare("
                UPDATE batch_transactions
                SET notes = REPLACE(notes, :oldStr, :newStr)
                WHERE reference = :oldRef
            ");
            $stmtBatchNotes->execute([
                'oldStr' => '#' . $oldNumber,
                'newStr' => '#' . $newNumber,
                'oldRef' => 'PO-' . $oldNumber
            ]);

            $stmtBatchRef = $this->db->prepare("
                UPDATE batch_transactions
                SET reference = :newRef
                WHERE reference = :oldRef
            ");
            $stmtBatchRef->execute([
                'newRef' => 'PO-' . $newNumber,
                'oldRef' => 'PO-' . $oldNumber
            ]);

            $this->audit->log(
                'UPDATE',
                'purchase_orders',
                $id,
                'UPDATE',
                null,
                ['old_number' => $oldNumber, 'new_number' => $newNumber],
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
     * Add a line item to a purchase order.
     * Recalculates totals after adding.
     *
     * Required keys in $data: item_id, quantity, unit_price_fc, unit_price_bc
     *
     * @throws \RuntimeException if PO not found
     */
    public function addLine(int $poId, array $data): int {
        $ctx = AuthContext::get();
        $po  = $this->repo->findById($poId, $ctx->business_id);
        if (!$po) {
            throw new \RuntimeException('Purchase order not found');
        }

        $this->db->beginTransaction();
        try {
            $lineId = $this->repo->addLine($poId, $data);
            $this->repo->recalculateTotals($poId, $this->db);

            $this->audit->log(
                'CREATE',
                'po_details',
                $lineId,
                'CREATE',
                null,
                ['purchase_order_id' => $poId, 'item_id' => $data['item_id'], 'quantity' => $data['quantity']],
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
     * Remove a line item from a purchase order.
     * Recalculates totals after removing.
     *
     * @throws \RuntimeException if PO or line not found
     */
    public function removeLine(int $poId, int $lineId): void {
        $ctx = AuthContext::get();
        $po  = $this->repo->findById($poId, $ctx->business_id);
        if (!$po) {
            throw new \RuntimeException('Purchase order not found');
        }

        $this->db->beginTransaction();
        try {
            $this->repo->removeLine($lineId, $poId);
            $this->repo->recalculateTotals($poId, $this->db);

            $this->audit->log(
                'DELETE',
                'po_details',
                $lineId,
                'DELETE',
                ['purchase_order_id' => $poId, 'line_id' => $lineId],
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
     * Update the status of a purchase order.
     *
     * Valid transitions:
     *   DRAFT      → APPROVED | CANCELLED
     *   APPROVED   → RECEIVED  (triggers stock IN for each line item) | CANCELLED
     *   RECEIVED   → CANCELLED (with guards)
     *
     * RECEIVED is the terminal state. CLOSED is no longer valid.
     *
     * @throws \RuntimeException if PO not found or transition is invalid
     */
    public function updateStatus(int $id, string $newStatus, ?array $batchInfo = null): void {
        $ctx    = AuthContext::get();
        $po     = $this->repo->findById($id, $ctx->business_id);
        if (!$po) {
            throw new \RuntimeException('Purchase order not found');
        }

        $current = $po['status'];

        // CLOSED is no longer a valid status — reject before general transition check
        if ($newStatus === 'CLOSED') {
            throw new \RuntimeException(
                'CLOSED status is no longer valid; RECEIVED is the terminal state.'
            );
        }

        $allowed = [
            'DRAFT'    => ['APPROVED', 'CANCELLED'],
            'APPROVED' => ['RECEIVED', 'CANCELLED'],
            'RECEIVED' => ['CANCELLED'],
        ];

        if (!isset($allowed[$current]) || !in_array($newStatus, $allowed[$current], true)) {
            throw new \RuntimeException(
                'Invalid status transition from ' . $current . ' to ' . $newStatus
            );
        }

        // Pre-validate expiry dates for RECEIVED transition (before starting transaction)
        if ($newStatus === 'RECEIVED') {
            $lines = $this->repo->getLines($id);
            // Validate that all expiry-tracked items have expiry_date set on their lines
            $missingExpiry = [];
            foreach ($lines as $line) {
                if ((int) ($line['has_expiry'] ?? 0) === 1 && empty($line['expiry_date'])) {
                    $missingExpiry[] = $line['item_name'] ?? "Item #{$line['item_id']}";
                }
            }
            if (!empty($missingExpiry)) {
                throw new \RuntimeException(
                    'Expiry date required for items: ' . implode(', ', $missingExpiry) . '. Edit the PO lines to add expiry dates before receiving.'
                );
            }
        }

        $this->db->beginTransaction();
        try {
            // Check payment status before allowing cancellation of RECEIVED PO
            if ($newStatus === 'CANCELLED' && $current === 'RECEIVED') {
                $stmt = $this->db->prepare("
                    SELECT COALESCE(SUM(amount_bc), 0) AS total_paid
                    FROM payment_allocations
                    WHERE reference_type = 'PURCHASE_ORDER'
                      AND reference_id = :po_id
                ");
                $stmt->execute(['po_id' => $id]);
                $totalPaid = (float) $stmt->fetchColumn();

                if ($totalPaid > 0) {
                    throw new \RuntimeException('Cannot cancel: purchase order has associated payments. Reverse payments first.');
                }
            }

            $this->repo->updateStatus($id, $ctx->business_id, $newStatus);

            // RECEIVE: trigger batch creation + stock IN for each line item + update supplier balance
            if ($newStatus === 'RECEIVED') {
                foreach ($lines as $line) {
                    $itemId = (int) $line['item_id'];
                    $hasExpiry = (int) ($line['has_expiry'] ?? 0);

                    // For expiry-tracked items: create batch using the expiry_date from the line
                    if ($hasExpiry === 1 && !empty($line['expiry_date'])) {
                        $this->batchService->createBatch(
                            $itemId,
                            (float) $line['quantity'],
                            $line['expiry_date'],
                            null, // manufacturing_date not tracked on PO lines
                            null, // batch_number auto-generated
                            $id
                        );
                    }

                    // Always adjust stock aggregate (for both expiry and non-expiry items)
                    $this->itemService->adjustStock(
                        $itemId,
                        'IN',
                        (float) $line['quantity'],
                        'PO-' . $po['po_number'],
                        'Stock IN from Purchase Order #' . $po['po_number']
                    );
                }

                // Update item cost prices from PO line prices
                $priceUpdater = new PriceUpdater($this->db);
                $priceUpdater->updatePricesFromPO($lines, $po['po_date'], $id, $po['po_number'], isset($po['supplier_id']) ? (int) $po['supplier_id'] : null);

                // Decrease supplier payee balance (we owe the supplier)
                $supplierId = $po['supplier_id'] ?? null;
                if ($supplierId) {
                    $totalBc = (float) ($po['total_amount_bc'] ?? 0);
                    if ($totalBc > 0) {
                        $this->peopleRepo->updatePayeeBalance((int) $supplierId, -$totalBc);

                        // Auto-allocate any existing unallocated payments to this PO
                        $this->settlementEngine->autoAllocatePOFromPayments($id, (int)$supplierId, $ctx->business_id);
                        
                        // Check if the PO became fully settled due to auto-allocation
                        $settledIds = $this->settlementEngine->evaluateSupplierSettlement((int)$supplierId, $ctx->business_id);
                        if (in_array($id, $settledIds)) {
                            // Needs to be updated manually since we are inside a transaction in PurchaseOrderService
                            $this->repo->updateStatus($id, $ctx->business_id, 'PAID');
                            $newStatus = 'PAID'; // Update the local variable so audit logs correctly
                        }
                    }
                }
            }

            // CANCEL from RECEIVED: reverse stock IN and supplier balance increase
            if ($newStatus === 'CANCELLED' && $current === 'RECEIVED') {
                // Reverse stock IN for each line item
                $lines = $this->repo->getLines($id);
                foreach ($lines as $line) {
                    $this->itemService->adjustStock(
                        (int) $line['item_id'],
                        'OUT',
                        (float) $line['quantity'],
                        'PO-' . $po['po_number'],
                        'Stock OUT from cancelled Purchase Order #' . $po['po_number']
                    );
                }

                // Reverse supplier payee balance
                $supplierId = $po['supplier_id'] ?? null;
                if ($supplierId) {
                    $totalBc = (float) ($po['total_amount_bc'] ?? 0);
                    if ($totalBc > 0) {
                        $this->peopleRepo->updatePayeeBalance((int) $supplierId, $totalBc);
                    }
                }
            }

            $this->audit->log(
                'UPDATE',
                'purchase_orders',
                $id,
                'STATUS_CHANGE',
                ['status' => $current],
                ['status' => $newStatus],
                $ctx->user_id,
                $ctx->tenant_id,
                $ctx->business_id
            );

            $this->db->commit();

            // Notify users with "purchaseOrders.approve" permission about status change
            try {
                $recipientIds = $this->resolveRecipientsByPermission('purchaseOrders.approve', $ctx->business_id);
                if (!empty($recipientIds)) {
                    $this->notificationService->notify(
                        'PO_STATUS_CHANGE',
                        'Purchase Order Status Changed',
                        'Purchase Order ' . ($po['po_number'] ?? '#' . $id) . ' status changed from ' . $current . ' to ' . $newStatus,
                        $recipientIds,
                        'purchase_orders',
                        $id,
                        $ctx->user_id
                    );
                }
            } catch (\Throwable $e) {
                error_log("[PurchaseOrderService::updateStatus] Notification error: " . $e->getMessage());
            }
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Delete a purchase order.
     * Only DRAFT status is allowed.
     * Deletes line items first, then the PO header, within a single transaction.
     *
     * @throws \RuntimeException if not found or not in DRAFT status
     */
    public function delete(int $id): void {
        $ctx = AuthContext::get();
        $po  = $this->repo->findById($id, $ctx->business_id);
        if (!$po) {
            throw new \RuntimeException('Purchase order not found');
        }
        if ($po['status'] !== 'DRAFT') {
            throw new \RuntimeException('Only DRAFT purchase orders can be deleted');
        }

        $this->db->beginTransaction();
        try {
            // Delete line items first to avoid orphaned records
            $this->repo->deleteLines($id);
            // Then delete the PO header
            $this->repo->delete($id, $ctx->business_id);

            $this->audit->log(
                'DELETE',
                'purchase_orders',
                $id,
                'DELETE',
                $po,
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
     * Compute the payment status of a purchase order based on linked payment slips.
     * Subtracts issued debit note totals from the effective payable amount.
     *
     * Returns:
     *   'PAID'           — sum of payment slip amounts >= effective payable
     *   'PARTIALLY_PAID' — sum > 0 but < effective payable
     *   'UNPAID'         — no payments (sum = 0)
     *
     * @throws \RuntimeException if PO not found
     */
    public function computePaymentStatus(int $poId): string {
        $ctx = AuthContext::get();
        $po = $this->repo->findById($poId, $ctx->business_id);
        if (!$po) {
            throw new \RuntimeException('Purchase order not found');
        }

        $totalAmountBc = (float) ($po['total_amount_bc'] ?? 0);

        // Subtract issued debit note totals
        $debitNoteTotalBc = $this->getIssuedDebitNoteTotalBc($poId, $ctx->business_id);
        $effectivePayable = $totalAmountBc - $debitNoteTotalBc;
        if ($effectivePayable < 0) $effectivePayable = 0;

        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount_bc), 0) AS total_paid
            FROM payment_allocations
            WHERE reference_type = 'PURCHASE_ORDER'
              AND reference_id = :po_id
        ");
        $stmt->execute(['po_id' => $poId]);
        $totalPaid = (float) $stmt->fetchColumn();

        if ($totalPaid <= 0) {
            return 'UNPAID';
        }
        if ($effectivePayable <= 0 || $totalPaid >= $effectivePayable) {
            return 'PAID';
        }
        return 'PARTIALLY_PAID';
    }

    /**
     * Compute the remaining amount (in FC) for a purchase order.
     * Returns total_amount_fc minus sum of payment allocations amount_fc minus issued debit note total FC.
     */
    public function computeRemainingAmountFc(int $poId): float {
        $ctx = AuthContext::get();
        $po = $this->repo->findById($poId, $ctx->business_id);
        if (!$po) {
            return 0.0;
        }

        $totalFc = (float) ($po['total_amount_fc'] ?? 0);

        // Subtract issued debit note totals (FC)
        $debitNoteTotalFc = $this->getIssuedDebitNoteTotalFc($poId, $ctx->business_id);
        $effectiveTotalFc = $totalFc - $debitNoteTotalFc;
        if ($effectiveTotalFc < 0) $effectiveTotalFc = 0;

        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount_fc), 0) AS total_paid_fc
            FROM payment_allocations
            WHERE reference_type = 'PURCHASE_ORDER'
              AND reference_id = :po_id
        ");
        $stmt->execute(['po_id' => $poId]);
        $totalPaidFc = (float) $stmt->fetchColumn();

        $remaining = round($effectiveTotalFc - $totalPaidFc, 2);
        return $remaining > 0 ? $remaining : 0.0;
    }

    /**
     * Get sum of issued debit note totals (BC) for a PO.
     */
    private function getIssuedDebitNoteTotalBc(int $poId, int $businessId): float {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(total_amount_bc), 0)
            FROM debit_notes
            WHERE purchase_order_id = :po_id
              AND business_id = :business_id
              AND status = 'ISSUED'
        ");
        $stmt->execute(['po_id' => $poId, 'business_id' => $businessId]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Get sum of issued debit note totals (FC) for a PO.
     */
    private function getIssuedDebitNoteTotalFc(int $poId, int $businessId): float {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(total_amount_fc), 0)
            FROM debit_notes
            WHERE purchase_order_id = :po_id
              AND business_id = :business_id
              AND status = 'ISSUED'
        ");
        $stmt->execute(['po_id' => $poId, 'business_id' => $businessId]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Return pre-filled payment slip data for a RECEIVED purchase order.
     * Accounts for debit notes and existing payments.
     *
     * Only available for POs in RECEIVED status.
     *
     * @throws \RuntimeException if PO not found or not in RECEIVED status
     */
    public function getPaymentShortcutData(int $poId): array {
        $ctx = AuthContext::get();
        $po = $this->repo->findById($poId, $ctx->business_id);
        if (!$po) {
            throw new \RuntimeException('Purchase order not found');
        }
        if ($po['status'] !== 'RECEIVED') {
            throw new \RuntimeException('Payment shortcut is only available for RECEIVED purchase orders.');
        }

        $remainingFc = $this->computeRemainingAmountFc($poId);

        return [
            'supplier_id'    => $po['supplier_id'],
            'payee_id'       => $po['supplier_id'],
            'amount_fc'      => $remainingFc,
            'currency'       => $po['currency'] ?? null,
            'exchange_rate'  => $po['exchange_rate'] ?? null,
            'reference'      => $po['po_number'],
            'reference_type' => 'PURCHASE_ORDER',
            'reference_id'   => $poId,
        ];
    }

    /**
     * Generate a suggested PO number for the current business.
     */
    public function generatePoNumber(): string {
        $ctx = AuthContext::get();
        return $this->repo->generatePoNumber($ctx->business_id);
    }

    /**
     * Duplicate a purchase order: creates a new DRAFT PO with the same header and lines.
     *
     * @param int $sourceId The source PO ID to duplicate
     * @return int The new PO ID
     * @throws \RuntimeException if source PO not found
     */
    public function duplicate(int $sourceId): int {
        $ctx = AuthContext::get();
        $source = $this->repo->findById($sourceId, $ctx->business_id);
        if (!$source) {
            throw new \RuntimeException('Purchase order not found');
        }

        $lines = $source['lines'] ?? [];
        $lineData = array_map(function ($line) {
            return [
                'item_id'          => $line['item_id'],
                'quantity'         => $line['quantity'],
                'unit_price_fc'    => $line['unit_price_fc'],
                'unit_price_bc'    => $line['unit_price_bc'],
                'tax_rate'         => $line['tax_rate'] ?? 0,
                'discount_percent' => $line['discount_percent'] ?? 0,
            ];
        }, $lines);

        return $this->create([
            'supplier_id'   => $source['supplier_id'],
            'po_date'       => date('Y-m-d'),
            'currency'      => $source['currency'],
            'exchange_rate' => $source['exchange_rate'],
            'notes'         => $source['notes'] ? $source['notes'] . ' (duplicated from ' . $source['po_number'] . ')' : 'Duplicated from ' . $source['po_number'],
        ], $lineData);
    }

    /**
     * Resolve all user IDs that hold a specific permission in a given business.
     *
     * Handles the special case where role_id = 1 (Business Owner) has all permissions.
     * For other roles, checks the role_permissions + permissions tables.
     *
     * @param string $permissionCode The permission code (e.g., "purchaseOrders.approve")
     * @param int    $businessId     The business to scope the lookup to
     * @return int[] Array of user IDs holding the permission
     */
    private function resolveRecipientsByPermission(string $permissionCode, int $businessId): array {
        // Users with role_id = 1 (Business Owner) have ALL permissions
        // For other roles, join through role_permissions and permissions tables
        $stmt = $this->db->prepare("
            SELECT DISTINCT ur.user_id
            FROM user_roles ur
            WHERE ur.business_id = :business_id
              AND ur.is_active = 1
              AND (
                  ur.role_id = 1
                  OR ur.role_id IN (
                      SELECT rp.role_id
                      FROM role_permissions rp
                      JOIN permissions p ON p.id = rp.permission_id
                      WHERE p.code = :permission_code
                  )
              )
        ");
        $stmt->execute([
            'business_id'     => $businessId,
            'permission_code' => $permissionCode,
        ]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
