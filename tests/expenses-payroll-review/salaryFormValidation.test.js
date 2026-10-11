/**
 * Property 10: Frontend Salary Form Validation
 *
 * **Validates: Requirements 17.1, 17.2, 17.3, 17.4**
 *
 * For any salary form state where period_end < period_start, or gross_amount_fc ≤ 0,
 * or tax_fc < 0, or tax_fc > gross_amount_fc, or exchange_rate ≤ 0, validateForm()
 * SHALL return false.
 *
 * Uses Vitest + fast-check + jsdom to generate random invalid form states and verify
 * validation behavior.
 *
 * Tag: Feature: expenses-payroll-review, Property 10: Frontend salary form validation
 */
import { describe, it, expect, beforeEach } from 'vitest';
import { JSDOM } from 'jsdom';
import fc from 'fast-check';

// =============================================================================
// EXTRACTED VALIDATION LOGIC (from salaryForm.modal.js)
// =============================================================================

/**
 * Recreates the validateForm() logic from salaryForm.modal.js.
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

    const periodStartEl = document.getElementById('inputSalaryPeriodStart');
    const periodEndEl = document.getElementById('inputSalaryPeriodEnd');
    const currencyEl = document.getElementById('inputSalaryCurrency');
    const exchangeRateEl = document.getElementById('inputSalaryExchangeRate');
    const grossAmountFcEl = document.getElementById('inputSalaryGrossAmountFc');
    const taxFcEl = document.getElementById('inputSalaryTaxFc');

    // Validate period_start: required
    if (!periodStartEl.value) {
        markInvalid(document, periodStartEl, 'Period start is required');
        valid = false;
    }

    // Validate period_end: required
    if (!periodEndEl.value) {
        markInvalid(document, periodEndEl, 'Period end is required');
        valid = false;
    }

    // Validate period_end >= period_start
    if (periodStartEl.value && periodEndEl.value && periodEndEl.value < periodStartEl.value) {
        markInvalid(document, periodEndEl, 'Period end must be on or after period start');
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

    // Validate gross_amount_fc: must be a positive number
    const grossValue = grossAmountFcEl.value;
    if (!grossValue) {
        markInvalid(document, grossAmountFcEl, 'Gross amount is required');
        valid = false;
    } else if (isNaN(parseFloat(grossValue)) || parseFloat(grossValue) <= 0) {
        markInvalid(document, grossAmountFcEl, 'Gross amount must be a positive number');
        valid = false;
    }

    // Validate tax_fc: if provided, must be >= 0 and <= gross_amount_fc
    const taxValue = taxFcEl.value;
    if (taxValue !== '') {
        const taxNum = parseFloat(taxValue);
        const grossNum = parseFloat(grossValue) || 0;
        if (isNaN(taxNum) || taxNum < 0 || taxNum > grossNum) {
            markInvalid(document, taxFcEl, 'Tax must be between 0 and gross amount');
            valid = false;
        }
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
    <div><input id="inputSalaryPeriodStart" type="text" value="2024-01-01" /></div>
    <div><input id="inputSalaryPeriodEnd" type="text" value="2024-01-31" /></div>
    <div><input id="inputSalaryCurrency" type="text" value="USD" /></div>
    <div><input id="inputSalaryExchangeRate" type="text" value="1.0" /></div>
    <div><input id="inputSalaryGrossAmountFc" type="text" value="5000" /></div>
    <div><input id="inputSalaryTaxFc" type="text" value="500" /></div>
    <div><input id="inputSalaryNetAmountFc" type="text" value="4500" /></div>
</div>
`;

// =============================================================================
// ARBITRARIES (generators)
// =============================================================================

// Date generator: produces valid YYYY-MM-DD strings using integer components
const dateArb = fc.tuple(
    fc.integer({ min: 2020, max: 2030 }),  // year
    fc.integer({ min: 1, max: 12 }),       // month
    fc.integer({ min: 1, max: 28 })        // day (use 28 to avoid invalid dates)
).map(([y, m, d]) => `${y}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`);

// Generate a pair of dates where period_end < period_start
const invalidDatePairArb = fc.tuple(dateArb, dateArb)
    .filter(([a, b]) => a !== b)
    .map(([a, b]) => {
        // Sort so we can guarantee end < start
        const sorted = [a, b].sort();
        return { periodStart: sorted[1], periodEnd: sorted[0] }; // end < start
    });

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

// Invalid gross_amount_fc: non-positive OR non-numeric OR empty
const invalidGrossArb = fc.oneof(
    nonPositiveNumberArb,
    nonNumericArb,
    emptyArb
);

// Invalid exchange_rate: non-positive OR non-numeric OR empty
const invalidExchangeRateArb = fc.oneof(
    nonPositiveNumberArb,
    nonNumericArb,
    emptyArb
);

// Negative tax values (tax_fc < 0)
const negativeTaxArb = fc.double({ min: -1e9, max: -0.001, noNaN: true }).map(n => String(n));

// Tax exceeding gross: generate a valid gross and a tax > gross
const taxExceedsGrossArb = fc.tuple(
    fc.double({ min: 1, max: 1e6, noNaN: true }),    // gross
    fc.double({ min: 0.01, max: 1e6, noNaN: true })  // extra above gross
).map(([gross, extra]) => ({
    gross: String(gross),
    tax: String(gross + extra)
}));

// Valid positive numbers for valid base states
const validPositiveNumberArb = fc.double({ min: 0.01, max: 1e6, noNaN: true })
    .filter(n => n > 0)
    .map(n => String(n));

// Valid tax: between 0 and gross (inclusive)
const validTaxArb = (grossStr) => {
    const gross = parseFloat(grossStr) || 5000;
    return fc.double({ min: 0, max: gross, noNaN: true }).map(n => String(n));
};

// =============================================================================
// PROPERTY TESTS
// =============================================================================

describe('Property 10: Frontend salary form validation', () => {

    // --- period_end < period_start ---
    describe('period_end < period_start causes validation failure', () => {
        let dom, document, modalRoot;

        beforeEach(() => {
            dom = new JSDOM(MODAL_HTML);
            document = dom.window.document;
            modalRoot = document.getElementById('modal-root');
        });

        it('rejects when period_end is before period_start', () => {
            /**
             * Validates: Requirements 17.1
             */
            fc.assert(
                fc.property(invalidDatePairArb, ({ periodStart, periodEnd }) => {
                    document.getElementById('inputSalaryPeriodStart').value = periodStart;
                    document.getElementById('inputSalaryPeriodEnd').value = periodEnd;
                    document.getElementById('inputSalaryCurrency').value = 'USD';
                    document.getElementById('inputSalaryExchangeRate').value = '1.0';
                    document.getElementById('inputSalaryGrossAmountFc').value = '5000';
                    document.getElementById('inputSalaryTaxFc').value = '500';

                    const result = validateForm(document, modalRoot);

                    expect(result).toBe(false);

                    // Verify the period end field is marked invalid
                    const periodEndEl = document.getElementById('inputSalaryPeriodEnd');
                    expect(periodEndEl.classList.contains('is-invalid')).toBe(true);

                    // Verify feedback message
                    const feedback = periodEndEl.parentElement.querySelector('.invalid-feedback');
                    expect(feedback).not.toBeNull();
                    expect(feedback.textContent).toBe('Period end must be on or after period start');
                }),
                { numRuns: 100 }
            );
        });
    });

    // --- gross_amount_fc ≤ 0 or non-numeric ---
    describe('gross_amount_fc ≤ 0 or non-numeric causes validation failure', () => {
        let dom, document, modalRoot;

        beforeEach(() => {
            dom = new JSDOM(MODAL_HTML);
            document = dom.window.document;
            modalRoot = document.getElementById('modal-root');
        });

        it('rejects any non-positive or non-numeric gross_amount_fc value', () => {
            /**
             * Validates: Requirements 17.2
             */
            fc.assert(
                fc.property(invalidGrossArb, (grossValue) => {
                    document.getElementById('inputSalaryPeriodStart').value = '2024-01-01';
                    document.getElementById('inputSalaryPeriodEnd').value = '2024-01-31';
                    document.getElementById('inputSalaryCurrency').value = 'USD';
                    document.getElementById('inputSalaryExchangeRate').value = '1.0';
                    document.getElementById('inputSalaryGrossAmountFc').value = grossValue;
                    document.getElementById('inputSalaryTaxFc').value = ''; // Empty is valid (optional)

                    const result = validateForm(document, modalRoot);

                    expect(result).toBe(false);

                    // Verify the gross amount field is marked invalid
                    const grossEl = document.getElementById('inputSalaryGrossAmountFc');
                    expect(grossEl.classList.contains('is-invalid')).toBe(true);

                    // Verify feedback message exists
                    const feedback = grossEl.parentElement.querySelector('.invalid-feedback');
                    expect(feedback).not.toBeNull();
                    expect(
                        feedback.textContent === 'Gross amount is required' ||
                        feedback.textContent === 'Gross amount must be a positive number'
                    ).toBe(true);
                }),
                { numRuns: 100 }
            );
        });
    });

    // --- tax_fc < 0 ---
    describe('tax_fc < 0 causes validation failure', () => {
        let dom, document, modalRoot;

        beforeEach(() => {
            dom = new JSDOM(MODAL_HTML);
            document = dom.window.document;
            modalRoot = document.getElementById('modal-root');
        });

        it('rejects any negative tax_fc value', () => {
            /**
             * Validates: Requirements 17.3
             */
            fc.assert(
                fc.property(negativeTaxArb, (taxValue) => {
                    document.getElementById('inputSalaryPeriodStart').value = '2024-01-01';
                    document.getElementById('inputSalaryPeriodEnd').value = '2024-01-31';
                    document.getElementById('inputSalaryCurrency').value = 'USD';
                    document.getElementById('inputSalaryExchangeRate').value = '1.0';
                    document.getElementById('inputSalaryGrossAmountFc').value = '5000';
                    document.getElementById('inputSalaryTaxFc').value = taxValue;

                    const result = validateForm(document, modalRoot);

                    expect(result).toBe(false);

                    // Verify the tax field is marked invalid
                    const taxEl = document.getElementById('inputSalaryTaxFc');
                    expect(taxEl.classList.contains('is-invalid')).toBe(true);

                    // Verify feedback message
                    const feedback = taxEl.parentElement.querySelector('.invalid-feedback');
                    expect(feedback).not.toBeNull();
                    expect(feedback.textContent).toBe('Tax must be between 0 and gross amount');
                }),
                { numRuns: 100 }
            );
        });
    });

    // --- tax_fc > gross_amount_fc ---
    describe('tax_fc > gross_amount_fc causes validation failure', () => {
        let dom, document, modalRoot;

        beforeEach(() => {
            dom = new JSDOM(MODAL_HTML);
            document = dom.window.document;
            modalRoot = document.getElementById('modal-root');
        });

        it('rejects when tax_fc exceeds gross_amount_fc', () => {
            /**
             * Validates: Requirements 17.3
             */
            fc.assert(
                fc.property(taxExceedsGrossArb, ({ gross, tax }) => {
                    document.getElementById('inputSalaryPeriodStart').value = '2024-01-01';
                    document.getElementById('inputSalaryPeriodEnd').value = '2024-01-31';
                    document.getElementById('inputSalaryCurrency').value = 'USD';
                    document.getElementById('inputSalaryExchangeRate').value = '1.0';
                    document.getElementById('inputSalaryGrossAmountFc').value = gross;
                    document.getElementById('inputSalaryTaxFc').value = tax;

                    const result = validateForm(document, modalRoot);

                    expect(result).toBe(false);

                    // Verify the tax field is marked invalid
                    const taxEl = document.getElementById('inputSalaryTaxFc');
                    expect(taxEl.classList.contains('is-invalid')).toBe(true);

                    // Verify feedback message
                    const feedback = taxEl.parentElement.querySelector('.invalid-feedback');
                    expect(feedback).not.toBeNull();
                    expect(feedback.textContent).toBe('Tax must be between 0 and gross amount');
                }),
                { numRuns: 100 }
            );
        });
    });

    // --- exchange_rate ≤ 0 ---
    describe('exchange_rate ≤ 0 or non-numeric causes validation failure', () => {
        let dom, document, modalRoot;

        beforeEach(() => {
            dom = new JSDOM(MODAL_HTML);
            document = dom.window.document;
            modalRoot = document.getElementById('modal-root');
        });

        it('rejects any non-positive or non-numeric exchange_rate value', () => {
            /**
             * Validates: Requirements 17.4
             */
            fc.assert(
                fc.property(invalidExchangeRateArb, (rateValue) => {
                    document.getElementById('inputSalaryPeriodStart').value = '2024-01-01';
                    document.getElementById('inputSalaryPeriodEnd').value = '2024-01-31';
                    document.getElementById('inputSalaryCurrency').value = 'USD';
                    document.getElementById('inputSalaryExchangeRate').value = rateValue;
                    document.getElementById('inputSalaryGrossAmountFc').value = '5000';
                    document.getElementById('inputSalaryTaxFc').value = '500';

                    const result = validateForm(document, modalRoot);

                    expect(result).toBe(false);

                    // Verify the exchange rate field is marked invalid
                    const rateEl = document.getElementById('inputSalaryExchangeRate');
                    expect(rateEl.classList.contains('is-invalid')).toBe(true);

                    // Verify feedback message
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

    // --- Multiple invalid fields simultaneously ---
    describe('multiple invalid fields at once', () => {
        let dom, document, modalRoot;

        beforeEach(() => {
            dom = new JSDOM(MODAL_HTML);
            document = dom.window.document;
            modalRoot = document.getElementById('modal-root');
        });

        it('rejects when multiple fields are invalid simultaneously', () => {
            /**
             * Validates: Requirements 17.1, 17.2, 17.3, 17.4
             */
            fc.assert(
                fc.property(
                    invalidDatePairArb,
                    invalidGrossArb,
                    invalidExchangeRateArb,
                    ({ periodStart, periodEnd }, grossValue, rateValue) => {
                        document.getElementById('inputSalaryPeriodStart').value = periodStart;
                        document.getElementById('inputSalaryPeriodEnd').value = periodEnd;
                        document.getElementById('inputSalaryCurrency').value = 'USD';
                        document.getElementById('inputSalaryExchangeRate').value = rateValue;
                        document.getElementById('inputSalaryGrossAmountFc').value = grossValue;
                        document.getElementById('inputSalaryTaxFc').value = '';

                        const result = validateForm(document, modalRoot);

                        expect(result).toBe(false);
                    }
                ),
                { numRuns: 100 }
            );
        });
    });
});
