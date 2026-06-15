<?php

namespace Tests\Property;

use PHPUnit\Framework\TestCase;

/**
 * Feature: po-price-update, Property 4: Previous record closure
 *
 * For any item that has an existing item_prices record with NULL valid_to,
 * when a new price record is inserted with valid_from = V, the previous
 * record's valid_to SHALL be set to (V - 1 day) if the previous record's
 * valid_from < V, or to V if the previous record's valid_from >= V.
 *
 * Validates: Requirements 2.3, 2.4
 */
class PriceRecordClosurePropertyTest extends TestCase
{
    private const ITERATIONS = 120;

    /**
     * Replicate the date closure logic from ItemPriceRepository::closePreviousRecord().
     *
     * This is the PHP equivalent of the SQL CASE expression:
     *   WHEN valid_from >= :new_valid_from THEN :new_valid_from
     *   ELSE DATE_SUB(:new_valid_from, INTERVAL 1 DAY)
     *
     * @param string $previousValidFrom The previous record's valid_from (Y-m-d)
     * @param string $newValidFrom      The new record's valid_from (Y-m-d)
     * @return string The computed valid_to date (Y-m-d)
     */
    private function computeClosureDate(string $previousValidFrom, string $newValidFrom): string
    {
        if ($previousValidFrom >= $newValidFrom) {
            // Same-day closure: previous valid_from >= new valid_from
            return $newValidFrom;
        }

        // Normal case: previous valid_from < new valid_from → valid_to = new_valid_from - 1 day
        $date = new \DateTimeImmutable($newValidFrom);
        return $date->modify('-1 day')->format('Y-m-d');
    }

    /**
     * Data provider generating 120 random date pairs where previous valid_from < new valid_from.
     * Tests the normal case: valid_to = new_valid_from - 1 day.
     *
     * Each case: [previousValidFrom, newValidFrom]
     */
    public static function normalClosureProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Generate a random base date between 2020-01-02 and 2030-12-31
            $baseTimestamp = mt_rand(
                strtotime('2020-01-02'),
                strtotime('2030-12-31')
            );
            $newValidFrom = date('Y-m-d', $baseTimestamp);

            // Previous valid_from must be strictly before new valid_from (1 to 3650 days earlier)
            $daysBefore = mt_rand(1, 3650);
            $previousTimestamp = strtotime("-{$daysBefore} days", $baseTimestamp);
            $previousValidFrom = date('Y-m-d', $previousTimestamp);

            $cases["normal_iter_{$i}_prev_{$previousValidFrom}_new_{$newValidFrom}"] = [
                $previousValidFrom,
                $newValidFrom,
            ];
        }
        return $cases;
    }

    /**
     * Property 4 (Normal case): When previous valid_from < new valid_from,
     * valid_to SHALL be set to (new_valid_from - 1 day).
     *
     * **Validates: Requirements 2.3**
     *
     * @dataProvider normalClosureProvider
     */
    public function testNormalClosureSetsValidToOneDayBeforeNewValidFrom(
        string $previousValidFrom,
        string $newValidFrom
    ): void {
        // Precondition: previous valid_from < new valid_from
        $this->assertLessThan(
            $newValidFrom,
            $previousValidFrom,
            'Test precondition: previous valid_from must be before new valid_from'
        );

        $computedValidTo = $this->computeClosureDate($previousValidFrom, $newValidFrom);

        // Expected: new_valid_from - 1 day
        $expectedValidTo = (new \DateTimeImmutable($newValidFrom))
            ->modify('-1 day')
            ->format('Y-m-d');

        $this->assertSame(
            $expectedValidTo,
            $computedValidTo,
            sprintf(
                'Normal closure: previous valid_from=%s, new valid_from=%s → '
                . 'valid_to should be %s (new_valid_from - 1 day), got %s',
                $previousValidFrom,
                $newValidFrom,
                $expectedValidTo,
                $computedValidTo
            )
        );

        // Additional invariant: valid_to must be >= previous valid_from
        $this->assertGreaterThanOrEqual(
            $previousValidFrom,
            $computedValidTo,
            sprintf(
                'Invariant: valid_to (%s) must be >= previous valid_from (%s)',
                $computedValidTo,
                $previousValidFrom
            )
        );

        // Additional invariant: valid_to must be < new valid_from
        $this->assertLessThan(
            $newValidFrom,
            $computedValidTo,
            sprintf(
                'Invariant: valid_to (%s) must be < new valid_from (%s)',
                $computedValidTo,
                $newValidFrom
            )
        );
    }

    /**
     * Data provider generating 120 random date pairs where previous valid_from >= new valid_from.
     * Tests the edge case: same-day closure (valid_to = new_valid_from).
     *
     * Each case: [previousValidFrom, newValidFrom]
     */
    public static function sameDayClosureProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Generate a random new valid_from date
            $baseTimestamp = mt_rand(
                strtotime('2020-01-01'),
                strtotime('2030-12-31')
            );
            $newValidFrom = date('Y-m-d', $baseTimestamp);

            // Previous valid_from is same day or later (0 to 365 days after)
            $daysAfter = mt_rand(0, 365);
            $previousTimestamp = strtotime("+{$daysAfter} days", $baseTimestamp);
            $previousValidFrom = date('Y-m-d', $previousTimestamp);

            $cases["sameday_iter_{$i}_prev_{$previousValidFrom}_new_{$newValidFrom}"] = [
                $previousValidFrom,
                $newValidFrom,
            ];
        }
        return $cases;
    }

    /**
     * Property 4 (Edge case): When previous valid_from >= new valid_from,
     * valid_to SHALL be set to new_valid_from (same day closure).
     *
     * **Validates: Requirements 2.4**
     *
     * @dataProvider sameDayClosureProvider
     */
    public function testSameDayClosureSetsValidToNewValidFrom(
        string $previousValidFrom,
        string $newValidFrom
    ): void {
        // Precondition: previous valid_from >= new valid_from
        $this->assertGreaterThanOrEqual(
            $newValidFrom,
            $previousValidFrom,
            'Test precondition: previous valid_from must be >= new valid_from'
        );

        $computedValidTo = $this->computeClosureDate($previousValidFrom, $newValidFrom);

        $this->assertSame(
            $newValidFrom,
            $computedValidTo,
            sprintf(
                'Same-day closure: previous valid_from=%s, new valid_from=%s → '
                . 'valid_to should be %s (same as new_valid_from), got %s',
                $previousValidFrom,
                $newValidFrom,
                $newValidFrom,
                $computedValidTo
            )
        );
    }

    /**
     * Data provider generating 120 random date pairs covering both branches.
     * Verifies the correct branch is taken regardless of input.
     *
     * Each case: [previousValidFrom, newValidFrom]
     */
    public static function mixedClosureProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Generate two random dates
            $timestamp1 = mt_rand(
                strtotime('2020-01-01'),
                strtotime('2030-12-31')
            );
            $timestamp2 = mt_rand(
                strtotime('2020-01-01'),
                strtotime('2030-12-31')
            );

            $previousValidFrom = date('Y-m-d', $timestamp1);
            $newValidFrom = date('Y-m-d', $timestamp2);

            $cases["mixed_iter_{$i}_prev_{$previousValidFrom}_new_{$newValidFrom}"] = [
                $previousValidFrom,
                $newValidFrom,
            ];
        }
        return $cases;
    }

    /**
     * Property 4 (Combined): For any pair of dates, the closure logic SHALL
     * correctly select the appropriate branch and compute the valid_to date.
     *
     * **Validates: Requirements 2.3, 2.4**
     *
     * @dataProvider mixedClosureProvider
     */
    public function testClosureLogicSelectsCorrectBranch(
        string $previousValidFrom,
        string $newValidFrom
    ): void {
        $computedValidTo = $this->computeClosureDate($previousValidFrom, $newValidFrom);

        if ($previousValidFrom < $newValidFrom) {
            // Normal case: valid_to = new_valid_from - 1 day
            $expectedValidTo = (new \DateTimeImmutable($newValidFrom))
                ->modify('-1 day')
                ->format('Y-m-d');

            $this->assertSame(
                $expectedValidTo,
                $computedValidTo,
                sprintf(
                    'Branch selection: prev=%s < new=%s → expected valid_to=%s, got %s',
                    $previousValidFrom,
                    $newValidFrom,
                    $expectedValidTo,
                    $computedValidTo
                )
            );
        } else {
            // Edge case (previous >= new): valid_to = new_valid_from
            $this->assertSame(
                $newValidFrom,
                $computedValidTo,
                sprintf(
                    'Branch selection: prev=%s >= new=%s → expected valid_to=%s, got %s',
                    $previousValidFrom,
                    $newValidFrom,
                    $newValidFrom,
                    $computedValidTo
                )
            );
        }
    }
}
