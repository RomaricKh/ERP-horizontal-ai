<?php

namespace Tests\Unit;

use App\Services\BalanceRecalculator;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for BalanceRecalculator service.
 *
 * Tests the recalculate method with various scenarios:
 * - No slips (balances stay at zero)
 * - Only payments (negative balance)
 * - Only collections (positive balance)
 * - Mixed slips (correct net balance)
 * - Single account recalculation
 * - Report format includes before/after values
 * - Transaction rollback on error
 *
 * Validates: Requirements 11.1, 11.2, 11.3, 11.4, 11.5, 12.2, 12.3, 12.4
 */
class BalanceRecalculatorTest extends TestCase
{
    /**
     * Test recalculate with no slips — balances stay at zero.
     *
     * Validates: Requirements 11.1, 11.2, 11.3
     */
    public function testRecalculateWithNoSlipsBalancesStayAtZero(): void
    {
        $accounts = [
            ['id' => 1, 'balance' => '100.00', 'balance_fc' => '50.00'],
        ];

        $pdo = $this->buildMockPdo($accounts, [], []);
        $recalculator = new BalanceRecalculator($pdo);
        $report = $recalculator->recalculate();

        $this->assertCount(1, $report);
        $this->assertSame(1, $report[0]['account_id']);
        $this->assertSame(100.0, $report[0]['prev_balance']);
        $this->assertSame(0.0, $report[0]['new_balance']);
        $this->assertSame(50.0, $report[0]['prev_balance_fc']);
        $this->assertSame(0.0, $report[0]['new_balance_fc']);
    }

    /**
     * Test recalculate with only payments — negative balance.
     *
     * Validates: Requirements 11.1, 11.2
     */
    public function testRecalculateWithOnlyPaymentsNegativeBalance(): void
    {
        $accounts = [
            ['id' => 1, 'balance' => '0.00', 'balance_fc' => '0.00'],
        ];
        $payments = [
            ['account_id' => 1, 'total_bc' => '300.00', 'total_fc' => '150.00'],
        ];

        $pdo = $this->buildMockPdo($accounts, $payments, []);
        $recalculator = new BalanceRecalculator($pdo);
        $report = $recalculator->recalculate();

        $this->assertCount(1, $report);
        $this->assertSame(-300.0, $report[0]['new_balance']);
        $this->assertSame(-150.0, $report[0]['new_balance_fc']);
    }

    /**
     * Test recalculate with only collections — positive balance.
     *
     * Validates: Requirements 11.1, 11.3
     */
    public function testRecalculateWithOnlyCollectionsPositiveBalance(): void
    {
        $accounts = [
            ['id' => 1, 'balance' => '0.00', 'balance_fc' => '0.00'],
        ];
        $collections = [
            ['account_id' => 1, 'total_bc' => '500.00', 'total_fc' => '250.00'],
        ];

        $pdo = $this->buildMockPdo($accounts, [], $collections);
        $recalculator = new BalanceRecalculator($pdo);
        $report = $recalculator->recalculate();

        $this->assertCount(1, $report);
        $this->assertSame(500.0, $report[0]['new_balance']);
        $this->assertSame(250.0, $report[0]['new_balance_fc']);
    }

    /**
     * Test recalculate with mixed slips — correct net balance.
     *
     * Validates: Requirements 11.1, 11.2, 11.3
     */
    public function testRecalculateWithMixedSlipsCorrectNetBalance(): void
    {
        $accounts = [
            ['id' => 1, 'balance' => '999.00', 'balance_fc' => '888.00'],
        ];
        $payments = [
            ['account_id' => 1, 'total_bc' => '200.00', 'total_fc' => '100.00'],
        ];
        $collections = [
            ['account_id' => 1, 'total_bc' => '500.00', 'total_fc' => '250.00'],
        ];

        $pdo = $this->buildMockPdo($accounts, $payments, $collections);
        $recalculator = new BalanceRecalculator($pdo);
        $report = $recalculator->recalculate();

        $this->assertCount(1, $report);
        // Net: 500 - 200 = 300 (bc), 250 - 100 = 150 (fc)
        $this->assertSame(300.0, $report[0]['new_balance']);
        $this->assertSame(150.0, $report[0]['new_balance_fc']);
        // Before values
        $this->assertSame(999.0, $report[0]['prev_balance']);
        $this->assertSame(888.0, $report[0]['prev_balance_fc']);
    }

    /**
     * Test single account recalculation — only processes that account.
     *
     * Validates: Requirements 12.2, 12.3
     */
    public function testSingleAccountRecalculation(): void
    {
        $accounts = [
            ['id' => 5, 'balance' => '100.00', 'balance_fc' => '50.00'],
        ];
        $payments = [
            ['account_id' => 5, 'total_bc' => '80.00', 'total_fc' => '40.00'],
        ];
        $collections = [
            ['account_id' => 5, 'total_bc' => '200.00', 'total_fc' => '100.00'],
        ];

        $pdo = $this->buildMockPdo($accounts, $payments, $collections, 5);
        $recalculator = new BalanceRecalculator($pdo);
        $report = $recalculator->recalculate(5);

        $this->assertCount(1, $report);
        $this->assertSame(5, $report[0]['account_id']);
        // Net: 200 - 80 = 120 (bc), 100 - 40 = 60 (fc)
        $this->assertSame(120.0, $report[0]['new_balance']);
        $this->assertSame(60.0, $report[0]['new_balance_fc']);
    }

    /**
     * Test report format includes all required fields.
     *
     * Validates: Requirements 12.4
     */
    public function testReportFormatIncludesBeforeAfterValues(): void
    {
        $accounts = [
            ['id' => 1, 'balance' => '50.00', 'balance_fc' => '25.00'],
            ['id' => 2, 'balance' => '75.00', 'balance_fc' => '37.50'],
        ];

        $pdo = $this->buildMockPdo($accounts, [], []);
        $recalculator = new BalanceRecalculator($pdo);
        $report = $recalculator->recalculate();

        $this->assertCount(2, $report);

        foreach ($report as $entry) {
            $this->assertArrayHasKey('account_id', $entry);
            $this->assertArrayHasKey('prev_balance', $entry);
            $this->assertArrayHasKey('new_balance', $entry);
            $this->assertArrayHasKey('prev_balance_fc', $entry);
            $this->assertArrayHasKey('new_balance_fc', $entry);
        }
    }

    /**
     * Test transaction rollback on error.
     *
     * Validates: Requirements 11.4, 11.5
     */
    public function testTransactionRollbackOnError(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->expects($this->once())->method('rollBack');
        $pdo->expects($this->never())->method('commit');

        // First prepare call (getAccounts) throws an exception
        $pdo->method('prepare')->willThrowException(new \RuntimeException('DB error'));

        $recalculator = new BalanceRecalculator($pdo);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DB error');
        $recalculator->recalculate();
    }

    /**
     * Test recalculate with multiple accounts and different slips.
     *
     * Validates: Requirements 11.1, 11.2, 11.3
     */
    public function testRecalculateMultipleAccountsWithDifferentSlips(): void
    {
        $accounts = [
            ['id' => 1, 'balance' => '0.00', 'balance_fc' => '0.00'],
            ['id' => 2, 'balance' => '0.00', 'balance_fc' => '0.00'],
        ];
        $payments = [
            ['account_id' => 1, 'total_bc' => '100.00', 'total_fc' => '50.00'],
            ['account_id' => 2, 'total_bc' => '200.00', 'total_fc' => '100.00'],
        ];
        $collections = [
            ['account_id' => 1, 'total_bc' => '300.00', 'total_fc' => '150.00'],
        ];

        $pdo = $this->buildMockPdo($accounts, $payments, $collections);
        $recalculator = new BalanceRecalculator($pdo);
        $report = $recalculator->recalculate();

        $this->assertCount(2, $report);

        // Account 1: 300 - 100 = 200 (bc), 150 - 50 = 100 (fc)
        $acct1 = $this->findReportEntry($report, 1);
        $this->assertSame(200.0, $acct1['new_balance']);
        $this->assertSame(100.0, $acct1['new_balance_fc']);

        // Account 2: 0 - 200 = -200 (bc), 0 - 100 = -100 (fc)
        $acct2 = $this->findReportEntry($report, 2);
        $this->assertSame(-200.0, $acct2['new_balance']);
        $this->assertSame(-100.0, $acct2['new_balance_fc']);
    }

    // ── Helper Methods ────────────────────────────────────────────────────

    private function findReportEntry(array $report, int $accountId): array
    {
        foreach ($report as $entry) {
            if ($entry['account_id'] === $accountId) {
                return $entry;
            }
        }
        $this->fail("Account {$accountId} not found in report");
    }

    /**
     * Build a mock PDO that simulates the BalanceRecalculator's query sequence.
     *
     * The recalculate method executes queries in this order:
     * 1. SELECT accounts (before) — getAccounts
     * 2. UPDATE reset balances — resetBalances
     * 3. SELECT payment sums — replayPayments
     * 4. UPDATE per payment group — replayPayments (one per group)
     * 5. SELECT collection sums — replayCollections
     * 6. UPDATE per collection group — replayCollections (one per group)
     * 7. SELECT accounts (after) — getAccounts
     *
     * We track balance state internally to simulate the DB updates.
     */
    private function buildMockPdo(
        array $accounts,
        array $payments,
        array $collections,
        ?int $accountId = null
    ): PDO {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);

        // Track balance state for simulation
        $balanceState = [];
        foreach ($accounts as $acct) {
            $balanceState[(int) $acct['id']] = [
                'balance'    => (float) $acct['balance'],
                'balance_fc' => (float) $acct['balance_fc'],
            ];
        }

        $callIndex = 0;

        $pdo->method('prepare')
            ->willReturnCallback(function (string $sql) use (
                &$callIndex,
                &$balanceState,
                $accounts,
                $payments,
                $collections,
                $accountId
            ) {
                $stmt = $this->createMock(PDOStatement::class);
                $stmt->method('execute')->willReturn(true);

                $currentCall = $callIndex++;

                // Call 0: SELECT accounts (before)
                if ($currentCall === 0) {
                    $stmt->method('fetchAll')->willReturn($accounts);
                    return $stmt;
                }

                // Call 1: UPDATE reset balances
                if ($currentCall === 1) {
                    foreach ($balanceState as $id => &$state) {
                        if ($accountId === null || $id === $accountId) {
                            $state['balance'] = 0.0;
                            $state['balance_fc'] = 0.0;
                        }
                    }
                    return $stmt;
                }

                // Call 2: SELECT payment sums
                if ($currentCall === 2) {
                    $stmt->method('fetchAll')->willReturn($payments);
                    return $stmt;
                }

                // Calls 3..3+N-1: UPDATE per payment group
                $paymentUpdateEnd = 3 + count($payments);
                if ($currentCall >= 3 && $currentCall < $paymentUpdateEnd) {
                    $payIdx = $currentCall - 3;
                    $pay = $payments[$payIdx];
                    $aid = (int) $pay['account_id'];
                    if (isset($balanceState[$aid])) {
                        $balanceState[$aid]['balance'] -= (float) $pay['total_bc'];
                        $balanceState[$aid]['balance_fc'] -= (float) $pay['total_fc'];
                    }
                    return $stmt;
                }

                // Next call: SELECT collection sums
                $collectionSelectCall = $paymentUpdateEnd;
                if ($currentCall === $collectionSelectCall) {
                    $stmt->method('fetchAll')->willReturn($collections);
                    return $stmt;
                }

                // Calls after: UPDATE per collection group
                $collectionUpdateStart = $collectionSelectCall + 1;
                $collectionUpdateEnd = $collectionUpdateStart + count($collections);
                if ($currentCall >= $collectionUpdateStart && $currentCall < $collectionUpdateEnd) {
                    $colIdx = $currentCall - $collectionUpdateStart;
                    $col = $collections[$colIdx];
                    $aid = (int) $col['account_id'];
                    if (isset($balanceState[$aid])) {
                        $balanceState[$aid]['balance'] += (float) $col['total_bc'];
                        $balanceState[$aid]['balance_fc'] += (float) $col['total_fc'];
                    }
                    return $stmt;
                }

                // Final call: SELECT accounts (after) — return updated state
                $afterAccounts = [];
                foreach ($balanceState as $id => $state) {
                    $afterAccounts[] = [
                        'id'         => $id,
                        'balance'    => (string) $state['balance'],
                        'balance_fc' => (string) $state['balance_fc'],
                    ];
                }
                $stmt->method('fetchAll')->willReturn($afterAccounts);
                return $stmt;
            });

        return $pdo;
    }
}
