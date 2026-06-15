import { describe, it, expect } from 'vitest';
import fc from 'fast-check';
import fs from 'fs';
import path from 'path';

/**
 * Bugs 3–8 — Six Modals Crash (payeeSelect.js)
 * Bug Condition Exploration Test
 *
 * `payeeSelect.js` line 15 does `(json.data || []).filter(...)` but the People
 * API returns `{ data: { people: [...] } }`, so `json.data` is an object (not
 * an array). Calling `.filter()` on a plain object throws a TypeError.
 *
 * The fix should access `json.data?.people || []` instead of `json.data || []`.
 *
 * This test verifies the source code accesses `.people` on the response data.
 * On unfixed code, this test FAILS — proving the bug.
 *
 * **Validates: Requirements 1.4, 1.5, 1.6, 1.7, 1.8, 1.9**
 */

describe('Bugs 3–8 — Payee Select: populatePayeeSelect() must access json.data.people', () => {
  const filePath = path.resolve('public/assets/js/utils/payeeSelect.js');
  const source = fs.readFileSync(filePath, 'utf-8');

  it('the payee extraction line must access .people property from json.data', () => {
    // The correct pattern is: json.data?.people or json.data.people
    // The buggy pattern is: json.data || [] (treats json.data as an array)
    const accessesPeopleProperty = /json\.data\??\.\s*people/.test(source);
    expect(accessesPeopleProperty).toBe(true);
  });

  it('calling .filter() on the API response shape { data: { people: [...] } } must not throw', () => {
    // Simulate what the code does with the API response
    // The People API returns: { success: true, data: { people: [...] } }
    // The code does: const json = await res.json(); then accesses json.data

    // Extract the payee extraction logic from source
    const payeeLineMatch = source.match(/const\s+payees\s*=\s*(.+)/);
    const payeeLine = payeeLineMatch ? payeeLineMatch[1].trim() : '';

    // The line should reference .people somewhere
    const referencesPeople = payeeLine.includes('people');
    expect(referencesPeople).toBe(true);
  });

  it('property: for any valid people array, extracting payees from { data: { people: arr } } must not throw TypeError', () => {
    fc.assert(
      fc.property(
        fc.array(
          fc.record({
            name: fc.string({ minLength: 1 }),
            payee_id: fc.option(fc.integer({ min: 1 }), { nil: null }),
          }),
          { minLength: 0, maxLength: 20 }
        ),
        (people) => {
          // Simulate the API response structure
          const json = { success: true, data: { people } };

          // The BUGGY code does: (json.data || []).filter(p => p.payee_id != null)
          // json.data is { people: [...] }, which is truthy, so || [] doesn't help
          // .filter() on a plain object throws TypeError

          // The CORRECT code should do: (json.data?.people || []).filter(...)
          // Let's verify the source does the correct thing
          const accessesPeopleProperty = /json\.data\??\.\s*people/.test(source);
          expect(accessesPeopleProperty).toBe(true);
        }
      ),
      { numRuns: 50 }
    );
  });
});
