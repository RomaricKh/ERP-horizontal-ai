<?php

namespace Tests\Property\ExpensePayroll;

use App\Services\ExpenseService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Property 8: Date Format Validation
 *
 * For any string that does NOT match YYYY-MM-DD format or does NOT represent a valid
 * calendar date, the service SHALL throw InvalidArgumentException. For valid YYYY-MM-DD
 * dates, it SHALL NOT throw.
 *
 * Feature: expenses-payroll-review, Property 8: Date format validation
 *
 * **Validates: Requirements 13.1, 13.2, 13.3**
 */
class DateValidationTest extends TestCase
{
    private const ITERATIONS = 100;

    private ExpenseService $service;

    protected function setUp(): void
    {
        $ref = new ReflectionClass(ExpenseService::class);
        $this->service = $ref->newInstanceWithoutConstructor();
    }

    /**
     * Invoke the protected validateDate method via reflection.
     */
    private function callValidateDate(string $date, string $fieldName): void
    {
        $ref = new ReflectionClass($this->service);
        $method = $ref->getMethod('validateDate');
        $method->setAccessible(true);
        $method->invoke($this->service, $date, $fieldName);
    }

    // ─── Scenario 1: Random non-date-like strings → rejection ─────────────────

    /**
     * Generate random strings that are not date-like.
     */
    public static function randomNonDateStringProvider(): array
    {
        $cases = [];
        $generators = [
            // Pure alphabetic strings
            fn() => substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, mt_rand(3, 20)),
            // Numeric without dashes
            fn() => (string) mt_rand(10000000, 99999999),
            // Special characters
            fn() => str_repeat(chr(mt_rand(33, 47)), mt_rand(3, 10)),
            // Empty-ish strings with whitespace
            fn() => str_repeat(' ', mt_rand(1, 5)) . substr(str_shuffle('abc123'), 0, mt_rand(1, 5)),
            // Reversed format DD-MM-YYYY
            fn() => sprintf('%02d-%02d-%04d', mt_rand(1, 28), mt_rand(1, 12), mt_rand(2000, 2030)),
            // Wrong separators (slash)
            fn() => sprintf('%04d/%02d/%02d', mt_rand(2000, 2030), mt_rand(1, 12), mt_rand(1, 28)),
            // Wrong separators (dot)
            fn() => sprintf('%04d.%02d.%02d', mt_rand(2000, 2030), mt_rand(1, 12), mt_rand(1, 28)),
            // Short year
            fn() => sprintf('%02d-%02d-%02d', mt_rand(20, 99), mt_rand(1, 12), mt_rand(1, 28)),
            // ISO with time appended
            fn() => sprintf('%04d-%02d-%02dT%02d:%02d:%02d', mt_rand(2000, 2030), mt_rand(1, 12), mt_rand(1, 28), mt_rand(0, 23), mt_rand(0, 59), mt_rand(0, 59)),
            // Only year-month
            fn() => sprintf('%04d-%02d', mt_rand(2000, 2030), mt_rand(1, 12)),
            // Year only
            fn() => (string) mt_rand(1900, 2100),
            // Leading/trailing spaces around valid-looking date
            fn() => ' ' . sprintf('%04d-%02d-%02d', mt_rand(2000, 2030), mt_rand(1, 12), mt_rand(1, 28)),
            fn() => sprintf('%04d-%02d-%02d', mt_rand(2000, 2030), mt_rand(1, 12), mt_rand(1, 28)) . ' ',
            // Random mixed content
            fn() => 'date:' . mt_rand(2000, 2030) . '-' . mt_rand(1, 12) . '-' . mt_rand(1, 28),
            // Totally random bytes (printable ASCII)
            fn() => implode('', array_map(fn() => chr(mt_rand(32, 126)), range(1, mt_rand(5, 15)))),
        ];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $generator = $generators[array_rand($generators)];
            $randomStr = $generator();
            $cases["random_nondate_{$i}"] = [$randomStr];
        }

        return $cases;
    }

    /**
     * Property 8 (Scenario 1): Random non-date-like strings are rejected.
     *
     * For any string that does NOT match YYYY-MM-DD format, validateDate()
     * SHALL throw InvalidArgumentException.
     *
     * **Validates: Requirements 13.1, 13.2, 13.3**
     *
     * @dataProvider randomNonDateStringProvider
     * @group property
     */
    public function testRandomNonDateStringsAreRejected(string $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid date format/');

        $this->callValidateDate($input, 'expense_date');
    }

    // ─── Scenario 2: YYYY-MM-DD format but invalid calendar dates → rejection ──

    /**
     * Generate strings matching YYYY-MM-DD pattern but representing invalid calendar dates.
     */
    public static function invalidCalendarDateProvider(): array
    {
        $cases = [];
        $generators = [
            // Month > 12
            fn() => sprintf('%04d-%02d-%02d', mt_rand(1900, 2100), mt_rand(13, 99), mt_rand(1, 28)),
            // Month = 0
            fn() => sprintf('%04d-00-%02d', mt_rand(1900, 2100), mt_rand(1, 28)),
            // Day = 0
            fn() => sprintf('%04d-%02d-00', mt_rand(1900, 2100), mt_rand(1, 12)),
            // Day > 31 (always invalid)
            fn() => sprintf('%04d-%02d-%02d', mt_rand(1900, 2100), mt_rand(1, 12), mt_rand(32, 99)),
            // Feb 30 (never valid)
            fn() => sprintf('%04d-02-30', mt_rand(1900, 2100)),
            // Feb 31 (never valid)
            fn() => sprintf('%04d-02-31', mt_rand(1900, 2100)),
            // Feb 29 on non-leap year
            fn() => self::generateFeb29NonLeapYear(),
            // April, June, September, November with 31 days
            fn() => sprintf('%04d-%02d-31', mt_rand(1900, 2100), [4, 6, 9, 11][mt_rand(0, 3)]),
        ];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $generator = $generators[array_rand($generators)];
            $invalidDate = $generator();
            $cases["invalid_calendar_{$i}"] = [$invalidDate];
        }

        return $cases;
    }

    /**
     * Generate a Feb 29 date on a non-leap year.
     */
    private static function generateFeb29NonLeapYear(): string
    {
        // Find a non-leap year in range 1900-2100
        do {
            $year = mt_rand(1900, 2100);
        } while (self::isLeapYear($year));

        return sprintf('%04d-02-29', $year);
    }

    /**
     * Check if a year is a leap year.
     */
    private static function isLeapYear(int $year): bool
    {
        return ($year % 4 === 0 && $year % 100 !== 0) || ($year % 400 === 0);
    }

    /**
     * Property 8 (Scenario 2): YYYY-MM-DD format with invalid calendar dates are rejected.
     *
     * For any string matching YYYY-MM-DD format but NOT representing a valid calendar date
     * (month > 12, day > max for month, Feb 29 on non-leap years), validateDate()
     * SHALL throw InvalidArgumentException.
     *
     * **Validates: Requirements 13.1, 13.2, 13.3**
     *
     * @dataProvider invalidCalendarDateProvider
     * @group property
     */
    public function testInvalidCalendarDatesAreRejected(string $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid date format/');

        $this->callValidateDate($input, 'expense_date');
    }

    // ─── Scenario 3: Valid YYYY-MM-DD dates → acceptance ────────────────────────

    /**
     * Generate valid YYYY-MM-DD dates (random year 1900-2100, valid month 1-12,
     * valid day for that month/year).
     */
    public static function validDateProvider(): array
    {
        $cases = [];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $year = mt_rand(1900, 2100);
            $month = mt_rand(1, 12);
            $maxDay = self::maxDayForMonth($month, $year);
            $day = mt_rand(1, $maxDay);
            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $cases["valid_date_{$i}"] = [$date];
        }

        return $cases;
    }

    /**
     * Get the maximum valid day for a given month and year.
     */
    private static function maxDayForMonth(int $month, int $year): int
    {
        switch ($month) {
            case 1: case 3: case 5: case 7: case 8: case 10: case 12:
                return 31;
            case 4: case 6: case 9: case 11:
                return 30;
            case 2:
                return self::isLeapYear($year) ? 29 : 28;
            default:
                return 28;
        }
    }

    /**
     * Property 8 (Scenario 3): Valid YYYY-MM-DD dates are accepted.
     *
     * For any valid YYYY-MM-DD date (year 1900-2100, valid month, valid day for
     * month/year), validateDate() SHALL NOT throw.
     *
     * **Validates: Requirements 13.1, 13.2, 13.3**
     *
     * @dataProvider validDateProvider
     * @group property
     */
    public function testValidDatesAreAccepted(string $input): void
    {
        // Should not throw — if it does, the test automatically fails
        $this->callValidateDate($input, 'expense_date');

        $this->assertTrue(true, 'Valid date accepted without exception');
    }
}
