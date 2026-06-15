import { describe, it, expect } from 'vitest';
import fc from 'fast-check';
import fs from 'fs';
import path from 'path';

/**
 * Bug 14 — API Response Fallback Patterns
 * Bug Condition Exploration Test
 *
 * `accounts.js` uses `data.data.accounts ?? data.data` and `exchangeRates.js`
 * uses `data.data.exchange_rates ?? data.data`. The `??` operator only triggers
 * on `null`/`undefined`, so if the expected key is missing, `data.data` (the
 * wrapper object) is passed to `renderTable()` instead of an empty array.
 *
 * The fix should use `?? []` instead of `?? data.data`.
 *
 * This test reads the actual JS source and verifies the fallback is `[]`.
 * On unfixed code, this test FAILS — proving the bug.
 *
 * **Validates: Requirements 1.40, 1.41**
 */

describe('Bug 14 — Fallback Pattern: accounts.js and exchangeRates.js must fall back to []', () => {
  const accountsPath = path.resolve('public/assets/js/pages/Finance/accounts.js');
  const exchangeRatesPath = path.resolve('public/assets/js/pages/Finance/exchangeRates.js');

  const accountsSource = fs.readFileSync(accountsPath, 'utf-8');
  const exchangeRatesSource = fs.readFileSync(exchangeRatesPath, 'utf-8');

  it('accounts.js must use ?? [] instead of ?? data.data for the accounts fallback', () => {
    // The buggy pattern: data.data.accounts ?? data.data
    // The correct pattern: data.data.accounts ?? []
    const hasBuggyFallback = /data\.data\.accounts\s*\?\?\s*data\.data/.test(accountsSource);
    const hasCorrectFallback = /data\.data\.accounts\s*\?\?\s*\[\s*\]/.test(accountsSource);

    expect(hasBuggyFallback, 'accounts.js still uses ?? data.data (wrapper object) as fallback').toBe(false);
    expect(hasCorrectFallback, 'accounts.js should use ?? [] as fallback').toBe(true);
  });

  it('exchangeRates.js must use ?? [] instead of ?? data.data for the exchange_rates fallback', () => {
    // The buggy pattern: data.data.exchange_rates ?? data.data
    // The correct pattern: data.data.exchange_rates ?? []
    const hasBuggyFallback = /data\.data\.exchange_rates\s*\?\?\s*data\.data/.test(exchangeRatesSource);
    const hasCorrectFallback = /data\.data\.exchange_rates\s*\?\?\s*\[\s*\]/.test(exchangeRatesSource);

    expect(hasBuggyFallback, 'exchangeRates.js still uses ?? data.data (wrapper object) as fallback').toBe(false);
    expect(hasCorrectFallback, 'exchangeRates.js should use ?? [] as fallback').toBe(true);
  });

  it('property: for any API response missing the expected key, renderTable must receive an array, not an object', () => {
    fc.assert(
      fc.property(
        fc.record({
          success: fc.constant(true),
          data: fc.record({
            // Intentionally omit 'accounts' and 'exchange_rates' keys
            other_key: fc.string(),
          }),
        }),
        (apiResponse) => {
          // Simulate what the buggy code does:
          // renderTable(data.data.accounts ?? data.data)
          // When accounts is undefined, ?? triggers, but falls back to data.data (an object)
          const buggyResult = apiResponse.data.accounts ?? apiResponse.data;
          const correctResult = apiResponse.data.accounts ?? [];

          // The buggy result is an object (not an array)
          expect(Array.isArray(buggyResult)).toBe(false);
          // The correct result is an array
          expect(Array.isArray(correctResult)).toBe(true);

          // Verify the source code uses the correct pattern
          const accountsHasCorrectFallback = /data\.data\.accounts\s*\?\?\s*\[\s*\]/.test(accountsSource);
          const exchangeRatesHasCorrectFallback = /data\.data\.exchange_rates\s*\?\?\s*\[\s*\]/.test(exchangeRatesSource);

          expect(accountsHasCorrectFallback).toBe(true);
          expect(exchangeRatesHasCorrectFallback).toBe(true);
        }
      ),
      { numRuns: 20 }
    );
  });
});
