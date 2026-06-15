<?php

namespace Tests\Property;

use App\Repository\AccountRepository;
use App\Repository\PeopleRepository;
use App\Repository\SalesInvoiceRepository;
use App\Services\AuditService;
use App\Services\ExchangeRateService;
use App\Services\ItemService;
use App\Services\SalesInvoiceService;
use App\Services\StockValidator;
use App\Support\AuthContext;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Feature: operations-financial-integrity, Property 8: Invoice DELIVERED Transition Side Effects
 *
 * For any Sales Invoice with a customer and a positive total_amount_bc,
 * transitioning to DELIVERED and then to CANCELLED (when UNPAID) should
 * return the customer's payee balance to its original value (net delta = 0).
 *
 * The new lifecycle is:
 *   DRAFT → DELIVERED (triggers stock OUT + customer balance increase)
 *   DRAFT → CANCELLED (no balance impact)
 *   DELIVERED → CANCELLED (with guard: only if UNPAID; reverses stock + balance)
 *
 * Validates: Requirements 6.2, 6.3, 13.4
 */
class SalesInvoiceBalancePropertyTest extends TestCase
{
    private const ITERATIONS = 120;

    /**
     * Data provider generating 120 random invoice amounts and customer IDs.
     *
     * Each case: [customerId, totalAmountBc]
     */
    public static function deliverThenCancelProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $customerId    = mt_rand(1, 10000);
            // Random amount between 0.01 and 999999.99
            $totalAmountBc = round(mt_rand(1, 99999999) / 100, 2);
            $cases["customer_{$customerId}_amount_{$totalAmountBc}_iter_{$i}"] = [
                $customerId,
                $totalAmountBc,
            ];
        }
        return $cases;
    }

    /**
     * Property: Sales Invoice deliver-then-cancel round-trip
     *
     * After DELIVERED then CANCELLED (UNPAID), the net delta passed to
     * updatePayeeBalance() must be zero.
     *
     * **Validates: Requirements 6.2, 6.3, 13.4**
     *
     * @dataProvider deliverThenCancelProvider
     */
    public function testDeliverThenCancelNetDeltaIsZero(
        int $customerId,
        float $totalAmountBc
    ): void {
        $invoiceId  = mt_rand(1, 10000);
        $businessId = mt_rand(1, 100);

        // Set up AuthContext
        AuthContext::set((object) [
            'user_id'     => 1,
            'tenant_id'   => 1,
            'business_id' => $businessId,
        ]);

        // Invoice record template
        $invoiceRecord = [
            'id'              => $invoiceId,
            'business_id'     => $businessId,
            'customer_id'     => $customerId,
            'invoice_number'  => 'INV-2025-001',
            'invoice_date'    => '2025-01-01',
            'currency'        => 'USD',
            'exchange_rate'   => 1.0,
            'total_amount_fc' => $totalAmountBc,
            'total_amount_bc' => $totalAmountBc,
            'notes'           => null,
            'created_at'      => '2025-01-01',
            'updated_at'      => '2025-01-01',
            'created_by'      => 1,
        ];

        // Track balance deltas
        $balanceDeltas = [];

        // -- Build stubs using anonymous subclasses --

        // SI repository: returns DRAFT on first findById, DELIVERED on second
        $siRepo = new class($invoiceRecord) extends SalesInvoiceRepository {
            /** @var array */
            private $record;
            /** @var int */
            private $callCount = 0;

            public function __construct(array $record)
            {
                // Skip parent constructor (needs PDO)
                $this->record = $record;
            }

            public function findById(int $id, int $businessId): ?array
            {
                $status = $this->callCount === 0 ? 'DRAFT' : 'DELIVERED';
                $this->callCount++;
                return array_merge($this->record, ['status' => $status]);
            }

            public function updateStatus(int $id, int $businessId, string $status): void
            {
                // no-op
            }

            public function getLines(int $invoiceId): array
            {
                return [];
            }
        };

        // People repository: tracks all updatePayeeBalance calls
        $peopleRepo = new class($balanceDeltas) extends PeopleRepository {
            /** @var array */
            private $deltas;

            public function __construct(array &$deltas)
            {
                // Skip parent constructor
                $this->deltas = &$deltas;
            }

            public function updatePayeeBalance(int $payeeId, float $delta): void
            {
                $this->deltas[] = ['payeeId' => $payeeId, 'delta' => $delta];
            }
        };

        // Audit service stub
        $audit = new class extends AuditService {
            public function __construct()
            {
                // Skip parent constructor
            }

            public function log(string $eventType, string $table, $recordId,
                                string $action, ?array $oldValues = null, ?array $newValues = null,
                                ?int $userId = null, ?int $tenantId = null, ?int $businessId = null, bool $force = false, bool $tenantScope = false): void
            {
                // no-op
            }
        };

        // Item service stub
        $itemService = new class extends ItemService {
            public function __construct()
            {
                // Skip parent constructor
            }

            public function adjustStock(int $itemId, string $type, float $quantity, ?string $reference, ?string $notes): void
            {
                // no-op
            }
        };

        // Account repository stub
        $accountRepo = new class extends AccountRepository {
            public function __construct()
            {
                // Skip parent constructor
            }

            public function findById(int $id, int $businessId): ?array
            {
                return null;
            }

            public function updateBalance(int $accountId, float $delta, PDO $db, float $deltaFc = 0.0): void
            {
                // no-op
            }
        };

        // StockValidator stub
        $stockValidator = new class extends StockValidator {
            public function __construct()
            {
                // Skip parent constructor
            }

            public function assertAvailable(int $itemId, float $requestedQty, int $businessId): void
            {
                // no-op — always passes
            }

            public function checkBatch(array $requirements, int $businessId): array
            {
                return [];
            }
        };

        // PDO stub — must handle prepare() calls for computePaymentStatusInternal
        // which queries total_amount_bc and collection_slips sum
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Mock the two prepare calls for computePaymentStatusInternal:
        // 1st: SELECT total_amount_bc → returns the invoice total
        // 2nd: SELECT COALESCE(SUM(amount_bc), 0) → returns 0 (UNPAID)
        $stmtTotal = $this->createMock(PDOStatement::class);
        $stmtTotal->method('execute')->willReturn(true);
        $stmtTotal->method('fetchColumn')->willReturn($totalAmountBc);

        $stmtCollected = $this->createMock(PDOStatement::class);
        $stmtCollected->method('execute')->willReturn(true);
        $stmtCollected->method('fetchColumn')->willReturn(0.0);

        $pdo->method('prepare')->willReturnOnConsecutiveCalls($stmtTotal, $stmtCollected);

        // Build service with injected mocks
        $service = $this->buildServiceWithMocks($pdo, $siRepo, $peopleRepo, $audit, $itemService, $accountRepo, $stockValidator);

        // Step 1: Transition DRAFT → DELIVERED (increases balance)
        $service->updateStatus($invoiceId, 'DELIVERED');

        // Step 2: Transition DELIVERED → CANCELLED (should reverse balance, UNPAID guard passes)
        $service->updateStatus($invoiceId, 'CANCELLED');

        // Assert: exactly 2 balance calls were made
        $this->assertCount(
            2,
            $balanceDeltas,
            'Expected exactly 2 balance updates (one for DELIVERED, one for CANCELLED)'
        );

        // Assert: both calls targeted the correct customer
        $this->assertSame(
            $customerId,
            $balanceDeltas[0]['payeeId'],
            'DELIVERED balance update must target the correct customer'
        );
        $this->assertSame(
            $customerId,
            $balanceDeltas[1]['payeeId'],
            'CANCELLED balance update must target the correct customer'
        );

        // Assert: first delta is +amount (DELIVERED increases balance)
        $this->assertEqualsWithDelta(
            $totalAmountBc,
            $balanceDeltas[0]['delta'],
            0.001,
            'First delta (DELIVERED) should be +totalAmountBc'
        );

        // Assert: second delta is -amount (CANCELLED reverses balance)
        $this->assertEqualsWithDelta(
            -$totalAmountBc,
            $balanceDeltas[1]['delta'],
            0.001,
            'Second delta (CANCELLED) should be -totalAmountBc'
        );

        // Assert: net delta is zero (round-trip property)
        $netDelta = $balanceDeltas[0]['delta'] + $balanceDeltas[1]['delta'];
        $this->assertEqualsWithDelta(
            0.0,
            $netDelta,
            0.001,
            sprintf(
                'Net balance delta must be zero after deliver-then-cancel. '
                . 'Customer: %d, Amount: %.2f, Deltas: [%.2f, %.2f], Net: %.2f',
                $customerId,
                $totalAmountBc,
                $balanceDeltas[0]['delta'],
                $balanceDeltas[1]['delta'],
                $netDelta
            )
        );
    }

    /**
     * Data provider generating 120 random invoice amounts and customer IDs
     * for non-balance-impacting cancellation from DRAFT status.
     *
     * Each case: [customerId, totalAmountBc]
     */
    public static function cancelFromDraftProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $customerId    = mt_rand(1, 10000);
            // Random amount between 0.01 and 999999.99
            $totalAmountBc = round(mt_rand(1, 99999999) / 100, 2);
            $cases["customer_{$customerId}_amount_{$totalAmountBc}_iter_{$i}"] = [
                $customerId,
                $totalAmountBc,
            ];
        }
        return $cases;
    }

    /**
     * Property: Non-balance-impacting cancellation from DRAFT is a no-op
     *
     * Cancelling an SI from DRAFT status (not DELIVERED) must NOT call
     * updatePayeeBalance(), because no balance increase happened at DRAFT.
     *
     * **Validates: Requirements 6.4**
     *
     * @dataProvider cancelFromDraftProvider
     */
    public function testCancelFromDraftDoesNotUpdateBalance(
        int $customerId,
        float $totalAmountBc
    ): void {
        $invoiceId  = mt_rand(1, 10000);
        $businessId = mt_rand(1, 100);

        // Set up AuthContext
        AuthContext::set((object) [
            'user_id'     => 1,
            'tenant_id'   => 1,
            'business_id' => $businessId,
        ]);

        // Invoice record in DRAFT status
        $invoiceRecord = [
            'id'              => $invoiceId,
            'business_id'     => $businessId,
            'customer_id'     => $customerId,
            'invoice_number'  => 'INV-2025-002',
            'invoice_date'    => '2025-01-01',
            'currency'        => 'USD',
            'exchange_rate'   => 1.0,
            'total_amount_fc' => $totalAmountBc,
            'total_amount_bc' => $totalAmountBc,
            'notes'           => null,
            'created_at'      => '2025-01-01',
            'updated_at'      => '2025-01-01',
            'created_by'      => 1,
        ];

        // Track balance deltas
        $balanceDeltas = [];

        // SI repository: always returns invoice in DRAFT status
        $siRepo = new class($invoiceRecord) extends SalesInvoiceRepository {
            /** @var array */
            private $record;

            public function __construct(array $record)
            {
                $this->record = $record;
            }

            public function findById(int $id, int $businessId): ?array
            {
                return array_merge($this->record, ['status' => 'DRAFT']);
            }

            public function updateStatus(int $id, int $businessId, string $status): void
            {
                // no-op
            }

            public function getLines(int $invoiceId): array
            {
                return [];
            }
        };

        // People repository: tracks all updatePayeeBalance calls
        $peopleRepo = new class($balanceDeltas) extends PeopleRepository {
            /** @var array */
            private $deltas;

            public function __construct(array &$deltas)
            {
                $this->deltas = &$deltas;
            }

            public function updatePayeeBalance(int $payeeId, float $delta): void
            {
                $this->deltas[] = ['payeeId' => $payeeId, 'delta' => $delta];
            }
        };

        // Audit service stub
        $audit = new class extends AuditService {
            public function __construct()
            {
            }

            public function log(string $eventType, string $table, $recordId,
                                string $action, ?array $oldValues = null, ?array $newValues = null,
                                ?int $userId = null, ?int $tenantId = null, ?int $businessId = null, bool $force = false, bool $tenantScope = false): void
            {
            }
        };

        // Item service stub
        $itemService = new class extends ItemService {
            public function __construct()
            {
            }

            public function adjustStock(int $itemId, string $type, float $quantity, ?string $reference, ?string $notes): void
            {
            }
        };

        // Account repository stub
        $accountRepo = new class extends AccountRepository {
            public function __construct()
            {
            }

            public function findById(int $id, int $businessId): ?array
            {
                return null;
            }

            public function updateBalance(int $accountId, float $delta, PDO $db, float $deltaFc = 0.0): void
            {
            }
        };

        // StockValidator stub
        $stockValidator = new class extends StockValidator {
            public function __construct()
            {
            }

            public function assertAvailable(int $itemId, float $requestedQty, int $businessId): void
            {
            }

            public function checkBatch(array $requirements, int $businessId): array
            {
                return [];
            }
        };

        // PDO stub
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Build service with injected mocks
        $service = $this->buildServiceWithMocks($pdo, $siRepo, $peopleRepo, $audit, $itemService, $accountRepo, $stockValidator);

        // Transition DRAFT → CANCELLED
        $service->updateStatus($invoiceId, 'CANCELLED');

        // Assert: no balance calls were made
        $this->assertCount(
            0,
            $balanceDeltas,
            sprintf(
                'Cancelling an SI from DRAFT must NOT call updatePayeeBalance(). '
                . 'Customer: %d, Amount: %.2f, but got %d balance call(s)',
                $customerId,
                $totalAmountBc,
                count($balanceDeltas)
            )
        );
    }

    /**
     * Build a SalesInvoiceService with mocked dependencies injected via reflection.
     */
    private function buildServiceWithMocks(
        PDO $pdo,
        SalesInvoiceRepository $siRepo,
        PeopleRepository $peopleRepo,
        AuditService $audit,
        ItemService $itemService,
        AccountRepository $accountRepo,
        StockValidator $stockValidator
    ): SalesInvoiceService {
        $exchangeRateService = $this->createMock(ExchangeRateService::class);
        $exchangeRateService->method('resolveRate')->willReturnCallback(
            fn(string $currency, ?float $providedRate) => $providedRate ?? 1.0
        );

        $ref     = new ReflectionClass(SalesInvoiceService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $props = [
            'db'                  => $pdo,
            'repo'                => $siRepo,
            'peopleRepo'          => $peopleRepo,
            'audit'               => $audit,
            'itemService'         => $itemService,
            'accountRepo'         => $accountRepo,
            'exchangeRateService' => $exchangeRateService,
            'stockValidator'      => $stockValidator,
        ];

        foreach ($props as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($service, $value);
        }

        return $service;
    }
}
