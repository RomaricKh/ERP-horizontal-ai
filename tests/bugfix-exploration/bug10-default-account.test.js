import { describe, it, expect } from 'vitest';
import fc from 'fast-check';
import fs from 'fs';
import path from 'path';

/**
 * Bug 10 — No Default Account on Business Creation
 * Bug Condition Exploration Test
 *
 * `BusinessService::create()` inserts the business record and logs the audit
 * entry but does NOT create a default `business_accounts` record. Users must
 * manually create an account before they can use payments, collections, or
 * mark invoices as paid.
 *
 * This test reads the actual PHP source and verifies that `create()` calls
 * `AccountRepository::create()` or equivalent to create a default account.
 * On unfixed code, this test FAILS — proving the bug.
 *
 * **Validates: Requirements 1.18**
 */

describe('Bug 10 — Default Account: BusinessService::create() must create a default account', () => {
  const servicePath = path.resolve('src/Services/BusinessService.php');
  const source = fs.readFileSync(servicePath, 'utf-8');

  // Extract the create method body
  const methodMatch = source.match(
    /public\s+function\s+create\s*\([^)]*\)[^{]*\{([\s\S]*?)(?=\n\s{4}public\s+function|\n\s{4}private\s+function|\n\})/
  );
  const methodBody = methodMatch ? methodMatch[1] : '';

  it('the create() method must call accountRepo->create() to create a default account', () => {
    // Look for a call to create a default account
    const createsAccount =
      /accountRepo\s*->\s*create/i.test(methodBody) ||
      /AccountRepository/i.test(methodBody);
    expect(createsAccount).toBe(true);
  });

  it('the BusinessService class must have an AccountRepository dependency', () => {
    // The class should import/use AccountRepository
    const hasAccountRepoDependency =
      /AccountRepository/.test(source) &&
      /accountRepo/.test(source);
    expect(hasAccountRepoDependency).toBe(true);
  });

  it('property: for any business creation data, a default account must be provisioned', () => {
    fc.assert(
      fc.property(
        fc.record({
          name: fc.string({ minLength: 1, maxLength: 50 }).filter(s => s.trim().length > 0),
          currency: fc.constantFrom('USD', 'EUR', 'GBP', 'LBP', 'AED'),
        }),
        (data) => {
          // The create() method should contain account creation logic
          // for every business, regardless of the input data
          const createsAccount = /accountRepo\s*->\s*create/i.test(methodBody);
          expect(createsAccount).toBe(true);
        }
      ),
      { numRuns: 20 }
    );
  });
});
