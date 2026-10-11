<?php

namespace App\Services;

use App\Repository\PayeeStatementRepository;
use App\Repository\PeopleRepository;
use PDO;

class PayeeStatementService
{
    private PayeeStatementRepository $statementRepo;
    private PeopleRepository $peopleRepo;

    public function __construct(PDO $db)
    {
        $this->statementRepo = new PayeeStatementRepository($db);
        $this->peopleRepo = new PeopleRepository($db);
    }

    /**
     * Verify payee belongs to the given business.
     * Throws RuntimeException if not found.
     */
    private function requirePayee(int $payeeId, int $businessId): array
    {
        $payee = $this->peopleRepo->verifyPayeeBelongsToBusiness($payeeId, $businessId);
        if (!$payee) {
            throw new \RuntimeException('Payee not found');
        }
        return $payee;
    }

    /**
     * Validate that start_date and end_date are present, valid YYYY-MM-DD dates,
     * and that start_date <= end_date.
     */
    public function validateDateRange(?string $startDate, ?string $endDate): void
    {
        if (!$startDate || !$endDate) {
            throw new \InvalidArgumentException('start_date and end_date are required.');
        }
        if (!$this->isValidDate($startDate)) {
            throw new \InvalidArgumentException('start_date must be a valid date in YYYY-MM-DD format.');
        }
        if (!$this->isValidDate($endDate)) {
            throw new \InvalidArgumentException('end_date must be a valid date in YYYY-MM-DD format.');
        }
        if ($startDate > $endDate) {
            throw new \InvalidArgumentException('start_date must be less than or equal to end_date.');
        }
    }

    /**
     * Check if a date string is a valid YYYY-MM-DD calendar date.
     */
    private function isValidDate(string $date): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }

    /**
     * Get a full payee statement for the given date range.
     *
     * Orchestrates: verify payee → validate dates → get opening balance →
     * get transactions → compute running balances → compute closing balance.
     *
     * @return array{payee_name: string, opening_balance: float, closing_balance: float, transactions: array}
     */
    public function getStatement(int $payeeId, int $businessId, string $startDate, string $endDate): array
    {
        // 1. Verify payee belongs to business
        $payee = $this->requirePayee($payeeId, $businessId);

        // 2. Validate date range
        $this->validateDateRange($startDate, $endDate);

        // 3. Get opening balance (sum of all pre-period transactions)
        $openingBalance = $this->statementRepo->calculateOpeningBalance($payeeId, $businessId, $startDate);

        // 4. Get transactions in date range
        $transactions = $this->statementRepo->getTransactions($payeeId, $businessId, $startDate, $endDate);

        // 5. Compute running balances
        $transactions = $this->computeRunningBalances($openingBalance, $transactions);

        // 6. Closing balance = last running balance (or opening if no transactions)
        $closingBalance = !empty($transactions)
            ? (float)$transactions[count($transactions) - 1]['running_balance']
            : $openingBalance;

        return [
            'payee_name'      => $payee['payee_name'],
            'opening_balance' => $openingBalance,
            'closing_balance' => $closingBalance,
            'transactions'    => $transactions,
        ];
    }

    /**
     * Compute running balances for each transaction, preserving
     * existing signed debit/credit conventions.
     *
     * Debit and credit amounts already have their signs baked in
     * (e.g., negative debit = we owe them, positive credit = we paid them).
     * Running balance = opening + cumulative (debit + credit).
     */
    public function computeRunningBalances(float $openingBalance, array $transactions): array
    {
        $running = $openingBalance;
        foreach ($transactions as &$txn) {
            $debit = (float)($txn['debit_amount'] ?? 0);
            $credit = (float)($txn['credit_amount'] ?? 0);
            $running += $debit + $credit;
            $txn['running_balance'] = $running;
        }
        return $transactions;
    }
}
