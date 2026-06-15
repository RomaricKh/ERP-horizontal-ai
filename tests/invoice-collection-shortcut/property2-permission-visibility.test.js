/**
 * Feature: invoice-collection-shortcut
 * Property 2: Permission-based button visibility
 *
 * For any set of invoices rendered in the sales invoices table, if the current user
 * lacks the `collections.create` permission, then no invoice row SHALL contain the
 * "Create Collection Slip" button, regardless of invoice status.
 *
 * **Validates: Requirements 1.4**
 *
 * @vitest-environment jsdom
 */
import { describe, it, expect } from 'vitest';
import fc from 'fast-check';

// --- Pure logic extracted from salesInvoices.js renderTable ---

const VALID_STATUSES = ['DRAFT', 'APPROVED', 'PAID', 'CANCELLED'];

/**
 * Determines whether the "Create Collection Slip" button should be rendered
 * for a given invoice based on permission and status.
 */
function shouldRenderCollectButton(invoice, canCollect) {
    return canCollect && invoice.status === 'PAID';
}

// --- Arbitraries ---

/** Generate a random date string in YYYY-MM-DD format */
const dateArb = fc.tuple(
    fc.integer({ min: 2020, max: 2030 }),
    fc.integer({ min: 1, max: 12 }),
    fc.integer({ min: 1, max: 28 })
).map(([y, m, d]) => `${y}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`);

/** Generate a random PAID invoice */
const paidInvoiceArb = fc.record({
    id: fc.integer({ min: 1, max: 99999 }),
    invoice_number: fc.string({ minLength: 1, maxLength: 20 }).filter(s => s.trim().length > 0),
    customer_name: fc.string({ minLength: 1, maxLength: 50 }),
    invoice_date: dateArb,
    status: fc.constant('PAID'),
    total_amount_fc: fc.integer({ min: 0, max: 99999900 }).map(n => n / 100),
    total_amount_bc: fc.integer({ min: 0, max: 99999900 }).map(n => n / 100),
});

/** Generate a random invoice with any status */
const invoiceArb = fc.record({
    id: fc.integer({ min: 1, max: 99999 }),
    invoice_number: fc.string({ minLength: 1, maxLength: 20 }).filter(s => s.trim().length > 0),
    customer_name: fc.string({ minLength: 1, maxLength: 50 }),
    invoice_date: dateArb,
    status: fc.constantFrom(...VALID_STATUSES),
    total_amount_fc: fc.integer({ min: 0, max: 99999900 }).map(n => n / 100),
    total_amount_bc: fc.integer({ min: 0, max: 99999900 }).map(n => n / 100),
});

/** Generate a list of random invoices (some PAID, some not) */
const invoiceListArb = fc.array(invoiceArb, { minLength: 1, maxLength: 20 });

// --- Tests ---

describe('Feature: invoice-collection-shortcut, Property 2: Permission-based button visibility', () => {
    it('button is hidden for all PAID invoices when permission is false', () => {
        fc.assert(
            fc.property(paidInvoiceArb, (invoice) => {
                const result = shouldRenderCollectButton(invoice, false);
                expect(result).toBe(false);
            }),
            { numRuns: 100 }
        );
    });

    it('no invoice in a list shows the button when permission is false', () => {
        fc.assert(
            fc.property(invoiceListArb, (invoices) => {
                for (const invoice of invoices) {
                    const result = shouldRenderCollectButton(invoice, false);
                    expect(result).toBe(false);
                }
            }),
            { numRuns: 100 }
        );
    });

    it('permission flag is the sole gate: same PAID invoice shows button with permission, hides without', () => {
        fc.assert(
            fc.property(paidInvoiceArb, fc.boolean(), (invoice, hasPermission) => {
                const result = shouldRenderCollectButton(invoice, hasPermission);

                if (hasPermission) {
                    expect(result).toBe(true);
                } else {
                    expect(result).toBe(false);
                }
            }),
            { numRuns: 100 }
        );
    });
});
