/**
 * Formatter Module
 * Pure-function ES module for formatting numbers, dates, currencies, and phone numbers
 * according to active regional settings. No DOM access, no side effects.
 */

// Module-level state, set by configure()
let config = {
  thousands_sep: ',',
  decimal_sep: '.',
  date_format: 'Y-m-d',
  currency_format: 'USD',
  phone_format: ''
};

/**
 * Parse a number format pattern string into thousands and decimal separators.
 * Supported patterns: "1,234.56", "1.234,56", "1 234,56"
 * @param {string} pattern - The number format pattern
 * @returns {{ thousands_sep: string, decimal_sep: string }}
 */
export function parseNumberFormat(pattern) {
  if (!pattern || typeof pattern !== 'string') {
    return { thousands_sep: ',', decimal_sep: '.' };
  }

  // Pattern "1 234,56" — space as thousands, comma as decimal
  if (pattern.includes(' ') && pattern.includes(',')) {
    return { thousands_sep: ' ', decimal_sep: ',' };
  }

  // Pattern "1.234,56" — dot as thousands, comma as decimal
  if (pattern.includes('.') && pattern.includes(',') && pattern.indexOf('.') < pattern.indexOf(',')) {
    return { thousands_sep: '.', decimal_sep: ',' };
  }

  // Pattern "1,234.56" — comma as thousands, dot as decimal
  if (pattern.includes(',') && pattern.includes('.') && pattern.indexOf(',') < pattern.indexOf('.')) {
    return { thousands_sep: ',', decimal_sep: '.' };
  }

  // Default fallback
  return { thousands_sep: ',', decimal_sep: '.' };
}

/**
 * Configure the formatter with regional settings.
 * Parses the number_format pattern and stores all format settings.
 * @param {object} settings - Settings object with number_format, date_format, currency_format, phone_format
 */
export function configure(settings) {
  if (!settings || typeof settings !== 'object') {
    return;
  }

  const parsed = parseNumberFormat(settings.number_format);
  config.thousands_sep = parsed.thousands_sep;
  config.decimal_sep = parsed.decimal_sep;
  config.date_format = settings.date_format || 'Y-m-d';
  config.currency_format = settings.currency_format || 'USD';
  config.phone_format = settings.phone_format || '';
}

/**
 * Format a numeric value using configured thousands and decimal separators.
 * Groups the integer part in thousands and preserves decimal precision.
 * @param {number|string} value - The numeric value to format
 * @param {number} [decimals] - Number of decimal places (if undefined, uses the value's natural precision)
 * @returns {string} The formatted number string
 */
export function formatNumber(value, decimals) {
  if (value === null || value === undefined || value === '') {
    return '';
  }

  const num = typeof value === 'string' ? parseFloat(value) : value;
  if (isNaN(num)) {
    return '';
  }

  // Determine decimal places
  let decimalPlaces;
  if (decimals !== undefined && decimals !== null) {
    decimalPlaces = decimals;
  } else {
    // Use the natural precision of the value
    const strVal = String(value);
    const dotIndex = strVal.indexOf('.');
    decimalPlaces = dotIndex === -1 ? 0 : strVal.length - dotIndex - 1;
  }

  // Format with fixed decimal places
  const fixed = Math.abs(num).toFixed(decimalPlaces);
  const parts = fixed.split('.');
  let integerPart = parts[0];
  const decimalPart = parts.length > 1 ? parts[1] : '';

  // Group integer part in thousands
  const grouped = [];
  let count = 0;
  for (let i = integerPart.length - 1; i >= 0; i--) {
    grouped.unshift(integerPart[i]);
    count++;
    if (count % 3 === 0 && i > 0) {
      grouped.unshift(config.thousands_sep);
    }
  }
  integerPart = grouped.join('');

  // Build result
  let result = integerPart;
  if (decimalPlaces > 0) {
    result += config.decimal_sep + decimalPart;
  }

  // Handle negative numbers
  if (num < 0) {
    result = '-' + result;
  }

  return result;
}

/**
 * Format an ISO date string according to the configured date format pattern.
 * Supported patterns: "d/m/Y", "Y-m-d", "m/d/Y"
 * @param {string} dateStr - ISO date string (e.g., "2025-01-31" or "2025-01-31T10:00:00Z")
 * @returns {string} The formatted date string, or "" for invalid/empty input
 */
export function formatDate(dateStr) {
  if (!dateStr || typeof dateStr !== 'string') {
    return '';
  }

  // Extract date parts from ISO string
  const match = dateStr.match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (!match) {
    return '';
  }

  const year = match[1];
  const month = match[2];
  const day = match[3];

  // Validate date components
  const y = parseInt(year, 10);
  const m = parseInt(month, 10);
  const d = parseInt(day, 10);

  if (m < 1 || m > 12 || d < 1 || d > 31) {
    return '';
  }

  // Apply format pattern
  switch (config.date_format) {
    case 'd/m/Y':
      return `${day}/${month}/${year}`;
    case 'm/d/Y':
      return `${month}/${day}/${year}`;
    case 'Y-m-d':
    default:
      return `${year}-${month}-${day}`;
  }
}

/**
 * Format a numeric value as currency with the configured currency code/symbol.
 * Multi-letter codes (e.g., "USD") are prefixed with a space.
 * Single non-alphanumeric symbols (e.g., "$") are prefixed without a space.
 * @param {number|string} value - The numeric value to format
 * @param {number} [decimals] - Number of decimal places
 * @returns {string} The formatted currency string
 */
export function formatCurrency(value, decimals) {
  const formattedNumber = formatNumber(value, decimals);
  if (formattedNumber === '') {
    return '';
  }

  const currency = config.currency_format;

  // Determine if it's a multi-letter code or a single symbol
  if (currency.length === 1 && !/[a-zA-Z0-9]/.test(currency)) {
    // Single non-alphanumeric character — no space
    return `${currency}${formattedNumber}`;
  } else {
    // Multi-letter code — prefix with space
    return `${currency} ${formattedNumber}`;
  }
}

/**
 * Format a raw digit string using the configured phone format template.
 * Each "#" in the template is replaced by the corresponding digit.
 * @param {string|null} rawDigits - The raw phone number digits
 * @returns {string} The formatted phone number, or "" for null/empty, or raw if fewer digits than placeholders
 */
export function formatPhone(rawDigits) {
  if (rawDigits === null || rawDigits === undefined || rawDigits === '') {
    return '';
  }

  const digits = String(rawDigits);
  const template = config.phone_format;

  if (!template) {
    return digits;
  }

  // Count placeholders
  const placeholderCount = (template.match(/#/g) || []).length;

  // If fewer digits than placeholders, return raw unformatted
  if (digits.length < placeholderCount) {
    return digits;
  }

  // Replace each "#" with the corresponding digit
  let digitIndex = 0;
  let result = '';
  for (let i = 0; i < template.length; i++) {
    if (template[i] === '#') {
      result += digits[digitIndex];
      digitIndex++;
    } else {
      result += template[i];
    }
  }

  // Append any remaining digits
  if (digitIndex < digits.length) {
    result += digits.slice(digitIndex);
  }

  return result;
}
