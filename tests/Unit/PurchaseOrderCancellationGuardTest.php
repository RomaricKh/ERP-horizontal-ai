<?php

namespace Tests\Unit;

use App\Repository\PeopleRepository;
use App\Repository\PurchaseOrderRepository;
use App\Services\AuditService;
use App\Services\ExchangeRateService;
use App\Services\ItemService;
use App\Services\PurchaseOrderService;
use App\Support\AuthContext;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for PurchaseOrderService cancellation guard.
 *
 * Validates: Requirements 13.1, 13.3
 */
class PurchaseOrderCancellationGuardTest extends TestCase
{
    private int $businessId = 1;
    private int $poId       = 100;

    protected function setUp(): void
    {
        AuthContext::set((object) [
            'user_id'     => 1,
            'tenant_id'   => 1,
            'business_id' => $this->businessId,
        ]);
    }

    /**
     * Test: RECEIVED PO with payments (PARTIALLY_PAID) cannot be cancelled.
     *
     * When a RECEIVED PO has associated payment slips (totalPaid > 0 but < total),
     * cancellation must be rejected with the guard message.
     *
     * Validates: Requirement 13.1
     */
    public function testCancelReceivedPoWithPartialPaymentsIsRejected(): void
    {
        $poRecord = $this->makePoRecord([
            'supplier_id'     => 42,
            'total_amount_bc' => 1000.00,
            'status'          => 'RECEIVED',
        ]);

        $poRepo     = $this->makePoRepoStub($poRecord, 'RECEIVED');
        $balanceDeltas = [];
        $peopleRepo = $this->makePeopleRepoStub($balanceDeltas);
        $service    = $this->buildService($poRepo, $peopleRepo, 500.00); // partially paid

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot cancel: purchase order has associated payments. Reverse payments first.');

        $service->updateStatus($this->poId, 'CANCELLED');
    }

    /**
     * Test: RECEIVED PO with payments (PAID) cannot be cancelled.
     *
     * When a RECEIVED PO has payment slips totalling >= total_amount_bc,
     * cancellation must be rejected with the guard message.
     *
     * Validates: Requirement 13.1
     */
    public function testCancelReceivedPoWithFullPaymentsIsRejected(): void
    {
        $poRecord = $this->makePoRecord([
            'supplier_id'     => 42,
            'total_amount_bc' => 1000.00,
            'status'          => 'RECEIVED',
        ]);

        $poRepo     = $this->makePoRepoStub($poRecord, 'RECEIVED');
        $balanceDeltas = [];
        $peopleRepo = $this->makePeopleRepoStub($balanceDeltas);
        $service    = $this->buildService($poRepo, $peopleRepo, 1000.00); // fully paid

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot cancel: purchase order has associated payments. Reverse payments first.');

        $service->updateStatus($this->poId, 'CANCELLED');
    }

    /**
     * Test: RECEIVED PO with no payments (UNPAID) can be cancelled.
     *
     * When a RECEIVED PO has no associated payment slips (totalPaid = 0),
     * cancellation should proceed and reverse stock/balance.
     *
     * Validates: Requirement 13.3
     */
    public function testCancelReceivedPoWithNoPaymentsIsAllowed(): void
    {
        $balanceDeltas = [];
        $supplierId    = 42;
        $totalBc       = 1000.00;

        $poRecord = $this->makePoRecord([
            'supplier_id'     => $supplierId,
            'total_amount_bc' => $totalBc,
            'status'          => 'RECEIVED',
        ]);

        $poRepo     = $this->makePoRepoStub($poRecord, 'RECEIVED');
        $peopleRepo = $this->makePeopleRepoStub($balanceDeltas);
        $service    = $this->buildService($poRepo, $peopleRepo, 0.0); // unpaid

        // Should not throw
        $service->updateStatus($this->poId, 'CANCELLED');

        // Verify supplier balance was reversed (positive delta = reversing the negative from RECEIVED)
        $this->assertCount(1, $balanceDeltas);
        $this->assertSame($supplierId, $balanceDeltas[0]['payeeId']);
        $this->assertEqualsWithDelta($totalBc, $balanceDeltas[0]['delta'], 0.001);
    }

    /**
     * Test: RECEIVED PO with overpayment cannot be cancelled.
     *
     * Even if payments exceed the PO total, cancellation is still rejected.
     *
     * Validates: Requirement 13.1
     */
    public function testCancelReceivedPoWithOverpaymentIsRejected(): void
    {
        $poRecord = $this->makePoRecord([
            'supplier_id'     => 42,
            'total_amount_bc' => 1000.00,
            'status'          => 'RECEIVED',
        ]);

        $poRepo     = $this->makePoRepoStub($poRecord, 'RECEIVED');
        $balanceDeltas = [];
        $peopleRepo = $this->makePeopleRepoStub($balanceDeltas);
        $service    = $this->buildService($poRepo, $peopleRepo, 1500.00); // overpaid

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot cancel: purchase order has associated payments. Reverse payments first.');

        $service->updateStatus($this->poId, 'CANCELLED');
    }

    /**
     * Test: Cancellation guard does not apply to APPROVED→CANCELLED.
     *
     * The payment guard only applies to RECEIVED→CANCELLED, not APPROVED→CANCELLED.
     *
     * Validates: Requirement 13.1 (guard only for RECEIVED)
     */
    public function testCancelFromApprovedDoesNotCheckPayments(): void
    {
        $balanceDeltas = [];

        $poRecord = $this->makePoRecord([
            'supplier_id'     => 42,
            'total_amount_bc' => 1000.00,
            'status'          => 'APPROVED',
        ]);

        $poRepo     = $this->makePoRepoStub($poRecord, 'APPROVED');
        $peopleRepo = $this->makePeopleRepoStub($balanceDeltas);
        // Even with payments, APPROVED→CANCELLED should work (guard doesn't apply)
        $service    = $this->buildService($poRepo, $peopleRepo, 500.00);

        // Should not throw
        $service->updateStatus($this->poId, 'CANCELLED');

        // No balance reversal for APPROVED cancellation
        $this->assertCount(0, $balanceDeltas);
    }

    // ---------------------------------------------------------------
    // Helper methods
    // ---------------------------------------------------------------

    private function makePoRecord(array $overrides = []): array
    {
        return array_merge([
            'id'              => $this->poId,
            'business_id'     => $this->businessId,
            'supplier_id'     => null,
            'po_number'       => 'PO-TEST-001',
            'po_date'         => '2025-01-15',
            'currency'        => 'USD',
            'exchange_rate'   => 1.0,
            'total_amount_fc' => 0.00,
            'total_amount_bc' => 0.00,
            'notes'           => null,
            'created_at'      => '2025-01-15',
            'updated_at'      => '2025-01-15',
            'created_by'      => 1,
        ], $overrides);
    }

    private function makePoRepoStub(array $record, string $status): PurchaseOrderRepository
    {
        return new class($record, $status) extends PurchaseOrderRepository {
            private array  $record;
            private string $status;

            public function __construct(array $record, string $status)
            {
                $this->record = $record;
                $this->status = $status;
            }

            public function findById(int $id, int $businessId): ?array
            {
                return array_merge($this->record, ['status' => $this->status]);
            }

            public function updateStatus(int $id, int $businessId, string $status): void
            {
                // no-op
            }

            public function getLines(int $poId): array
            {
                return [];
            }
        };
    }

    private function makePeopleRepoStub(array &$deltas): PeopleRepository
    {
        return new class($deltas) extends PeopleRepository {
            private array $deltas;

            public function __construct(array &$deltas)
            {
                $this->deltas = &$deltas;
            }

            public function updatePayeeBalance(int $payeeId, float $delta): void
            {
                $this->deltas[] = ['payeeId' => $payeeId, 'delta' => $delta];
            }
        };
    }

    /**
     * @param float $totalPaid  Simulated total paid amount returned by payment_slips query
     */
    private function buildService(
        PurchaseOrderRepository $poRepo,
        PeopleRepository $peopleRepo,
        float $totalPaid = 0.0
    ): PurchaseOrderService {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Mock prepare/execute/fetchColumn for the cancellation payment guard query
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn($totalPaid);
        $pdo->method('prepare')->willReturn($stmt);

        $audit = new class extends AuditService {
            public function __construct() {}
            public function log(string $eventType, string $table, $recordId,
                                string $action, ?array $oldValues = null, ?array $newValues = null,
                                ?int $userId = null, ?int $tenantId = null, ?int $businessId = null, bool $force = false, bool $tenantScope = false): void {}
        };

        $itemService = new class extends ItemService {
            public function __construct() {}
            public function adjustStock(int $itemId, string $type, float $quantity, ?string $reference, ?string $notes): void {}
        };

        $exchangeRateService = new class extends ExchangeRateService {
            public function __construct() {}
            public function resolveRate(string $currency, ?float $providedRate): float
            {
                if ($providedRate !== null) {
                    return $providedRate;
                }
                throw new \InvalidArgumentException("Exchange rate is required for currency {$currency}");
            }
        };

        $ref     = new ReflectionClass(PurchaseOrderService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $props = [
            'db'                  => $pdo,
            'repo'                => $poRepo,
            'peopleRepo'          => $peopleRepo,
            'exchangeRateService' => $exchangeRateService,
            'audit'               => $audit,
            'itemService'         => $itemService,
        ];

        foreach ($props as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($service, $value);
        }

        return $service;
    }
}
