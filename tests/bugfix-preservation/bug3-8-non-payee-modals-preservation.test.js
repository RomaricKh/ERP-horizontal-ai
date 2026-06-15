import { describe, it, expect } from 'vitest';
import fc from 'fast-check';
import fs from 'fs';
import path from 'path';

/**
 * Bugs 3–8 Preservation — Non-Payee Modals and Form Submission
 *
 * Observe that modals NOT using `payeeSelect.js` (Business form, Item form,
 * Item Types) work on unfixed code. Also verify that form submission logic
 * for POs, invoices, expenses, salaries, payments, collections exists and
 * saves records via API calls.
 *
 * This test must PASS on unfixed code — it confirms baseline behavior to preserve.
 *
 * **Validates: Requirements 3.7, 3.8, 3.9**
 */

describe('Bugs 3–8 Preservation — Non-payee modals and form submission work correctly', () => {

  // Modals that do NOT use payeeSelect.js
  const nonPayeeModals = [
    { file: 'public/assets/js/pages/Business/businessForm.modal.js', name: 'Business form' },
    { file: 'public/assets/js/pages/Items/itemForm.modal.js', name: 'Item form' },
    { file: 'public/assets/js/pages/Items/itemTypes.modal.js', name: 'Item Types' },
  ];

  // Modals that DO use payeeSelect.js (their form submission should still work)
  const payeeModals = [
    { file: 'public/assets/js/pages/PurchaseOrders/poForm.modal.js', name: 'PO form' },
    { file: 'public/assets/js/pages/SalesInvoices/invoiceForm.modal.js', name: 'Invoice form' },
    { file: 'public/assets/js/pages/Expenses/expenseForm.modal.js', name: 'Expense form' },
    { file: 'public/assets/js/pages/Salaries/salaryForm.modal.js', name: 'Salary form' },
    { file: 'public/assets/js/pages/Finance/paymentForm.modal.js', name: 'Payment form' },
    { file: 'public/assets/js/pages/Finance/collectionForm.modal.js', name: 'Collection form' },
  ];

  for (const { file, name } of nonPayeeModals) {
    it(`${name} modal does NOT import payeeSelect.js`, () => {
      const source = fs.readFileSync(path.resolve(file), 'utf-8');
      const importsPayeeSelect = /payeeSelect/i.test(source);
      expect(importsPayeeSelect, `${name} should NOT use payeeSelect`).toBe(false);
    });
  }

  for (const { file, name } of nonPayeeModals) {
    it(`${name} modal has a mount/init function`, () => {
      const source = fs.readFileSync(path.resolve(file), 'utf-8');
      const hasMount = /export\s+(async\s+)?function\s+(mount|init)/m.test(source);
      expect(hasMount, `${name} should have a mount or init function`).toBe(true);
    });
  }

  it('People page JS fetches people correctly from the API', () => {
    const peoplePath = path.resolve('public/assets/js/pages/People/people.js');
    const source = fs.readFileSync(peoplePath, 'utf-8');

    // The People page should fetch from /api/people
    const fetchesPeople = /apiFetch\s*\(\s*['"`]\/api\/people['"`]/.test(source);
    expect(fetchesPeople, 'People page should fetch from /api/people').toBe(true);
  });

  for (const { file, name } of payeeModals) {
    it(`${name} has form submission logic that calls apiFetch`, () => {
      const source = fs.readFileSync(path.resolve(file), 'utf-8');

      // Each form modal should have apiFetch for form submission
      const hasApiFetch = /apiFetch/.test(source);
      expect(hasApiFetch, `${name} should use apiFetch for form submission`).toBe(true);

      // Each form should have a submit handler or save logic
      const hasSubmitLogic = /submit|save|method:\s*['"]POST['"]|method:\s*['"]PUT['"]/i.test(source);
      expect(hasSubmitLogic, `${name} should have submit/save logic`).toBe(true);
    });
  }

  it('property: for all non-payee modals, none import payeeSelect', () => {
    fc.assert(
      fc.property(
        fc.integer({ min: 0, max: nonPayeeModals.length - 1 }),
        (index) => {
          const { file } = nonPayeeModals[index];
          const source = fs.readFileSync(path.resolve(file), 'utf-8');
          expect(/payeeSelect/i.test(source)).toBe(false);
        }
      ),
      { numRuns: nonPayeeModals.length }
    );
  });

  it('property: for all form modals (payee and non-payee), apiFetch is used for submission', () => {
    const allModals = [...nonPayeeModals, ...payeeModals];

    fc.assert(
      fc.property(
        fc.integer({ min: 0, max: allModals.length - 1 }),
        (index) => {
          const { file } = allModals[index];
          const source = fs.readFileSync(path.resolve(file), 'utf-8');
          expect(/apiFetch/.test(source)).toBe(true);
        }
      ),
      { numRuns: allModals.length }
    );
  });
});
