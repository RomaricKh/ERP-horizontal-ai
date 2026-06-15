<?php

namespace Tests\Property;

use App\Repository\PeopleRepository;
use App\Repository\SalaryRepository;
use App\Services\AuditService;
use App\Services\ExchangeRateService;
use App\Services\NotificationService;
use App\Services\SalaryService;
use App\Support\AuthContext;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Feature: people-balance-tracking, Property 4: Salary create-then-delete round-trip
 *
 * For any Salary with a payee and a positive net_amount_bc,
 * creating and then deleting the salary should return the
 * payee balance to its original value (net delta = 0).
 *
 * Validates: Requirements 4.1, 4.4
 */
class SalaryBalancePropertyTest extends TestCase
{
    private const ITERATIONS = 120;

    /**
     * Data provider generating 120 random gross amounts, tax amounts,
     * exchange rates, and payee IDs.
     *
     * Each case: [payeeId, grossAmountFc, taxAmountFc, exchangeRate]
     *
     * Constraints:
     *   - grossAmountFc in [0.01, 99999.99]
     *   - taxAmountFc in [0, grossAmountFc] (tax cannot exceed gross)
     *   - exchangeRate in [0.01, 100.00]
     */
    public static function createThenDeleteProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $payeeId       = mt_rand(1, 10000);
            $grossAmountFc = round(mt_rand(1, 9999999) / 100, 2);
            // Tax must be <= gross amount (service validates this)
            $taxAmountFc   = round(mt_rand(0, (int) ($grossAmountFc * 100)) / 100, 2);
            $exchangeRate  = round(mt_rand(1, 10000) / 100, 2);

            $cases["payee_{$payeeId}_gross_{$grossAmountFc}_tax_{$taxAmountFc}_rate_{$exchangeRate}_iter_{$i}"] = [
                $payeeId,
                $grossAmountFc,
                $taxAmountFc,
                $exchangeRate,
            ];
        }
        return $cases;
    }

    /**
     * Property 4: Salary create-then-delete round-trip
     *
     * After create then delete, the net delta passed to
     * updatePayeeBalance() must be zero.
     *
     * **Validates: Requirements 4.1, 4.4**
     *
     * @dataProvider createThenDeleteProvider
     */
    public function testCreateThenDeleteNetDeltaIsZero(
        int $payeeId,
        float $grossAmountFc,
        float $taxAmountFc,
        float $exchangeRate
    ): void {
        $salaryId   = mt_rand(1, 10000);
        $businessId = mt_rand(1, 100);

        // Compute expected net_amount_bc the same way SalaryService::calculateAmounts() does
        $grossAmountBc    = round($grossAmountFc * $exchangeRate, 2);
        $taxBc            = round($taxAmountFc * $exchangeRate, 2);
        $netAmountFc      = round($grossAmountFc - $taxAmountFc, 2);
        $expectedNetBc    = round($grossAmountBc - $taxBc, 2);

        // Set up AuthContext
        AuthContext::set((object) [
            'user_id'     => 1,
            'tenant_id'   => 1,
            'business_id' => $businessId,
        ]);

        // Track balance deltas
        $balanceDeltas = [];

        // The record that findById will return after create
        $storedRecord = [
            'id'               => $salaryId,
            'business_id'      => $businessId,
            'payee_id'         => $payeeId,
            'employee_name'    => 'Test Employee',
            'period_start'     => '2025-01-01',
            'period_end'       => '2025-01-31',
            'currency'         => 'USD',
            'exchange_rate'    => $exchangeRate,
            'gross_amount_fc'  => $grossAmountFc,
            'gross_amount_bc'  => $grossAmountBc,
            'tax_fc'           => $taxAmountFc,
            'tax_bc'           => $taxBc,
            'net_amount_fc'    => $netAmountFc,
            'net_amount_bc'    => $expectedNetBc,
            'notes'            => null,
            'created_at'       => '2025-01-15',
            'updated_at'       => '2025-01-15',
        ];

        // -- Build stubs using anonymous subclasses --

        // Salary repository: create returns ID, findById returns stored record, delete is no-op
        $salaryRepo = new class($salaryId, $storedRecord) extends SalaryRepository {
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
        $service = $this->buildServiceWithStubs($pdo, $salaryRepo, $peopleRepo, $audit);

        // Step 1: Create salary (increases payee balance by net_amount_bc)
        $service->create([
            'payee_id'        => $payeeId,
            'period_start'    => '2025-01-01',
            'period_end'      => '2025-01-31',
            'currency'        => 'USD',
            'exchange_rate'   => $exchangeRate,
            'gross_amount_fc' => $grossAmountFc,
            'tax_fc'          => $taxAmountFc,
        ]);

        // Step 2: Delete salary (should decrease payee balance by net_amount_bc)
        $service->delete($salaryId);

        // Assert: exactly 2 balance calls were made
        $this->assertCount(
            2,
            $balanceDeltas,
            'Expected exactly 2 balance updates (one for create, one for delete)'
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

        // Assert: first delta is -net_amount_bc (create: business owes payee)
        $this->assertEqualsWithDelta(
            -$expectedNetBc,
            $balanceDeltas[0]['delta'],
            0.001,
            'First delta (create) should be -net_amount_bc'
        );

        // Assert: second delta is +net_amount_bc (delete reverses balance)
        $this->assertEqualsWithDelta(
            $expectedNetBc,
            $balanceDeltas[1]['delta'],
            0.001,
            'Second delta (delete) should be +net_amount_bc'
        );

        // Assert: net delta is zero (round-trip property)
        $netDelta = $balanceDeltas[0]['delta'] + $balanceDeltas[1]['delta'];
        $this->assertEqualsWithDelta(
            0.0,
            $netDelta,
            0.001,
            sprintf(
                'Net balance delta must be zero after create-then-delete. '
                . 'Payee: %d, GrossFC: %.2f, TaxFC: %.2f, Rate: %.2f, NetBC: %.2f, '
                . 'Deltas: [%.2f, %.2f], Net: %.2f',
                $payeeId,
                $grossAmountFc,
                $taxAmountFc,
                $exchangeRate,
                $expectedNetBc,
                $balanceDeltas[0]['delta'],
                $balanceDeltas[1]['delta'],
                $netDelta
            )
        );
    }

    /**
     * Data provider generating 120 random old and new net_amount_bc values for edit delta testing.
     *
     * Each case: [payeeId, oldNetAmountBc, newNetAmountBc]
     * Uses exchange_rate=1.0 and tax_fc=0 so gross_amount_fc = net_amount_bc.
     */
    public static function amountEditDeltaProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $payeeId        = mt_rand(1, 10000);
            // Random old net_amount_bc between 0.01 and 99999.99
            $oldNetAmountBc = round(mt_rand(1, 9999999) / 100, 2);
            // Random new net_amount_bc between 0.01 and 99999.99 (may equal old)
            $newNetAmountBc = round(mt_rand(1, 9999999) / 100, 2);
            $cases["payee_{$payeeId}_old_{$oldNetAmountBc}_new_{$newNetAmountBc}_iter_{$i}"] = [
                $payeeId,
                $oldNetAmountBc,
                $newNetAmountBc,
            ];
        }
        return $cases;
    }

    /**
     * Property 7: Salary amount edit adjusts balance by exact delta
     *
     * Feature: people-balance-tracking, Property 7: Salary amount edit adjusts balance by exact delta
     *
     * For any Salary with a payee, when the net_amount_bc changes from old to new,
     * the payee balance should change by exactly (new - old).
     *
     * Uses exchange_rate=1.0 and tax_fc=0 so gross_amount_fc = net_amount_bc for simplicity.
     *
     * **Validates: Requirements 4.2, 9.2**
     *
     * @dataProvider amountEditDeltaProvider
     */
    public function testAmountEditAdjustsBalanceByExactDelta(
        int $payeeId,
        float $oldNetAmountBc,
        float $newNetAmountBc
    ): void {
        $salaryId   = mt_rand(1, 10000);
        $businessId = mt_rand(1, 100);

        $expectedDelta = round($newNetAmountBc - $oldNetAmountBc, 2);

        // Set up AuthContext
        AuthContext::set((object) [
            'user_id'     => 1,
            'tenant_id'   => 1,
            'business_id' => $businessId,
        ]);

        // Track balance deltas
        $balanceDeltas = [];

        // The "before" record that findById returns (the existing salary)
        // exchange_rate=1.0, tax_fc=0 so gross_amount_fc = net_amount_bc
        $beforeRecord = [
            'id'               => $salaryId,
            'business_id'      => $businessId,
            'payee_id'         => $payeeId,
            'employee_name'    => 'Test Employee',
            'period_start'     => '2025-01-01',
            'period_end'       => '2025-01-31',
            'currency'         => 'USD',
            'exchange_rate'    => 1.0,
            'gross_amount_fc'  => $oldNetAmountBc,
            'gross_amount_bc'  => $oldNetAmountBc,
            'tax_fc'           => 0.0,
            'tax_bc'           => 0.0,
            'net_amount_fc'    => $oldNetAmountBc,
            'net_amount_bc'    => $oldNetAmountBc,
            'notes'            => null,
            'created_at'       => '2025-01-15',
            'updated_at'       => '2025-01-15',
        ];

        // Salary repository stub: findById returns the before record, update is no-op
        $salaryRepo = new class($salaryId, $beforeRecord) extends SalaryRepository {
            private int $returnId;
            private array $record;

            public function __construct(int $returnId, array $record)
            {
                $this->returnId = $returnId;
                $this->record   = $record;
            }

            public function findById(int $id, int $businessId): ?array
            {
                return $this->record;
            }

            public function update(int $id, int $businessId, array $data): void
            {
                // no-op
            }
        };

        // People repository stub: tracks all updatePayeeBalance calls
        $peopleRepo = new class($balanceDeltas) extends PeopleRepository {
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

        // PDO stub
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Build service with injected stubs
        $service = $this->buildServiceWithStubs($pdo, $salaryRepo, $peopleRepo, $audit);

        // Call update with new amount (exchange_rate=1.0, tax_fc=0 so gross_amount_fc = net_amount_bc)
        $service->update($salaryId, [
            'payee_id'        => $payeeId,
            'period_start'    => '2025-01-01',
            'period_end'      => '2025-01-31',
            'currency'        => 'USD',
            'exchange_rate'   => 1.0,
            'gross_amount_fc' => $newNetAmountBc,
            'tax_fc'          => 0,
        ]);

        // When old and new net amounts are equal, no balance call should be made
        if ($expectedDelta == 0) {
            $this->assertCount(
                0,
                $balanceDeltas,
                'No balance update expected when net_amount_bc does not change'
            );
            return;
        }

        // Assert: exactly 1 balance call was made
        $this->assertCount(
            1,
            $balanceDeltas,
            sprintf(
                'Expected exactly 1 balance update for amount edit. Old: %.2f, New: %.2f',
                $oldNetAmountBc,
                $newNetAmountBc
            )
        );

        // Assert: the call targeted the correct payee
        $this->assertSame(
            $payeeId,
            $balanceDeltas[0]['payeeId'],
            'Balance update must target the correct payee'
        );

        // Assert: the delta is exactly -(new_net_amount_bc - old_net_amount_bc)
        $this->assertEqualsWithDelta(
            -$expectedDelta,
            $balanceDeltas[0]['delta'],
            0.001,
            sprintf(
                'Balance delta must be exactly -(new - old). '
                . 'Payee: %d, OldNetBC: %.2f, NewNetBC: %.2f, ExpectedDelta: %.2f, ActualDelta: %.2f',
                $payeeId,
                $oldNetAmountBc,
                $newNetAmountBc,
                -$expectedDelta,
                $balanceDeltas[0]['delta']
            )
        );
    }

    /**
     * Data provider generating 120 random payee A, payee B, old net_amount_bc, and new net_amount_bc
     * for payee change testing. Ensures payee A != payee B.
     *
     * Each case: [payeeA, payeeB, oldNetAmountBc, newNetAmountBc]
     * Uses exchange_rate=1.0 and tax_fc=0 so gross_amount_fc = net_amount_bc.
     */
    public static function payeeChangeProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $payeeA = mt_rand(1, 10000);
            // Ensure payee B is different from payee A
            do {
                $payeeB = mt_rand(1, 10000);
            } while ($payeeB === $payeeA);
            // Random old net_amount_bc between 0.01 and 99999.99
            $oldNetAmountBc = round(mt_rand(1, 9999999) / 100, 2);
            // Random new net_amount_bc between 0.01 and 99999.99
            $newNetAmountBc = round(mt_rand(1, 9999999) / 100, 2);
            $cases["payeeA_{$payeeA}_payeeB_{$payeeB}_old_{$oldNetAmountBc}_new_{$newNetAmountBc}_iter_{$i}"] = [
                $payeeA,
                $payeeB,
                $oldNetAmountBc,
                $newNetAmountBc,
            ];
        }
        return $cases;
    }

    /**
     * Property 8: Salary payee change transfers balance
     *
     * Feature: people-balance-tracking, Property 8: Salary payee change transfers balance
     *
     * For any Salary with a payee, when the payee_id changes from payee A to payee B,
     * payee A's balance should decrease by the old net_amount_bc and payee B's balance
     * should increase by the new net_amount_bc.
     *
     * Uses exchange_rate=1.0 and tax_fc=0 so gross_amount_fc = net_amount_bc for simplicity.
     *
     * **Validates: Requirements 4.3**
     *
     * @dataProvider payeeChangeProvider
     */
    public function testPayeeChangeTransfersBalance(
        int $payeeA,
        int $payeeB,
        float $oldNetAmountBc,
        float $newNetAmountBc
    ): void {
        $salaryId   = mt_rand(1, 10000);
        $businessId = mt_rand(1, 100);

        // Set up AuthContext
        AuthContext::set((object) [
            'user_id'     => 1,
            'tenant_id'   => 1,
            'business_id' => $businessId,
        ]);

        // Track balance deltas
        $balanceDeltas = [];

        // The "before" record: salary assigned to payee A with old net amount
        // exchange_rate=1.0, tax_fc=0 so gross_amount_fc = net_amount_bc
        $beforeRecord = [
            'id'               => $salaryId,
            'business_id'      => $businessId,
            'payee_id'         => $payeeA,
            'employee_name'    => 'Payee A Employee',
            'period_start'     => '2025-01-01',
            'period_end'       => '2025-01-31',
            'currency'         => 'USD',
            'exchange_rate'    => 1.0,
            'gross_amount_fc'  => $oldNetAmountBc,
            'gross_amount_bc'  => $oldNetAmountBc,
            'tax_fc'           => 0.0,
            'tax_bc'           => 0.0,
            'net_amount_fc'    => $oldNetAmountBc,
            'net_amount_bc'    => $oldNetAmountBc,
            'notes'            => null,
            'created_at'       => '2025-01-15',
            'updated_at'       => '2025-01-15',
        ];

        // Salary repository stub: findById returns the before record, update is no-op
        $salaryRepo = new class($salaryId, $beforeRecord) extends SalaryRepository {
            private int $returnId;
            private array $record;

            public function __construct(int $returnId, array $record)
            {
                $this->returnId = $returnId;
                $this->record   = $record;
            }

            public function findById(int $id, int $businessId): ?array
            {
                return $this->record;
            }

            public function update(int $id, int $businessId, array $data): void
            {
                // no-op
            }
        };

        // People repository stub: tracks all updatePayeeBalance calls
        $peopleRepo = new class($balanceDeltas) extends PeopleRepository {
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

        // PDO stub
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);

        // Build service with injected stubs
        $service = $this->buildServiceWithStubs($pdo, $salaryRepo, $peopleRepo, $audit);

        // Call update changing payee from A to B with new amount (exchange_rate=1.0, tax_fc=0)
        $service->update($salaryId, [
            'payee_id'        => $payeeB,
            'period_start'    => '2025-01-01',
            'period_end'      => '2025-01-31',
            'currency'        => 'USD',
            'exchange_rate'   => 1.0,
            'gross_amount_fc' => $newNetAmountBc,
            'tax_fc'          => 0,
        ]);

        // Assert: exactly 2 balance calls were made (one decrease for old payee, one increase for new payee)
        $this->assertCount(
            2,
            $balanceDeltas,
            sprintf(
                'Expected exactly 2 balance updates for payee change. '
                . 'PayeeA: %d, PayeeB: %d, OldNetBC: %.2f, NewNetBC: %.2f',
                $payeeA,
                $payeeB,
                $oldNetAmountBc,
                $newNetAmountBc
            )
        );

        // Assert: first call reverses old payee A by +old net_amount_bc (undo the original negative)
        $this->assertSame(
            $payeeA,
            $balanceDeltas[0]['payeeId'],
            'First balance update must target old payee A'
        );
        $this->assertEqualsWithDelta(
            $oldNetAmountBc,
            $balanceDeltas[0]['delta'],
            0.001,
            sprintf(
                'Old payee A balance must increase by old net_amount_bc (reverse original salary). '
                . 'PayeeA: %d, OldNetBC: %.2f, ActualDelta: %.2f',
                $payeeA,
                $oldNetAmountBc,
                $balanceDeltas[0]['delta']
            )
        );

        // Assert: second call assigns new payee B by -new net_amount_bc (business owes new payee)
        $this->assertSame(
            $payeeB,
            $balanceDeltas[1]['payeeId'],
            'Second balance update must target new payee B'
        );
        $this->assertEqualsWithDelta(
            -$newNetAmountBc,
            $balanceDeltas[1]['delta'],
            0.001,
            sprintf(
                'New payee B balance must decrease by new net_amount_bc (business owes new payee). '
                . 'PayeeB: %d, NewNetBC: %.2f, ActualDelta: %.2f',
                $payeeB,
                $newNetAmountBc,
                $balanceDeltas[1]['delta']
            )
        );
    }

    /**
     * Build a SalaryService with stubbed dependencies injected via reflection.
     */
    private function buildServiceWithStubs(
        PDO $pdo,
        SalaryRepository $salaryRepo,
        PeopleRepository $peopleRepo,
        AuditService $audit
    ): SalaryService {
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

        $notificationService = new class extends NotificationService {
            public function __construct() {}
            public function notify(
                string $type,
                string $title,
                string $body,
                $recipientIds,
                ?string $entityType = null,
                ?int $entityId = null,
                ?int $senderId = null
            ): void {
                // no-op
            }
        };

        $ref     = new ReflectionClass(SalaryService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $props = [
            'db'                  => $pdo,
            'repo'                => $salaryRepo,
            'peopleRepo'          => $peopleRepo,
            'exchangeRateService' => $exchangeRateService,
            'audit'               => $audit,
            'notificationService' => $notificationService,
        ];

        foreach ($props as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($service, $value);
        }

        return $service;
    }
}
