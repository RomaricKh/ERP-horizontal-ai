/**
 * Converts a foreign-currency amount to base currency.
 *
 * @param {number} amountFc    - Amount in foreign currency.
 * @param {number} exchangeRate - Exchange rate (FC → BC).
 * @returns {number} Amount in base currency, rounded to 2 decimal places.
 */
export function calcBc(amountFc, exchangeRate) {
    return Math.round(amountFc * exchangeRate * 100) / 100;
}

/**
 * Formats a numeric value to a fixed number of decimal places.
 *
 * @param {number|string|null|undefined} value - The value to format.
 * @param {number} [decimals=2] - Number of decimal places.
 * @returns {string} The formatted number string.
 */
export function formatAmount(value, decimals = 2) {
    return parseFloat(value || 0).toFixed(decimals);
}
