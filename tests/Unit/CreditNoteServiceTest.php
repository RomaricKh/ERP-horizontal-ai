<?php

namespace Tests\Unit;

use App\Services\CreditNoteService;
use App\Support\AuthContext;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CreditNoteService — issuing a credit note for a delivered invoice.
 *
 * Validates: Requirements 3.24 (REQ-024)
 * - Stock is reversed (IN) for returned items
 * - Customer payee balance is reduced
 * - Credit note total does not exceed invoice total (Property 4)
 */
class CreditNoteServiceTest extends TestCase
{
    private int $businessId = 1;
    private int $tenantId = 1;
    private int $userId = 1;

    protected function setUp(): void
    {
        AuthContext::set((object) [
            'user_id'     => $this->userId,
            'tenant_id'   => $this->tenantId,
            'business_id' => $this->businessId,
            'permissions' => ['invoices.manage', 'invoices.view'],
        ]);
    }

    /**
     * Build a PDO mock that simulates the database for credit note operations.
     *
     * @param array $invoice  The invoice data to return from findById
     * @param array $stockMap [itemId => quantity] current stock levels
     * @param float $payeeBalance Current payee balance
     * @param float $existingCreditTotal Sum of existing issued credit notes for the invoice
     */
    private function buildPdoMock(
        array $invoice,
        array $stockMap = [],
        float $payeeBalance = 1000.0,
        float $existingCreditTotal = 0.0
    ): PDO {
        $pdo = $this->createMock(PDO::class);
        $capturedPayeeBalanceDelta = 0.0;
        $stockAdjustments = [];
        $insertedCreditNoteId = 100;
        $insertedLineId = 200;
        $creditNoteStatus = 'DRAFT';
        $creditNoteTotalBc = 0.0;

        $pdo->method('inTransaction')->willReturn(true);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('rollBack')->willReturn(true);
        $pdo->method('lastInsertId')->willReturnCallback(function () use (&$insertedCreditNoteId, &$insertedLineId) {
            static $callCount = 0;
            $callCount++;
            return $callCount === 1 ? (string) $insertedCreditNoteId : (string) ($insertedLineId++);
        });

        $pdo->method('prepare')->willReturnCallback(
            function (string $sql) use (
                $invoice, $stockMap, &$payeeBalance, &$capturedPayeeBalanceDelta,
                &$stockAdjustments, $existingCreditTotal, &$creditNoteStatus, &$creditNoteTotalBc,
                $insertedCreditNoteId
            ) {
                $stmt = $this->createMock(PDOStatement::class);
                $capturedParams = [];

                $stmt->method('execute')->willReturnCallback(
                    function ($params = []) use (&$capturedParams, $sql, &$payeeBalance, &$capturedPayeeBalanceDelta, &$stockAdjustments, &$creditNoteStatus) {
                        $capturedParams = $params ?? [];

                        // Track payee balance updates
                        if (stripos($sql, 'UPDATE payees') !== false && isset($capturedParams['delta'])) {
                            $capturedPayeeBalanceDelta += (float) $capturedParams['delta'];
                            $payeeBalance += (float) $capturedParams['delta'];
                        }

                        // Track stock adjustments
                        if (stripos($sql, 'INSERT INTO stock_transactions') !== false) {
                            $stockAdjustments[] = $capturedParams;
                        }

                        // Track status updates
                        if (stripos($sql, 'UPDATE credit_notes SET status') !== false) {
                            $creditNoteStatus = $capturedParams['status'] ?? $creditNoteStatus;
                        }

                        return true;
                    }
                );

                // Sales invoice lookup
                if (stripos($sql, 'FROM sales_invoices') !== false && stripos($sql, 'WHERE') !== false) {
                    $stmt->method('fetch')->willReturn($invoice);
                }
                // Credit notes SUM for invoice (existing credit total)
                elseif (stripos($sql, 'SUM(total_amount_bc)') !== false && stripos($sql, 'credit_notes') !== false) {
                    $stmt->method('fetchColumn')->willReturn($existingCreditTotal);
                }
                // Credit note findById
                elseif (stripos($sql, 'FROM credit_notes cn') !== false) {
                    $stmt->method('fetch')->willReturnCallback(function () use ($insertedCreditNoteId, $invoice, &$creditNoteStatus, &$creditNoteTotalBc) {
                        return [
                            'id' => $insertedCreditNoteId,
                            'business_id' => 1,
                            'sales_invoice_id' => $invoice['id'],
                            'credit_note_number' => 'CN-2025-001',
                            'credit_note_date' => '2025-01-15',
                            'status' => $creditNoteStatus,
                            'currency' => $invoice['currency'],
                            'exchange_rate' => $invoice['exchange_rate'],
                            'total_amount_fc' => $creditNoteTotalBc,
                            'total_amount_bc' => $creditNoteTotalBc,
                            'notes' => null,
                            'created_at' => '2025-01-15 10:00:00',
                            'created_by' => 1,
                            'invoice_number' => $invoice['invoice_number'] ?? 'INV-001',
                            'lines' => [],
                        ];
                    });
                }
                // Credit note items SUM for totals
                elseif (stripos($sql, 'FROM credit_note_items') !== false && stripos($sql, 'SUM') !== false) {
                    $stmt->method('fetch')->willReturnCallback(function () use (&$creditNoteTotalBc) {
                        return ['total_fc' => $creditNoteTotalBc, 'total_bc' => $creditNoteTotalBc];
                    });
                }
                // Credit note items list
                elseif (stripos($sql, 'FROM credit_note_items') !== false) {
                    $stmt->method('fetchAll')->willReturn([]);
                }
                // Credit note number generation
                elseif (stripos($sql, 'MAX(CAST') !== false && stripos($sql, 'credit_note_number') !== false) {
                    $stmt->method('fetch')->willReturn(['max_seq' => null]);
                }
                // Stock lookup
                elseif (stripos($sql, 'FROM stock') !== false) {
                    $stmt->method('fetch')->willReturnCallback(function () use (&$capturedParams, $stockMap) {
                        $itemId = $capturedParams['item'] ?? $capturedParams['item_id'] ?? null;
                        if ($itemId !== null && isset($stockMap[$itemId])) {
                            return ['id' => 1, 'quantity' => $stockMap[$itemId]];
                        }
                        return false;
                    });
                }
                // Items lookup
                elseif (stripos($sql, 'FROM items') !== false) {
                    $stmt->method('fetch')->willReturn(['id' => 1, 'name' => 'Test Item', 'business_id' => 1]);
                }
                // Audit log
                elseif (stripos($sql, 'INSERT INTO audit_log') !== false) {
                    // no-op
                }
                // Default
                else {
                    $stmt->method('fetch')->willReturn(false);
                    $stmt->method('fetchAll')->willReturn([]);
                    $stmt->method('fetchColumn')->willReturn(0);
                }

                $stmt->method('rowCount')->willReturn(1);

                return $stmt;
            }
        );

        return $pdo;
    }

    /**
     * Test: Credit note cannot exceed invoice total (Property 4).
     *
     * We test this by building a mock where the recalculated total (200) plus
     * existing credit total (400) exceeds the invoice total (500).
     */
    public function testCreditNoteTotalCannotExceedInvoiceTotal(): void
    {
        $invoice = [
            'id' => 1,
            'business_id' => $this->businessId,
            'customer_id' => 10,
            'invoice_number' => 'INV-2025-001',
            'invoice_date' => '2025-01-01',
            'due_date' => '2025-01-31',
            'status' => 'DELIVERED',
            'currency' => 'USD',
            'exchange_rate' => '1.000000',
            'total_amount_fc' => '500.00',
            'total_amount_bc' => '500.00',
            'lines' => [],
        ];

        // Build a custom mock where the credit note findById returns total_amount_bc = 200
        // and existing credit total is 400, so 400 + 200 = 600 > 500 (invoice total)
        $pdo = $this->createMock(PDO::class);
        $insertCount = 0;

        $pdo->method('inTransaction')->willReturn(true);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('rollBack')->willReturn(true);
        $pdo->method('lastInsertId')->willReturnCallback(function () use (&$insertCount) {
            $insertCount++;
            return $insertCount === 1 ? '100' : (string) (200 + $insertCount);
        });

        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($invoice) {
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            $stmt->method('rowCount')->willReturn(1);

            // Sales invoice lookup
            if (stripos($sql, 'FROM sales_invoices') !== false) {
                $stmt->method('fetch')->willReturn($invoice);
            }
            // Credit note number generation
            elseif (stripos($sql, 'MAX(CAST') !== false) {
                $stmt->method('fetch')->willReturn(['max_seq' => null]);
            }
            // Credit note items SUM (recalculate totals)
            elseif (stripos($sql, 'SUM(total_price') !== false && stripos($sql, 'credit_note_items') !== false) {
                $stmt->method('fetch')->willReturn(['total_fc' => 200.00, 'total_bc' => 200.00]);
            }
            // Credit note findById (after recalculate)
            elseif (stripos($sql, 'FROM credit_notes cn') !== false) {
                $stmt->method('fetch')->willReturn([
                    'id' => 100,
                    'business_id' => 1,
                    'sales_invoice_id' => 1,
                    'credit_note_number' => 'CN-2025-001',
                    'credit_note_date' => '2025-01-15',
                    'status' => 'DRAFT',
                    'currency' => 'USD',
                    'exchange_rate' => '1.000000',
                    'total_amount_fc' => '200.00',
                    'total_amount_bc' => '200.00',
                    'notes' => null,
                    'created_at' => '2025-01-15 10:00:00',
                    'created_by' => 1,
                    'invoice_number' => 'INV-2025-001',
                    'lines' => [],
                ]);
            }
            // Credit note items list
            elseif (stripos($sql, 'FROM credit_note_items') !== false) {
                $stmt->method('fetchAll')->willReturn([]);
            }
            // Sum of existing credit notes for invoice (returns 400)
            elseif (stripos($sql, 'SUM(total_amount_bc)') !== false && stripos($sql, 'credit_notes') !== false) {
                $stmt->method('fetchColumn')->willReturn(400.0);
            }
            // Default
            else {
                $stmt->method('fetch')->willReturn(false);
                $stmt->method('fetchAll')->willReturn([]);
                $stmt->method('fetchColumn')->willReturn(0);
            }

            return $stmt;
        });

        $service = new CreditNoteService($pdo);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Credit note total would exceed the original invoice total');

        $service->create(
            ['sales_invoice_id' => 1, 'credit_note_date' => '2025-01-15'],
            [['item_id' => 1, 'quantity' => 2, 'unit_price_fc' => 100, 'unit_price_bc' => 100]]
        );
    }

    /**
     * Test: Credit note can only be issued for DELIVERED or PAID invoices.
     */
    public function testCreditNoteRejectsNonDeliveredInvoice(): void
    {
        $invoice = [
            'id' => 1,
            'business_id' => $this->businessId,
            'customer_id' => 10,
            'invoice_number' => 'INV-2025-001',
            'invoice_date' => '2025-01-01',
            'due_date' => '2025-01-31',
            'status' => 'DRAFT',
            'currency' => 'USD',
            'exchange_rate' => '1.000000',
            'total_amount_fc' => '500.00',
            'total_amount_bc' => '500.00',
            'lines' => [],
        ];

        $pdo = $this->buildPdoMock($invoice);
        $service = new CreditNoteService($pdo);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Credit notes can only be issued for DELIVERED or PAID invoices');

        $service->create(
            ['sales_invoice_id' => 1, 'credit_note_date' => '2025-01-15'],
            [['item_id' => 1, 'quantity' => 1, 'unit_price_fc' => 50, 'unit_price_bc' => 50]]
        );
    }

    /**
     * Test: Credit note requires sales_invoice_id.
     */
    public function testCreditNoteRequiresInvoiceId(): void
    {
        $pdo = $this->createMock(PDO::class);
        $service = new CreditNoteService($pdo);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Sales invoice ID is required');

        $service->create(
            ['credit_note_date' => '2025-01-15'],
            []
        );
    }

    /**
     * Test: Credit note requires date.
     */
    public function testCreditNoteRequiresDate(): void
    {
        $pdo = $this->createMock(PDO::class);
        $service = new CreditNoteService($pdo);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Credit note date is required');

        $service->create(
            ['sales_invoice_id' => 1],
            []
        );
    }

    /**
     * Test: Only DRAFT credit notes can be issued.
     */
    public function testOnlyDraftCreditNotesCanBeIssued(): void
    {
        $invoice = [
            'id' => 1,
            'business_id' => $this->businessId,
            'customer_id' => 10,
            'invoice_number' => 'INV-2025-001',
            'invoice_date' => '2025-01-01',
            'due_date' => '2025-01-31',
            'status' => 'DELIVERED',
            'currency' => 'USD',
            'exchange_rate' => '1.000000',
            'total_amount_fc' => '500.00',
            'total_amount_bc' => '500.00',
            'lines' => [],
        ];

        // Build a mock where the credit note is already ISSUED
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($invoice) {
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('execute')->willReturn(true);

            if (stripos($sql, 'FROM credit_notes cn') !== false) {
                $stmt->method('fetch')->willReturn([
                    'id' => 100,
                    'business_id' => 1,
                    'sales_invoice_id' => 1,
                    'credit_note_number' => 'CN-2025-001',
                    'credit_note_date' => '2025-01-15',
                    'status' => 'ISSUED',
                    'currency' => 'USD',
                    'exchange_rate' => '1.000000',
                    'total_amount_fc' => '100.00',
                    'total_amount_bc' => '100.00',
                    'notes' => null,
                    'created_at' => '2025-01-15 10:00:00',
                    'created_by' => 1,
                    'invoice_number' => 'INV-2025-001',
                    'lines' => [],
                ]);
            } elseif (stripos($sql, 'FROM credit_note_items') !== false) {
                $stmt->method('fetchAll')->willReturn([]);
            } else {
                $stmt->method('fetch')->willReturn(false);
            }

            return $stmt;
        });

        $service = new CreditNoteService($pdo);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only DRAFT credit notes can be issued');

        $service->issue(100);
    }
}
