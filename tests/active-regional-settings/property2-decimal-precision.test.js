/**
 * Property 2: Decimal precision preservation
 *
 * For any numeric value with N decimal places (where N ≥ 0) and any valid
 * separator configuration, formatting the number SHALL produce a string whose
 * decimal portion contains exactly N digits.
 *
 * Validates: Requirements 2.5
 *
 * Feature: active-regional-settings, Property 2: Decimal precision preservation
 */

import { describe, it, expect } from 'vitest';
import * as fc from 'fast-check';
import { configure, formatNumber } from '../../public/assets/js/i18n/formatter.js';

/**
 * Supported number format patterns with their expected separators.
 */
const SUPPORTED_PATTERNS = [
  { pattern: '1,234.56', thousands_sep: ',', decimal_sep: '.' },
  { pattern: '1.234,56', thousands_sep: '.', decimal_sep: ',' },
  { pattern: '1 234,56', thousands_sep: ' ', decimal_sep: ',' },
];

/**
 * Arbitrary that generates one of the supported number format patterns.
 */
const patternArb = fc.constantFrom(...SUPPORTED_PATTERNS);

/**
 * Arbitrary that generates a number of decimal places (0-6).
 */
const decimalPlacesArb = fc.integer({ min: 0, max: 6 });

/**
 * Arbitrary that generates numeric values suitable for formatting.
 * Covers a wide range including small, medium, and large numbers.
 */
const numericValueArb = fc.double({
  min: 0,
  max: 999999999,
  noNaN: true,
  noDefaultInfinity: true,
});

describe('Feature: active-regional-settings, Property 2: Decimal precision preservation', () => {
  it('formatting a number with N decimal places produces a string with exactly N decimal digits', () => {
    fc.assert(
      fc.property(
        patternArb,
        numericValueArb,
        decimalPlacesArb,
        (patternConfig, value, decimals) => {
          // Configure the formatter with this pattern
          configure({ number_format: patternConfig.pattern });

          // Format the value with the specified number of decimal places
          const formatted = formatNumber(value, decimals);

          // The formatted string should not be empty for valid numbers
          expect(formatted).not.toBe('');

          const decimalSep = patternConfig.decimal_sep;

          if (decimals === 0) {
            // When 0 decimal places, the decimal separator should NOT appear
            // (unless it's also used as thousands separator, which doesn't happen
            // in our supported patterns since thousands and decimal seps are always different)
            expect(formatted).not.toContain(decimalSep);
          } else {
            // When N > 0 decimal places, the decimal separator should appear
            expect(formatted).toContain(decimalSep);

            // Find the last occurrence of the decimal separator
            const lastDecSepIndex = formatted.lastIndexOf(decimalSep);
            expect(lastDecSepIndex).toBeGreaterThan(-1);

            // The portion after the decimal separator is the fractional part
            const fractionalPart = formatted.slice(lastDecSepIndex + 1);

            // The fractional part should contain exactly N digits
            expect(fractionalPart).toMatch(/^\d+$/);
            expect(fractionalPart.length).toBe(decimals);
          }
        }
      ),
      { numRuns: 100 }
    );
  });
});
