<?php

namespace Tests\Property;

use App\Repository\AccountRepository;
use App\Repository\CollectionRepository;
use App\Repository\PeopleRepository;
use App\Services\AuditService;
use App\Services\CollectionService;
use App\Services\ExchangeRateService;
use App\Support\AuthContext;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Feature: people-balance-tracking, Property 12: Collection create-then-delete round-trip
 *
 * For any Collection Slip with a payee and a positive amount_bc,
 * creating and then deleting the collection should return the
 * payee balance to its original value (net delta = 0).
 *
 * CollectionService.create() DECREASES payee balance by amount_bc.
 * CollectionService.delete() INCREASES payee balance by amount_bc.
 *
 * Validates: Requirements 6.3, 6.4
 */
class CollectionBalanceRegressionPropertyTest extends TestCase
{
    private const ITERATIONS = 120;

    /**
     * Data provider generating 120 random collection amounts, exchange rates, and payee IDs.
     *
     * Each case: [payeeId, accountId, amountFc, exchangeRate]
     */
    public static function createThenDeleteProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $payeeId      = mt_rand(1, 10000);
            $accountId    = mt_rand(1, 1000);
            // Random amount_fc between 0.01 and 99999.99
            $amountFc     = round(mt_rand(1, 9999999) / 100, 2);
            // Random exchange rate between 0.01 and 100.00
            $exchangeRate = round(mt_rand(1, 10000) / 100, 2);
            $cases["payee_{$payeeId}_account_{$accountId}_fc_{$amountFc}_rate_{$exchangeRate}_iter_{$i}"] = [
                $payeeId,
                $accountId,
                $amountFc,
                $exchangeRate,
            ];
        }
        return $cases;
    }

    /**
     * Property 12: Collection create-then-delete round-trip
     *
     * After create then delete, the net delta passed to
     * updatePayeeBalance() must be zero.
     *
     * **Validates: Requirements 6.3, 6.4**
     *
     * @dataProvider createThenDeleteProvider
     */
    public function testCreateThenDeleteNetDeltaIsZero(
        int $payeeId,
        int $accountId,
        float $amountFc,
        float $exchangeRate
    ): void {
        $collectionId = mt_rand(1, 10000);
        $businessId   = mt_rand(1, 100);

        // Compute expected amount_bc the same way CollectionService does
        $expectedAmountBc = round($amountFc * $exchangeRate, 2);

        // Set up AuthContext
        AuthContext::set((object) [
            'user_id'     => 1,
            'tenant_id'   => 1,
            'business_id' => $businessId,
        ]);

        // Track payee balance deltas
        $balanceDeltas = [];

        // The record that findById will return after create
        $storedRecord = [
            'id'              => $collectionId,
            'business_id'     => $businessId,
            'account_id'      => $accountId,
            'account_name'    => 'Test Account',
            'payee_id'        => $payeeId,
            'payee_name'      => 'Test Payee',
            'reference_type'  => 'GENERAL',
            'reference_id'    => null,
            'slip_number'     => 'COL-001',
            'slip_date'       => '2025-01-15',
            'currency'        => 'USD',
            'exchange_rate'   => $exchangeRate,
            'amount_fc'       => $amountFc,
            'amount_bc'       => $expectedAmountBc,
            'method'          => 'CASH',
            'notes'           => null,
            'created_at'      => '2025-01-15',
            'updated_at'      => '2025-01-15',
            'created_by'      => 1,
        ];

        // -- Build stubs using anonymous subclasses --

        // Collection repository: create returns ID, findById returns stored record, delete is no-op
        $collectionRepo = new class($collectionId, $storedRecord) extends CollectionRepository {
            private int $returnId;
            private array $record;

            public function __construct(int $returnId, array $record)
            {
                // Skip parent constructor (needs PDO)
                $this->returnId = $returnId;
                $this->record   = $record;
            }

            public function create(array $data): int
            {
                return $this->returnId;
            }

            public function findById(int $id, int $businessId): ?array
            {
                return $this->record;
            }

            public function delete(int $id, int $businessId): void
            {
                // no-op
            }
        };

        // Account repository: updateBalance is no-op (we only care about payee balance)
        $accountRepo = new class extends AccountRepository {
            public function __construct()
            {
                // Skip parent constructor
            }

            public function findById(int $id, int $businessId): ?array
            {
                return ['id' => $id, 'business_id' => $businessId, 'currency' => 'USD', 'balance' => 0, 'balance_fc' => 0];
            }

            public function updateBalance(int $accountId, float $delta, PDO $db, float $deltaFc = 0.0): void
            {
                // no-op — we only track payee balance deltas in this test
            }
        };

        // People repository: tracks all updatePayeeBalance calls
        $peopleRepo = new class($balanceDeltas) extends PeopleRepository {
            private array $deltas;

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

        // PDO stub
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Build service with injected stubs
        $service = $this->buildServiceWithStubs($pdo, $collectionRepo, $accountRepo, $peopleRepo, $audit);

        // Step 1: Create collection (decreases payee balance by amount_bc)
        $service->create([
            'account_id'     => $accountId,
            'payee_id'       => $payeeId,
            'reference_type' => 'GENERAL',
            'slip_number'    => 'COL-001',
            'slip_date'      => '2025-01-15',
            'currency'       => 'USD',
            'exchange_rate'  => $exchangeRate,
            'amount_fc'      => $amountFc,
            'method'         => 'CASH',
        ]);

        // Step 2: Delete collection (should increase payee balance by amount_bc)
        $service->delete($collectionId);

        // Assert: exactly 2 payee balance calls were made
        $this->assertCount(
            2,
            $balanceDeltas,
            'Expected exactly 2 payee balance updates (one for create, one for delete)'
        );

        // Assert: both calls targeted the correct payee
        $this->assertSame(
            $payeeId,
            $balanceDeltas[0]['payeeId'],
            'Create balance update must target the correct payee'
        );
        $this->assertSame(
            $payeeId,
            $balanceDeltas[1]['payeeId'],
            'Delete balance update must target the correct payee'
        );

        // Assert: first delta is -amount_bc (create DECREASES payee balance)
        $this->assertEqualsWithDelta(
            -$expectedAmountBc,
            $balanceDeltas[0]['delta'],
            0.001,
            'First delta (create) should be -amount_bc (collection decreases payee balance)'
        );

        // Assert: second delta is +amount_bc (delete INCREASES payee balance)
        $this->assertEqualsWithDelta(
            $expectedAmountBc,
            $balanceDeltas[1]['delta'],
            0.001,
            'Second delta (delete) should be +amount_bc (delete reverses the decrease)'
        );

        // Assert: net delta is zero (round-trip property)
        $netDelta = $balanceDeltas[0]['delta'] + $balanceDeltas[1]['delta'];
        $this->assertEqualsWithDelta(
            0.0,
            $netDelta,
            0.001,
            sprintf(
                'Net payee balance delta must be zero after create-then-delete. '
                . 'Payee: %d, Account: %d, AmountFC: %.2f, Rate: %.2f, AmountBC: %.2f, '
                . 'Deltas: [%.2f, %.2f], Net: %.2f',
                $payeeId,
                $accountId,
                $amountFc,
                $exchangeRate,
                $expectedAmountBc,
                $balanceDeltas[0]['delta'],
                $balanceDeltas[1]['delta'],
                $netDelta
            )
        );
    }

    /**
     * Build a CollectionService with stubbed dependencies injected via reflection.
     */
    private function buildServiceWithStubs(
        PDO $pdo,
        CollectionRepository $collectionRepo,
        AccountRepository $accountRepo,
        PeopleRepository $peopleRepo,
        AuditService $audit
    ): CollectionService {
        $ref     = new ReflectionClass(CollectionService::class);
        $service = $ref->newInstanceWithoutConstructor();

        // Create an ExchangeRateService stub that returns the provided rate as-is
        $exchangeRateService = new class extends ExchangeRateService {
            public function __construct()
            {
                // Skip parent constructor
            }

            public function resolveRate(string $currency, ?float $providedRate): float
            {
                // In this test, exchange_rate is always provided, so just return it
                return $providedRate ?? 1.0;
            }
        };

        // SettlementEngine stub that returns empty arrays (no settlements)
        $settlementEngine = new class extends \App\Services\SettlementEngine {
            public function __construct() {}
            public function evaluateSupplierSettlement(int $payeeId, int $businessId): array { return []; }
            public function evaluateCustomerSettlement(int $payeeId, int $businessId): array { return []; }
        };

        $props = [
            'db'                  => $pdo,
            'repo'                => $collectionRepo,
            'accountRepo'         => $accountRepo,
            'peopleRepo'          => $peopleRepo,
            'exchangeRateService' => $exchangeRateService,
            'audit'               => $audit,
            'settlementEngine'    => $settlementEngine,
        ];

        foreach ($props as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($service, $value);
        }

        return $service;
    }
}
