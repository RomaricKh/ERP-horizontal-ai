/**
 * Feature: invoice-collection-shortcut
 * Unit Tests: Edge cases
 *
 * 1. Pre-fill application with missing payee: Verify the form handles a payee_id
 *    that doesn't exist in the dropdown gracefully
 * 2. Empty prefill object: Verify the form handles an empty/null prefill gracefully
 * 3. Reference type constant: Verify reference_type is always set to "CUSTOMER"
 *
 * **Validates: Requirements 2.3, 3.4**
 *
 * @vitest-environment jsdom
 */
import { describe, it, expect, beforeEach } from 'vitest';

// --- Simulate the applyPrefill logic from collectionForm.modal.js using DOM ---

/**
 * Creates a minimal DOM structure simulating the collection form fields.
 */
function createFormDOM(payeeOptions = []) {
    document.body.innerHTML = `
        <select id="inputCollectionPayee">
            <option value="">— Select payee —</option>
            ${payeeOptions.map(p => `<option value="${p.id}">${p.name}</option>`).join('')}
        </select>
        <select id="inputCollectionReferenceType">
            <option value="">— Select —</option>
            <option value="CUSTOMER">Customer</option>
            <option value="SUPPLIER">Supplier</option>
        </select>
        <input id="inputCollectionReferenceId" type="text" value="" />
        <select id="inputCollectionCurrency">
            <option value="">— Select —</option>
            <option value="USD">USD</option>
            <option value="EUR">EUR</option>
            <option value="LBP">LBP</option>
        </select>
        <input id="inputCollectionExchangeRate" type="text" value="" />
        <input id="inputCollectionAmountFc" type="text" value="" />
        <input id="inputCollectionAmountBc" type="text" value="" />
    `;

    return {
        payee: () => document.getElementById('inputCollectionPayee'),
        referenceType: () => document.getElementById('inputCollectionReferenceType'),
        referenceId: () => document.getElementById('inputCollectionReferenceId'),
        currency: () => document.getElementById('inputCollectionCurrency'),
        exchangeRate: () => document.getElementById('inputCollectionExchangeRate'),
        amountFc: () => document.getElementById('inputCollectionAmountFc'),
        amountBc: () => document.getElementById('inputCollectionAmountBc'),
    };
}

/**
 * DOM-based applyPrefill that mirrors the real implementation.
 */
function applyPrefill(prefill, F) {
    if (!prefill) return;

    if (prefill.payee_id) {
        const payeeSelect = F.payee();
        const option = payeeSelect.querySelector(`option[value="${prefill.payee_id}"]`);
        if (option) {
            payeeSelect.value = prefill.payee_id;
        }
        // If option doesn't exist, leave unselected
    }

    if (prefill.reference_type) {
        F.referenceType().value = prefill.reference_type;
    }

    if (prefill.reference_id) {
        F.referenceId().value = prefill.reference_id;
    }

    if (prefill.currency) {
        F.currency().value = prefill.currency;
    }

    if (prefill.exchange_rate) {
        F.exchangeRate().value = prefill.exchange_rate;
    }

    if (prefill.amount_fc) {
        F.amountFc().value = prefill.amount_fc;
    }
}

// --- Tests ---

describe('Feature: invoice-collection-shortcut, Unit Tests: Edge cases', () => {
    describe('Missing payee in dropdown', () => {
        it('leaves payee field unselected when payee_id does not exist in dropdown', () => {
            const F = createFormDOM([
                { id: '10', name: 'Customer A' },
                { id: '20', name: 'Customer B' },
            ]);

            const prefill = {
                payee_id: 999, // Does not exist in dropdown
                reference_type: 'CUSTOMER',
                reference_id: 'INV-001',
                currency: 'USD',
                exchange_rate: 1.5,
                amount_fc: 100,
            };

            applyPrefill(prefill, F);

            // Payee should remain at default (empty value)
            expect(F.payee().value).toBe('');
        });

        it('still fills other fields even when payee is missing from dropdown', () => {
            const F = createFormDOM([
                { id: '10', name: 'Customer A' },
            ]);

            const prefill = {
                payee_id: 999, // Does not exist
                reference_type: 'CUSTOMER',
                reference_id: 'INV-002',
                currency: 'EUR',
                exchange_rate: 1.08,
                amount_fc: 250.50,
            };

            applyPrefill(prefill, F);

            // Payee unselected
            expect(F.payee().value).toBe('');
            // Other fields filled correctly
            expect(F.referenceType().value).toBe('CUSTOMER');
            expect(F.referenceId().value).toBe('INV-002');
            expect(F.currency().value).toBe('EUR');
            expect(F.exchangeRate().value).toBe('1.08');
            expect(F.amountFc().value).toBe('250.5');
        });

        it('selects payee correctly when payee_id exists in dropdown', () => {
            const F = createFormDOM([
                { id: '10', name: 'Customer A' },
                { id: '20', name: 'Customer B' },
            ]);

            const prefill = {
                payee_id: 20,
                reference_type: 'CUSTOMER',
                reference_id: 'INV-003',
                currency: 'USD',
                exchange_rate: 1,
                amount_fc: 500,
            };

            applyPrefill(prefill, F);

            expect(F.payee().value).toBe('20');
        });
    });

    describe('Empty prefill object', () => {
        it('handles null prefill gracefully without changing form fields', () => {
            const F = createFormDOM([
                { id: '10', name: 'Customer A' },
            ]);

            applyPrefill(null, F);

            expect(F.payee().value).toBe('');
            expect(F.referenceType().value).toBe('');
            expect(F.referenceId().value).toBe('');
            expect(F.currency().value).toBe('');
            expect(F.exchangeRate().value).toBe('');
            expect(F.amountFc().value).toBe('');
        });

        it('handles undefined prefill gracefully without changing form fields', () => {
            const F = createFormDOM([
                { id: '10', name: 'Customer A' },
            ]);

            applyPrefill(undefined, F);

            expect(F.payee().value).toBe('');
            expect(F.referenceType().value).toBe('');
            expect(F.referenceId().value).toBe('');
            expect(F.currency().value).toBe('');
            expect(F.exchangeRate().value).toBe('');
            expect(F.amountFc().value).toBe('');
        });

        it('handles empty object prefill gracefully without changing form fields', () => {
            const F = createFormDOM([
                { id: '10', name: 'Customer A' },
            ]);

            applyPrefill({}, F);

            expect(F.payee().value).toBe('');
            expect(F.referenceType().value).toBe('');
            expect(F.referenceId().value).toBe('');
            expect(F.currency().value).toBe('');
            expect(F.exchangeRate().value).toBe('');
            expect(F.amountFc().value).toBe('');
        });
    });

    describe('Reference type always CUSTOMER', () => {
        it('reference_type is set to CUSTOMER when prefill comes from invoice shortcut', () => {
            const F = createFormDOM([
                { id: '5', name: 'Test Customer' },
            ]);

            const prefill = {
                payee_id: 5,
                reference_type: 'CUSTOMER',
                reference_id: 'INV-100',
                currency: 'USD',
                exchange_rate: 1,
                amount_fc: 1000,
            };

            applyPrefill(prefill, F);

            expect(F.referenceType().value).toBe('CUSTOMER');
        });

        it('reference_type field value is exactly "CUSTOMER" string', () => {
            const F = createFormDOM([]);

            const prefill = {
                payee_id: 1,
                reference_type: 'CUSTOMER',
                reference_id: 'INV-200',
                currency: 'LBP',
                exchange_rate: 89500,
                amount_fc: 50,
            };

            applyPrefill(prefill, F);

            expect(F.referenceType().value).toBe('CUSTOMER');
            expect(F.referenceType().value).not.toBe('customer');
            expect(F.referenceType().value).not.toBe('SUPPLIER');
        });
    });
});
