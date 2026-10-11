<?php

namespace Tests\Property;

use App\Services\PayeeStatementService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Property 10: Running balance computation is algebraically correct
 *
 * For any opening balance and any sequence of transactions with debit_amount
 * and credit_amount fields, the running balance at position N SHALL equal
 * opening_balance + SUM(debit_amount[0..N] + credit_amount[0..N]),
 * and the closing balance SHALL equal the running balance of the last transaction.
 *
 * **Validates: Requirements 5.5**
 */
class StatementBalancePropertyTest extends TestCase
{
    private const ITERATIONS = 100;

    /**
     * Create a PayeeStatementService instance with minimal stubs
     * (only computeRunningBalances is a pure function, no DB needed).
     */
    private function createService(): PayeeStatementService
    {
        // Create a mock PDO that won't be used (computeRunningBalances is pure)
        $pdo = $this->createMock(PDO::class);

        // Use reflection to bypass constructor DB dependencies
        $reflector = new \ReflectionClass(PayeeStatementService::class);
        $service = $reflector->newInstanceWithoutConstructor();

        return $service;
    }

    // -------------------------------------------------------------------------
    // Property 10a: Running balance at position N = opening + SUM(debit+credit from 0..N)
    // -------------------------------------------------------------------------

    /**
     * Data provider generating random opening balances and transaction sequences.
     */
    public static function runningBalanceProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Opening balance: random between -100000 and 100000
            $openingBalance = round((mt_rand(-10000000, 10000000) / 100), 2);

            // Random number of transactions (1 to 20)
            $txnCount = mt_rand(1, 20);
            $transactions = [];
            for ($j = 0; $j < $txnCount; $j++) {
                $transactions[] = [
                    'debit_amount' => round((mt_rand(-5000000, 5000000) / 100), 2),
                    'credit_amount' => round((mt_rand(-5000000, 5000000) / 100), 2),
                    'description' => "Transaction {$j}",
                    'date' => '2025-01-' . str_pad(mt_rand(1, 28), 2, '0', STR_PAD_LEFT),
                ];
            }

            $cases["opening_{$openingBalance}_txns_{$txnCount}_iter_{$i}"] = [
                $openingBalance,
                $transactions,
            ];
        }
        return $cases;
    }

    /**
     * Property 10a: Running balance at position N = opening_balance + SUM(debit_amount[0..N] + credit_amount[0..N])
     *
     * **Validates: Requirements 5.5**
     *
     * @dataProvider runningBalanceProvider
     */
    public function testRunningBalanceAtPositionNIsAlgebraicallyCorrect(
        float $openingBalance,
        array $transactions
    ): void {
        $service = $this->createService();
        $result = $service->computeRunningBalances($openingBalance, $transactions);

        // Verify running balance at each position N
        $cumulativeSum = 0.0;
        for ($n = 0; $n < count($result); $n++) {
            $cumulativeSum += (float)$transactions[$n]['debit_amount'] + (float)$transactions[$n]['credit_amount'];
            $expected = round($openingBalance + $cumulativeSum, 2);
            $actual = round((float)$result[$n]['running_balance'], 2);

            $this->assertEqualsWithDelta(
                $expected,
                $actual,
                0.001,
                "Running balance at position {$n} should be opening ({$openingBalance}) + cumulative sum ({$cumulativeSum}) = {$expected}, got {$actual}"
            );
        }
    }

    // -------------------------------------------------------------------------
    // Property 10b: Last transaction's running_balance = closing balance
    // -------------------------------------------------------------------------

    /**
     * Data provider for closing balance test.
     */
    public static function closingBalanceProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $openingBalance = round((mt_rand(-10000000, 10000000) / 100), 2);
            $txnCount = mt_rand(1, 15);
            $transactions = [];
            for ($j = 0; $j < $txnCount; $j++) {
                $transactions[] = [
                    'debit_amount' => round((mt_rand(-5000000, 5000000) / 100), 2),
                    'credit_amount' => round((mt_rand(-5000000, 5000000) / 100), 2),
                    'description' => "Txn {$j}",
                    'date' => '2025-02-' . str_pad(mt_rand(1, 28), 2, '0', STR_PAD_LEFT),
                ];
            }

            $cases["closing_balance_iter_{$i}"] = [
                $openingBalance,
                $transactions,
            ];
        }
        return $cases;
    }

    /**
     * Property 10b: Last transaction's running_balance equals the closing balance.
     *
     * The closing balance SHALL equal the running balance of the last transaction.
     *
     * **Validates: Requirements 5.5**
     *
     * @dataProvider closingBalanceProvider
     */
    public function testClosingBalanceEqualsLastRunningBalance(
        float $openingBalance,
        array $transactions
    ): void {
        $service = $this->createService();
        $result = $service->computeRunningBalances($openingBalance, $transactions);

        // Compute expected closing balance = opening + sum of all debits and credits
        $totalSum = 0.0;
        foreach ($transactions as $txn) {
            $totalSum += (float)$txn['debit_amount'] + (float)$txn['credit_amount'];
        }
        $expectedClosing = round($openingBalance + $totalSum, 2);

        // Last transaction's running_balance should be the closing balance
        $lastRunningBalance = round((float)$result[count($result) - 1]['running_balance'], 2);

        $this->assertEqualsWithDelta(
            $expectedClosing,
            $lastRunningBalance,
            0.001,
            "Closing balance should equal last running balance: expected {$expectedClosing}, got {$lastRunningBalance}"
        );
    }

    // -------------------------------------------------------------------------
    // Property 10c: Empty transaction list returns empty array
    // -------------------------------------------------------------------------

    /**
     * Data provider for empty transactions with various opening balances.
     */
    public static function emptyTransactionProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < 50; $i++) {
            $openingBalance = round((mt_rand(-10000000, 10000000) / 100), 2);
            $cases["empty_txns_opening_{$openingBalance}_iter_{$i}"] = [$openingBalance];
        }
        return $cases;
    }

    /**
     * Property 10c: Empty transaction list returns empty array.
     *
     * **Validates: Requirements 5.5**
     *
     * @dataProvider emptyTransactionProvider
     */
    public function testEmptyTransactionListReturnsEmptyArray(float $openingBalance): void
    {
        $service = $this->createService();
        $result = $service->computeRunningBalances($openingBalance, []);

        $this->assertIsArray($result);
        $this->assertEmpty($result, "Empty transaction input should return empty array");
    }

    // -------------------------------------------------------------------------
    // Property 10d: Single transaction: running_balance = opening + debit + credit
    // -------------------------------------------------------------------------

    /**
     * Data provider for single transaction cases.
     */
    public static function singleTransactionProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $openingBalance = round((mt_rand(-10000000, 10000000) / 100), 2);
            $debit = round((mt_rand(-5000000, 5000000) / 100), 2);
            $credit = round((mt_rand(-5000000, 5000000) / 100), 2);

            $cases["single_txn_opening_{$openingBalance}_d_{$debit}_c_{$credit}_iter_{$i}"] = [
                $openingBalance,
                $debit,
                $credit,
            ];
        }
        return $cases;
    }

    /**
     * Property 10d: Single transaction running_balance = opening + debit + credit.
     *
     * **Validates: Requirements 5.5**
     *
     * @dataProvider singleTransactionProvider
     */
    public function testSingleTransactionRunningBalance(
        float $openingBalance,
        float $debit,
        float $credit
    ): void {
        $service = $this->createService();
        $transactions = [
            [
                'debit_amount' => $debit,
                'credit_amount' => $credit,
                'description' => 'Single transaction',
                'date' => '2025-03-15',
            ],
        ];

        $result = $service->computeRunningBalances($openingBalance, $transactions);

        $expected = round($openingBalance + $debit + $credit, 2);
        $actual = round((float)$result[0]['running_balance'], 2);

        $this->assertEqualsWithDelta(
            $expected,
            $actual,
            0.001,
            "Single transaction running_balance should be opening ({$openingBalance}) + debit ({$debit}) + credit ({$credit}) = {$expected}, got {$actual}"
        );
    }
}
