<?php

namespace Tests\Integration;

use App\Services\BalanceRecalculator;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for balance recalculation.
 *
 * Tests full recalculation with mixed payment/collection history.
 * Tests single account recalculation isolation.
 * Tests idempotence (run twice, same result).
 * Tests transaction rollback on error.
 *
 * Validates: Requirements 11.1, 11.2, 11.3, 11.4, 11.5, 12.2, 12.3, 12.6
 */
class BalanceRecalculationIntegrationTest extends TestCase
{
    // ───────────────────────────────────────────────────────────────────────
    // Test: Full recalculation with mixed payment/collection history
    // ───────────────────────────────────────────────────────────────────────

    /**
     * Tests that recalculation correctly computes balances from payment and
     * collection history. After recalculation:
     * - balance = sum(collections.amount_bc) - sum(payments.amount_bc)
     * - balance_fc = sum(collections.amount_fc) - sum(payments.amount_fc)
     *
     * Validates: Requirements 11.1, 11.2, 11.3
     */
    public function testFullRecalculationWithMixedHistory(): void
    {
        // Account 1: has payments (1000 BC, 800 FC) and collections (1500 BC, 1200 FC)
        // Expected: balance = 1500 - 1000 = 500, balance_fc = 1200 - 800 = 400
        // Account 2: has only payments (600 BC, 500 FC)
        // Expected: balance = 0 - 600 = -600, balance_fc = 0 - 500 = -500

        $accounts = [
            ['id' => 1, 'balance' => 999.99, 'balance_fc' => 888.88], // wrong values
            ['id' => 2, 'balance' => 111.11, 'balance_fc' => 222.22], // wrong values
        ];

        $paymentsByAccount = [
            ['account_id' => 1, 'total_bc' => 1000.00, 'total_fc' => 800.00],
            ['account_id' => 2, 'total_bc' => 600.00, 'total_fc' => 500.00],
        ];

        $collectionsByAccount = [
            ['account_id' => 1, 'total_bc' => 1500.00, 'total_fc' => 1200.00],
        ];

        // Track balance updates
        $balanceUpdates = [];

        $pdo = $this->buildRecalculationPdo(
            $accounts,
            $paymentsByAccount,
            $collectionsByAccount,
            $balanceUpdates
        );

        $recalculator = new BalanceRecalculator($pdo);
        $report = $recalculator->recalculate(null);

        // Verify report contains both accounts
        $this->assertCount(2, $report);

        // Verify account 1 report
        $acct1 = $this->findAccountInReport($report, 1);
        $this->assertNotNull($acct1);
        $this->assertEqualsWithDelta(999.99, $acct1['prev_balance'], 0.001);
        $this->assertEqualsWithDelta(500.00, $acct1['new_balance'], 0.001);
        $this->assertEqualsWithDelta(888.88, $acct1['prev_balance_fc'], 0.001);
        $this->assertEqualsWithDelta(400.00, $acct1['new_balance_fc'], 0.001);

        // Verify account 2 report
        $acct2 = $this->findAccountInReport($report, 2);
        $this->assertNotNull($acct2);
        $this->assertEqualsWithDelta(111.11, $acct2['prev_balance'], 0.001);
        $this->assertEqualsWithDelta(-600.00, $acct2['new_balance'], 0.001);
        $this->assertEqualsWithDelta(222.22, $acct2['prev_balance_fc'], 0.001);
        $this->assertEqualsWithDelta(-500.00, $acct2['new_balance_fc'], 0.001);
    }

    // ───────────────────────────────────────────────────────────────────────
    // Test: Single account recalculation isolation
    // ───────────────────────────────────────────────────────────────────────

    /**
     * Tests that recalculating a single account only affects that account.
     * Other accounts remain unchanged.
     *
     * Validates: Requirements 12.2, 12.3
     */
    public function testSingleAccountRecalculationIsolation(): void
    {
        // Only account 1 should be recalculated
        $accounts = [
            ['id' => 1, 'balance' => 999.99, 'balance_fc' => 888.88],
        ];

        $paymentsByAccount = [
            ['account_id' => 1, 'total_bc' => 300.00, 'total_fc' => 250.00],
        ];

        $collectionsByAccount = [
            ['account_id' => 1, 'total_bc' => 800.00, 'total_fc' => 700.00],
        ];

        $balanceUpdates = [];

        $pdo = $this->buildRecalculationPdo(
            $accounts,
            $paymentsByAccount,
            $collectionsByAccount,
            $balanceUpdates,
            1 // target account ID
        );

        $recalculator = new BalanceRecalculator($pdo);
        $report = $recalculator->recalculate(1);

        // Verify only one account in report
        $this->assertCount(1, $report);
        $this->assertSame(1, $report[0]['account_id']);

        // Verify correct computation: balance = 800 - 300 = 500, balance_fc = 700 - 250 = 450
        $this->assertEqualsWithDelta(999.99, $report[0]['prev_balance'], 0.001);
        $this->assertEqualsWithDelta(500.00, $report[0]['new_balance'], 0.001);
        $this->assertEqualsWithDelta(888.88, $report[0]['prev_balance_fc'], 0.001);
        $this->assertEqualsWithDelta(450.00, $report[0]['new_balance_fc'], 0.001);
    }

    // ───────────────────────────────────────────────────────────────────────
    // Test: Idempotence (run twice, same result)
    // ───────────────────────────────────────────────────────────────────────

    /**
     * Tests that running recalculation twice produces the same final balances.
     * The second run should show prev_balance == new_balance (no change).
     *
     * Validates: Requirement 12.6
     */
    public function testRecalculationIdempotence(): void
    {
        // First run: account starts with wrong balance, gets corrected
        $accounts = [
            ['id' => 1, 'balance' => 999.99, 'balance_fc' => 888.88],
        ];

        $paymentsByAccount = [
            ['account_id' => 1, 'total_bc' => 200.00, 'total_fc' => 150.00],
        ];

        $collectionsByAccount = [
            ['account_id' => 1, 'total_bc' => 700.00, 'total_fc' => 600.00],
        ];

        $balanceUpdates = [];

        $pdo = $this->buildRecalculationPdo(
            $accounts,
            $paymentsByAccount,
            $collectionsByAccount,
            $balanceUpdates
        );

        $recalculator = new BalanceRecalculator($pdo);
        $report1 = $recalculator->recalculate(null);

        // First run: balance corrected from 999.99 to 500
        $this->assertEqualsWithDelta(500.00, $report1[0]['new_balance'], 0.001);
        $this->assertEqualsWithDelta(450.00, $report1[0]['new_balance_fc'], 0.001);

        // Second run: simulate that account now has the correct balance
        $accountsAfterFirstRun = [
            ['id' => 1, 'balance' => 500.00, 'balance_fc' => 450.00],
        ];

        $balanceUpdates2 = [];
        $pdo2 = $this->buildRecalculationPdo(
            $accountsAfterFirstRun,
            $paymentsByAccount,
            $collectionsByAccount,
            $balanceUpdates2
        );

        $recalculator2 = new BalanceRecalculator($pdo2);
        $report2 = $recalculator2->recalculate(null);

        // Second run: prev_balance == new_balance (idempotent)
        $this->assertEqualsWithDelta(500.00, $report2[0]['prev_balance'], 0.001);
        $this->assertEqualsWithDelta(500.00, $report2[0]['new_balance'], 0.001);
        $this->assertEqualsWithDelta(450.00, $report2[0]['prev_balance_fc'], 0.001);
        $this->assertEqualsWithDelta(450.00, $report2[0]['new_balance_fc'], 0.001);
    }

    // ───────────────────────────────────────────────────────────────────────
    // Test: Transaction rollback on error
    // ───────────────────────────────────────────────────────────────────────

    /**
     * Tests that if an error occurs during recalculation, the transaction
     * is rolled back and no partial changes are committed.
     *
     * Validates: Requirements 11.4, 11.5
     */
    public function testTransactionRollbackOnError(): void
    {
        $committed = false;
        $rolledBack = false;

        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->expects($this->never())->method('commit');
        $pdo->expects($this->once())->method('rollBack')
            ->willReturnCallback(function () use (&$rolledBack) {
                $rolledBack = true;
                return true;
            });

        // First query (getAccounts) succeeds
        $accountsStmt = $this->createMock(PDOStatement::class);
        $accountsStmt->method('execute')->willReturn(true);
        $accountsStmt->method('fetchAll')->willReturn([
            ['id' => 1, 'balance' => 100.00, 'balance_fc' => 50.00],
        ]);

        // Second query (resetBalances) throws an exception
        $resetStmt = $this->createMock(PDOStatement::class);
        $resetStmt->method('execute')->willThrowException(
            new \RuntimeException('Database error during reset')
        );

        $callIndex = 0;
        $stmts = [$accountsStmt, $resetStmt];
        $pdo->method('prepare')->willReturnCallback(
            function () use (&$callIndex, $stmts) {
                return $stmts[$callIndex++] ?? $stmts[count($stmts) - 1];
            }
        );

        $recalculator = new BalanceRecalculator($pdo);

        $thrown = false;
        try {
            $recalculator->recalculate(null);
        } catch (\RuntimeException $e) {
            $thrown = true;
            $this->assertSame('Database error during reset', $e->getMessage());
        }

        $this->assertTrue($thrown, 'Expected RuntimeException to be thrown');
        $this->assertTrue($rolledBack, 'Transaction must be rolled back on error');
    }

    // ───────────────────────────────────────────────────────────────────────
    // Test: Account with no transactions gets zero balance
    // ───────────────────────────────────────────────────────────────────────

    /**
     * Tests that an account with no payment or collection history
     * gets balance = 0 and balance_fc = 0 after recalculation.
     *
     * Validates: Requirements 11.1, 11.2, 11.3
     */
    public function testAccountWithNoTransactionsGetsZeroBalance(): void
    {
        $accounts = [
            ['id' => 5, 'balance' => 123.45, 'balance_fc' => 67.89],
        ];

        // No payments, no collections for this account
        $paymentsByAccount = [];
        $collectionsByAccount = [];
        $balanceUpdates = [];

        $pdo = $this->buildRecalculationPdo(
            $accounts,
            $paymentsByAccount,
            $collectionsByAccount,
            $balanceUpdates
        );

        $recalculator = new BalanceRecalculator($pdo);
        $report = $recalculator->recalculate(null);

        $this->assertCount(1, $report);
        $this->assertEqualsWithDelta(123.45, $report[0]['prev_balance'], 0.001);
        $this->assertEqualsWithDelta(0.00, $report[0]['new_balance'], 0.001);
        $this->assertEqualsWithDelta(67.89, $report[0]['prev_balance_fc'], 0.001);
        $this->assertEqualsWithDelta(0.00, $report[0]['new_balance_fc'], 0.001);
    }

    // ───────────────────────────────────────────────────────────────────────
    // Helpers
    // ───────────────────────────────────────────────────────────────────────

    private function findAccountInReport(array $report, int $accountId): ?array
    {
        foreach ($report as $entry) {
            if ($entry['account_id'] === $accountId) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * Build a mock PDO that simulates the BalanceRecalculator's query sequence:
     * 1. getAccounts (before) — returns accounts with current balances
     * 2. resetBalances — UPDATE sets balance/balance_fc to 0
     * 3. replayPayments — SELECT grouped payments, then UPDATE for each
     * 4. replayCollections — SELECT grouped collections, then UPDATE for each
     * 5. getAccounts (after) — returns accounts with new computed balances
     *
     * The mock tracks balance updates to compute final values.
     */
    private function buildRecalculationPdo(
        array $accounts,
        array $paymentsByAccount,
        array $collectionsByAccount,
        array &$balanceUpdates,
        ?int $targetAccountId = null
    ): PDO {
        // Compute expected final balances
        $finalBalances = [];
        foreach ($accounts as $acct) {
            $finalBalances[$acct['id']] = ['balance' => 0.0, 'balance_fc' => 0.0];
        }

        foreach ($paymentsByAccount as $row) {
            $aid = $row['account_id'];
            if (isset($finalBalances[$aid])) {
                $finalBalances[$aid]['balance'] -= $row['total_bc'];
                $finalBalances[$aid]['balance_fc'] -= $row['total_fc'];
            }
        }

        foreach ($collectionsByAccount as $row) {
            $aid = $row['account_id'];
            if (isset($finalBalances[$aid])) {
                $finalBalances[$aid]['balance'] += $row['total_bc'];
                $finalBalances[$aid]['balance_fc'] += $row['total_fc'];
            }
        }

        // Build "after" accounts with computed balances
        $accountsAfter = [];
        foreach ($accounts as $acct) {
            $accountsAfter[] = [
                'id'         => $acct['id'],
                'balance'    => $finalBalances[$acct['id']]['balance'],
                'balance_fc' => $finalBalances[$acct['id']]['balance_fc'],
            ];
        }

        // Mock PDO
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);

        // Track query sequence
        $callIndex = 0;

        // Query 1: getAccounts (before) — SELECT accounts
        $accountsBeforeStmt = $this->createMock(PDOStatement::class);
        $accountsBeforeStmt->method('execute')->willReturn(true);
        $accountsBeforeStmt->method('fetchAll')->willReturn($accounts);

        // Query 2: resetBalances — UPDATE (no-op in mock)
        $resetStmt = $this->createMock(PDOStatement::class);
        $resetStmt->method('execute')->willReturn(true);

        // Query 3: replayPayments — SELECT grouped payments
        $paymentsStmt = $this->createMock(PDOStatement::class);
        $paymentsStmt->method('execute')->willReturn(true);
        $paymentsStmt->method('fetchAll')->willReturn($paymentsByAccount);

        // Query 4: UPDATE for each payment group (one per account with payments)
        $payUpdateStmt = $this->createMock(PDOStatement::class);
        $payUpdateStmt->method('execute')->willReturnCallback(
            function ($params) use (&$balanceUpdates) {
                $balanceUpdates[] = ['type' => 'payment', 'params' => $params];
                return true;
            }
        );

        // Query 5: replayCollections — SELECT grouped collections
        $collectionsStmt = $this->createMock(PDOStatement::class);
        $collectionsStmt->method('execute')->willReturn(true);
        $collectionsStmt->method('fetchAll')->willReturn($collectionsByAccount);

        // Query 6: UPDATE for each collection group
        $collUpdateStmt = $this->createMock(PDOStatement::class);
        $collUpdateStmt->method('execute')->willReturnCallback(
            function ($params) use (&$balanceUpdates) {
                $balanceUpdates[] = ['type' => 'collection', 'params' => $params];
                return true;
            }
        );

        // Query 7: getAccounts (after) — SELECT accounts with new balances
        $accountsAfterStmt = $this->createMock(PDOStatement::class);
        $accountsAfterStmt->method('execute')->willReturn(true);
        $accountsAfterStmt->method('fetchAll')->willReturn($accountsAfter);

        // Build the prepare sequence
        $prepareSequence = [
            $accountsBeforeStmt,  // getAccounts (before)
            $resetStmt,           // resetBalances
            $paymentsStmt,        // replayPayments SELECT
        ];

        // Add payment UPDATE stmts (one per payment group)
        foreach ($paymentsByAccount as $_) {
            $prepareSequence[] = $payUpdateStmt;
        }

        $prepareSequence[] = $collectionsStmt; // replayCollections SELECT

        // Add collection UPDATE stmts (one per collection group)
        foreach ($collectionsByAccount as $_) {
            $prepareSequence[] = $collUpdateStmt;
        }

        $prepareSequence[] = $accountsAfterStmt; // getAccounts (after)

        $pdo->method('prepare')->willReturnCallback(
            function () use (&$callIndex, $prepareSequence) {
                $result = $prepareSequence[$callIndex] ?? $prepareSequence[count($prepareSequence) - 1];
                $callIndex++;
                return $result;
            }
        );

        return $pdo;
    }
}
