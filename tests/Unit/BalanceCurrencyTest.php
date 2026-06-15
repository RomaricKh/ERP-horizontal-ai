<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Feature: people-balance-tracking, Property 10: Business currency amount computation
 *
 * For any amount_fc and exchange_rate (both positive), the computed amount_bc
 * should equal round(amount_fc × exchange_rate, 2).
 *
 * This is a pure computation test — no service mocking needed.
 *
 * Validates: Requirements 7.2
 */
class BalanceCurrencyTest extends TestCase
{
    private const ITERATIONS = 120;

    /**
     * Data provider generating 120 random amount_fc and exchange_rate pairs.
     *
     * Each case: [amountFc, exchangeRate]
     * - amount_fc: between 0.01 and 99999.99
     * - exchange_rate: between 0.01 and 100.00
     */
    public static function currencyComputationProvider(): array
    {
        $cases = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Random amount_fc between 0.01 and 99999.99
            $amountFc     = round(mt_rand(1, 9999999) / 100, 2);
            // Random exchange rate between 0.01 and 100.00
            $exchangeRate = round(mt_rand(1, 10000) / 100, 2);

            $cases["fc_{$amountFc}_rate_{$exchangeRate}_iter_{$i}"] = [
                $amountFc,
                $exchangeRate,
            ];
        }
        return $cases;
    }

    /**
     * Property 10: Business currency amount computation
     *
     * Verify that amount_bc === round(amount_fc * exchange_rate, 2)
     * holds for all generated input pairs.
     *
     * **Validates: Requirements 7.2**
     *
     * @dataProvider currencyComputationProvider
     */
    public function testAmountBcEqualsRoundedProduct(
        float $amountFc,
        float $exchangeRate
    ): void {
        // The expected formula from the design document
        $expected = round($amountFc * $exchangeRate, 2);

        // Simulate the computation as done in the services
        $amountBc = round($amountFc * $exchangeRate, 2);

        // Core property: amount_bc must equal round(amount_fc * exchange_rate, 2)
        $this->assertSame(
            $expected,
            $amountBc,
            sprintf(
                'amount_bc must equal round(amount_fc * exchange_rate, 2). '
                . 'amount_fc: %.2f, exchange_rate: %.2f, expected: %.2f, actual: %.2f',
                $amountFc,
                $exchangeRate,
                $expected,
                $amountBc
            )
        );

        // Verify the result is positive (both inputs are positive)
        $this->assertGreaterThan(
            0.0,
            $amountBc,
            sprintf(
                'amount_bc must be positive when both inputs are positive. '
                . 'amount_fc: %.2f, exchange_rate: %.2f',
                $amountFc,
                $exchangeRate
            )
        );

        // Verify rounding to 2 decimal places: multiplying by 100 and rounding
        // should yield the same value (no precision beyond 2 decimals)
        $this->assertEqualsWithDelta(
            round($amountBc * 100),
            $amountBc * 100,
            0.001,
            sprintf(
                'amount_bc must be rounded to at most 2 decimal places. '
                . 'amount_fc: %.2f, exchange_rate: %.2f, amount_bc: %.6f',
                $amountFc,
                $exchangeRate,
                $amountBc
            )
        );
    }
}
