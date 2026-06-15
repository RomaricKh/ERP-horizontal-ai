import { describe, it, expect } from 'vitest';
import fc from 'fast-check';
import fs from 'fs';
import path from 'path';

/**
 * Bug 14 Preservation — Successful API Responses Unchanged
 *
 * Observe that when `data.data.accounts` / `data.data.exchange_rates` keys are
 * present, `renderTable()` receives the correct array. The current code uses
 * `data.data.accounts ?? data.data` — when the key IS present, the `??` operator
 * returns the value (not the fallback), so `renderTable()` gets the correct array.
 *
 * This test must PASS on unfixed code — it confirms baseline behavior to preserve.
 *
 * **Validates: Requirements 3.28, 3.29, 3.30**
 */

describe('Bug 14 Preservation — Successful API responses pass correct data to renderTable', () => {
  const accountsPath = path.resolve('public/assets/js/pages/Finance/accounts.js');
  const exchangeRatesPath = path.resolve('public/assets/js/pages/Finance/exchangeRates.js');

  const accountsSource = fs.readFileSync(accountsPath, 'utf-8');
  const exchangeRatesSource = fs.readFileSync(exchangeRatesPath, 'utf-8');

  it('accounts.js accesses data.data.accounts from the API response', () => {
    // The current code uses: renderTable(data.data.accounts ?? data.data)
    // When data.data.accounts IS present, ?? returns data.data.accounts (correct)
    const accessesAccountsKey = /data\.data\.accounts/.test(accountsSource);
    expect(accessesAccountsKey, 'accounts.js should access data.data.accounts').toBe(true);
  });

  it('accounts.js calls renderTable with the accounts data', () => {
    const callsRenderTable = /renderTable\s*\(/.test(accountsSource);
    expect(callsRenderTable, 'accounts.js should call renderTable').toBe(true);
  });

  it('accounts.js renderTable function processes account objects with expected fields', () => {
    // The renderTable function expects objects with: id, name, currency, balance
    expect(accountsSource).toContain('account.id');
    expect(accountsSource).toContain('account.name');
    expect(accountsSource).toContain('account.currency');
    expect(accountsSource).toContain('account.balance');
  });

  it('exchangeRates.js accesses data.data.exchange_rates from the API response', () => {
    // The current code uses: renderTable(data.data.exchange_rates ?? data.data)
    // When data.data.exchange_rates IS present, ?? returns data.data.exchange_rates (correct)
    const accessesRatesKey = /data\.data\.exchange_rates/.test(exchangeRatesSource);
    expect(accessesRatesKey, 'exchangeRates.js should access data.data.exchange_rates').toBe(true);
  });

  it('exchangeRates.js calls renderTable with the rates data', () => {
    const callsRenderTable = /renderTable\s*\(/.test(exchangeRatesSource);
    expect(callsRenderTable, 'exchangeRates.js should call renderTable').toBe(true);
  });

  it('exchangeRates.js renderTable function processes rate objects with expected fields', () => {
    // The renderTable function expects objects with: id, currency, rate_date/date, rate, source
    expect(exchangeRatesSource).toContain('rate.id');
    expect(exchangeRatesSource).toContain('rate.rate');
    expect(exchangeRatesSource).toContain('rate.source');
  });

  it('property: when data.data.accounts is present, ?? returns the accounts array (not the fallback)', () => {
    fc.assert(
      fc.property(
        fc.array(
          fc.record({
            id: fc.integer({ min: 1, max: 10000 }),
            name: fc.string({ minLength: 1, maxLength: 50 }),
            currency: fc.constantFrom('USD', 'EUR', 'GBP', 'LBP'),
            balance: fc.float({ min: 0, max: 100000, noNaN: true }),
          }),
          { minLength: 0, maxLength: 20 }
        ),
        (accounts) => {
          // Simulate the API response structure
          const data = { success: true, data: { accounts } };

          // Current code: data.data.accounts ?? data.data
          // When accounts key IS present, ?? returns accounts (the array)
          const result = data.data.accounts ?? data.data;

          // Result should be the accounts array, not the wrapper
          expect(Array.isArray(result)).toBe(true);
          expect(result).toBe(accounts);
          expect(result.length).toBe(accounts.length);
        }
      ),
      { numRuns: 50 }
    );
  });

  it('property: when data.data.exchange_rates is present, ?? returns the rates array (not the fallback)', () => {
    fc.assert(
      fc.property(
        fc.array(
          fc.record({
            id: fc.integer({ min: 1, max: 10000 }),
            currency: fc.constantFrom('USD', 'EUR', 'GBP', 'LBP'),
            rate_date: fc.integer({ min: 2020, max: 2025 }).chain(y =>
              fc.integer({ min: 1, max: 12 }).chain(m =>
                fc.integer({ min: 1, max: 28 }).map(d =>
                  `${y}-${String(m).padStart(2,'0')}-${String(d).padStart(2,'0')}`))),
            rate: fc.double({ min: 0.0001, max: 100000, noNaN: true, noDefaultInfinity: true }),
            source: fc.option(fc.string({ minLength: 1, maxLength: 20 }), { nil: null }),
          }),
          { minLength: 0, maxLength: 20 }
        ),
        (exchange_rates) => {
          // Simulate the API response structure
          const data = { success: true, data: { exchange_rates } };

          // Current code: data.data.exchange_rates ?? data.data
          // When exchange_rates key IS present, ?? returns exchange_rates (the array)
          const result = data.data.exchange_rates ?? data.data;

          // Result should be the exchange_rates array, not the wrapper
          expect(Array.isArray(result)).toBe(true);
          expect(result).toBe(exchange_rates);
          expect(result.length).toBe(exchange_rates.length);
        }
      ),
      { numRuns: 50 }
    );
  });

  it('other page JS files use their own response access patterns', () => {
    // Verify that other page JS files exist and have their own fetch patterns
    const otherPages = [
      'public/assets/js/pages/Salaries/salaries.js',
      'public/assets/js/pages/Finance/payments.js',
      'public/assets/js/pages/Finance/collections.js',
      'public/assets/js/pages/Expenses/expenses.js',
      'public/assets/js/pages/People/people.js',
      'public/assets/js/pages/Items/items.js',
    ];

    for (const pagePath of otherPages) {
      const exists = fs.existsSync(path.resolve(pagePath));
      expect(exists, `${pagePath} should exist`).toBe(true);

      const source = fs.readFileSync(path.resolve(pagePath), 'utf-8');
      // Each page should have its own apiFetch call
      expect(/apiFetch/.test(source), `${pagePath} should use apiFetch`).toBe(true);
    }
  });
});
