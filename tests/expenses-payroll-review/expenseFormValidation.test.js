/**
 * Property 9: Frontend Expense Form Numeric Validation
 *
 * **Validates: Requirements 16.1, 16.2**
 *
 * For any value in the expense form's amount_fc field that is ≤ 0 or non-numeric,
 * and for any exchange_rate value that is ≤ 0 or non-numeric, validateForm() SHALL
 * return false and mark the field as invalid.
 *
 * Uses Vitest + fast-check + jsdom to generate random invalid form states and verify
 * validation behavior.
 *
 * Tag: Feature: expenses-payroll-review, Property 9: Frontend expense form numeric validation
 */
import { describe, it, expect, beforeEach } from 'vitest';
import { JSDOM } from 'jsdom';
import fc from 'fast-check';

// =============================================================================
// EXTRACTED VALIDATION LOGIC (from expenseForm.modal.js)
// =============================================================================

/**
 * Recreates the validateForm() logic from expenseForm.modal.js.
 * Operates on a jsdom document with the modal's DOM structure.
 *
 * Returns true if the form is valid, false otherwise.
 * Also applies .is-invalid and .invalid-feedback to the DOM.
 */
function validateForm(document, modalRoot) {
    // Clear previous validation
    modalRoot.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
    modalRoot.querySelectorAll('.invalid-feedback').forEach(el => el.remove());

    let valid = true;

    const dateEl = document.getElementById('inputExpenseDate');
    const currencyEl = document.getElementById('inputExpenseCurrency');
    const exchangeRateEl = document.getElementById('inputExpenseExchangeRate');
    const amountFcEl = document.getElementById('inputExpenseAmountFc');

    // Validate expense_date: required and must be a valid date
    const dateValue = dateEl.value;
    if (!dateValue) {
        markInvalid(document, dateEl, 'Expense date is required');
        valid = false;
    } else if (isNaN(new Date(dateValue).getTime())) {
        markInvalid(document, dateEl, 'Expense date must be a valid date');
        valid = false;
    }

    // Validate currency: required
    if (!currencyEl.value) {
        markInvalid(document, currencyEl, 'Currency is required');
        valid = false;
    }

    // Validate exchange_rate: must be a positive number
    const rateValue = exchangeRateEl.value;
    if (!rateValue) {
        markInvalid(document, exchangeRateEl, 'Exchange rate is required');
        valid = false;
    } else if (isNaN(parseFloat(rateValue)) || parseFloat(rateValue) <= 0) {
        markInvalid(document, exchangeRateEl, 'Exchange rate must be a positive number');
        valid = false;
    }

    // Validate amount_fc: must be a positive number
    const amountValue = amountFcEl.value;
    if (!amountValue) {
        markInvalid(document, amountFcEl, 'Amount is required');
        valid = false;
    } else if (isNaN(parseFloat(amountValue)) || parseFloat(amountValue) <= 0) {
        markInvalid(document, amountFcEl, 'Amount must be a positive number');
        valid = false;
    }

    return valid;
}

function markInvalid(document, input, message) {
    if (!input) return;
    input.classList.add('is-invalid');
    let fb = input.parentElement.querySelector('.invalid-feedback');
    if (!fb) {
        fb = document.createElement('div');
        fb.className = 'invalid-feedback';
        input.parentElement.appendChild(fb);
    }
    fb.textContent = message;
}

// =============================================================================
// MINIMAL DOM STRUCTURE
// =============================================================================

const MODAL_HTML = `
<div id="modal-root">
    <div><input id="inputExpenseDate" type="text" value="2024-01-15" /></div>
    <div><input id="inputExpenseCurrency" type="text" value="USD" /></div>
    <div><input id="inputExpenseExchangeRate" type="text" value="1.0" /></div>
    <div><input id="inputExpenseAmountFc" type="text" value="100" /></div>
</div>
`;

// =============================================================================
// ARBITRARIES (generators)
// =============================================================================

/**
 * The validateForm() uses parseFloat() for numeric checks:
 * - Empty string → falsy → "required" rejection
 * - parseFloat(x) is NaN → "must be a positive number" rejection
 * - parseFloat(x) <= 0 → "must be a positive number" rejection
 *
 * Note: parseFloat is lenient — "1.2.3" → 1.2, "1,000" → 1.
 * Our generators must produce values that actually fail these checks.
 */

// Non-positive numbers: zero and negative values (parseFloat returns <= 0)
const nonPositiveNumberArb = fc.oneof(
    fc.constant('0'),
    fc.constant('0.0'),
    fc.constant('0.00'),
    fc.constant('-0'),
    fc.double({ min: -1e9, max: -0.001, noNaN: true }).map(n => String(n)),
    fc.integer({ min: -1000000, max: -1 }).map(n => String(n))
);

// Non-numeric strings where parseFloat returns NaN
// These are strings that do NOT start with a digit, sign, or decimal point
// leading to a parseable number
const nonNumericArb = fc.oneof(
    fc.constant('abc'),
    fc.constant('twelve'),
    fc.constant('hello'),
    fc.constant('$100'),
    fc.constant('undefined'),
    fc.constant('null'),
    fc.constant('NaN'),
    fc.constant(' '),
    fc.constant('   '),
    fc.stringMatching(/^[a-zA-Z]{1,10}$/)
);

// Empty string (falsy — triggers the "required" validation path)
const emptyArb = fc.constant('');

// Combined: any invalid amount_fc value (non-positive OR non-numeric OR empty)
const invalidAmountArb = fc.oneof(
    nonPositiveNumberArb,
    nonNumericArb,
    emptyArb
);

// Combined: any invalid exchange_rate value (non-positive OR non-numeric OR empty)
const invalidExchangeRateArb = fc.oneof(
    nonPositiveNumberArb,
    nonNumericArb,
    emptyArb
);

// Valid positive numbers for the "positive case" tests
const validPositiveNumberArb = fc.double({ min: 0.01, max: 1e9, noNaN: true })
    .filter(n => n > 0)
    .map(n => String(n));

// =============================================================================
// PROPERTY TESTS
// =============================================================================

describe('Property 9: Frontend expense form numeric validation', () => {

    // --- amount_fc: non-positive / non-numeric values ---
    describe('amount_fc: non-positive or non-numeric values cause validation failure', () => {
        let dom, document, modalRoot;

        beforeEach(() => {
            dom = new JSDOM(MODAL_HTML);
            document = dom.window.document;
            modalRoot = document.getElementById('modal-root');
        });

        it('rejects any non-positive or non-numeric amount_fc value', () => {
            /**
             * Validates: Requirements 16.1
             */
            fc.assert(
                fc.property(invalidAmountArb, (amountValue) => {
                    // Set up DOM: valid date, currency, exchange_rate — only amount is invalid
                    document.getElementById('inputExpenseDate').value = '2024-06-15';
                    document.getElementById('inputExpenseCurrency').value = 'USD';
                    document.getElementById('inputExpenseExchangeRate').value = '1.5';
                    document.getElementById('inputExpenseAmountFc').value = amountValue;

                    const result = validateForm(document, modalRoot);

                    expect(result).toBe(false);

                    // Verify the amount field is marked invalid
                    const amountEl = document.getElementById('inputExpenseAmountFc');
                    expect(amountEl.classList.contains('is-invalid')).toBe(true);

                    // Verify feedback message exists
                    const feedback = amountEl.parentElement.querySelector('.invalid-feedback');
                    expect(feedback).not.toBeNull();
                    expect(
                        feedback.textContent === 'Amount is required' ||
                        feedback.textContent === 'Amount must be a positive number'
                    ).toBe(true);
                }),
                { numRuns: 100 }
            );
        });
    });

    // --- exchange_rate: non-positive / non-numeric values ---
    describe('exchange_rate: non-positive or non-numeric values cause validation failure', () => {
        let dom, document, modalRoot;

        beforeEach(() => {
            dom = new JSDOM(MODAL_HTML);
            document = dom.window.document;
            modalRoot = document.getElementById('modal-root');
        });

        it('rejects any non-positive or non-numeric exchange_rate value', () => {
            /**
             * Validates: Requirements 16.2
             */
            fc.assert(
                fc.property(invalidExchangeRateArb, (rateValue) => {
                    // Set up DOM: valid date, currency, amount — only exchange_rate is invalid
                    document.getElementById('inputExpenseDate').value = '2024-06-15';
                    document.getElementById('inputExpenseCurrency').value = 'USD';
                    document.getElementById('inputExpenseExchangeRate').value = rateValue;
                    document.getElementById('inputExpenseAmountFc').value = '500';

                    const result = validateForm(document, modalRoot);

                    expect(result).toBe(false);

                    // Verify the exchange rate field is marked invalid
                    const rateEl = document.getElementById('inputExpenseExchangeRate');
                    expect(rateEl.classList.contains('is-invalid')).toBe(true);

                    // Verify feedback message exists
                    const feedback = rateEl.parentElement.querySelector('.invalid-feedback');
                    expect(feedback).not.toBeNull();
                    expect(
                        feedback.textContent === 'Exchange rate is required' ||
                        feedback.textContent === 'Exchange rate must be a positive number'
                    ).toBe(true);
                }),
                { numRuns: 100 }
            );
        });
    });

    // --- Both fields invalid simultaneously ---
    describe('both amount_fc and exchange_rate invalid together', () => {
        let dom, document, modalRoot;

        beforeEach(() => {
            dom = new JSDOM(MODAL_HTML);
            document = dom.window.document;
            modalRoot = document.getElementById('modal-root');
        });

        it('rejects when both amount_fc and exchange_rate are invalid', () => {
            /**
             * Validates: Requirements 16.1, 16.2
             */
            fc.assert(
                fc.property(invalidAmountArb, invalidExchangeRateArb, (amountValue, rateValue) => {
                    document.getElementById('inputExpenseDate').value = '2024-06-15';
                    document.getElementById('inputExpenseCurrency').value = 'USD';
                    document.getElementById('inputExpenseExchangeRate').value = rateValue;
                    document.getElementById('inputExpenseAmountFc').value = amountValue;

                    const result = validateForm(document, modalRoot);

                    expect(result).toBe(false);

                    // Both fields should be marked invalid
                    const amountEl = document.getElementById('inputExpenseAmountFc');
                    const rateEl = document.getElementById('inputExpenseExchangeRate');
                    expect(amountEl.classList.contains('is-invalid')).toBe(true);
                    expect(rateEl.classList.contains('is-invalid')).toBe(true);
                }),
                { numRuns: 100 }
            );
        });
    });

    // --- Positive case: valid positive numbers pass ---
    describe('positive case: valid positive numbers pass validation', () => {
        let dom, document, modalRoot;

        beforeEach(() => {
            dom = new JSDOM(MODAL_HTML);
            document = dom.window.document;
            modalRoot = document.getElementById('modal-root');
        });

        it('accepts valid positive numeric values for amount_fc and exchange_rate', () => {
            /**
             * Validates: Requirements 16.1, 16.2
             */
            fc.assert(
                fc.property(validPositiveNumberArb, validPositiveNumberArb, (amountValue, rateValue) => {
                    document.getElementById('inputExpenseDate').value = '2024-06-15';
                    document.getElementById('inputExpenseCurrency').value = 'USD';
                    document.getElementById('inputExpenseExchangeRate').value = rateValue;
                    document.getElementById('inputExpenseAmountFc').value = amountValue;

                    const result = validateForm(document, modalRoot);

                    expect(result).toBe(true);

                    // No fields should be marked invalid
                    const invalidElements = modalRoot.querySelectorAll('.is-invalid');
                    expect(invalidElements.length).toBe(0);
                }),
                { numRuns: 100 }
            );
        });
    });
});
