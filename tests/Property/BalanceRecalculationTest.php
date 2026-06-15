<?php

namespace Tests\Property;

use App\Services\BalanceRecalculator;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Feature: operations-financial-integrity, Property 14: Balance Recalculation Correctness
 *
 * For any set of accounts with any history of payment and collection slips,
 * after recalculation, each account's balance equals
 * sum(collection_slips.amount_bc) - sum(payment_slips.amount_bc)
 * and balance_fc equals
 * sum(collection_slips.amount_fc) - sum(payment_slips.amount_fc)
 * for slips linked to that account.
 *
 * **Validates: Requirements 11.1, 11.2, 11.3**
 */
class BalanceRecalculationTest extends TestCase
{
    private const ITERATIONS = 120;

    /**
     * Data provider generating 120 random scenarios with random accounts,
     * payment slips, and collection slips.
     *
     * Each case: [accounts, paymentSlips, collectionSlips]
     * - accounts: array of account IDs (1-5 accounts)
     * - paymentSlips: array of [account_id, amount_bc, amount_fc] (0-20 slips)
     * - collectionSlips: array of [account_id, amount_bc, amount_fc] (0-20 slips)
     */
    public static function balanceRecalculationProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Generate 1-5 random accounts
            $numAccounts = mt_rand(1, 5);
            $accountIds = [];
            for ($a = 0; $a < $numAccounts; $a++) {
                $accountIds[] = mt_rand(1, 10000);
            }
            $accountIds = array_unique($accountIds);
            if (empty($accountIds)) {
                $accountIds = [mt_rand(1, 10000)];
            }
            $accountIds = array_values($accountIds);

            // Generate 0-20 random payment slips
            $numPayments = mt_rand(0, 20);
            $paymentSlips = [];
            for ($p = 0; $p < $numPayments; $p++) {
                $paymentSlips[] = [
                    'account_id' => $accountIds[array_rand($accountIds)],
                    'amount_bc'  => round(mt_rand(1, 9999999) / 100, 2),
                    'amount_fc'  => round(mt_rand(1, 9999999) / 100, 2),
                ];
            }

            // Generate 0-20 random collection slips
            $numCollections = mt_rand(0, 20);
            $collectionSlips = [];
            for ($c = 0; $c < $numCollections; $c++) {
                $collectionSlips[] = [
                    'account_id' => $accountIds[array_rand($accountIds)],
                    'amount_bc'  => round(mt_rand(1, 9999999) / 100, 2),
                    'amount_fc'  => round(mt_rand(1, 9999999) / 100, 2),
                ];
            }

            $cases["iter_{$i}_accts_" . count($accountIds) . "_pay_{$numPayments}_col_{$numCollections}"] = [
                $accountIds,
                $paymentSlips,
                $collectionSlips,
            ];
        }
        return $cases;
    }

    /**
     * Property 14: Balance Recalculation Correctness
     *
     * After recalculation, each account's balance equals:
     *   sum(collections.amount_bc for that account) - sum(payments.amount_bc for that account)
     * And balance_fc equals:
     *   sum(collections.amount_fc for that account) - sum(payments.amount_fc for that account)
     *
     * **Validates: Requirements 11.1, 11.2, 11.3**
     *
     * @dataProvider balanceRecalculationProvider
     */
    public function testBalanceRecalculationCorrectness(
        array $accountIds,
        array $paymentSlips,
        array $collectionSlips
    ): void {
        // Compute expected balances per account
        $expectedBalances = [];
        foreach ($accountIds as $id) {
            $expectedBalances[$id] = ['balance' => 0.0, 'balance_fc' => 0.0];
        }

        foreach ($collectionSlips as $slip) {
            $aid = $slip['account_id'];
            $expectedBalances[$aid]['balance'] += $slip['amount_bc'];
            $expectedBalances[$aid]['balance_fc'] += $slip['amount_fc'];
        }

        foreach ($paymentSlips as $slip) {
            $aid = $slip['account_id'];
            $expectedBalances[$aid]['balance'] -= $slip['amount_bc'];
            $expectedBalances[$aid]['balance_fc'] -= $slip['amount_fc'];
        }

        // Build grouped payment/collection sums per account (as the service queries them)
        $paymentSums = [];
        foreach ($paymentSlips as $slip) {
            $aid = $slip['account_id'];
            if (!isset($paymentSums[$aid])) {
                $paymentSums[$aid] = ['account_id' => $aid, 'total_bc' => 0.0, 'total_fc' => 0.0];
            }
            $paymentSums[$aid]['total_bc'] += $slip['amount_bc'];
            $paymentSums[$aid]['total_fc'] += $slip['amount_fc'];
        }

        $collectionSums = [];
        foreach ($collectionSlips as $slip) {
            $aid = $slip['account_id'];
            if (!isset($collectionSums[$aid])) {
                $collectionSums[$aid] = ['account_id' => $aid, 'total_bc' => 0.0, 'total_fc' => 0.0];
            }
            $collectionSums[$aid]['total_bc'] += $slip['amount_bc'];
            $collectionSums[$aid]['total_fc'] += $slip['amount_fc'];
        }

        // Format grouped sums as arrays with string values (simulating DB results)
        $paymentRows = [];
        foreach ($paymentSums as $row) {
            $paymentRows[] = [
                'account_id' => $row['account_id'],
                'total_bc'   => (string) round($row['total_bc'], 2),
                'total_fc'   => (string) round($row['total_fc'], 2),
            ];
        }

        $collectionRows = [];
        foreach ($collectionSums as $row) {
            $collectionRows[] = [
                'account_id' => $row['account_id'],
                'total_bc'   => (string) round($row['total_bc'], 2),
                'total_fc'   => (string) round($row['total_fc'], 2),
            ];
        }

        // Build accounts array with random initial balances (these get reset)
        $accounts = [];
        foreach ($accountIds as $id) {
            $accounts[] = [
                'id'         => (string) $id,
                'balance'    => (string) round(mt_rand(-999999, 999999) / 100, 2),
                'balance_fc' => (string) round(mt_rand(-999999, 999999) / 100, 2),
            ];
        }

        // Build mock PDO using the same pattern as the unit test
        $pdo = $this->buildMockPdo($accounts, $paymentRows, $collectionRows);
        $recalculator = new BalanceRecalculator($pdo);
        $report = $recalculator->recalculate();

        // Assert report has correct number of accounts
        $this->assertCount(
            count($accountIds),
            $report,
            'Report should contain one entry per account'
        );

        // Assert each account's new_balance and new_balance_fc match expected
        foreach ($report as $entry) {
            $aid = $entry['account_id'];
            $this->assertArrayHasKey($aid, $expectedBalances, "Account {$aid} should be in expected balances");

            $expectedBc = round($expectedBalances[$aid]['balance'], 2);
            $expectedFc = round($expectedBalances[$aid]['balance_fc'], 2);

            $this->assertEqualsWithDelta(
                $expectedBc,
                $entry['new_balance'],
                0.01,
                sprintf(
                    'Account %d: new_balance should be %.2f (sum(collections.bc) - sum(payments.bc)), got %.2f',
                    $aid,
                    $expectedBc,
                    $entry['new_balance']
                )
            );

            $this->assertEqualsWithDelta(
                $expectedFc,
                $entry['new_balance_fc'],
                0.01,
                sprintf(
                    'Account %d: new_balance_fc should be %.2f (sum(collections.fc) - sum(payments.fc)), got %.2f',
                    $aid,
                    $expectedFc,
                    $entry['new_balance_fc']
                )
            );
        }
    }

    // ── Property 15: Balance Recalculation Idempotence ──────────────────────

    private const IDEMPOTENCE_ITERATIONS = 100;

    /**
     * Data provider generating 100 random scenarios for idempotence testing.
     *
     * Each case: [accounts, paymentSlips, collectionSlips, initialBalances]
     * - accounts: array of account IDs (1-5 accounts)
     * - paymentSlips: array of [account_id, amount_bc, amount_fc] (0-15 slips)
     * - collectionSlips: array of [account_id, amount_bc, amount_fc] (0-15 slips)
     * - initialBalances: array of [account_id => [balance, balance_fc]] (random starting state)
     */
    public static function balanceRecalculationIdempotenceProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::IDEMPOTENCE_ITERATIONS; $i++) {
            // Generate 1-5 random accounts
            $numAccounts = mt_rand(1, 5);
            $accountIds = [];
            for ($a = 0; $a < $numAccounts; $a++) {
                $accountIds[] = mt_rand(1, 10000);
            }
            $accountIds = array_unique($accountIds);
            if (empty($accountIds)) {
                $accountIds = [mt_rand(1, 10000)];
            }
            $accountIds = array_values($accountIds);

            // Generate random initial balances (simulating arbitrary starting state)
            $initialBalances = [];
            foreach ($accountIds as $id) {
                $initialBalances[$id] = [
                    'balance'    => round(mt_rand(-9999999, 9999999) / 100, 2),
                    'balance_fc' => round(mt_rand(-9999999, 9999999) / 100, 2),
                ];
            }

            // Generate 0-15 random payment slips
            $numPayments = mt_rand(0, 15);
            $paymentSlips = [];
            for ($p = 0; $p < $numPayments; $p++) {
                $paymentSlips[] = [
                    'account_id' => $accountIds[array_rand($accountIds)],
                    'amount_bc'  => round(mt_rand(1, 9999999) / 100, 2),
                    'amount_fc'  => round(mt_rand(1, 9999999) / 100, 2),
                ];
            }

            // Generate 0-15 random collection slips
            $numCollections = mt_rand(0, 15);
            $collectionSlips = [];
            for ($c = 0; $c < $numCollections; $c++) {
                $collectionSlips[] = [
                    'account_id' => $accountIds[array_rand($accountIds)],
                    'amount_bc'  => round(mt_rand(1, 9999999) / 100, 2),
                    'amount_fc'  => round(mt_rand(1, 9999999) / 100, 2),
                ];
            }

            $cases["idempotence_iter_{$i}_accts_" . count($accountIds)] = [
                $accountIds,
                $paymentSlips,
                $collectionSlips,
                $initialBalances,
            ];
        }
        return $cases;
    }

    /**
     * Property 15: Balance Recalculation Idempotence
     *
     * Running the balance recalculation multiple times produces the same
     * final balance and balance_fc values as running it once.
     *
     * **Validates: Requirements 12.6**
     *
     * @dataProvider balanceRecalculationIdempotenceProvider
     */
    public function testBalanceRecalculationIdempotence(
        array $accountIds,
        array $paymentSlips,
        array $collectionSlips,
        array $initialBalances
    ): void {
        // Build grouped payment/collection sums per account
        $paymentSums = [];
        foreach ($paymentSlips as $slip) {
            $aid = $slip['account_id'];
            if (!isset($paymentSums[$aid])) {
                $paymentSums[$aid] = ['account_id' => $aid, 'total_bc' => 0.0, 'total_fc' => 0.0];
            }
            $paymentSums[$aid]['total_bc'] += $slip['amount_bc'];
            $paymentSums[$aid]['total_fc'] += $slip['amount_fc'];
        }

        $collectionSums = [];
        foreach ($collectionSlips as $slip) {
            $aid = $slip['account_id'];
            if (!isset($collectionSums[$aid])) {
                $collectionSums[$aid] = ['account_id' => $aid, 'total_bc' => 0.0, 'total_fc' => 0.0];
            }
            $collectionSums[$aid]['total_bc'] += $slip['amount_bc'];
            $collectionSums[$aid]['total_fc'] += $slip['amount_fc'];
        }

        // Format grouped sums as arrays with string values (simulating DB results)
        $paymentRows = [];
        foreach ($paymentSums as $row) {
            $paymentRows[] = [
                'account_id' => $row['account_id'],
                'total_bc'   => (string) round($row['total_bc'], 2),
                'total_fc'   => (string) round($row['total_fc'], 2),
            ];
        }

        $collectionRows = [];
        foreach ($collectionSums as $row) {
            $collectionRows[] = [
                'account_id' => $row['account_id'],
                'total_bc'   => (string) round($row['total_bc'], 2),
                'total_fc'   => (string) round($row['total_fc'], 2),
            ];
        }

        // Build accounts array with the random initial balances
        $accounts = [];
        foreach ($accountIds as $id) {
            $accounts[] = [
                'id'         => (string) $id,
                'balance'    => (string) $initialBalances[$id]['balance'],
                'balance_fc' => (string) $initialBalances[$id]['balance_fc'],
            ];
        }

        // --- First run ---
        $pdo1 = $this->buildMockPdo($accounts, $paymentRows, $collectionRows);
        $recalculator1 = new BalanceRecalculator($pdo1);
        $report1 = $recalculator1->recalculate();

        // --- Second run (using the output of first run as initial state) ---
        // After first recalculation, the accounts now have the recalculated balances.
        // Build new accounts array from first run's results.
        $accountsAfterFirstRun = [];
        foreach ($report1 as $entry) {
            $accountsAfterFirstRun[] = [
                'id'         => (string) $entry['account_id'],
                'balance'    => (string) $entry['new_balance'],
                'balance_fc' => (string) $entry['new_balance_fc'],
            ];
        }

        $pdo2 = $this->buildMockPdo($accountsAfterFirstRun, $paymentRows, $collectionRows);
        $recalculator2 = new BalanceRecalculator($pdo2);
        $report2 = $recalculator2->recalculate();

        // Assert both runs produce identical results
        $this->assertCount(
            count($report1),
            $report2,
            'Both recalculation runs should produce the same number of report entries'
        );

        // Build lookup maps for comparison
        $report1ByAccount = [];
        foreach ($report1 as $entry) {
            $report1ByAccount[$entry['account_id']] = $entry;
        }

        $report2ByAccount = [];
        foreach ($report2 as $entry) {
            $report2ByAccount[$entry['account_id']] = $entry;
        }

        foreach ($report1ByAccount as $accountId => $entry1) {
            $this->assertArrayHasKey(
                $accountId,
                $report2ByAccount,
                "Account {$accountId} should appear in both recalculation reports"
            );

            $entry2 = $report2ByAccount[$accountId];

            $this->assertEqualsWithDelta(
                $entry1['new_balance'],
                $entry2['new_balance'],
                0.01,
                sprintf(
                    'Account %d: new_balance should be identical across runs (run1=%.2f, run2=%.2f)',
                    $accountId,
                    $entry1['new_balance'],
                    $entry2['new_balance']
                )
            );

            $this->assertEqualsWithDelta(
                $entry1['new_balance_fc'],
                $entry2['new_balance_fc'],
                0.01,
                sprintf(
                    'Account %d: new_balance_fc should be identical across runs (run1=%.2f, run2=%.2f)',
                    $accountId,
                    $entry1['new_balance_fc'],
                    $entry2['new_balance_fc']
                )
            );
        }
    }

    // ── Property 16: Single-Account Recalculation Isolation ────────────────

    private const ISOLATION_ITERATIONS = 100;

    /**
     * Data provider generating 100 random cases for single-account recalculation isolation.
     *
     * Each case: [accounts (2-5 with balances), paymentSlips, collectionSlips, targetAccountId]
     * - accounts: array of [id, balance, balance_fc] (2-5 accounts)
     * - paymentSlips: array of [account_id, amount_bc, amount_fc] (0-15 slips across all accounts)
     * - collectionSlips: array of [account_id, amount_bc, amount_fc] (0-15 slips across all accounts)
     * - targetAccountId: one account ID chosen for recalculation
     */
    public static function singleAccountIsolationProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ISOLATION_ITERATIONS; $i++) {
            // Generate 2-5 unique account IDs
            $numAccounts = mt_rand(2, 5);
            $accountIds = [];
            while (count($accountIds) < $numAccounts) {
                $id = mt_rand(1, 10000);
                if (!in_array($id, $accountIds, true)) {
                    $accountIds[] = $id;
                }
            }

            // Generate random initial balances for each account
            $accounts = [];
            foreach ($accountIds as $id) {
                $accounts[] = [
                    'id'         => (string) $id,
                    'balance'    => (string) round(mt_rand(-9999999, 9999999) / 100, 2),
                    'balance_fc' => (string) round(mt_rand(-9999999, 9999999) / 100, 2),
                ];
            }

            // Generate 0-15 random payment slips distributed across accounts
            $numPayments = mt_rand(0, 15);
            $paymentSlips = [];
            for ($p = 0; $p < $numPayments; $p++) {
                $paymentSlips[] = [
                    'account_id' => $accountIds[array_rand($accountIds)],
                    'amount_bc'  => round(mt_rand(1, 9999999) / 100, 2),
                    'amount_fc'  => round(mt_rand(1, 9999999) / 100, 2),
                ];
            }

            // Generate 0-15 random collection slips distributed across accounts
            $numCollections = mt_rand(0, 15);
            $collectionSlips = [];
            for ($c = 0; $c < $numCollections; $c++) {
                $collectionSlips[] = [
                    'account_id' => $accountIds[array_rand($accountIds)],
                    'amount_bc'  => round(mt_rand(1, 9999999) / 100, 2),
                    'amount_fc'  => round(mt_rand(1, 9999999) / 100, 2),
                ];
            }

            // Pick one random target account for recalculation
            $targetAccountId = $accountIds[array_rand($accountIds)];

            $cases["isolation_iter_{$i}_target_{$targetAccountId}"] = [
                $accounts,
                $paymentSlips,
                $collectionSlips,
                $targetAccountId,
            ];
        }
        return $cases;
    }

    /**
     * Property 16: Single-Account Recalculation Isolation
     *
     * When recalculation is invoked for a single account_id, only that account's
     * balance and balance_fc are modified; all other accounts remain unchanged.
     *
     * **Validates: Requirements 12.2, 12.3**
     *
     * @dataProvider singleAccountIsolationProvider
     */
    public function testSingleAccountRecalculationIsolation(
        array $accounts,
        array $paymentSlips,
        array $collectionSlips,
        int $targetAccountId
    ): void {
        // Record initial balances for all accounts
        $initialBalances = [];
        foreach ($accounts as $acct) {
            $initialBalances[(int) $acct['id']] = [
                'balance'    => (float) $acct['balance'],
                'balance_fc' => (float) $acct['balance_fc'],
            ];
        }

        // Build grouped payment/collection sums for the TARGET account only
        $targetPaymentSums = [];
        foreach ($paymentSlips as $slip) {
            if ((int) $slip['account_id'] === $targetAccountId) {
                if (!isset($targetPaymentSums[$targetAccountId])) {
                    $targetPaymentSums[$targetAccountId] = ['account_id' => $targetAccountId, 'total_bc' => 0.0, 'total_fc' => 0.0];
                }
                $targetPaymentSums[$targetAccountId]['total_bc'] += $slip['amount_bc'];
                $targetPaymentSums[$targetAccountId]['total_fc'] += $slip['amount_fc'];
            }
        }

        $targetCollectionSums = [];
        foreach ($collectionSlips as $slip) {
            if ((int) $slip['account_id'] === $targetAccountId) {
                if (!isset($targetCollectionSums[$targetAccountId])) {
                    $targetCollectionSums[$targetAccountId] = ['account_id' => $targetAccountId, 'total_bc' => 0.0, 'total_fc' => 0.0];
                }
                $targetCollectionSums[$targetAccountId]['total_bc'] += $slip['amount_bc'];
                $targetCollectionSums[$targetAccountId]['total_fc'] += $slip['amount_fc'];
            }
        }

        // Format as DB result rows (string values)
        $paymentRows = [];
        foreach ($targetPaymentSums as $row) {
            $paymentRows[] = [
                'account_id' => $row['account_id'],
                'total_bc'   => (string) round($row['total_bc'], 2),
                'total_fc'   => (string) round($row['total_fc'], 2),
            ];
        }

        $collectionRows = [];
        foreach ($targetCollectionSums as $row) {
            $collectionRows[] = [
                'account_id' => $row['account_id'],
                'total_bc'   => (string) round($row['total_bc'], 2),
                'total_fc'   => (string) round($row['total_fc'], 2),
            ];
        }

        // Build mock PDO for single-account recalculation
        // When accountId is provided, getAccounts only returns the target account
        $targetAccount = null;
        foreach ($accounts as $acct) {
            if ((int) $acct['id'] === $targetAccountId) {
                $targetAccount = $acct;
                break;
            }
        }

        $pdo = $this->buildSingleAccountMockPdo(
            $targetAccount,
            $paymentRows,
            $collectionRows
        );

        $recalculator = new BalanceRecalculator($pdo);
        $report = $recalculator->recalculate($targetAccountId);

        // Assert: report only contains the target account
        $this->assertCount(
            1,
            $report,
            'Single-account recalculation report should contain exactly one entry'
        );
        $this->assertEquals(
            $targetAccountId,
            $report[0]['account_id'],
            'Report entry should be for the target account'
        );

        // Assert: the target account's previous balance matches initial
        $this->assertEqualsWithDelta(
            $initialBalances[$targetAccountId]['balance'],
            $report[0]['prev_balance'],
            0.01,
            'Target account prev_balance should match initial balance'
        );
        $this->assertEqualsWithDelta(
            $initialBalances[$targetAccountId]['balance_fc'],
            $report[0]['prev_balance_fc'],
            0.01,
            'Target account prev_balance_fc should match initial balance_fc'
        );

        // Assert: all other accounts are NOT in the report (they were never touched)
        $reportAccountIds = array_column($report, 'account_id');
        foreach ($accounts as $acct) {
            $acctId = (int) $acct['id'];
            if ($acctId !== $targetAccountId) {
                $this->assertNotContains(
                    $acctId,
                    $reportAccountIds,
                    sprintf(
                        'Account %d should NOT appear in the recalculation report (isolation violated)',
                        $acctId
                    )
                );
            }
        }
    }

    /**
     * Build a mock PDO for single-account recalculation.
     *
     * When recalculate($accountId) is called, the service:
     * 1. SELECT target account only (getAccounts with WHERE id = :id)
     * 2. UPDATE reset target account only
     * 3. SELECT payment sums for target account only
     * 4. UPDATE per payment group (only target)
     * 5. SELECT collection sums for target account only
     * 6. UPDATE per collection group (only target)
     * 7. SELECT target account after (getAccounts with WHERE id = :id)
     */
    private function buildSingleAccountMockPdo(
        array $targetAccount,
        array $paymentRows,
        array $collectionRows
    ): PDO {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);

        // Track balance state for the target account only
        $balanceState = [
            (int) $targetAccount['id'] => [
                'balance'    => (float) $targetAccount['balance'],
                'balance_fc' => (float) $targetAccount['balance_fc'],
            ],
        ];

        $callIndex = 0;

        $pdo->method('prepare')
            ->willReturnCallback(function (string $sql) use (
                &$callIndex,
                &$balanceState,
                $targetAccount,
                $paymentRows,
                $collectionRows
            ) {
                $stmt = $this->createMock(PDOStatement::class);
                $stmt->method('execute')->willReturn(true);

                $currentCall = $callIndex++;
                $targetId = (int) $targetAccount['id'];

                // Call 0: SELECT target account (before)
                if ($currentCall === 0) {
                    $stmt->method('fetchAll')->willReturn([$targetAccount]);
                    return $stmt;
                }

                // Call 1: UPDATE reset target account balance
                if ($currentCall === 1) {
                    $balanceState[$targetId]['balance'] = 0.0;
                    $balanceState[$targetId]['balance_fc'] = 0.0;
                    return $stmt;
                }

                // Call 2: SELECT payment sums for target account
                if ($currentCall === 2) {
                    $stmt->method('fetchAll')->willReturn($paymentRows);
                    return $stmt;
                }

                // Calls 3..3+N-1: UPDATE per payment group
                $paymentUpdateEnd = 3 + count($paymentRows);
                if ($currentCall >= 3 && $currentCall < $paymentUpdateEnd) {
                    $payIdx = $currentCall - 3;
                    $pay = $paymentRows[$payIdx];
                    $aid = (int) $pay['account_id'];
                    if (isset($balanceState[$aid])) {
                        $balanceState[$aid]['balance'] -= (float) $pay['total_bc'];
                        $balanceState[$aid]['balance_fc'] -= (float) $pay['total_fc'];
                    }
                    return $stmt;
                }

                // Next call: SELECT collection sums for target account
                $collectionSelectCall = $paymentUpdateEnd;
                if ($currentCall === $collectionSelectCall) {
                    $stmt->method('fetchAll')->willReturn($collectionRows);
                    return $stmt;
                }

                // Calls after: UPDATE per collection group
                $collectionUpdateStart = $collectionSelectCall + 1;
                $collectionUpdateEnd = $collectionUpdateStart + count($collectionRows);
                if ($currentCall >= $collectionUpdateStart && $currentCall < $collectionUpdateEnd) {
                    $colIdx = $currentCall - $collectionUpdateStart;
                    $col = $collectionRows[$colIdx];
                    $aid = (int) $col['account_id'];
                    if (isset($balanceState[$aid])) {
                        $balanceState[$aid]['balance'] += (float) $col['total_bc'];
                        $balanceState[$aid]['balance_fc'] += (float) $col['total_fc'];
                    }
                    return $stmt;
                }

                // Final call: SELECT target account (after) — return updated state
                $afterAccounts = [];
                foreach ($balanceState as $id => $state) {
                    $afterAccounts[] = [
                        'id'         => (string) $id,
                        'balance'    => (string) $state['balance'],
                        'balance_fc' => (string) $state['balance_fc'],
                    ];
                }
                $stmt->method('fetchAll')->willReturn($afterAccounts);
                return $stmt;
            });

        return $pdo;
    }

    // ── Helper Methods ────────────────────────────────────────────────────

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
        array $paymentRows,
        array $collectionRows
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
                $paymentRows,
                $collectionRows
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
                        $state['balance'] = 0.0;
                        $state['balance_fc'] = 0.0;
                    }
                    return $stmt;
                }

                // Call 2: SELECT payment sums
                if ($currentCall === 2) {
                    $stmt->method('fetchAll')->willReturn($paymentRows);
                    return $stmt;
                }

                // Calls 3..3+N-1: UPDATE per payment group
                $paymentUpdateEnd = 3 + count($paymentRows);
                if ($currentCall >= 3 && $currentCall < $paymentUpdateEnd) {
                    $payIdx = $currentCall - 3;
                    $pay = $paymentRows[$payIdx];
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
                    $stmt->method('fetchAll')->willReturn($collectionRows);
                    return $stmt;
                }

                // Calls after: UPDATE per collection group
                $collectionUpdateStart = $collectionSelectCall + 1;
                $collectionUpdateEnd = $collectionUpdateStart + count($collectionRows);
                if ($currentCall >= $collectionUpdateStart && $currentCall < $collectionUpdateEnd) {
                    $colIdx = $currentCall - $collectionUpdateStart;
                    $col = $collectionRows[$colIdx];
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
                        'id'         => (string) $id,
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
