/**
 * Unit tests for exchangeRateHelper.js
 * Validates: Requirements 1.1, 1.2, 1.3, 1.4
 *
 * @vitest-environment jsdom
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mock apiFetch
vi.mock('../../public/assets/js/Auth/apiFetch.js', () => ({
  apiFetch: vi.fn(),
}));

import { apiFetch } from '../../public/assets/js/Auth/apiFetch.js';
import { fetchAndApplyRate, attachRateAutoFetch } from '../../public/assets/js/utils/exchangeRateHelper.js';

describe('exchangeRateHelper', () => {
  let rateInput;
  let warningEl;

  beforeEach(() => {
    document.body.innerHTML = '';

    // Create a rate input element
    rateInput = document.createElement('input');
    rateInput.type = 'text';
    rateInput.value = '';
    document.body.appendChild(rateInput);

    // Create a warning element
    warningEl = document.createElement('span');
    warningEl.style.display = 'none';
    document.body.appendChild(warningEl);

    vi.clearAllMocks();
  });

  describe('fetchAndApplyRate', () => {
    it('with empty currency clears the field and returns early', async () => {
      rateInput.value = '1.5';
      warningEl.style.display = '';

      await fetchAndApplyRate('', rateInput, warningEl);

      expect(rateInput.value).toBe('');
      expect(warningEl.style.display).toBe('none');
      expect(apiFetch).not.toHaveBeenCalled();
    });

    it('with a valid currency sets the field value and dispatches input event', async () => {
      const mockResponse = {
        json: vi.fn().mockResolvedValue({
          success: true,
          data: { rate: 1.08, rate_date: '2025-01-15', is_base: false },
        }),
      };
      apiFetch.mockResolvedValue(mockResponse);

      const inputHandler = vi.fn();
      rateInput.addEventListener('input', inputHandler);

      await fetchAndApplyRate('EUR', rateInput, warningEl);

      expect(apiFetch).toHaveBeenCalledWith('/api/exchange-rates/latest?currency=EUR');
      expect(rateInput.value).toBe('1.08');
      expect(inputHandler).toHaveBeenCalled();
      expect(warningEl.style.display).toBe('none');
    });

    it('with a currency that has no rate clears the field and shows warning', async () => {
      const mockResponse = {
        json: vi.fn().mockResolvedValue({
          success: true,
          data: { rate: null, rate_date: null, is_base: false },
        }),
      };
      apiFetch.mockResolvedValue(mockResponse);

      rateInput.value = '2.0';

      await fetchAndApplyRate('XYZ', rateInput, warningEl);

      expect(rateInput.value).toBe('');
      expect(warningEl.textContent).toBe('No exchange rate found for XYZ');
      expect(warningEl.style.display).toBe('');
    });

    it('with base currency sets field to 1', async () => {
      const mockResponse = {
        json: vi.fn().mockResolvedValue({
          success: true,
          data: { rate: 1, rate_date: null, is_base: true },
        }),
      };
      apiFetch.mockResolvedValue(mockResponse);

      const inputHandler = vi.fn();
      rateInput.addEventListener('input', inputHandler);

      await fetchAndApplyRate('USD', rateInput, warningEl);

      expect(rateInput.value).toBe('1');
      expect(inputHandler).toHaveBeenCalled();
      expect(warningEl.style.display).toBe('none');
    });
  });

  describe('attachRateAutoFetch', () => {
    it('returns a cleanup function that removes the listener', () => {
      // Use an input element so .value can be set freely
      const currencyEl = document.createElement('input');
      currencyEl.type = 'text';
      document.body.appendChild(currencyEl);

      const mockResponse = {
        json: vi.fn().mockResolvedValue({
          success: true,
          data: { rate: 1.5, rate_date: '2025-01-10', is_base: false },
        }),
      };
      apiFetch.mockResolvedValue(mockResponse);

      const cleanup = attachRateAutoFetch(currencyEl, rateInput, warningEl);

      expect(typeof cleanup).toBe('function');

      // Set value before triggering change event
      currencyEl.value = 'GBP';
      currencyEl.dispatchEvent(new Event('change'));
      expect(apiFetch).toHaveBeenCalledTimes(1);

      // Call cleanup
      cleanup();

      // Reset mock
      apiFetch.mockClear();

      // Trigger change event again - should NOT call apiFetch since listener was removed
      currencyEl.dispatchEvent(new Event('change'));
      expect(apiFetch).not.toHaveBeenCalled();
    });
  });
});
