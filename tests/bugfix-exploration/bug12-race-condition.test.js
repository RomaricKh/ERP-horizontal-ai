import { describe, it, expect } from 'vitest';
import fc from 'fast-check';
import fs from 'fs';
import path from 'path';

/**
 * Bug 12 — Race Conditions in Delete Operations (3 Methods)
 * Bug Condition Exploration Test
 *
 * Three delete methods perform guard-condition checks outside of a transaction,
 * creating TOCTOU windows where concurrent operations can invalidate the guard
 * before the delete executes.
 *
 * - `AccountService::delete()` checks balance and countSlips outside transaction
 * - `ItemService::deleteType()` checks countItemsForType outside transaction
 * - `PeopleService::delete()` checks countTransactionsForPayee BEFORE beginTransaction()
 *
 * This test reads the actual PHP source and verifies that guard checks are
 * INSIDE the transaction boundary. On unfixed code, this test FAILS.
 *
 * **Validates: Requirements 1.35, 1.36, 1.37**
 */

/**
 * Helper: extract a method body from PHP source (handles nested braces).
 */
function extractMethodBody(source, methodName) {
  const regex = new RegExp(
    `public\\s+function\\s+${methodName}\\s*\\([^)]*\\)`,
    'm'
  );
  const match = regex.exec(source);
  if (!match) return null;

  let pos = match.index + match[0].length;
  while (pos < source.length && source[pos] !== '{') pos++;
  if (pos >= source.length) return null;

  let depth = 0;
  const start = pos;
  for (let i = pos; i < source.length; i++) {
    if (source[i] === '{') depth++;
    else if (source[i] === '}') {
      depth--;
      if (depth === 0) {
        return source.substring(start, i + 1);
      }
    }
  }
  return null;
}

/**
 * Check that guard checks appear AFTER beginTransaction() in the method body.
 * Returns true if the guard check is inside the transaction boundary.
 */
function guardIsInsideTransaction(methodBody, guardPattern) {
  if (!methodBody) return false;

  const txStart = methodBody.indexOf('beginTransaction');
  if (txStart === -1) return false;

  // Find the guard check
  const guardMatch = methodBody.match(guardPattern);
  if (!guardMatch) return false;

  const guardPos = methodBody.indexOf(guardMatch[0]);

  // Guard must appear AFTER beginTransaction
  return guardPos > txStart;
}

describe('Bug 12 — Race Condition: guard checks must be inside transaction boundary', () => {

  it('AccountService::delete() must check balance and countSlips INSIDE the transaction', () => {
    const source = fs.readFileSync(path.resolve('src/Services/AccountService.php'), 'utf-8');
    const methodBody = extractMethodBody(source, 'delete');
    expect(methodBody).not.toBeNull();

    // The balance check and countSlips check must be AFTER beginTransaction()
    const balanceInsideTx = guardIsInsideTransaction(methodBody, /balance/);
    const slipsInsideTx = guardIsInsideTransaction(methodBody, /countSlips/);

    expect(balanceInsideTx, 'Balance check is outside transaction — TOCTOU vulnerability').toBe(true);
    expect(slipsInsideTx, 'countSlips check is outside transaction — TOCTOU vulnerability').toBe(true);
  });

  it('ItemService::deleteType() must check countItemsForType INSIDE the transaction', () => {
    const source = fs.readFileSync(path.resolve('src/Services/ItemService.php'), 'utf-8');
    const methodBody = extractMethodBody(source, 'deleteType');
    expect(methodBody).not.toBeNull();

    const guardInsideTx = guardIsInsideTransaction(methodBody, /countItemsForType/);
    expect(guardInsideTx, 'countItemsForType check is outside transaction — TOCTOU vulnerability').toBe(true);
  });

  it('PeopleService::delete() must check countTransactionsForPayee INSIDE the transaction', () => {
    const source = fs.readFileSync(path.resolve('src/Services/PeopleService.php'), 'utf-8');
    const methodBody = extractMethodBody(source, 'delete');
    expect(methodBody).not.toBeNull();

    const guardInsideTx = guardIsInsideTransaction(methodBody, /countTransactionsForPayee/);
    expect(guardInsideTx, 'countTransactionsForPayee check is outside transaction — TOCTOU vulnerability').toBe(true);
  });

  it('property: for any of the 3 delete methods, guard checks must be atomic with the delete', () => {
    const cases = [
      {
        file: 'src/Services/AccountService.php',
        method: 'delete',
        guards: [/balance/, /countSlips/],
      },
      {
        file: 'src/Services/ItemService.php',
        method: 'deleteType',
        guards: [/countItemsForType/],
      },
      {
        file: 'src/Services/PeopleService.php',
        method: 'delete',
        guards: [/countTransactionsForPayee/],
      },
    ];

    fc.assert(
      fc.property(
        fc.integer({ min: 0, max: cases.length - 1 }),
        (index) => {
          const { file, method, guards } = cases[index];
          const source = fs.readFileSync(path.resolve(file), 'utf-8');
          const methodBody = extractMethodBody(source, method);
          expect(methodBody).not.toBeNull();

          for (const guard of guards) {
            expect(guardIsInsideTransaction(methodBody, guard)).toBe(true);
          }
        }
      ),
      { numRuns: cases.length }
    );
  });
});
