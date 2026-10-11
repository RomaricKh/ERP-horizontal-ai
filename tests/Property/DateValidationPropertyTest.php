<?php

namespace Tests\Property;

use App\Services\PayeeStatementService;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Property 9: Date validation rejects malformed and illogical ranges
 *
 * For any string that does not match the format YYYY-MM-DD or does not represent
 * a valid calendar date (e.g., 2024-02-30), or for any pair where start_date > end_date,
 * the PayeeStatementService SHALL throw an InvalidArgumentException with a descriptive message.
 *
 * **Validates: Requirements 12.1, 12.2, 12.3**
 */
class DateValidationPropertyTest extends TestCase
{
    private const ITERATIONS = 100;

    private static ?PDO $pdo = null;
    private PayeeStatementService $service;

    public static function setUpBeforeClass(): void
    {
        self::$pdo = new PDO('mysql:host=localhost;dbname=erp;charset=utf8mb4', 'root', '');
        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public static function tearDownAfterClass(): void
    {
        self::$pdo = null;
    }

    protected function setUp(): void
    {
        $this->service = new PayeeStatementService(self::$pdo);
    }

    /**
     * Generate random strings that don't match YYYY-MM-DD format.
     */
    public static function malformedDateStringProvider(): array
    {
        $cases = [];
        $malformedPatterns = [
            // Wrong separators
            fn() => sprintf('%04d/%02d/%02d', mt_rand(2000, 2030), mt_rand(1, 12), mt_rand(1, 28)),
            fn() => sprintf('%04d.%02d.%02d', mt_rand(2000, 2030), mt_rand(1, 12), mt_rand(1, 28)),
            // Reversed format (DD-MM-YYYY)
            fn() => sprintf('%02d-%02d-%04d', mt_rand(1, 28), mt_rand(1, 12), mt_rand(2000, 2030)),
            // Missing leading zeros
            fn() => sprintf('%04d-%d-%d', mt_rand(2000, 2030), mt_rand(1, 9), mt_rand(1, 9)),
            // Random alphanumeric strings
            fn() => substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, mt_rand(5, 15)),
            // Partial dates
            fn() => sprintf('%04d-%02d', mt_rand(2000, 2030), mt_rand(1, 12)),
            fn() => sprintf('%04d', mt_rand(2000, 2030)),
            // Extra characters
            fn() => sprintf('%04d-%02d-%02dT00:00:00', mt_rand(2000, 2030), mt_rand(1, 12), mt_rand(1, 28)),
            // Short year
            fn() => sprintf('%02d-%02d-%02d', mt_rand(20, 30), mt_rand(1, 12), mt_rand(1, 28)),
            // Spaces around
            fn() => sprintf(' %04d-%02d-%02d', mt_rand(2000, 2030), mt_rand(1, 12), mt_rand(1, 28)),
            fn() => sprintf('%04d-%02d-%02d ', mt_rand(2000, 2030), mt_rand(1, 12), mt_rand(1, 28)),
        ];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $generator = $malformedPatterns[array_rand($malformedPatterns)];
            $malformed = $generator();
            $cases["malformed_start_{$i}"] = [$malformed, '2024-06-15', 'start_date'];
            $cases["malformed_end_{$i}"] = ['2024-01-01', $malformed, 'end_date'];
        }
        return $cases;
    }

    /**
     * Property 9 (part A): Malformed date strings are rejected
     *
     * For any string that does not match YYYY-MM-DD format, validateDateRange
     * SHALL throw InvalidArgumentException.
     *
     * **Validates: Requirements 12.1, 12.2, 12.3**
     *
     * @dataProvider malformedDateStringProvider
     */
    public function testMalformedDateStringsAreRejected(
        string $startDate,
        string $endDate,
        string $expectedField
    ): void {
        $this->expectException(\InvalidArgumentException::class);

        if ($expectedField === 'start_date') {
            $this->expectExceptionMessageMatches('/start_date/');
        } else {
            $this->expectExceptionMessageMatches('/end_date/');
        }

        $this->service->validateDateRange($startDate, $endDate);
    }

    /**
     * Generate dates that match YYYY-MM-DD format but are invalid calendar dates.
     */
    public static function invalidCalendarDateProvider(): array
    {
        $cases = [];
        $invalidDates = [
            // Invalid month (0 or > 12)
            fn() => sprintf('%04d-%02d-%02d', mt_rand(2000, 2030), 0, mt_rand(1, 28)),
            fn() => sprintf('%04d-%02d-%02d', mt_rand(2000, 2030), mt_rand(13, 19), mt_rand(1, 28)),
            // Invalid day (0 or > days in month)
            fn() => sprintf('%04d-%02d-%02d', mt_rand(2000, 2030), mt_rand(1, 12), 0),
            fn() => sprintf('%04d-%02d-32', mt_rand(2000, 2030), mt_rand(1, 12)),
            // February 30/31 (never valid)
            fn() => sprintf('%04d-02-30', mt_rand(2000, 2030)),
            fn() => sprintf('%04d-02-31', mt_rand(2000, 2030)),
            // Feb 29 on non-leap year (2023, 2025, 2026, 2027)
            fn() => sprintf('%04d-02-29', [2023, 2025, 2026, 2027][mt_rand(0, 3)]),
            // April/June/September/November 31 (30-day months)
            fn() => sprintf('%04d-%02d-31', mt_rand(2000, 2030), [4, 6, 9, 11][mt_rand(0, 3)]),
        ];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $generator = $invalidDates[array_rand($invalidDates)];
            $invalidDate = $generator();
            $cases["invalid_cal_start_{$i}"] = [$invalidDate, '2024-12-31', 'start_date'];
            $cases["invalid_cal_end_{$i}"] = ['2024-01-01', $invalidDate, 'end_date'];
        }
        return $cases;
    }

    /**
     * Property 9 (part B): Invalid calendar dates are rejected
     *
     * For any string that matches YYYY-MM-DD format but does not represent
     * a valid calendar date, validateDateRange SHALL throw InvalidArgumentException.
     *
     * **Validates: Requirements 12.1, 12.2, 12.3**
     *
     * @dataProvider invalidCalendarDateProvider
     */
    public function testInvalidCalendarDatesAreRejected(
        string $startDate,
        string $endDate,
        string $expectedField
    ): void {
        $this->expectException(\InvalidArgumentException::class);

        if ($expectedField === 'start_date') {
            $this->expectExceptionMessageMatches('/start_date/');
        } else {
            $this->expectExceptionMessageMatches('/end_date/');
        }

        $this->service->validateDateRange($startDate, $endDate);
    }

    /**
     * Generate null/empty value pairs.
     */
    public static function nullEmptyValueProvider(): array
    {
        $cases = [];
        $nullValues = [null, ''];

        for ($i = 0; $i < 20; $i++) {
            $nullVal = $nullValues[array_rand($nullValues)];
            $cases["null_start_{$i}"] = [$nullVal, '2024-06-15'];
            $cases["null_end_{$i}"] = ['2024-01-01', $nullVal];
        }
        // Both null/empty
        $cases['both_null'] = [null, null];
        $cases['both_empty'] = ['', ''];
        $cases['null_and_empty'] = [null, ''];
        $cases['empty_and_null'] = ['', null];

        return $cases;
    }

    /**
     * Property 9 (part C): Null/empty dates are rejected
     *
     * For any null or empty date value, validateDateRange SHALL throw
     * InvalidArgumentException with a message about required dates.
     *
     * **Validates: Requirements 12.1, 12.2, 12.3**
     *
     * @dataProvider nullEmptyValueProvider
     */
    public function testNullEmptyDatesAreRejected(
        ?string $startDate,
        ?string $endDate
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/required|start_date|end_date/');

        $this->service->validateDateRange($startDate, $endDate);
    }

    /**
     * Generate valid date pairs where start > end (illogical ranges).
     */
    public static function illogicalRangeProvider(): array
    {
        $cases = [];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Generate two different valid dates, ensure start > end
            $year1 = mt_rand(2020, 2030);
            $month1 = mt_rand(1, 12);
            $day1 = mt_rand(1, 28); // Stay at 28 max to avoid calendar issues

            // Make a second date that is strictly earlier
            $year2 = mt_rand(2000, $year1 - 1);
            $month2 = mt_rand(1, 12);
            $day2 = mt_rand(1, 28);

            $laterDate = sprintf('%04d-%02d-%02d', $year1, $month1, $day1);
            $earlierDate = sprintf('%04d-%02d-%02d', $year2, $month2, $day2);

            $cases["illogical_range_{$i}"] = [$laterDate, $earlierDate];
        }
        return $cases;
    }

    /**
     * Property 9 (part D): Illogical ranges (start > end) are rejected
     *
     * For any pair of valid dates where start_date > end_date, validateDateRange
     * SHALL throw InvalidArgumentException indicating the illogical range.
     *
     * **Validates: Requirements 12.1, 12.2, 12.3**
     *
     * @dataProvider illogicalRangeProvider
     */
    public function testIllogicalRangesAreRejected(
        string $startDate,
        string $endDate
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/start_date must be less than or equal to end_date/');

        $this->service->validateDateRange($startDate, $endDate);
    }

    /**
     * Generate valid date range pairs where start <= end.
     */
    public static function validDateRangeProvider(): array
    {
        $cases = [];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Generate a valid start date
            $year = mt_rand(2020, 2028);
            $month = mt_rand(1, 12);
            $day = mt_rand(1, 28);
            $startDate = sprintf('%04d-%02d-%02d', $year, $month, $day);

            // Generate an end date that is >= start date (later year guaranteed)
            $endYear = mt_rand($year + 1, 2030);
            $endMonth = mt_rand(1, 12);
            $endDay = mt_rand(1, 28);
            $endDate = sprintf('%04d-%02d-%02d', $endYear, $endMonth, $endDay);

            $cases["valid_range_{$i}"] = [$startDate, $endDate];
        }

        // Also include same-date ranges (start == end)
        for ($i = 0; $i < 10; $i++) {
            $date = sprintf('%04d-%02d-%02d', mt_rand(2020, 2030), mt_rand(1, 12), mt_rand(1, 28));
            $cases["same_date_{$i}"] = [$date, $date];
        }

        return $cases;
    }

    /**
     * Property 9 (part E): Valid date ranges pass without exception
     *
     * For any pair of valid YYYY-MM-DD dates where start_date <= end_date,
     * validateDateRange SHALL NOT throw any exception.
     *
     * **Validates: Requirements 12.1, 12.2, 12.3**
     *
     * @dataProvider validDateRangeProvider
     */
    public function testValidDateRangesPass(
        string $startDate,
        string $endDate
    ): void {
        // Should not throw - if it does, the test fails automatically
        $this->service->validateDateRange($startDate, $endDate);

        // If we reach here, validation passed
        $this->assertTrue(true, 'Valid date range accepted without exception');
    }
}
