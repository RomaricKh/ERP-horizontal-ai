/**
 * Unit tests for Formatter edge cases
 * Validates: Requirements 2.2, 2.3, 2.4, 3.2, 3.3, 3.4, 3.5, 5.2, 5.3, 5.4
 */
import { describe, it, expect, beforeEach } from 'vitest';
import { parseNumberFormat, configure, formatDate, formatPhone } from '../../public/assets/js/i18n/formatter.js';

describe('Formatter - parseNumberFormat edge cases', () => {
  it('parses "1.234,56" → thousands=".", decimal=","', () => {
    // Validates: Requirement 2.2
    const result = parseNumberFormat('1.234,56');
    expect(result.thousands_sep).toBe('.');
    expect(result.decimal_sep).toBe(',');
  });

  it('parses "1,234.56" → thousands=",", decimal="."', () => {
    // Validates: Requirement 2.3
    const result = parseNumberFormat('1,234.56');
    expect(result.thousands_sep).toBe(',');
    expect(result.decimal_sep).toBe('.');
  });

  it('parses "1 234,56" → thousands=" ", decimal=","', () => {
    // Validates: Requirement 2.4
    const result = parseNumberFormat('1 234,56');
    expect(result.thousands_sep).toBe(' ');
    expect(result.decimal_sep).toBe(',');
  });
});

describe('Formatter - formatDate edge cases', () => {
  beforeEach(() => {
    // Reset config before each test
    configure({ number_format: '1,234.56', date_format: 'Y-m-d', currency_format: 'USD', phone_format: '' });
  });

  it('formats "2025-01-31" with "d/m/Y" → "31/01/2025"', () => {
    // Validates: Requirement 3.2
    configure({ number_format: '1,234.56', date_format: 'd/m/Y', currency_format: 'USD', phone_format: '' });
    expect(formatDate('2025-01-31')).toBe('31/01/2025');
  });

  it('formats "2025-01-31" with "Y-m-d" → "2025-01-31"', () => {
    // Validates: Requirement 3.3
    configure({ number_format: '1,234.56', date_format: 'Y-m-d', currency_format: 'USD', phone_format: '' });
    expect(formatDate('2025-01-31')).toBe('2025-01-31');
  });

  it('formats "2025-01-31" with "m/d/Y" → "01/31/2025"', () => {
    // Validates: Requirement 3.4
    configure({ number_format: '1,234.56', date_format: 'm/d/Y', currency_format: 'USD', phone_format: '' });
    expect(formatDate('2025-01-31')).toBe('01/31/2025');
  });

  it('returns "" for invalid date string', () => {
    // Validates: Requirement 3.5
    expect(formatDate('not-a-date')).toBe('');
  });

  it('returns "" for empty string', () => {
    // Validates: Requirement 3.5
    expect(formatDate('')).toBe('');
  });

  it('returns "" for null', () => {
    // Validates: Requirement 3.5
    expect(formatDate(null)).toBe('');
  });

  it('returns "" for undefined', () => {
    // Validates: Requirement 3.5
    expect(formatDate(undefined)).toBe('');
  });
});

describe('Formatter - formatPhone edge cases', () => {
  beforeEach(() => {
    configure({ number_format: '1,234.56', date_format: 'Y-m-d', currency_format: 'USD', phone_format: '+1 (###) ###-####' });
  });

  it('returns "" for null phone', () => {
    // Validates: Requirement 5.4
    expect(formatPhone(null)).toBe('');
  });

  it('returns "" for empty string phone', () => {
    // Validates: Requirement 5.4
    expect(formatPhone('')).toBe('');
  });

  it('returns "" for undefined phone', () => {
    // Validates: Requirement 5.4
    expect(formatPhone(undefined)).toBe('');
  });

  it('returns raw string when fewer digits than placeholders', () => {
    // Validates: Requirement 5.2
    // Template "+1 (###) ###-####" has 10 placeholders
    expect(formatPhone('12345')).toBe('12345');
  });

  it('returns raw string for single digit with multi-placeholder template', () => {
    // Validates: Requirement 5.2
    expect(formatPhone('7')).toBe('7');
  });

  it('appends extra digits when more digits than placeholders', () => {
    // Validates: Requirement 5.3
    // Template "+1 (###) ###-####" has 10 placeholders, providing 13 digits
    const result = formatPhone('1234567890123');
    expect(result).toBe('+1 (123) 456-7890123');
  });

  it('appends single extra digit when one more than placeholders', () => {
    // Validates: Requirement 5.3
    // Template has 10 placeholders, providing 11 digits
    const result = formatPhone('12345678901');
    expect(result).toBe('+1 (123) 456-78901');
  });
});
