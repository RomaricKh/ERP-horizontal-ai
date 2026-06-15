import { apiFetch } from '../Auth/apiFetch.js';

/**
 * Fetch the latest exchange rate for the given currency and populate the rate input.
 * Dispatches an 'input' event on the rate input so existing recalcBc listeners fire.
 *
 * @param {string} currency       - The selected currency code.
 * @param {HTMLInputElement} rateInput - The exchange rate input element.
 * @param {HTMLElement|null} warningEl - Optional element to show "no rate found" warning.
 * @returns {Promise<void>}
 */
export async function fetchAndApplyRate(currency, rateInput, warningEl = null) {
    if (!currency) {
        rateInput.value = '';
        if (warningEl) warningEl.style.display = 'none';
        return;
    }

    try {
        const res = await apiFetch(`/api/exchange-rates/latest?currency=${encodeURIComponent(currency)}`);
        const json = await res.json();
        const rate = json.data?.rate ?? null;

        if (rate !== null) {
            rateInput.value = rate;
            rateInput.dispatchEvent(new Event('input', { bubbles: true }));
            if (warningEl) warningEl.style.display = 'none';
        } else {
            rateInput.value = '';
            if (warningEl) {
                warningEl.textContent = `No exchange rate found for ${currency}`;
                warningEl.style.display = '';
            }
        }
    } catch (err) {
        console.error('Failed to fetch exchange rate:', err);
    }
}

/**
 * Attach a 'change' event listener to a currency select/input that auto-fetches
 * the exchange rate on currency change.
 *
 * @param {HTMLElement} currencyEl - The currency select/input element.
 * @param {HTMLInputElement} rateInput - The exchange rate input element.
 * @param {HTMLElement|null} warningEl - Optional warning element.
 * @returns {Function} Cleanup function to remove the listener.
 */
export function attachRateAutoFetch(currencyEl, rateInput, warningEl = null) {
    const handler = () => {
        fetchAndApplyRate(currencyEl.value, rateInput, warningEl);
    };

    currencyEl.addEventListener('change', handler);

    return () => {
        currencyEl.removeEventListener('change', handler);
    };
}
