<?php

namespace Tests\Property\ExpensePayroll;

use App\Services\ExpenseService;
use App\Services\SalaryService;
use App\Support\AuthContext;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Feature: expenses-payroll-review, Property 3: Expense input validation, Property 4: Salary input validation
 *
 * Property 3: For any expense create or update request where expense_date is empty,
 * or currency is empty/whitespace-only, or amount_fc is non-numeric, or amount_fc ≤ 0,
 * or exchange_rate is provided and ≤ 0, the ExpenseService SHALL throw an
 * InvalidArgumentException before any database write occurs.
 *
 * Property 4: For any salary create or update request where gross_amount_fc ≤ 0,
 * or tax_fc < 0, or exchange_rate is provided and ≤ 0, or period dates are invalid,
 * the SalaryService SHALL throw an InvalidArgumentException before any database write occurs.
 *
 * **Validates: Requirements 7.1, 7.2, 7.3, 7.4, 7.5, 8.1, 8.2, 8.3, 8.4, 8.5**
 */
class InputValidationTest extends TestCase
{
    private const ITERATIONS = 100;

    protected function setUp(): void
    {
        AuthContext::set((object) [
            'user_id'     => 1,
            'tenant_id'   => 1,
            'business_id' => 1,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Create an ExpenseService instance via reflection (bypasses constructor)
     * and set up mock dependencies so validation runs before any DB call.
     */
    private function createExpenseService(): ExpenseService
    {
        $ref = new ReflectionClass(ExpenseService::class);
        $service = $ref->newInstanceWithoutConstructor();

        // Set db property to a mock PDO
        $pdo = $this->createMock(\PDO::class);
        $dbProp = $ref->getProperty('db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($service, $pdo);

        // Mock exchangeRateService — should never be reached if validation throws
        $mockExchangeRate = $this->createMock(\App\Services\ExchangeRateService::class);
        $mockExchangeRate->method('resolveRate')->willReturn(1.0);
        $ersProp = $ref->getProperty('exchangeRateService');
        $ersProp->setAccessible(true);
        $ersProp->setValue($service, $mockExchangeRate);

        // Mock peopleRepo
        $mockPeopleRepo = $this->createMock(\App\Repository\PeopleRepository::class);
        $prProp = $ref->getProperty('peopleRepo');
        $prProp->setAccessible(true);
        $prProp->setValue($service, $mockPeopleRepo);

        // Mock audit service
        $mockAudit = $this->createMock(\App\Services\AuditService::class);
        $auditProp = $ref->getProperty('audit');
        $auditProp->setAccessible(true);
        $auditProp->setValue($service, $mockAudit);

        // Mock repo
        $mockRepo = $this->createMock(\App\Repository\ExpenseRepository::class);
        $repoProp = $ref->getProperty('repo');
        $repoProp->setAccessible(true);
        $repoProp->setValue($service, $mockRepo);

        return $service;
    }

    /**
     * Create a SalaryService instance via reflection (bypasses constructor)
     * and set up minimal mock PDO so validation runs before any DB call.
     */
    private function createSalaryService(): SalaryService
    {
        $ref = new ReflectionClass(SalaryService::class);
        $service = $ref->newInstanceWithoutConstructor();

        // Set db property to a mock PDO — validation should throw before DB is touched
        $pdo = $this->createMock(\PDO::class);
        $dbProp = $ref->getProperty('db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($service, $pdo);

        return $service;
    }

    /**
     * Invoke ExpenseService::create() with the given data.
     * Expects InvalidArgumentException to be thrown before any DB write.
     */
    private function invokeExpenseCreate(array $data): void
    {
        $service = $this->createExpenseService();
        $service->create($data);
    }

    /**
     * Invoke ExpenseService::update() with the given data.
     * We need to mock the repository to simulate finding an existing record.
     */
    private function invokeExpenseUpdate(array $data): void
    {
        $ref = new ReflectionClass(ExpenseService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $pdo = $this->createMock(\PDO::class);
        $dbProp = $ref->getProperty('db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($service, $pdo);

        // Mock repo to return an existing record (so update doesn't throw "not found")
        $mockRepo = $this->createMock(\App\Repository\ExpenseRepository::class);
        $mockRepo->method('findById')->willReturn([
            'id' => 1,
            'business_id' => 1,
            'payee_id' => null,
            'expense_date' => '2024-01-01',
            'currency' => 'USD',
            'amount_fc' => 100.00,
            'amount_bc' => 100.00,
            'exchange_rate' => 1.0,
        ]);
        $repoProp = $ref->getProperty('repo');
        $repoProp->setAccessible(true);
        $repoProp->setValue($service, $mockRepo);

        // Mock exchangeRateService
        $mockExchangeRate = $this->createMock(\App\Services\ExchangeRateService::class);
        $mockExchangeRate->method('resolveRate')->willReturn(1.0);
        $ersProp = $ref->getProperty('exchangeRateService');
        $ersProp->setAccessible(true);
        $ersProp->setValue($service, $mockExchangeRate);

        // Mock peopleRepo
        $mockPeopleRepo = $this->createMock(\App\Repository\PeopleRepository::class);
        $prProp = $ref->getProperty('peopleRepo');
        $prProp->setAccessible(true);
        $prProp->setValue($service, $mockPeopleRepo);

        // Mock audit service
        $mockAudit = $this->createMock(\App\Services\AuditService::class);
        $auditProp = $ref->getProperty('audit');
        $auditProp->setAccessible(true);
        $auditProp->setValue($service, $mockAudit);

        $service->update(1, $data);
    }

    /**
     * Invoke SalaryService::create() with the given data.
     */
    private function invokeSalaryCreate(array $data): void
    {
        $service = $this->createSalaryService();
        $service->create($data);
    }

    /**
     * Invoke the private validateData method directly on SalaryService.
     */
    private function invokeSalaryValidateData(array $data): void
    {
        $ref = new ReflectionClass(SalaryService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $pdo = $this->createMock(\PDO::class);
        $dbProp = $ref->getProperty('db');
        $dbProp->setAccessible(true);
        $dbProp->setValue($service, $pdo);

        $method = $ref->getMethod('validateData');
        $method->setAccessible(true);
        $method->invoke($service, $data);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // GENERATORS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Generate a valid base expense data array (all fields pass validation).
     */
    private function validExpenseData(): array
    {
        return [
            'expense_date' => '2024-06-15',
            'currency'     => 'USD',
            'amount_fc'    => 100.50,
            'exchange_rate' => 1.0,
        ];
    }

    /**
     * Generate a valid base salary data array (all fields pass validation).
     */
    private function validSalaryData(): array
    {
        return [
            'period_start'    => '2024-01-01',
            'period_end'      => '2024-01-31',
            'currency'        => 'USD',
            'gross_amount_fc' => 5000.00,
            'tax_fc'          => 500.00,
            'exchange_rate'   => 1.0,
        ];
    }

    /**
     * Generate random empty/whitespace-only strings.
     */
    private function generateEmptyStrings(): array
    {
        $cases = ['', ' ', '  ', "\t", "\n", "   \t\n  "];
        for ($i = count($cases); $i < self::ITERATIONS; $i++) {
            $type = mt_rand(0, 3);
            switch ($type) {
                case 0:
                    $cases[] = '';
                    break;
                case 1:
                    $cases[] = str_repeat(' ', mt_rand(1, 20));
                    break;
                case 2:
                    $cases[] = str_repeat("\t", mt_rand(1, 5));
                    break;
                case 3:
                    $cases[] = str_repeat(" \t\n", mt_rand(1, 5));
                    break;
            }
        }
        return $cases;
    }

    /**
     * Generate non-numeric amount values.
     * These MUST fail PHP's is_numeric() check.
     */
    private function generateNonNumericAmounts(): array
    {
        $cases = [];
        $candidates = ['abc', 'NaN', '-INF', 'one hundred', '12.34.56', '$100', 'ten', '100USD'];
        foreach ($candidates as $c) {
            if (!is_numeric($c)) {
                $cases[] = $c;
            }
        }
        while (count($cases) < self::ITERATIONS) {
            $type = mt_rand(0, 4);
            $candidate = null;
            switch ($type) {
                case 0:
                    // Random alphabetic string
                    $len = mt_rand(1, 10);
                    $str = '';
                    for ($j = 0; $j < $len; $j++) {
                        $str .= chr(mt_rand(65, 90));
                    }
                    $candidate = $str;
                    break;
                case 1:
                    // Special characters prefixed
                    $specials = ['!', '@', '#', '$', '%', '^', '&', '*'];
                    $candidate = $specials[array_rand($specials)] . mt_rand(1, 100);
                    break;
                case 2:
                    // Multiple dots
                    $candidate = mt_rand(1, 99) . '.' . mt_rand(0, 99) . '.' . mt_rand(0, 99);
                    break;
                case 3:
                    // Mixed alpha-numeric that isn't numeric
                    $candidate = mt_rand(1, 99) . chr(mt_rand(65, 90)) . mt_rand(1, 99);
                    break;
                case 4:
                    // Text words
                    $words = ['zero', 'one', 'hundred', 'million', 'null', 'undefined', 'NaN', 'false', 'true'];
                    $candidate = $words[array_rand($words)];
                    break;
            }
            if ($candidate !== null && !is_numeric($candidate)) {
                $cases[] = $candidate;
            }
        }
        return $cases;
    }

    /**
     * Generate zero and negative amounts.
     */
    private function generateNonPositiveAmounts(): array
    {
        $cases = [0, -1, -0.01, -100, -999999.99, 0.0, -0.001];
        for ($i = count($cases); $i < self::ITERATIONS; $i++) {
            $type = mt_rand(0, 2);
            switch ($type) {
                case 0:
                    // Zero or negative integer
                    $cases[] = -mt_rand(1, 1000000);
                    break;
                case 1:
                    // Negative float
                    $cases[] = -(mt_rand(1, 10000) / 100.0);
                    break;
                case 2:
                    // Zero
                    $cases[] = 0;
                    break;
            }
        }
        return $cases;
    }

    /**
     * Generate zero and negative exchange rates.
     */
    private function generateNonPositiveExchangeRates(): array
    {
        $cases = [0, -1, -0.5, -100, 0.0, -0.0001];
        for ($i = count($cases); $i < self::ITERATIONS; $i++) {
            $type = mt_rand(0, 2);
            switch ($type) {
                case 0:
                    $cases[] = -mt_rand(1, 10000) / 100.0;
                    break;
                case 1:
                    $cases[] = 0;
                    break;
                case 2:
                    $cases[] = -(mt_rand(1, 100) + mt_rand(0, 99) / 100.0);
                    break;
            }
        }
        return $cases;
    }

    /**
     * Generate negative tax amounts.
     */
    private function generateNegativeTaxAmounts(): array
    {
        $cases = [-1, -0.01, -100, -0.001, -999];
        for ($i = count($cases); $i < self::ITERATIONS; $i++) {
            $cases[] = -(mt_rand(1, 100000) / 100.0);
        }
        return $cases;
    }

    /**
     * Generate invalid date strings (not matching YYYY-MM-DD or invalid calendar dates).
     */
    private function generateInvalidDates(): array
    {
        $cases = [
            '2024-13-01',  // month > 12
            '2024-00-15',  // month = 0
            '2023-02-29',  // Non-leap year Feb 29
            '2024-04-31',  // Apr 31
            '2025-02-29',  // Non-leap year Feb 29
            '01-01-2024',  // Wrong format DD-MM-YYYY
            '2024/01/15',  // Slashes
            '20240115',    // No separators
            'not-a-date',  // Non-date string
            '2024-1-1',    // Single digits without zero-padding
        ];
        while (count($cases) < self::ITERATIONS) {
            $type = mt_rand(0, 5);
            switch ($type) {
                case 0:
                    // Invalid month (13-99)
                    $cases[] = sprintf('2024-%02d-15', mt_rand(13, 99));
                    break;
                case 1:
                    // Invalid day (32-99)
                    $cases[] = sprintf('2024-01-%02d', mt_rand(32, 99));
                    break;
                case 2:
                    // Wrong separator
                    $seps = ['/', '.', ' ', '_'];
                    $sep = $seps[array_rand($seps)];
                    $cases[] = "2024{$sep}01{$sep}15";
                    break;
                case 3:
                    // Random non-date string
                    $len = mt_rand(5, 15);
                    $str = '';
                    for ($j = 0; $j < $len; $j++) {
                        $str .= chr(mt_rand(48, 122));
                    }
                    // Ensure it doesn't accidentally match YYYY-MM-DD valid date
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $str)) {
                        $str = 'x' . $str;
                    }
                    $cases[] = $str;
                    break;
                case 4:
                    // Feb 29 in confirmed non-leap year
                    // Non-leap years: not divisible by 4, or divisible by 100 but not 400
                    $nonLeapYears = [2023, 2025, 2026, 2027, 2100, 2019, 2021, 2022];
                    $year = $nonLeapYears[array_rand($nonLeapYears)];
                    $cases[] = sprintf('%04d-02-29', $year);
                    break;
                case 5:
                    // Month 0 or day 0
                    if (mt_rand(0, 1)) {
                        $cases[] = sprintf('2024-00-%02d', mt_rand(1, 28));
                    } else {
                        $cases[] = sprintf('2024-%02d-00', mt_rand(1, 12));
                    }
                    break;
            }
        }
        return $cases;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PROPERTY 3: Expense Input Validation
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * @test
     * Property 3: Empty expense_date → InvalidArgumentException on create
     */
    public function testExpenseCreateRejectsEmptyDate(): void
    {
        $emptyStrings = $this->generateEmptyStrings();

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $data = $this->validExpenseData();
            $data['expense_date'] = $emptyStrings[$i];

            try {
                $this->invokeExpenseCreate($data);
                $this->fail("Expected InvalidArgumentException for empty expense_date: '{$emptyStrings[$i]}'");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('date', strtolower($e->getMessage()));
            }
        }
    }

    /**
     * @test
     * Property 3: Empty/whitespace currency → InvalidArgumentException on create
     */
    public function testExpenseCreateRejectsEmptyCurrency(): void
    {
        $emptyStrings = $this->generateEmptyStrings();

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $data = $this->validExpenseData();
            $data['currency'] = $emptyStrings[$i];

            try {
                $this->invokeExpenseCreate($data);
                $this->fail("Expected InvalidArgumentException for empty currency: '{$emptyStrings[$i]}'");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('currency', strtolower($e->getMessage()));
            }
        }
    }

    /**
     * @test
     * Property 3: Non-numeric amount_fc → InvalidArgumentException on create
     */
    public function testExpenseCreateRejectsNonNumericAmount(): void
    {
        $nonNumeric = $this->generateNonNumericAmounts();

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $data = $this->validExpenseData();
            $data['amount_fc'] = $nonNumeric[$i];

            try {
                $this->invokeExpenseCreate($data);
                $this->fail("Expected InvalidArgumentException for non-numeric amount_fc: '{$nonNumeric[$i]}'");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('amount', strtolower($e->getMessage()));
            }
        }
    }

    /**
     * @test
     * Property 3: amount_fc ≤ 0 → InvalidArgumentException on create
     */
    public function testExpenseCreateRejectsNonPositiveAmount(): void
    {
        $amounts = $this->generateNonPositiveAmounts();

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $data = $this->validExpenseData();
            $data['amount_fc'] = $amounts[$i];

            try {
                $this->invokeExpenseCreate($data);
                $this->fail("Expected InvalidArgumentException for amount_fc={$amounts[$i]}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('amount', strtolower($e->getMessage()));
            }
        }
    }

    /**
     * @test
     * Property 3: exchange_rate ≤ 0 (when provided) → InvalidArgumentException on create
     */
    public function testExpenseCreateRejectsNonPositiveExchangeRate(): void
    {
        $rates = $this->generateNonPositiveExchangeRates();

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $data = $this->validExpenseData();
            $data['exchange_rate'] = $rates[$i];

            try {
                $this->invokeExpenseCreate($data);
                $this->fail("Expected InvalidArgumentException for exchange_rate={$rates[$i]}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('exchange rate', strtolower($e->getMessage()));
            }
        }
    }

    /**
     * @test
     * Property 3: Empty expense_date → InvalidArgumentException on update
     */
    public function testExpenseUpdateRejectsEmptyDate(): void
    {
        $emptyStrings = $this->generateEmptyStrings();

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $data = $this->validExpenseData();
            $data['expense_date'] = $emptyStrings[$i];

            try {
                $this->invokeExpenseUpdate($data);
                $this->fail("Expected InvalidArgumentException for empty expense_date on update: '{$emptyStrings[$i]}'");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('date', strtolower($e->getMessage()));
            }
        }
    }

    /**
     * @test
     * Property 3: Empty/whitespace currency → InvalidArgumentException on update
     */
    public function testExpenseUpdateRejectsEmptyCurrency(): void
    {
        $emptyStrings = $this->generateEmptyStrings();

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $data = $this->validExpenseData();
            $data['currency'] = $emptyStrings[$i];

            try {
                $this->invokeExpenseUpdate($data);
                $this->fail("Expected InvalidArgumentException for empty currency on update: '{$emptyStrings[$i]}'");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('currency', strtolower($e->getMessage()));
            }
        }
    }

    /**
     * @test
     * Property 3: Non-numeric amount_fc → InvalidArgumentException on update
     */
    public function testExpenseUpdateRejectsNonNumericAmount(): void
    {
        $nonNumeric = $this->generateNonNumericAmounts();

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $data = $this->validExpenseData();
            $data['amount_fc'] = $nonNumeric[$i];

            try {
                $this->invokeExpenseUpdate($data);
                $this->fail("Expected InvalidArgumentException for non-numeric amount_fc on update: '{$nonNumeric[$i]}'");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('amount', strtolower($e->getMessage()));
            }
        }
    }

    /**
     * @test
     * Property 3: amount_fc ≤ 0 → InvalidArgumentException on update
     */
    public function testExpenseUpdateRejectsNonPositiveAmount(): void
    {
        $amounts = $this->generateNonPositiveAmounts();

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $data = $this->validExpenseData();
            $data['amount_fc'] = $amounts[$i];

            try {
                $this->invokeExpenseUpdate($data);
                $this->fail("Expected InvalidArgumentException for amount_fc={$amounts[$i]} on update");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('amount', strtolower($e->getMessage()));
            }
        }
    }

    /**
     * @test
     * Property 3: exchange_rate ≤ 0 (when provided) → InvalidArgumentException on update
     */
    public function testExpenseUpdateRejectsNonPositiveExchangeRate(): void
    {
        $rates = $this->generateNonPositiveExchangeRates();

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $data = $this->validExpenseData();
            $data['exchange_rate'] = $rates[$i];

            try {
                $this->invokeExpenseUpdate($data);
                $this->fail("Expected InvalidArgumentException for exchange_rate={$rates[$i]} on update");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('exchange rate', strtolower($e->getMessage()));
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PROPERTY 4: Salary Input Validation
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * @test
     * Property 4: gross_amount_fc ≤ 0 → InvalidArgumentException
     */
    public function testSalaryRejectsNonPositiveGrossAmount(): void
    {
        $amounts = $this->generateNonPositiveAmounts();

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $data = $this->validSalaryData();
            $data['gross_amount_fc'] = $amounts[$i];

            try {
                $this->invokeSalaryValidateData($data);
                $this->fail("Expected InvalidArgumentException for gross_amount_fc={$amounts[$i]}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('gross', strtolower($e->getMessage()));
            }
        }
    }

    /**
     * @test
     * Property 4: tax_fc < 0 → InvalidArgumentException
     */
    public function testSalaryRejectsNegativeTax(): void
    {
        $taxes = $this->generateNegativeTaxAmounts();

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $data = $this->validSalaryData();
            $data['tax_fc'] = $taxes[$i];

            try {
                $this->invokeSalaryValidateData($data);
                $this->fail("Expected InvalidArgumentException for tax_fc={$taxes[$i]}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('tax', strtolower($e->getMessage()));
            }
        }
    }

    /**
     * @test
     * Property 4: exchange_rate ≤ 0 (when provided) → InvalidArgumentException
     */
    public function testSalaryRejectsNonPositiveExchangeRate(): void
    {
        $rates = $this->generateNonPositiveExchangeRates();

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $data = $this->validSalaryData();
            $data['exchange_rate'] = $rates[$i];

            try {
                $this->invokeSalaryValidateData($data);
                $this->fail("Expected InvalidArgumentException for exchange_rate={$rates[$i]}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('exchange rate', strtolower($e->getMessage()));
            }
        }
    }

    /**
     * @test
     * Property 4: Invalid period dates → InvalidArgumentException
     */
    public function testSalaryRejectsInvalidPeriodDates(): void
    {
        $invalidDates = $this->generateInvalidDates();

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Test invalid period_start
            $data = $this->validSalaryData();
            $data['period_start'] = $invalidDates[$i];

            try {
                $this->invokeSalaryValidateData($data);
                $this->fail("Expected InvalidArgumentException for invalid period_start: '{$invalidDates[$i]}'");
            } catch (InvalidArgumentException $e) {
                // Expected — validation caught it before DB write
                $this->assertTrue(true);
            }
        }
    }

    /**
     * @test
     * Property 4: Invalid period_end dates → InvalidArgumentException
     */
    public function testSalaryRejectsInvalidPeriodEndDates(): void
    {
        $invalidDates = $this->generateInvalidDates();

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $data = $this->validSalaryData();
            $data['period_end'] = $invalidDates[$i];

            try {
                $this->invokeSalaryValidateData($data);
                $this->fail("Expected InvalidArgumentException for invalid period_end: '{$invalidDates[$i]}'");
            } catch (InvalidArgumentException $e) {
                // Expected — validation caught it before DB write
                $this->assertTrue(true);
            }
        }
    }

    /**
     * @test
     * Property 4: period_end before period_start → InvalidArgumentException
     */
    public function testSalaryRejectsPeriodEndBeforeStart(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $data = $this->validSalaryData();

            // Generate valid dates where end < start
            $year = mt_rand(2020, 2025);
            $startMonth = mt_rand(2, 12);
            $endMonth = mt_rand(1, $startMonth - 1);
            $data['period_start'] = sprintf('%04d-%02d-01', $year, $startMonth);
            $data['period_end'] = sprintf('%04d-%02d-01', $year, $endMonth);

            try {
                $this->invokeSalaryValidateData($data);
                $this->fail("Expected InvalidArgumentException for period_end ({$data['period_end']}) before period_start ({$data['period_start']})");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('period', strtolower($e->getMessage()));
            }
        }
    }

    /**
     * @test
     * Property 3: Random invalid expense data combinations → InvalidArgumentException on create
     *
     * Generates random combinations of multiple invalid fields to verify that
     * at least one validation fires before any DB write.
     */
    public function testExpenseCreateRejectsRandomInvalidCombinations(): void
    {
        $emptyStrings = $this->generateEmptyStrings();
        $nonNumeric = $this->generateNonNumericAmounts();
        $nonPositive = $this->generateNonPositiveAmounts();
        $badRates = $this->generateNonPositiveExchangeRates();

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $data = $this->validExpenseData();

            // Randomly invalidate 1-3 fields
            $fieldsToInvalidate = mt_rand(1, 3);
            $options = ['date', 'currency', 'amount_type', 'amount_value', 'rate'];
            shuffle($options);

            for ($j = 0; $j < $fieldsToInvalidate; $j++) {
                $idx = $i % count($emptyStrings);
                switch ($options[$j]) {
                    case 'date':
                        $data['expense_date'] = $emptyStrings[$idx];
                        break;
                    case 'currency':
                        $data['currency'] = $emptyStrings[$idx];
                        break;
                    case 'amount_type':
                        $data['amount_fc'] = $nonNumeric[$idx];
                        break;
                    case 'amount_value':
                        $data['amount_fc'] = $nonPositive[$idx];
                        break;
                    case 'rate':
                        $data['exchange_rate'] = $badRates[$idx];
                        break;
                }
            }

            try {
                $this->invokeExpenseCreate($data);
                $this->fail("Expected InvalidArgumentException for invalid combination at iteration {$i}");
            } catch (InvalidArgumentException $e) {
                // Expected: validation threw before any DB operation
                $this->assertTrue(true);
            }
        }
    }

    /**
     * @test
     * Property 4: Random invalid salary data combinations → InvalidArgumentException
     *
     * Generates random combinations of multiple invalid fields to verify that
     * at least one validation fires before any DB write.
     */
    public function testSalaryRejectsRandomInvalidCombinations(): void
    {
        $nonPositive = $this->generateNonPositiveAmounts();
        $negativeTax = $this->generateNegativeTaxAmounts();
        $badRates = $this->generateNonPositiveExchangeRates();
        $invalidDates = $this->generateInvalidDates();

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $data = $this->validSalaryData();

            // Randomly invalidate 1-3 fields
            $fieldsToInvalidate = mt_rand(1, 3);
            $options = ['gross', 'tax', 'rate', 'period_start', 'period_end'];
            shuffle($options);

            for ($j = 0; $j < $fieldsToInvalidate; $j++) {
                $idx = $i % count($nonPositive);
                switch ($options[$j]) {
                    case 'gross':
                        $data['gross_amount_fc'] = $nonPositive[$idx];
                        break;
                    case 'tax':
                        $data['tax_fc'] = $negativeTax[$idx];
                        break;
                    case 'rate':
                        $data['exchange_rate'] = $badRates[$idx];
                        break;
                    case 'period_start':
                        $data['period_start'] = $invalidDates[$idx];
                        break;
                    case 'period_end':
                        $data['period_end'] = $invalidDates[$idx];
                        break;
                }
            }

            try {
                $this->invokeSalaryValidateData($data);
                $this->fail("Expected InvalidArgumentException for invalid salary combination at iteration {$i}");
            } catch (InvalidArgumentException $e) {
                // Expected: validation threw before any DB operation
                $this->assertTrue(true);
            }
        }
    }
}
