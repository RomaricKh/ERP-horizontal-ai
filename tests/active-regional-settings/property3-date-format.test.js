/**
 * Property 3: Date formatting correctness
 *
 * For any valid date (year, month, day) and any supported date format pattern
 * ("d/m/Y", "Y-m-d", "m/d/Y"), formatting the date SHALL produce a string that
 * contains the correct day, month, and year values positioned according to the
 * pattern's structure.
 *
 * Validates: Requirements 3.1
 *
 * Feature: active-regional-settings, Property 3: Date formatting correctness
 */

import { describe, it, expect } from 'vitest';
import * as fc from 'fast-check';
import { configure, formatDate } from '../../public/assets/js/i18n/formatter.js';

/**
 * Supported date format patterns and their expected component positions.
 */
const SUPPORTED_DATE_FORMATS = ['d/m/Y', 'Y-m-d', 'm/d/Y'];

/**
 * Arbitrary that generates one of the supported date format patterns.
 */
const dateFormatArb = fc.constantFrom(...SUPPORTED_DATE_FORMATS);

/**
 * Arbitrary that generates a valid year in range 1900-2100.
 */
const yearArb = fc.integer({ min: 1900, max: 2100 });

/**
 * Arbitrary that generates a valid month (1-12).
 */
const monthArb = fc.integer({ min: 1, max: 12 });

/**
 * Arbitrary that generates a valid day (1-28) to avoid month-length issues.
 */
const dayArb = fc.integer({ min: 1, max: 28 });

/**
 * Pads a number to two digits with a leading zero if needed.
 * @param {number} n
 * @returns {string}
 */
function pad2(n) {
  return String(n).padStart(2, '0');
}

describe('Feature: active-regional-settings, Property 3: Date formatting correctness', () => {
  it('formatting a date with any supported pattern produces correctly positioned components', () => {
    fc.assert(
      fc.property(
        dateFormatArb,
        yearArb,
        monthArb,
        dayArb,
        (dateFormat, year, month, day) => {
          // Configure the formatter with the date format
          configure({ date_format: dateFormat });

          // Build the ISO date string input
          const isoDate = `${year}-${pad2(month)}-${pad2(day)}`;

          // Format the date
          const formatted = formatDate(isoDate);

          // The result should not be empty for valid dates
          expect(formatted).not.toBe('');

          // Expected component strings
          const expectedYear = String(year);
          const expectedMonth = pad2(month);
          const expectedDay = pad2(day);

          // Verify the formatted output contains correct components in the right positions
          switch (dateFormat) {
            case 'd/m/Y': {
              // Expected: "DD/MM/YYYY"
              const parts = formatted.split('/');
              expect(parts).toHaveLength(3);
              expect(parts[0]).toBe(expectedDay);
              expect(parts[1]).toBe(expectedMonth);
              expect(parts[2]).toBe(expectedYear);
              break;
            }
            case 'Y-m-d': {
              // Expected: "YYYY-MM-DD"
              const parts = formatted.split('-');
              expect(parts).toHaveLength(3);
              expect(parts[0]).toBe(expectedYear);
              expect(parts[1]).toBe(expectedMonth);
              expect(parts[2]).toBe(expectedDay);
              break;
            }
            case 'm/d/Y': {
              // Expected: "MM/DD/YYYY"
              const parts = formatted.split('/');
              expect(parts).toHaveLength(3);
              expect(parts[0]).toBe(expectedMonth);
              expect(parts[1]).toBe(expectedDay);
              expect(parts[2]).toBe(expectedYear);
              break;
            }
          }
        }
      ),
      { numRuns: 100 }
    );
  });
});
