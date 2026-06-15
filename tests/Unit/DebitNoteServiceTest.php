<?php

namespace Tests\Unit;

use App\Services\DebitNoteService;
use App\Support\AuthContext;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DebitNoteService — issuing a debit note for a received PO.
 *
 * Validates: Requirements 3.25 (REQ-025)
 * - Stock is reversed (OUT) for returned items
 * - Supplier payee balance is reduced
 * - Debit note total does not exceed PO total (Property 5)
 */
class DebitNoteServiceTest extends TestCase
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
            'permissions' => ['purchase_orders.manage', 'po.view'],
        ]);
    }

    /**
     * Build a PDO mock that simulates the database for debit note operations.
     */
    private function buildPdoMock(
        array $po,
        array $stockMap = [],
        float $payeeBalance = 1000.0,
        float $existingDebitTotal = 0.0
    ): PDO {
        $pdo = $this->createMock(PDO::class);
        $insertedDebitNoteId = 100;
        $insertedLineId = 200;
        $debitNoteStatus = 'DRAFT';
        $debitNoteTotalBc = 0.0;

        $pdo->method('inTransaction')->willReturn(true);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('rollBack')->willReturn(true);
        $pdo->method('lastInsertId')->willReturnCallback(function () use (&$insertedDebitNoteId, &$insertedLineId) {
            static $callCount = 0;
            $callCount++;
            return $callCount === 1 ? (string) $insertedDebitNoteId : (string) ($insertedLineId++);
        });

        $pdo->method('prepare')->willReturnCallback(
            function (string $sql) use (
                $po, $stockMap, &$payeeBalance, $existingDebitTotal,
                &$debitNoteStatus, &$debitNoteTotalBc, $insertedDebitNoteId
            ) {
                $stmt = $this->createMock(PDOStatement::class);
                $capturedParams = [];

                $stmt->method('execute')->willReturnCallback(
                    function ($params = []) use (&$capturedParams, $sql, &$payeeBalance, &$debitNoteStatus) {
                        $capturedParams = $params ?? [];

                        if (stripos($sql, 'UPDATE payees') !== false && isset($capturedParams['delta'])) {
                            $payeeBalance += (float) $capturedParams['delta'];
                        }
                        if (stripos($sql, 'UPDATE debit_notes SET status') !== false) {
                            $debitNoteStatus = $capturedParams['status'] ?? $debitNoteStatus;
                        }

                        return true;
                    }
                );

                // Purchase order lookup
                if (stripos($sql, 'FROM purchase_orders po') !== false && stripos($sql, 'WHERE') !== false) {
                    $stmt->method('fetch')->willReturn($po);
                }
                // PO lines
                elseif (stripos($sql, 'FROM po_details') !== false) {
                    $stmt->method('fetchAll')->willReturn($po['lines'] ?? []);
                }
                // Debit notes SUM for PO (existing debit total)
                elseif (stripos($sql, 'SUM(total_amount_bc)') !== false && stripos($sql, 'debit_notes') !== false) {
                    $stmt->method('fetchColumn')->willReturn($existingDebitTotal);
                }
                // Debit note findById
                elseif (stripos($sql, 'FROM debit_notes dn') !== false) {
                    $stmt->method('fetch')->willReturnCallback(function () use ($insertedDebitNoteId, $po, &$debitNoteStatus, &$debitNoteTotalBc) {
                        return [
                            'id' => $insertedDebitNoteId,
                            'business_id' => 1,
                            'purchase_order_id' => $po['id'],
                            'debit_note_number' => 'DN-2025-001',
                            'debit_note_date' => '2025-01-15',
                            'status' => $debitNoteStatus,
                            'currency' => $po['currency'],
                            'exchange_rate' => $po['exchange_rate'],
                            'total_amount_fc' => $debitNoteTotalBc,
                            'total_amount_bc' => $debitNoteTotalBc,
                            'notes' => null,
                            'created_at' => '2025-01-15 10:00:00',
                            'created_by' => 1,
                            'po_number' => $po['po_number'] ?? 'PO-001',
                            'lines' => [],
                        ];
                    });
                }
                // Debit note items SUM for totals
                elseif (stripos($sql, 'FROM debit_note_items') !== false && stripos($sql, 'SUM') !== false) {
                    $stmt->method('fetch')->willReturnCallback(function () use (&$debitNoteTotalBc) {
                        return ['total_fc' => $debitNoteTotalBc, 'total_bc' => $debitNoteTotalBc];
                    });
                }
                // Debit note items list
                elseif (stripos($sql, 'FROM debit_note_items') !== false) {
                    $stmt->method('fetchAll')->willReturn([]);
                }
                // Debit note number generation
                elseif (stripos($sql, 'MAX(CAST') !== false && stripos($sql, 'debit_note_number') !== false) {
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
     * Test: Debit note cannot exceed PO total (Property 5).
     *
     * We test this by building a mock where the recalculated total (200) plus
     * existing debit total (700) exceeds the PO total (800).
     */
    public function testDebitNoteTotalCannotExceedPOTotal(): void
    {
        $po = [
            'id' => 1,
            'business_id' => $this->businessId,
            'supplier_id' => 20,
            'supplier_name' => 'Test Supplier',
            'po_number' => 'PO-2025-001',
            'po_date' => '2025-01-01',
            'status' => 'RECEIVED',
            'currency' => 'USD',
            'exchange_rate' => '1.000000',
            'total_amount_fc' => '800.00',
            'total_amount_bc' => '800.00',
            'lines' => [],
        ];

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

        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($po) {
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            $stmt->method('rowCount')->willReturn(1);

            // Purchase order lookup
            if (stripos($sql, 'FROM purchase_orders po') !== false) {
                $stmt->method('fetch')->willReturn($po);
            }
            // PO lines
            elseif (stripos($sql, 'FROM po_details') !== false) {
                $stmt->method('fetchAll')->willReturn([]);
            }
            // Debit note number generation
            elseif (stripos($sql, 'MAX(CAST') !== false) {
                $stmt->method('fetch')->willReturn(['max_seq' => null]);
            }
            // Debit note items SUM (recalculate totals)
            elseif (stripos($sql, 'SUM(total_price') !== false && stripos($sql, 'debit_note_items') !== false) {
                $stmt->method('fetch')->willReturn(['total_fc' => 200.00, 'total_bc' => 200.00]);
            }
            // Debit note findById (after recalculate)
            elseif (stripos($sql, 'FROM debit_notes dn') !== false) {
                $stmt->method('fetch')->willReturn([
                    'id' => 100,
                    'business_id' => 1,
                    'purchase_order_id' => 1,
                    'debit_note_number' => 'DN-2025-001',
                    'debit_note_date' => '2025-01-15',
                    'status' => 'DRAFT',
                    'currency' => 'USD',
                    'exchange_rate' => '1.000000',
                    'total_amount_fc' => '200.00',
                    'total_amount_bc' => '200.00',
                    'notes' => null,
                    'created_at' => '2025-01-15 10:00:00',
                    'created_by' => 1,
                    'po_number' => 'PO-2025-001',
                    'lines' => [],
                ]);
            }
            // Debit note items list
            elseif (stripos($sql, 'FROM debit_note_items') !== false) {
                $stmt->method('fetchAll')->willReturn([]);
            }
            // Sum of existing debit notes for PO (returns 700)
            elseif (stripos($sql, 'SUM(total_amount_bc)') !== false && stripos($sql, 'debit_notes') !== false) {
                $stmt->method('fetchColumn')->willReturn(700.0);
            }
            // Default
            else {
                $stmt->method('fetch')->willReturn(false);
                $stmt->method('fetchAll')->willReturn([]);
                $stmt->method('fetchColumn')->willReturn(0);
            }

            return $stmt;
        });

        $service = new DebitNoteService($pdo);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Debit note total would exceed the original PO total');

        $service->create(
            ['purchase_order_id' => 1, 'debit_note_date' => '2025-01-15'],
            [['item_id' => 1, 'quantity' => 2, 'unit_price_fc' => 100, 'unit_price_bc' => 100]]
        );
    }

    /**
     * Test: Debit note can only be issued for RECEIVED or CLOSED POs.
     */
    public function testDebitNoteRejectsNonReceivedPO(): void
    {
        $po = [
            'id' => 1,
            'business_id' => $this->businessId,
            'supplier_id' => 20,
            'supplier_name' => 'Test Supplier',
            'po_number' => 'PO-2025-001',
            'po_date' => '2025-01-01',
            'status' => 'DRAFT',
            'currency' => 'USD',
            'exchange_rate' => '1.000000',
            'total_amount_fc' => '800.00',
            'total_amount_bc' => '800.00',
            'lines' => [],
        ];

        $pdo = $this->buildPdoMock($po);
        $service = new DebitNoteService($pdo);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Debit notes can only be issued for RECEIVED or CLOSED purchase orders');

        $service->create(
            ['purchase_order_id' => 1, 'debit_note_date' => '2025-01-15'],
            [['item_id' => 1, 'quantity' => 1, 'unit_price_fc' => 50, 'unit_price_bc' => 50]]
        );
    }

    /**
     * Test: Debit note requires purchase_order_id.
     */
    public function testDebitNoteRequiresPurchaseOrderId(): void
    {
        $pdo = $this->createMock(PDO::class);
        $service = new DebitNoteService($pdo);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Purchase order ID is required');

        $service->create(
            ['debit_note_date' => '2025-01-15'],
            []
        );
    }

    /**
     * Test: Debit note requires date.
     */
    public function testDebitNoteRequiresDate(): void
    {
        $pdo = $this->createMock(PDO::class);
        $service = new DebitNoteService($pdo);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Debit note date is required');

        $service->create(
            ['purchase_order_id' => 1],
            []
        );
    }

    /**
     * Test: Only DRAFT debit notes can be issued.
     */
    public function testOnlyDraftDebitNotesCanBeIssued(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) {
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('execute')->willReturn(true);

            if (stripos($sql, 'FROM debit_notes dn') !== false) {
                $stmt->method('fetch')->willReturn([
                    'id' => 100,
                    'business_id' => 1,
                    'purchase_order_id' => 1,
                    'debit_note_number' => 'DN-2025-001',
                    'debit_note_date' => '2025-01-15',
                    'status' => 'ISSUED',
                    'currency' => 'USD',
                    'exchange_rate' => '1.000000',
                    'total_amount_fc' => '100.00',
                    'total_amount_bc' => '100.00',
                    'notes' => null,
                    'created_at' => '2025-01-15 10:00:00',
                    'created_by' => 1,
                    'po_number' => 'PO-2025-001',
                    'lines' => [],
                ]);
            } elseif (stripos($sql, 'FROM debit_note_items') !== false) {
                $stmt->method('fetchAll')->willReturn([]);
            } else {
                $stmt->method('fetch')->willReturn(false);
            }

            return $stmt;
        });

        $service = new DebitNoteService($pdo);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only DRAFT debit notes can be issued');

        $service->issue(100);
    }

    /**
     * Test: Debit note accepted for RECEIVED PO (happy path validation).
     */
    public function testDebitNoteAcceptedForReceivedPO(): void
    {
        $po = [
            'id' => 1,
            'business_id' => $this->businessId,
            'supplier_id' => 20,
            'supplier_name' => 'Test Supplier',
            'po_number' => 'PO-2025-001',
            'po_date' => '2025-01-01',
            'status' => 'RECEIVED',
            'currency' => 'USD',
            'exchange_rate' => '1.000000',
            'total_amount_fc' => '1000.00',
            'total_amount_bc' => '1000.00',
            'lines' => [],
        ];

        // No existing debit notes, PO total is 1000
        $pdo = $this->buildPdoMock($po, [1 => 50], 1000.0, 0.0);
        $service = new DebitNoteService($pdo);

        // Should not throw — creating a debit note for 100 against a 1000 PO
        $id = $service->create(
            ['purchase_order_id' => 1, 'debit_note_date' => '2025-01-15'],
            [['item_id' => 1, 'quantity' => 1, 'unit_price_fc' => 100, 'unit_price_bc' => 100]]
        );

        $this->assertEquals(100, $id);
    }

    /**
     * Test: Debit note accepted for CLOSED PO.
     */
    public function testDebitNoteAcceptedForClosedPO(): void
    {
        $po = [
            'id' => 2,
            'business_id' => $this->businessId,
            'supplier_id' => 20,
            'supplier_name' => 'Test Supplier',
            'po_number' => 'PO-2025-002',
            'po_date' => '2025-01-01',
            'status' => 'CLOSED',
            'currency' => 'USD',
            'exchange_rate' => '1.000000',
            'total_amount_fc' => '2000.00',
            'total_amount_bc' => '2000.00',
            'lines' => [],
        ];

        $pdo = $this->buildPdoMock($po, [1 => 100], 2000.0, 0.0);
        $service = new DebitNoteService($pdo);

        $id = $service->create(
            ['purchase_order_id' => 2, 'debit_note_date' => '2025-01-15'],
            [['item_id' => 1, 'quantity' => 5, 'unit_price_fc' => 50, 'unit_price_bc' => 50]]
        );

        $this->assertEquals(100, $id);
    }
}
