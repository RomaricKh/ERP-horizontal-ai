/**
 * Property 4: Currency formatting combines number formatting with prefix rules
 *
 * For any numeric value and any valid currency format string, formatting as
 * currency SHALL produce a string where: (a) the numeric portion is formatted
 * identically to what formatNumber would produce, and (b) if the currency format
 * is a multi-letter code (e.g., "USD"), it appears as a prefix followed by a space,
 * or if it is a symbol (single non-alphanumeric character like "$"), it appears as
 * a prefix without a space.
 *
 * Validates: Requirements 4.1, 4.2, 4.3, 4.4
 *
 * Feature: active-regional-settings, Property 4: Currency formatting combines number formatting with prefix rules
 */

import { describe, it, expect } from 'vitest';
import * as fc from 'fast-check';
import { configure, formatNumber, formatCurrency } from '../../public/assets/js/i18n/formatter.js';

/**
 * Supported number format patterns.
 */
const SUPPORTED_PATTERNS = ['1,234.56', '1.234,56', '1 234,56'];

/**
 * Arbitrary for multi-letter currency codes (2-5 uppercase letters like "USD", "EUR").
 */
const multiLetterCodeArb = fc.array(
  fc.constantFrom(...'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('')),
  { minLength: 2, maxLength: 5 }
).map(chars => chars.join(''));

/**
 * Arbitrary for single non-alphanumeric currency symbols (e.g., "$", "€", "£", "¥").
 */
const singleSymbolArb = fc.constantFrom('$', '€', '£', '¥', '₹', '₽', '₩', '₪', '฿', '₫');

/**
 * Arbitrary that generates either a multi-letter code or a single symbol.
 */
const currencyFormatArb = fc.oneof(multiLetterCodeArb, singleSymbolArb);

/**
 * Arbitrary for number format patterns.
 */
const patternArb = fc.constantFrom(...SUPPORTED_PATTERNS);

/**
 * Arbitrary for positive numeric values suitable for currency formatting.
 */
const numericValueArb = fc.double({
  min: 0.01,
  max: 9999999.99,
  noNaN: true,
  noDefaultInfinity: true,
}).map(n => Math.abs(n));

/**
 * Arbitrary for decimal places (0-4).
 */
const decimalsArb = fc.integer({ min: 0, max: 4 });

describe('Feature: active-regional-settings, Property 4: Currency formatting combines number formatting with prefix rules', () => {
  it('currency formatting produces correctly prefixed number-formatted values', () => {
    fc.assert(
      fc.property(
        patternArb,
        currencyFormatArb,
        numericValueArb,
        decimalsArb,
        (pattern, currencyFormat, value, decimals) => {
          // Configure the formatter with the given pattern and currency format
          configure({
            number_format: pattern,
            currency_format: currencyFormat,
          });

          // Get the expected number-formatted portion
          const expectedNumberPart = formatNumber(value, decimals);

          // Get the currency-formatted result
          const currencyResult = formatCurrency(value, decimals);

          // The result should not be empty for valid numeric values
          expect(currencyResult).not.toBe('');

          // Determine if the currency format is a single non-alphanumeric symbol
          const isSingleSymbol = currencyFormat.length === 1 && !/[a-zA-Z0-9]/.test(currencyFormat);

          if (isSingleSymbol) {
            // (b) Single symbol: prefix without space → "€1,234.56"
            const expectedResult = `${currencyFormat}${expectedNumberPart}`;
            expect(currencyResult).toBe(expectedResult);

            // Verify the numeric portion matches formatNumber output exactly
            const numericPortion = currencyResult.slice(currencyFormat.length);
            expect(numericPortion).toBe(expectedNumberPart);
          } else {
            // (b) Multi-letter code: prefix with space → "USD 1,234.56"
            const expectedResult = `${currencyFormat} ${expectedNumberPart}`;
            expect(currencyResult).toBe(expectedResult);

            // Verify the prefix is followed by a space
            expect(currencyResult.startsWith(currencyFormat + ' ')).toBe(true);

            // Verify the numeric portion after "CODE " matches formatNumber output exactly
            const numericPortion = currencyResult.slice(currencyFormat.length + 1);
            expect(numericPortion).toBe(expectedNumberPart);
          }
        }
      ),
      { numRuns: 100 }
    );
  });
});
