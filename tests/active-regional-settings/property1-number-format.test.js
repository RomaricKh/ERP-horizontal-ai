/**
 * Property 1: Number format parsing and formatting round-trip
 *
 * For any valid number format pattern (from the set of supported patterns like
 * "1,234.56", "1.234,56", "1 234,56"), parsing the pattern into separators and
 * then formatting any numeric value with those separators SHALL produce a string
 * where the thousands grouping uses the parsed thousands_separator and the
 * fractional part is delimited by the parsed decimal_separator.
 *
 * Validates: Requirements 1.2, 2.1
 *
 * Feature: active-regional-settings, Property 1: Number format parsing and formatting round-trip
 */

import { describe, it, expect } from 'vitest';
import * as fc from 'fast-check';
import { parseNumberFormat, configure, formatNumber } from '../../public/assets/js/i18n/formatter.js';

/**
 * Supported number format patterns and their expected separators.
 */
const SUPPORTED_PATTERNS = [
  { pattern: '1,234.56', thousands_sep: ',', decimal_sep: '.' },
  { pattern: '1.234,56', thousands_sep: '.', decimal_sep: ',' },
  { pattern: '1 234,56', thousands_sep: ' ', decimal_sep: ',' },
];

/**
 * Arbitrary that generates one of the supported number format patterns.
 */
const patternArb = fc.constantFrom(...SUPPORTED_PATTERNS.map(p => p.pattern));

/**
 * Arbitrary that generates positive numeric values suitable for formatting.
 * We use numbers with 0-4 decimal places and values large enough to have
 * thousands grouping (>= 1000).
 */
const numericValueArb = fc.double({
  min: 1000,
  max: 999999999,
  noNaN: true,
  noDefaultInfinity: true,
}).map(n => Math.abs(n));

/**
 * Arbitrary for decimal places (0-4).
 */
const decimalsArb = fc.integer({ min: 0, max: 4 });

describe('Feature: active-regional-settings, Property 1: Number format parsing and formatting round-trip', () => {
  it('parsing a pattern and formatting a number uses the correct separators', () => {
    fc.assert(
      fc.property(
        patternArb,
        numericValueArb,
        decimalsArb,
        (pattern, value, decimals) => {
          // Parse the pattern to get expected separators
          const parsed = parseNumberFormat(pattern);
          const expectedPattern = SUPPORTED_PATTERNS.find(p => p.pattern === pattern);

          // Verify parsing produces correct separators
          expect(parsed.thousands_sep).toBe(expectedPattern.thousands_sep);
          expect(parsed.decimal_sep).toBe(expectedPattern.decimal_sep);

          // Configure the formatter with this pattern
          configure({ number_format: pattern });

          // Format the value
          const formatted = formatNumber(value, decimals);

          // The formatted string should not be empty for valid numbers
          expect(formatted).not.toBe('');

          // If there are decimal places, the decimal separator should be present
          if (decimals > 0) {
            expect(formatted).toContain(parsed.decimal_sep);

            // Split on the decimal separator - there should be exactly one occurrence
            // used as the fractional delimiter
            const lastDecSepIndex = formatted.lastIndexOf(parsed.decimal_sep);
            expect(lastDecSepIndex).toBeGreaterThan(0);

            // The fractional part after the decimal separator should have exactly `decimals` digits
            const fractionalPart = formatted.slice(lastDecSepIndex + 1);
            expect(fractionalPart).toMatch(/^\d+$/);
            expect(fractionalPart.length).toBe(decimals);
          }

          // The integer part (before decimal separator or entire string if no decimals)
          // should use the thousands separator for grouping
          const integerPart = decimals > 0
            ? formatted.slice(0, formatted.lastIndexOf(parsed.decimal_sep))
            : formatted;

          // Remove the thousands separator to check the digits are valid
          const digitsOnly = integerPart.split(parsed.thousands_sep).join('');
          expect(digitsOnly).toMatch(/^\d+$/);

          // If the number is large enough to have grouping (>= 1000),
          // the thousands separator should appear in the integer part
          if (Math.floor(value) >= 1000) {
            expect(integerPart).toContain(parsed.thousands_sep);
          }

          // Verify grouping: each group (except possibly the first) should be exactly 3 digits
          const groups = integerPart.split(parsed.thousands_sep);
          if (groups.length > 1) {
            // First group can be 1-3 digits
            expect(groups[0].length).toBeGreaterThanOrEqual(1);
            expect(groups[0].length).toBeLessThanOrEqual(3);
            // All subsequent groups must be exactly 3 digits
            for (let i = 1; i < groups.length; i++) {
              expect(groups[i].length).toBe(3);
            }
          }
        }
      ),
      { numRuns: 100 }
    );
  });
});
