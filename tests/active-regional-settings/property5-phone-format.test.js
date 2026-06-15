/**
 * Property 5: Phone template formatting
 *
 * For any raw digit string whose length equals the number of "#" placeholders
 * in the phone format template, formatting SHALL produce a string where every
 * "#" in the template is replaced by the corresponding digit from the raw string,
 * and all non-"#" characters in the template remain in their original positions.
 *
 * Validates: Requirements 5.1
 *
 * Feature: active-regional-settings, Property 5: Phone template formatting
 */

import { describe, it, expect } from 'vitest';
import * as fc from 'fast-check';
import { configure, formatPhone } from '../../public/assets/js/i18n/formatter.js';

/**
 * Arbitrary that generates non-"#" template filler characters.
 * These are characters commonly found in phone format templates:
 * spaces, dashes, parentheses, plus signs, dots, slashes.
 */
const fillerCharArb = fc.constantFrom(' ', '-', '(', ')', '+', '.', '/');

/**
 * Arbitrary that generates a phone format template.
 * A template is a string composed of "#" placeholders mixed with
 * non-digit filler characters. We ensure at least 1 placeholder exists.
 */
const phoneTemplateArb = fc.array(
  fc.oneof(
    { weight: 3, arbitrary: fc.constant('#') },
    { weight: 2, arbitrary: fillerCharArb }
  ),
  { minLength: 2, maxLength: 20 }
).filter(arr => arr.some(c => c === '#'))
  .map(arr => arr.join(''));

/**
 * Arbitrary that generates a digit string of exactly the given length.
 */
function digitStringOfLength(length) {
  return fc.array(
    fc.integer({ min: 0, max: 9 }),
    { minLength: length, maxLength: length }
  ).map(digits => digits.join(''));
}

describe('Feature: active-regional-settings, Property 5: Phone template formatting', () => {
  it('formatting replaces each "#" with the corresponding digit and preserves all other characters', () => {
    fc.assert(
      fc.property(
        phoneTemplateArb.chain(template => {
          const placeholderCount = (template.match(/#/g) || []).length;
          return digitStringOfLength(placeholderCount).map(digits => ({
            template,
            digits,
            placeholderCount
          }));
        }),
        ({ template, digits, placeholderCount }) => {
          // Configure the formatter with this phone template
          configure({ phone_format: template });

          // Format the digits
          const result = formatPhone(digits);

          // The result should have the same length as the template
          // (since digit count matches placeholder count exactly)
          expect(result.length).toBe(template.length);

          // Walk through the template and verify each position
          let digitIndex = 0;
          for (let i = 0; i < template.length; i++) {
            if (template[i] === '#') {
              // This position should be replaced by the corresponding digit
              expect(result[i]).toBe(digits[digitIndex]);
              digitIndex++;
            } else {
              // Non-"#" characters should remain in their original positions
              expect(result[i]).toBe(template[i]);
            }
          }

          // All digits should have been consumed
          expect(digitIndex).toBe(placeholderCount);
        }
      ),
      { numRuns: 100 }
    );
  });
});
