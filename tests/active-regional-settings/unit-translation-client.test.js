/**
 * Unit Tests for TranslationClient
 *
 * Tests:
 * - API error → default settings applied (Requirement 1.4)
 * - Translation API error → fallback to English / key returned (Requirement 6.4)
 * - Direction "RTL" → html dir="rtl", body class "rtl" (Requirements 8.2, 8.4)
 * - Direction "LTR" → html dir="ltr", no "rtl" class (Requirements 8.3, 8.4)
 *
 * Feature: active-regional-settings
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
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
import { configure } from '../../public/assets/js/i18n/formatter.js';
import { init, t } from '../../public/assets/js/i18n/translationClient.js';

describe('TranslationClient Unit Tests', () => {
  let dom;

  beforeEach(() => {
    dom = new JSDOM('<!DOCTYPE html><html><body></body></html>');
    global.document = dom.window.document;
    global.window = dom.window;
    vi.clearAllMocks();
  });

  afterEach(() => {
    vi.restoreAllMocks();
    delete global.document;
    delete global.window;
  });

  describe('Requirement 1.4: API error → default settings applied', () => {
    it('should configure formatter with default settings when /api/settings returns an error', async () => {
      // Mock settings API to throw a network error
      apiFetch.mockImplementation((url) => {
        if (url === '/api/settings') {
          return Promise.reject(new Error('Network error'));
        }
        if (url.startsWith('/api/translations/')) {
          return Promise.resolve({
            ok: true,
            json: () => Promise.resolve({ success: true, data: {} })
          });
        }
        return Promise.reject(new Error('Unknown URL'));
      });

      await init();

      // Formatter.configure should have been called with the default settings
      expect(configure).toHaveBeenCalled();
      const configArg = configure.mock.calls[configure.mock.calls.length - 1][0];
      expect(configArg.number_format).toBe('1,234.56');
      expect(configArg.date_format).toBe('Y-m-d');
      expect(configArg.currency_format).toBe('USD');
      expect(configArg.phone_format).toBe('+1 (###) ###-####');
    });

    it('should configure formatter with default settings when /api/settings returns non-ok response', async () => {
      apiFetch.mockImplementation((url) => {
        if (url === '/api/settings') {
          return Promise.resolve({
            ok: false,
            json: () => Promise.resolve({ success: false, data: null })
          });
        }
        if (url.startsWith('/api/translations/')) {
          return Promise.resolve({
            ok: true,
            json: () => Promise.resolve({ success: true, data: {} })
          });
        }
        return Promise.reject(new Error('Unknown URL'));
      });

      await init();

      expect(configure).toHaveBeenCalled();
      const configArg = configure.mock.calls[configure.mock.calls.length - 1][0];
      expect(configArg.number_format).toBe('1,234.56');
      expect(configArg.date_format).toBe('Y-m-d');
      expect(configArg.currency_format).toBe('USD');
      expect(configArg.phone_format).toBe('+1 (###) ###-####');
    });
  });

  describe('Requirement 6.4: Translation API error → fallback to English (key returned)', () => {
    it('should return the key itself when translation API throws an error', async () => {
      apiFetch.mockImplementation((url) => {
        if (url === '/api/settings') {
          return Promise.resolve({
            ok: true,
            json: () => Promise.resolve({
              success: true,
              data: {
                language: 'ar',
                direction: 'RTL',
                number_format: '1,234.56',
                date_format: 'Y-m-d',
                currency_format: 'USD',
                phone_format: '+1 (###) ###-####'
              }
            })
          });
        }
        if (url.startsWith('/api/translations/')) {
          return Promise.reject(new Error('Translation API error'));
        }
        return Promise.reject(new Error('Unknown URL'));
      });

      await init();

      // t(key) should return the key itself since dictionary is empty
      expect(t('dashboard.title')).toBe('dashboard.title');
      expect(t('settings.save')).toBe('settings.save');
      expect(t('any.random.key')).toBe('any.random.key');
    });

    it('should return the key itself when translation API returns non-ok response', async () => {
      apiFetch.mockImplementation((url) => {
        if (url === '/api/settings') {
          return Promise.resolve({
            ok: true,
            json: () => Promise.resolve({
              success: true,
              data: {
                language: 'xx',
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
            ok: false,
            json: () => Promise.resolve({ success: false, data: null, message: 'Language not found' })
          });
        }
        return Promise.reject(new Error('Unknown URL'));
      });

      await init();

      expect(t('common.cancel')).toBe('common.cancel');
      expect(t('nav.home')).toBe('nav.home');
    });
  });

  describe('Requirements 8.2, 8.4: Direction "RTL" → html dir="rtl", body class "rtl"', () => {
    it('should set html dir="rtl" and add "rtl" class to body when direction is RTL', async () => {
      apiFetch.mockImplementation((url) => {
        if (url === '/api/settings') {
          return Promise.resolve({
            ok: true,
            json: () => Promise.resolve({
              success: true,
              data: {
                language: 'ar',
                direction: 'RTL',
                number_format: '1.234,56',
                date_format: 'd/m/Y',
                currency_format: 'USD',
                phone_format: '+961 ## ### ###'
              }
            })
          });
        }
        if (url.startsWith('/api/translations/')) {
          return Promise.resolve({
            ok: true,
            json: () => Promise.resolve({ success: true, data: {} })
          });
        }
        return Promise.reject(new Error('Unknown URL'));
      });

      await init();

      const htmlEl = document.documentElement;
      const bodyEl = document.body;

      expect(htmlEl.getAttribute('dir')).toBe('rtl');
      expect(bodyEl.classList.contains('rtl')).toBe(true);
    });
  });

  describe('Requirements 8.3, 8.4: Direction "LTR" → html dir="ltr", no "rtl" class', () => {
    it('should set html dir="ltr" and remove "rtl" class from body when direction is LTR', async () => {
      // First set RTL to ensure the class is present, then switch to LTR
      apiFetch.mockImplementation((url) => {
        if (url === '/api/settings') {
          return Promise.resolve({
            ok: true,
            json: () => Promise.resolve({
              success: true,
              data: {
                language: 'ar',
                direction: 'RTL',
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
            json: () => Promise.resolve({ success: true, data: {} })
          });
        }
        return Promise.reject(new Error('Unknown URL'));
      });

      // Init with RTL first
      await init();
      expect(document.documentElement.getAttribute('dir')).toBe('rtl');
      expect(document.body.classList.contains('rtl')).toBe(true);

      // Now switch to LTR
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
            json: () => Promise.resolve({ success: true, data: {} })
          });
        }
        return Promise.reject(new Error('Unknown URL'));
      });

      await init();

      const htmlEl = document.documentElement;
      const bodyEl = document.body;

      expect(htmlEl.getAttribute('dir')).toBe('ltr');
      expect(bodyEl.classList.contains('rtl')).toBe(false);
    });

    it('should set html dir="ltr" and no "rtl" class when direction is LTR from the start', async () => {
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
            json: () => Promise.resolve({ success: true, data: {} })
          });
        }
        return Promise.reject(new Error('Unknown URL'));
      });

      await init();

      const htmlEl = document.documentElement;
      const bodyEl = document.body;

      expect(htmlEl.getAttribute('dir')).toBe('ltr');
      expect(bodyEl.classList.contains('rtl')).toBe(false);
    });
  });
});
