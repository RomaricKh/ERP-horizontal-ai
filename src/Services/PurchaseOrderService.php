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
                'business_id'   => $ctx->business_id,
                'supplier_id'   => isset($data['supplier_id']) && $data['supplier_id'] !== '' ? (int) $data['supplier_id'] : null,
                'po_number'     => $poNumber,
                'po_date'       => $data['po_date'],
                'currency'      => trim($data['currency']),
                'exchange_rate' => $resolvedRate,
                'notes'         => $data['notes'] ?? null,
                'created_by'    => $ctx->user_id,
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

        // Pre-validate batch_info for RECEIVED transition (before starting transaction)
        $batchInfoByItemId = [];
        if ($newStatus === 'RECEIVED') {
            $lines = $this->repo->getLines($id);
            $batchInfoByItemId = $this->validateBatchInfoForReceive($lines, $batchInfo);
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

                    // For expiry-tracked items: create batch before adjusting stock
                    if ($hasExpiry === 1 && isset($batchInfoByItemId[$itemId])) {
                        $info = $batchInfoByItemId[$itemId];
                        $this->batchService->createBatch(
                            $itemId,
                            (float) $line['quantity'],
                            $info['expiry_date'],
                            $info['manufacturing_date'] ?? null,
                            $info['batch_number'] ?? null,
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
     * Validate batch_info for PO receive transition.
     *
     * Checks that all expiry-tracked line items have matching batch_info entries
     * with valid expiry_date format. Ignores batch_info for non-expiry items.
     * Accepts past expiry dates without rejection (Requirement 10.6).
     *
     * @param array      $lines     PO line items (with has_expiry field)
     * @param array|null $batchInfo Batch info array from request payload
     * @return array     Indexed by item_id for quick lookup during processing
     * @throws \RuntimeException if validation fails (maps to HTTP 422 in controller)
     */
    private function validateBatchInfoForReceive(array $lines, ?array $batchInfo): array {
        // Index batch_info by item_id for quick lookup
        $batchInfoByItemId = [];
        if (is_array($batchInfo)) {
            foreach ($batchInfo as $entry) {
                if (isset($entry['item_id'])) {
                    $batchInfoByItemId[(int) $entry['item_id']] = $entry;
                }
            }
        }

        // Collect expiry-tracked items missing batch_info or with invalid dates
        $missingItems = [];
        $invalidDates = [];

        foreach ($lines as $line) {
            $hasExpiry = (int) ($line['has_expiry'] ?? 0);
            if ($hasExpiry !== 1) {
                continue; // Non-expiry items: ignore
            }

            $itemId = (int) $line['item_id'];
            $itemName = $line['item_name'] ?? "Item #{$itemId}";

            // Check if batch_info entry exists for this expiry-tracked item
            if (!isset($batchInfoByItemId[$itemId])) {
                $missingItems[] = $itemName;
                continue;
            }

            // Validate expiry_date format (YYYY-MM-DD)
            $entry = $batchInfoByItemId[$itemId];
            $expiryDate = $entry['expiry_date'] ?? null;

            if (!$this->isValidDateFormat($expiryDate)) {
                $invalidDates[] = $itemName;
            }
        }

        // Reject if any expiry-tracked items are missing batch_info
        if (!empty($missingItems)) {
            throw new \RuntimeException(
                'Batch information required for items: ' . implode(', ', $missingItems)
            );
        }

        // Reject if any expiry_date values are invalid
        if (!empty($invalidDates)) {
            throw new \RuntimeException(
                'Invalid expiry_date for item ' . implode(', ', $invalidDates) . ': must be YYYY-MM-DD'
            );
        }

        return $batchInfoByItemId;
    }

    /**
     * Validate that a date string matches YYYY-MM-DD format and is a valid calendar date.
     *
     * @param mixed $date
     * @return bool
     */
    private function isValidDateFormat($date): bool {
        if (!is_string($date) || $date === '') {
            return false;
        }

        // Check format with regex
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }

        // Validate it's a real calendar date
        $parts = explode('-', $date);
        $year  = (int) $parts[0];
        $month = (int) $parts[1];
        $day   = (int) $parts[2];

        return checkdate($month, $day, $year);
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
     *
     * Returns:
     *   'PAID'           — sum of payment slip amounts >= PO total_amount_bc
     *   'PARTIALLY_PAID' — sum > 0 but < total_amount_bc
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
        if ($totalPaid >= $totalAmountBc) {
            return 'PAID';
        }
        return 'PARTIALLY_PAID';
    }

    /**
     * Compute the remaining amount (in FC) for a purchase order.
     * Returns total_amount_fc minus sum of payment allocations amount_fc.
     */
    public function computeRemainingAmountFc(int $poId): float {
        $ctx = AuthContext::get();
        $po = $this->repo->findById($poId, $ctx->business_id);
        if (!$po) {
            return 0.0;
        }

        $totalFc = (float) ($po['total_amount_fc'] ?? 0);

        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount_fc), 0) AS total_paid_fc
            FROM payment_allocations
            WHERE reference_type = 'PURCHASE_ORDER'
              AND reference_id = :po_id
        ");
        $stmt->execute(['po_id' => $poId]);
        $totalPaidFc = (float) $stmt->fetchColumn();

        $remaining = round($totalFc - $totalPaidFc, 2);
        return $remaining > 0 ? $remaining : 0.0;
    }

    /**
     * Return pre-filled payment slip data for a RECEIVED purchase order.
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

        // Calculate remaining amount (total - already paid via allocations)
        $totalFc = (float) ($po['total_amount_fc'] ?? 0);
        $exchangeRate = (float) ($po['exchange_rate'] ?? 1);

        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount_fc), 0) AS total_paid_fc
            FROM payment_allocations
            WHERE reference_type = 'PURCHASE_ORDER'
              AND reference_id = :po_id
        ");
        $stmt->execute(['po_id' => $poId]);
        $totalPaidFc = (float) $stmt->fetchColumn();

        $remainingFc = round($totalFc - $totalPaidFc, 2);
        if ($remainingFc < 0) {
            $remainingFc = 0;
        }

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
