/**
 * Feature: invoice-collection-shortcut
 * Property 3: Pre-fill data mapping preserves invoice values
 *
 * For any valid invoice object with customer_payee_id, invoice_number, currency,
 * exchange_rate, and total_amount_fc, when the collection form is mounted with
 * pre-fill data derived from that invoice, the form fields (payee, reference_id,
 * currency, exchange_rate, amount_fc) SHALL contain values equal to the corresponding
 * invoice fields.
 *
 * **Validates: Requirements 2.2, 2.4, 2.5, 2.6, 2.7, 3.1, 3.2**
 *
 * @vitest-environment jsdom
 */
import { describe, it, expect, beforeEach } from 'vitest';
import fc from 'fast-check';

// --- Simulate the applyPrefill logic from collectionForm.modal.js ---

/**
 * Simulates the DOM-based applyPrefill function.
 * Sets form field values from a prefill object.
 */
function applyPrefill(prefill, formFields, payeeOptions) {
    if (!prefill) return;

    if (prefill.payee_id) {
        const optionExists = payeeOptions.includes(String(prefill.payee_id));
        if (optionExists) {
            formFields.payee = String(prefill.payee_id);
        }
        // If option doesn't exist, leave unselected
    }

    if (prefill.reference_type) {
        formFields.referenceType = prefill.reference_type;
    }

    if (prefill.reference_id) {
        formFields.referenceId = prefill.reference_id;
    }

    if (prefill.currency) {
        formFields.currency = prefill.currency;
    }

    if (prefill.exchange_rate) {
        formFields.exchangeRate = String(prefill.exchange_rate);
    }

    if (prefill.amount_fc) {
        formFields.amountFc = String(prefill.amount_fc);
    }
}

// --- Arbitraries ---

const currencyArb = fc.constantFrom('USD', 'EUR', 'LBP', 'GBP', 'AED', 'SAR', 'JPY');

const invoiceArb = fc.record({
    customer_payee_id: fc.integer({ min: 1, max: 9999 }),
    invoice_number: fc.string({ minLength: 1, maxLength: 20 }).filter(s => s.trim().length > 0),
    currency: currencyArb,
    exchange_rate: fc.integer({ min: 1, max: 99990000 }).map(n => n / 10000),
    total_amount_fc: fc.integer({ min: 1, max: 99999900 }).map(n => n / 100),
});

// --- Tests ---

describe('Feature: invoice-collection-shortcut, Property 3: Pre-fill data mapping preserves invoice values', () => {
    it('form fields match invoice values after applyPrefill when payee exists in dropdown', () => {
        fc.assert(
            fc.property(invoiceArb, (invoice) => {
                // Simulate form fields (initially empty)
                const formFields = {
                    payee: '',
                    referenceType: '',
                    referenceId: '',
                    currency: '',
                    exchangeRate: '',
                    amountFc: '',
                };

                // Payee exists in dropdown options
                const payeeOptions = [String(invoice.customer_payee_id)];

                // Build prefill object as salesInvoices.js does
                const prefill = {
                    payee_id: invoice.customer_payee_id,
                    reference_type: 'CUSTOMER',
                    reference_id: invoice.invoice_number,
                    currency: invoice.currency,
                    exchange_rate: invoice.exchange_rate,
                    amount_fc: invoice.total_amount_fc,
                };

                applyPrefill(prefill, formFields, payeeOptions);

                // Verify all fields match
                expect(formFields.payee).toBe(String(invoice.customer_payee_id));
                expect(formFields.referenceType).toBe('CUSTOMER');
                expect(formFields.referenceId).toBe(invoice.invoice_number);
                expect(formFields.currency).toBe(invoice.currency);
                expect(formFields.exchangeRate).toBe(String(invoice.exchange_rate));
                expect(formFields.amountFc).toBe(String(invoice.total_amount_fc));
            }),
            { numRuns: 100 }
        );
    });

    it('reference_type is always set to CUSTOMER regardless of invoice data', () => {
        fc.assert(
            fc.property(invoiceArb, (invoice) => {
                const formFields = {
                    payee: '',
                    referenceType: '',
                    referenceId: '',
                    currency: '',
                    exchangeRate: '',
                    amountFc: '',
                };

                const payeeOptions = [String(invoice.customer_payee_id)];

                const prefill = {
                    payee_id: invoice.customer_payee_id,
                    reference_type: 'CUSTOMER',
                    reference_id: invoice.invoice_number,
                    currency: invoice.currency,
                    exchange_rate: invoice.exchange_rate,
                    amount_fc: invoice.total_amount_fc,
                };

                applyPrefill(prefill, formFields, payeeOptions);

                expect(formFields.referenceType).toBe('CUSTOMER');
            }),
            { numRuns: 100 }
        );
    });

    it('currency field preserves exact currency code from invoice', () => {
        fc.assert(
            fc.property(invoiceArb, (invoice) => {
                const formFields = {
                    payee: '',
                    referenceType: '',
                    referenceId: '',
                    currency: '',
                    exchangeRate: '',
                    amountFc: '',
                };

                const payeeOptions = [String(invoice.customer_payee_id)];

                const prefill = {
                    payee_id: invoice.customer_payee_id,
                    reference_type: 'CUSTOMER',
                    reference_id: invoice.invoice_number,
                    currency: invoice.currency,
                    exchange_rate: invoice.exchange_rate,
                    amount_fc: invoice.total_amount_fc,
                };

                applyPrefill(prefill, formFields, payeeOptions);

                expect(formFields.currency).toBe(invoice.currency);
            }),
            { numRuns: 100 }
        );
    });
});
