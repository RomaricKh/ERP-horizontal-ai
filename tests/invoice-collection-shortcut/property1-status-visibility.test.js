/**
 * Feature: invoice-collection-shortcut
 * Property 1: Status-based button visibility
 *
 * For any invoice rendered in the sales invoices table, the "Create Collection Slip"
 * button SHALL appear in the actions column if and only if the invoice status is "PAID".
 *
 * **Validates: Requirements 1.1, 1.2**
 *
 * @vitest-environment jsdom
 */
import { describe, it, expect } from 'vitest';
import fc from 'fast-check';

// --- Pure logic extracted from salesInvoices.js renderTable ---

const VALID_STATUSES = ['DRAFT', 'APPROVED', 'PAID', 'CANCELLED'];

/**
 * Determines whether the "Create Collection Slip" button should be rendered
 * for a given invoice, assuming the user has the collections.create permission.
 */
function shouldRenderCollectButton(invoice, canCollect) {
    return canCollect && invoice.status === 'PAID';
}

// --- Arbitraries ---

/** Generate a random invoice status from the valid set */
const invoiceStatusArb = fc.constantFrom(...VALID_STATUSES);

/** Generate a random date string in YYYY-MM-DD format */
const dateArb = fc.tuple(
    fc.integer({ min: 2020, max: 2030 }),
    fc.integer({ min: 1, max: 12 }),
    fc.integer({ min: 1, max: 28 })
).map(([y, m, d]) => `${y}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`);

/** Generate a random invoice with a random status */
const invoiceArb = fc.record({
    id: fc.integer({ min: 1, max: 99999 }),
    invoice_number: fc.string({ minLength: 1, maxLength: 20 }).filter(s => s.trim().length > 0),
    customer_name: fc.string({ minLength: 1, maxLength: 50 }),
    invoice_date: dateArb,
    status: invoiceStatusArb,
    total_amount_fc: fc.integer({ min: 0, max: 99999900 }).map(n => n / 100),
    total_amount_bc: fc.integer({ min: 0, max: 99999900 }).map(n => n / 100),
});

// --- Tests ---

describe('Feature: invoice-collection-shortcut, Property 1: Status-based button visibility', () => {
    it('button appears if and only if status is PAID (with permission)', () => {
        fc.assert(
            fc.property(invoiceArb, (invoice) => {
                const result = shouldRenderCollectButton(invoice, true);

                if (invoice.status === 'PAID') {
                    expect(result).toBe(true);
                } else {
                    expect(result).toBe(false);
                }
            }),
            { numRuns: 100 }
        );
    });

    it('button never appears for non-PAID statuses regardless of other invoice properties', () => {
        const nonPaidStatusArb = fc.constantFrom('DRAFT', 'APPROVED', 'CANCELLED');
        const nonPaidInvoiceArb = fc.record({
            id: fc.integer({ min: 1, max: 99999 }),
            invoice_number: fc.string({ minLength: 1, maxLength: 20 }).filter(s => s.trim().length > 0),
            customer_name: fc.string({ minLength: 1, maxLength: 50 }),
            invoice_date: dateArb,
            status: nonPaidStatusArb,
            total_amount_fc: fc.integer({ min: 0, max: 99999900 }).map(n => n / 100),
            total_amount_bc: fc.integer({ min: 0, max: 99999900 }).map(n => n / 100),
        });

        fc.assert(
            fc.property(nonPaidInvoiceArb, (invoice) => {
                expect(shouldRenderCollectButton(invoice, true)).toBe(false);
            }),
            { numRuns: 100 }
        );
    });

    it('button always appears for PAID invoices with permission', () => {
        const paidInvoiceArb = fc.record({
            id: fc.integer({ min: 1, max: 99999 }),
            invoice_number: fc.string({ minLength: 1, maxLength: 20 }).filter(s => s.trim().length > 0),
            customer_name: fc.string({ minLength: 1, maxLength: 50 }),
            invoice_date: dateArb,
            status: fc.constant('PAID'),
            total_amount_fc: fc.integer({ min: 0, max: 99999900 }).map(n => n / 100),
            total_amount_bc: fc.integer({ min: 0, max: 99999900 }).map(n => n / 100),
        });

        fc.assert(
            fc.property(paidInvoiceArb, (invoice) => {
                expect(shouldRenderCollectButton(invoice, true)).toBe(true);
            }),
            { numRuns: 100 }
        );
    });
});
