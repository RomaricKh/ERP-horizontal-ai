/**
 * Property 6: Translation lookup returns value or key
 *
 * For any translation dictionary and any key string, calling `t(key)` SHALL
 * return the dictionary's value for that key if the key exists in the dictionary,
 * or return the key itself if it does not exist.
 *
 * Validates: Requirements 6.5
 *
 * Feature: active-regional-settings, Property 6: Translation lookup returns value or key
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import * as fc from 'fast-check';
import { JSDOM } from 'jsdom';

// Mock apiFetch before importing translationClient
vi.mock('../../public/assets/js/Auth/apiFetch.js', () => ({
  apiFetch: vi.fn()
}));

// Mock formatter module
vi.mock('../../public/assets/js/i18n/formatter.js', () => ({
  configure: vi.fn(),
  formatNumber: vi.fn(),
  formatDate: vi.fn(),
  formatCurrency: vi.fn(),
  formatPhone: vi.fn()
}));

import { apiFetch } from '../../public/assets/js/Auth/apiFetch.js';
import { init, t } from '../../public/assets/js/i18n/translationClient.js';

/**
 * Arbitrary that generates a translation key (dot-separated identifiers).
 */
const translationKeyArb = fc.array(
  fc.stringMatching(/^[a-z][a-z0-9_]{0,10}$/),
  { minLength: 1, maxLength: 4 }
).map(parts => parts.join('.'));

/**
 * Arbitrary that generates a translation value (non-empty string).
 */
const translationValueArb = fc.string({ minLength: 1, maxLength: 50 });

/**
 * Arbitrary that generates a translation dictionary (key-value map).
 */
const dictionaryArb = fc.array(
  fc.tuple(translationKeyArb, translationValueArb),
  { minLength: 1, maxLength: 20 }
).map(pairs => Object.fromEntries(pairs));

describe('Feature: active-regional-settings, Property 6: Translation lookup returns value or key', () => {
  let dom;

  beforeEach(() => {
    // Set up a minimal DOM environment
    dom = new JSDOM('<!DOCTYPE html><html><body></body></html>');
    global.document = dom.window.document;
    global.window = dom.window;
  });

  afterEach(() => {
    vi.restoreAllMocks();
    delete global.document;
    delete global.window;
  });

  /**
   * Helper to set up apiFetch mock to return a given dictionary.
   */
  function setupMocks(dictionary) {
    apiFetch.mockImplementation((url) => {
      if (url === '/api/settings') {
        return Promise.resolve({
          ok: true,
          json: () => Promise.resolve({
            success: true,
            data: {
              language: 'en',
              direction: 'LTR',
              number_format: '1,234.56',
              date_format: 'Y-m-d',
              currency_format: 'USD',
              phone_format: '+1 (###) ###-####'
            }
          })
        });
      }
      if (url.startsWith('/api/translations/')) {
        return Promise.resolve({
          ok: true,
          json: () => Promise.resolve({
            success: true,
            data: dictionary
          })
        });
      }
      return Promise.resolve({
        ok: false,
        json: () => Promise.resolve({ success: false })
      });
    });
  }

  it('t(key) returns the dictionary value when the key exists', async () => {
    await fc.assert(
      fc.asyncProperty(
        dictionaryArb,
        async (dictionary) => {
          setupMocks(dictionary);
          await init();

          // For every key that exists in the dictionary, t(key) should return its value
          const keys = Object.keys(dictionary);
          for (const key of keys) {
            expect(t(key)).toBe(dictionary[key]);
          }
        }
      ),
      { numRuns: 100 }
    );
  });

  it('t(key) returns the key itself when the key does not exist in the dictionary', async () => {
    await fc.assert(
      fc.asyncProperty(
        dictionaryArb,
        translationKeyArb,
        async (dictionary, randomKey) => {
          // Ensure the random key is NOT in the dictionary
          fc.pre(!(randomKey in dictionary));

          setupMocks(dictionary);
          await init();

          // t(key) should return the key itself when not found
          expect(t(randomKey)).toBe(randomKey);
        }
      ),
      { numRuns: 100 }
    );
  });
});
