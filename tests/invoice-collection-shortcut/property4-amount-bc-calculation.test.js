/**
 * Feature: invoice-collection-shortcut
 * Property 4: Amount BC calculation from pre-filled values
 *
 * For any amount_fc and exchange_rate values pre-filled into the collection form,
 * the displayed amount_bc SHALL equal Math.round(amount_fc * exchange_rate * 100) / 100
 * (i.e., the result of calcBc(amount_fc, exchange_rate)).
 *
 * **Validates: Requirements 2.8**
 *
 * @vitest-environment node
 */
import { describe, it, expect } from 'vitest';
import fc from 'fast-check';
import { calcBc, formatAmount } from '../../public/assets/js/utils/currency.js';

// --- Tests ---

describe('Feature: invoice-collection-shortcut, Property 4: Amount BC calculation', () => {
    it('calcBc returns Math.round(amount_fc * exchange_rate * 100) / 100 for any valid inputs', () => {
        fc.assert(
            fc.property(
                fc.integer({ min: 0, max: 99999900 }).map(n => n / 100),
                fc.integer({ min: 1, max: 99990000 }).map(n => n / 10000),
                (amountFc, exchangeRate) => {
                    const result = calcBc(amountFc, exchangeRate);
                    const expected = Math.round(amountFc * exchangeRate * 100) / 100;

                    expect(result).toBe(expected);
                }
            ),
            { numRuns: 100 }
        );
    });

    it('calcBc result is always rounded to at most 2 decimal places', () => {
        fc.assert(
            fc.property(
                fc.integer({ min: 0, max: 99999900 }).map(n => n / 100),
                fc.integer({ min: 1, max: 99990000 }).map(n => n / 10000),
                (amountFc, exchangeRate) => {
                    const result = calcBc(amountFc, exchangeRate);
                    // Check that result has at most 2 decimal places
                    const rounded = Math.round(result * 100) / 100;
                    expect(result).toBe(rounded);
                }
            ),
            { numRuns: 100 }
        );
    });

    it('formatAmount(calcBc(fc, rate)) produces a valid 2-decimal string', () => {
        fc.assert(
            fc.property(
                fc.integer({ min: 0, max: 99999900 }).map(n => n / 100),
                fc.integer({ min: 1, max: 99990000 }).map(n => n / 10000),
                (amountFc, exchangeRate) => {
                    const bc = calcBc(amountFc, exchangeRate);
                    const formatted = formatAmount(bc);

                    // Should be a string with exactly 2 decimal places
                    expect(formatted).toMatch(/^-?\d+\.\d{2}$/);

                    // Parsing it back should equal the calcBc result
                    expect(parseFloat(formatted)).toBe(bc);
                }
            ),
            { numRuns: 100 }
        );
    });

    it('calcBc with zero amount_fc always returns 0', () => {
        fc.assert(
            fc.property(
                fc.integer({ min: 1, max: 99990000 }).map(n => n / 10000),
                (exchangeRate) => {
                    expect(calcBc(0, exchangeRate)).toBe(0);
                }
            ),
            { numRuns: 100 }
        );
    });
});
