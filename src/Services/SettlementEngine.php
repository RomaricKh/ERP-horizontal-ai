<?php
namespace App\Services;

use PDO;

class SettlementEngine {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Evaluate supplier settlement after a payment slip is created.
     * A PO is considered settled when allocations + debit notes cover the full amount.
     *
     * @param int $payeeId   The supplier's payee ID (payees.id)
     * @param int $businessId The current business ID
     * @return array Array of settled purchase order IDs
     */
    public function evaluateSupplierSettlement(int $payeeId, int $businessId): array {
        // Find all RECEIVED POs for this supplier in this business
        $stmt = $this->db->prepare("
            SELECT po.id, po.total_amount_bc
            FROM purchase_orders po
            WHERE po.supplier_id = :payee_id
              AND po.business_id = :business_id
              AND po.status = 'RECEIVED'
        ");
        $stmt->execute([
            'payee_id'    => $payeeId,
            'business_id' => $businessId,
        ]);
        $pos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $settledIds = [];

        foreach ($pos as $po) {
            $totalAllocated = $this->sumAllocationsForDocument('PURCHASE_ORDER', (int) $po['id']);
            $totalAmount = (float) $po['total_amount_bc'];

            // Subtract issued debit notes from effective total
            $dnStmt = $this->db->prepare("
                SELECT COALESCE(SUM(total_amount_bc), 0)
                FROM debit_notes WHERE purchase_order_id = :po_id AND status = 'ISSUED'
            ");
            $dnStmt->execute(['po_id' => (int) $po['id']]);
            $debitNotesTotal = (float) $dnStmt->fetchColumn();

            $effectiveTotal = $totalAmount - $debitNotesTotal;

            // PO is settled when allocations cover the effective amount
            if ($effectiveTotal <= 0 || $totalAllocated >= $effectiveTotal) {
                $settledIds[] = (int) $po['id'];
            }
        }

        return $settledIds;
    }

    /**
     * Evaluate customer settlement after a collection slip is created.
     * An invoice is PAID when collections + credit notes cover the full amount.
     *
     * @param int $payeeId   The customer's payee ID (payees.id)
     * @param int $businessId The current business ID
     * @return array Array of settled sales invoice IDs (newly PAID)
     */
    public function evaluateCustomerSettlement(int $payeeId, int $businessId): array {
        // Find all DELIVERED invoices for this customer in this business
        $stmt = $this->db->prepare("
            SELECT si.id, si.total_amount_bc
            FROM sales_invoices si
            WHERE si.customer_id = :payee_id
              AND si.business_id = :business_id
              AND si.status = 'DELIVERED'
        ");
        $stmt->execute([
            'payee_id'    => $payeeId,
            'business_id' => $businessId,
        ]);
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $settledIds = [];

        foreach ($invoices as $invoice) {
            $totalAllocated = $this->sumCollectionAllocationsForDocument((int) $invoice['id']);
            $totalAmount = (float) $invoice['total_amount_bc'];

            // Subtract issued credit notes from effective total
            $cnStmt = $this->db->prepare("
                SELECT COALESCE(SUM(total_amount_bc), 0)
                FROM credit_notes WHERE sales_invoice_id = :inv_id AND status = 'ISSUED'
            ");
            $cnStmt->execute(['inv_id' => (int) $invoice['id']]);
            $creditNotesTotal = (float) $cnStmt->fetchColumn();

            $effectiveTotal = $totalAmount - $creditNotesTotal;

            // Invoice is settled when allocations cover the effective amount
            if ($effectiveTotal <= 0 || $totalAllocated >= $effectiveTotal) {
                $settledIds[] = (int) $invoice['id'];
            }
        }

        return $settledIds;
    }

    /**
     * Auto-allocate an amount across unpaid documents for a payee using FIFO (oldest first).
     *
     * For payment slips: allocates across RECEIVED POs, unpaid expenses, unpaid salaries
     * For collection slips: allocates across DELIVERED invoices
     *
     * @param int    $payeeId    The payee ID
     * @param int    $businessId The business ID
     * @param float  $amountBc   The total amount in base currency to allocate
     * @param float  $amountFc   The total amount in foreign currency to allocate
     * @param float  $exchangeRate The exchange rate for FC→BC conversion
     * @param string $slipType   Either 'payment' or 'collection'
     * @return array Array of allocation rows [{reference_type, reference_id, amount_fc, amount_bc}]
     */
    public function autoAllocateFIFO(int $payeeId, int $businessId, float $amountBc, float $amountFc, float $exchangeRate, string $slipType): array {
        $allocations = [];
        $remainingBc = $amountBc;
        $remainingFc = $amountFc;

        if ($slipType === 'collection') {
            $documents = $this->getUnpaidInvoicesForCustomer($payeeId, $businessId);
            foreach ($documents as $doc) {
                if ($remainingBc <= 0) break;

                $docRemaining = (float) $doc['remaining_bc'];
                if ($docRemaining <= 0) continue;

                $allocBc = min($remainingBc, $docRemaining);
                $allocFc = $exchangeRate > 0 ? round($allocBc / $exchangeRate, 2) : $allocBc;

                // Don't exceed remaining FC
                $allocFc = min($allocFc, $remainingFc);

                $allocations[] = [
                    'reference_type' => 'SALES_INVOICE',
                    'reference_id'   => (int) $doc['id'],
                    'amount_fc'      => $allocFc,
                    'amount_bc'      => $allocBc,
                ];

                $remainingBc -= $allocBc;
                $remainingFc -= $allocFc;
            }
        } else {
            // Payment: allocate across POs, expenses, salaries ordered by date
            $documents = $this->getUnpaidDocumentsForSupplier($payeeId, $businessId);
            foreach ($documents as $doc) {
                if ($remainingBc <= 0) break;

                $docRemaining = (float) $doc['remaining_bc'];
                if ($docRemaining <= 0) continue;

                $allocBc = min($remainingBc, $docRemaining);
                $allocFc = $exchangeRate > 0 ? round($allocBc / $exchangeRate, 2) : $allocBc;

                // Don't exceed remaining FC
                $allocFc = min($allocFc, $remainingFc);

                $allocations[] = [
                    'reference_type' => $doc['reference_type'],
                    'reference_id'   => (int) $doc['id'],
                    'amount_fc'      => $allocFc,
                    'amount_bc'      => $allocBc,
                ];

                $remainingBc -= $allocBc;
                $remainingFc -= $allocFc;
            }
        }

        return $allocations;
    }

    /**
     * Get unpaid/partially-paid invoices for a customer, ordered by date ASC (FIFO).
     * Returns each invoice with its remaining balance (accounting for credit notes).
     */
    private function getUnpaidInvoicesForCustomer(int $payeeId, int $businessId): array {
        $stmt = $this->db->prepare("
            SELECT
                si.id,
                si.total_amount_bc,
                si.invoice_date AS doc_date,
                COALESCE(
                    (SELECT SUM(ca.amount_bc) FROM collection_allocations ca WHERE ca.reference_type = 'SALES_INVOICE' AND ca.reference_id = si.id),
                    0
                ) AS allocated_bc,
                COALESCE(
                    (SELECT SUM(cn.total_amount_bc) FROM credit_notes cn WHERE cn.sales_invoice_id = si.id AND cn.status = 'ISSUED'),
                    0
                ) AS credit_notes_bc
            FROM sales_invoices si
            WHERE si.customer_id = :payee_id
              AND si.business_id = :business_id
              AND si.status = 'DELIVERED'
            ORDER BY si.invoice_date ASC, si.id ASC
        ");
        $stmt->execute([
            'payee_id'    => $payeeId,
            'business_id' => $businessId,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $effectiveTotal = (float) $row['total_amount_bc'] - (float) $row['credit_notes_bc'];
            $remaining = $effectiveTotal - (float) $row['allocated_bc'];
            if ($remaining > 0) {
                $row['remaining_bc'] = $remaining;
                $result[] = $row;
            }
        }
        return $result;
    }

    /**
     * Get unpaid/partially-paid documents for a supplier, ordered by date ASC (FIFO).
     * Includes RECEIVED POs, unpaid expenses, and unpaid salaries linked to this payee.
     */
    private function getUnpaidDocumentsForSupplier(int $payeeId, int $businessId): array {
        $documents = [];

        // 1. RECEIVED Purchase Orders (subtract issued debit notes from effective total)
        $stmt = $this->db->prepare("
            SELECT
                po.id,
                po.total_amount_bc,
                po.po_date AS doc_date,
                'PURCHASE_ORDER' AS reference_type,
                COALESCE(
                    (SELECT SUM(pa.amount_bc) FROM payment_allocations pa WHERE pa.reference_type = 'PURCHASE_ORDER' AND pa.reference_id = po.id),
                    0
                ) AS allocated_bc,
                COALESCE(
                    (SELECT SUM(dn.total_amount_bc) FROM debit_notes dn WHERE dn.purchase_order_id = po.id AND dn.status = 'ISSUED'),
                    0
                ) AS debit_notes_bc
            FROM purchase_orders po
            WHERE po.supplier_id = :payee_id
              AND po.business_id = :business_id
              AND po.status = 'RECEIVED'
            ORDER BY po.po_date ASC, po.id ASC
        ");
        $stmt->execute([
            'payee_id'    => $payeeId,
            'business_id' => $businessId,
        ]);
        $poRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($poRows as $row) {
            $effectiveTotal = (float) $row['total_amount_bc'] - (float) $row['debit_notes_bc'];
            $remaining = $effectiveTotal - (float) $row['allocated_bc'];
            if ($remaining > 0) {
                $row['remaining_bc'] = $remaining;
                $documents[] = $row;
            }
        }

        // 2. Unpaid Expenses for this payee
        $stmt = $this->db->prepare("
            SELECT
                e.id,
                e.amount_bc AS total_amount_bc,
                e.expense_date AS doc_date,
                'EXPENSE' AS reference_type,
                COALESCE(
                    (SELECT SUM(pa.amount_bc) FROM payment_allocations pa WHERE pa.reference_type = 'EXPENSE' AND pa.reference_id = e.id),
                    0
                ) AS allocated_bc
            FROM expenses e
            WHERE e.payee_id = :payee_id
              AND e.business_id = :business_id
            ORDER BY e.expense_date ASC, e.id ASC
        ");
        $stmt->execute([
            'payee_id'    => $payeeId,
            'business_id' => $businessId,
        ]);
        $expenseRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($expenseRows as $row) {
            $remaining = (float) $row['total_amount_bc'] - (float) $row['allocated_bc'];
            if ($remaining > 0) {
                $row['remaining_bc'] = $remaining;
                $documents[] = $row;
            }
        }

        // 3. Unpaid Salaries for this payee
        $stmt = $this->db->prepare("
            SELECT
                s.id,
                s.net_amount_bc AS total_amount_bc,
                s.period_start AS doc_date,
                'SALARY' AS reference_type,
                COALESCE(
                    (SELECT SUM(pa.amount_bc) FROM payment_allocations pa WHERE pa.reference_type = 'SALARY' AND pa.reference_id = s.id),
                    0
                ) AS allocated_bc
            FROM salaries s
            WHERE s.payee_id = :payee_id
              AND s.business_id = :business_id
            ORDER BY s.period_start ASC, s.id ASC
        ");
        $stmt->execute([
            'payee_id'    => $payeeId,
            'business_id' => $businessId,
        ]);
        $salaryRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($salaryRows as $row) {
            $remaining = (float) $row['total_amount_bc'] - (float) $row['allocated_bc'];
            if ($remaining > 0) {
                $row['remaining_bc'] = $remaining;
                $documents[] = $row;
            }
        }

        // Sort all documents by date ASC for FIFO
        usort($documents, function ($a, $b) {
            $cmp = strcmp($a['doc_date'], $b['doc_date']);
            if ($cmp === 0) {
                return (int) $a['id'] - (int) $b['id'];
            }
            return $cmp;
        });

        return $documents;
    }

    /**
     * Sum all payment allocations for a given document.
     */
    private function sumAllocationsForDocument(string $referenceType, int $referenceId): float {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount_bc), 0)
            FROM payment_allocations
            WHERE reference_type = :reference_type
              AND reference_id = :reference_id
        ");
        $stmt->execute([
            'reference_type' => $referenceType,
            'reference_id'   => $referenceId,
        ]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Sum all collection allocations for a given invoice.
     */
    private function sumCollectionAllocationsForDocument(int $referenceId): float {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount_bc), 0)
            FROM collection_allocations
            WHERE reference_type = 'SALES_INVOICE'
              AND reference_id = :reference_id
        ");
        $stmt->execute([
            'reference_id' => $referenceId,
        ]);
        return (float) $stmt->fetchColumn();
    }
}
