/**
 * Property 7: DOM translation application
 *
 * For any DOM tree containing elements with `data-i18n` attributes and any
 * translation dictionary: after applying translations, every element whose
 * `data-i18n` key exists in the dictionary SHALL have its `textContent` set to
 * the corresponding value, every element with a `data-i18n-placeholder` key
 * that exists in the dictionary SHALL have its `placeholder` attribute set to
 * the corresponding value, and every element whose key does NOT exist in the
 * dictionary SHALL retain its original text unchanged.
 *
 * Validates: Requirements 7.2, 7.3, 7.4, 7.5
 *
 * Feature: active-regional-settings, Property 7: DOM translation application
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
import { init, applyTranslations } from '../../public/assets/js/i18n/translationClient.js';

/**
 * Arbitrary that generates a translation key (dot-separated identifiers).
 */
const translationKeyArb = fc.array(
  fc.stringMatching(/^[a-z][a-z0-9_]{0,10}$/),
  { minLength: 1, maxLength: 4 }
).map(parts => parts.join('.'));

/**
 * Arbitrary that generates a translation value (non-empty printable string).
 */
const translationValueArb = fc.string({ minLength: 1, maxLength: 50 });

/**
 * Arbitrary that generates a translation dictionary (key-value map).
 */
const dictionaryArb = fc.array(
  fc.tuple(translationKeyArb, translationValueArb),
  { minLength: 1, maxLength: 15 }
).map(pairs => Object.fromEntries(pairs));

/**
 * Arbitrary for an element tag name (common HTML elements).
 */
const tagArb = fc.constantFrom('div', 'span', 'p', 'h1', 'h2', 'label', 'button', 'a', 'li');

/**
 * Arbitrary for an input-like element tag name (elements that support placeholder).
 */
const inputTagArb = fc.constantFrom('input', 'textarea');

/**
 * Generates a DOM element spec for a data-i18n text element.
 */
const textElementArb = fc.record({
  type: fc.constant('text'),
  tag: tagArb,
  key: translationKeyArb,
  originalText: fc.string({ minLength: 1, maxLength: 30 })
});

/**
 * Generates a DOM element spec for a data-i18n-placeholder element.
 */
const placeholderElementArb = fc.record({
  type: fc.constant('placeholder'),
  tag: inputTagArb,
  key: translationKeyArb,
  originalPlaceholder: fc.string({ minLength: 1, maxLength: 30 })
});

/**
 * Generates a mixed array of text and placeholder element specs.
 */
const domElementsArb = fc.array(
  fc.oneof(textElementArb, placeholderElementArb),
  { minLength: 1, maxLength: 20 }
);

describe('Feature: active-regional-settings, Property 7: DOM translation application', () => {
  let dom;

  beforeEach(() => {
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

  /**
   * Helper to build a DOM container from element specs.
   * Returns the container and a map of original values for verification.
   */
  function buildDOM(elements) {
    const container = document.createElement('div');
    const originals = [];

    for (const spec of elements) {
      const el = document.createElement(spec.tag);
      if (spec.type === 'text') {
        el.setAttribute('data-i18n', spec.key);
        el.textContent = spec.originalText;
        originals.push({ el, type: 'text', key: spec.key, original: spec.originalText });
      } else {
        el.setAttribute('data-i18n-placeholder', spec.key);
        el.setAttribute('placeholder', spec.originalPlaceholder);
        originals.push({ el, type: 'placeholder', key: spec.key, original: spec.originalPlaceholder });
      }
      container.appendChild(el);
    }

    return { container, originals };
  }

  it('elements with data-i18n keys in dictionary have textContent set to translated value', async () => {
    await fc.assert(
      fc.asyncProperty(
        dictionaryArb,
        domElementsArb,
        async (dictionary, elements) => {
          setupMocks(dictionary);
          await init();

          const { container, originals } = buildDOM(elements);
          applyTranslations(container);

          for (const item of originals) {
            if (item.type === 'text' && dictionary.hasOwnProperty(item.key)) {
              expect(item.el.textContent).toBe(dictionary[item.key]);
            }
          }
        }
      ),
      { numRuns: 100 }
    );
  });

  it('elements with data-i18n-placeholder keys in dictionary have placeholder set to translated value', async () => {
    await fc.assert(
      fc.asyncProperty(
        dictionaryArb,
        domElementsArb,
        async (dictionary, elements) => {
          setupMocks(dictionary);
          await init();

          const { container, originals } = buildDOM(elements);
          applyTranslations(container);

          for (const item of originals) {
            if (item.type === 'placeholder' && dictionary.hasOwnProperty(item.key)) {
              expect(item.el.getAttribute('placeholder')).toBe(dictionary[item.key]);
            }
          }
        }
      ),
      { numRuns: 100 }
    );
  });

  it('elements whose keys do NOT exist in dictionary retain original text unchanged', async () => {
    await fc.assert(
      fc.asyncProperty(
        dictionaryArb,
        domElementsArb,
        async (dictionary, elements) => {
          setupMocks(dictionary);
          await init();

          const { container, originals } = buildDOM(elements);
          applyTranslations(container);

          for (const item of originals) {
            if (item.type === 'text' && !dictionary.hasOwnProperty(item.key)) {
              expect(item.el.textContent).toBe(item.original);
            }
            if (item.type === 'placeholder' && !dictionary.hasOwnProperty(item.key)) {
              expect(item.el.getAttribute('placeholder')).toBe(item.original);
            }
          }
        }
      ),
      { numRuns: 100 }
    );
  });
});
