import { describe, it, expect } from 'vitest';
import fc from 'fast-check';
import fs from 'fs';
import path from 'path';

/**
 * Bug 12 Preservation — Successful Deletes (No Concurrent Modifications)
 *
 * Observe that successful deletes (no concurrent modifications) work correctly.
 * The three affected delete methods (AccountService::delete(), ItemService::deleteType(),
 * PeopleService::delete()) all have guard checks that reject invalid deletes.
 * When the guard passes and no concurrent modification occurs, the delete succeeds.
 *
 * Also verify that non-delete operations on these services remain unchanged.
 *
 * This test must PASS on unfixed code — it confirms baseline behavior to preserve.
 *
 * **Validates: Requirements 3.20, 3.21, 3.22, 3.23, 3.24**
 */

/**
 * Helper: extract a method body from PHP source (handles nested braces).
 */
function extractMethodBody(source, methodName) {
  const regex = new RegExp(
    `(?:public|private|protected)\\s+function\\s+${methodName}\\s*\\([^)]*\\)`,
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
      if (depth === 0) return source.substring(start, i + 1);
    }
  }
  return null;
}

describe('Bug 12 Preservation — Successful deletes and non-delete operations', () => {

  // AccountService::delete() guard checks
  it('AccountService::delete() checks balance != 0 before deleting', () => {
    const source = fs.readFileSync(path.resolve('src/Services/AccountService.php'), 'utf-8');
    const methodBody = extractMethodBody(source, 'delete');
    expect(methodBody).not.toBeNull();

    // Guard: balance must be 0
    expect(methodBody).toContain('balance');
    expect(methodBody).toContain('non-zero balance');
  });

  it('AccountService::delete() checks countSlips > 0 before deleting', () => {
    const source = fs.readFileSync(path.resolve('src/Services/AccountService.php'), 'utf-8');
    const methodBody = extractMethodBody(source, 'delete');
    expect(methodBody).not.toBeNull();

    // Guard: no linked slips
    expect(methodBody).toContain('countSlips');
    expect(methodBody).toContain('linked transactions');
  });

  it('AccountService::delete() calls repo->delete and audit->log on success', () => {
    const source = fs.readFileSync(path.resolve('src/Services/AccountService.php'), 'utf-8');
    const methodBody = extractMethodBody(source, 'delete');
    expect(methodBody).not.toBeNull();

    expect(methodBody).toContain('repo->delete');
    expect(methodBody).toContain('audit->log');
  });

  // ItemService::deleteType() guard checks
  it('ItemService::deleteType() checks countItemsForType > 0 before deleting', () => {
    const source = fs.readFileSync(path.resolve('src/Services/ItemService.php'), 'utf-8');
    const methodBody = extractMethodBody(source, 'deleteType');
    expect(methodBody).not.toBeNull();

    // Guard: no items using this type
    expect(methodBody).toContain('countItemsForType');
    expect(methodBody).toContain('in use by active items');
  });

  it('ItemService::deleteType() calls repo->deleteType and audit->log on success', () => {
    const source = fs.readFileSync(path.resolve('src/Services/ItemService.php'), 'utf-8');
    const methodBody = extractMethodBody(source, 'deleteType');
    expect(methodBody).not.toBeNull();

    expect(methodBody).toContain('repo->deleteType');
    expect(methodBody).toContain('audit->log');
  });

  // PeopleService::delete() guard checks
  it('PeopleService::delete() checks countTransactionsForPayee > 0 before deleting', () => {
    const source = fs.readFileSync(path.resolve('src/Services/PeopleService.php'), 'utf-8');
    const methodBody = extractMethodBody(source, 'delete');
    expect(methodBody).not.toBeNull();

    // Guard: no transactions for this payee
    expect(methodBody).toContain('countTransactionsForPayee');
    expect(methodBody).toContain('has transactions');
  });

  it('PeopleService::delete() calls peopleRepo->delete and audit->log on success', () => {
    const source = fs.readFileSync(path.resolve('src/Services/PeopleService.php'), 'utf-8');
    const methodBody = extractMethodBody(source, 'delete');
    expect(methodBody).not.toBeNull();

    expect(methodBody).toContain('peopleRepo->delete');
    expect(methodBody).toContain('audit->log');
  });

  it('PeopleService::delete() already uses a transaction for the delete+audit portion', () => {
    const source = fs.readFileSync(path.resolve('src/Services/PeopleService.php'), 'utf-8');
    const methodBody = extractMethodBody(source, 'delete');
    expect(methodBody).not.toBeNull();

    // PeopleService::delete() already has beginTransaction for the delete portion
    expect(methodBody).toContain('beginTransaction');
    expect(methodBody).toContain('commit');
    expect(methodBody).toContain('rollBack');
  });

  // Non-delete operations should remain unchanged
  it('AccountService non-delete methods (list, getById, create, update) exist', () => {
    const source = fs.readFileSync(path.resolve('src/Services/AccountService.php'), 'utf-8');
    expect(/function\s+list\s*\(/m.test(source)).toBe(true);
    expect(/function\s+getById\s*\(/m.test(source)).toBe(true);
    expect(/function\s+create\s*\(/m.test(source)).toBe(true);
    expect(/function\s+update\s*\(/m.test(source)).toBe(true);
  });

  it('ItemService non-delete type methods (listTypes, createType, updateType) exist', () => {
    const source = fs.readFileSync(path.resolve('src/Services/ItemService.php'), 'utf-8');
    expect(/function\s+listTypes\s*\(/m.test(source)).toBe(true);
    expect(/function\s+createType\s*\(/m.test(source)).toBe(true);
    expect(/function\s+updateType\s*\(/m.test(source)).toBe(true);
  });

  it('property: for all 3 delete methods, guard checks and repo+audit calls exist', () => {
    const deleteMethods = [
      {
        file: 'src/Services/AccountService.php',
        method: 'delete',
        guards: ['balance', 'countSlips'],
        repoCall: 'repo->delete',
      },
      {
        file: 'src/Services/ItemService.php',
        method: 'deleteType',
        guards: ['countItemsForType'],
        repoCall: 'repo->deleteType',
      },
      {
        file: 'src/Services/PeopleService.php',
        method: 'delete',
        guards: ['countTransactionsForPayee'],
        repoCall: 'peopleRepo->delete',
      },
    ];

    fc.assert(
      fc.property(
        fc.integer({ min: 0, max: deleteMethods.length - 1 }),
        (index) => {
          const { file, method, guards, repoCall } = deleteMethods[index];
          const source = fs.readFileSync(path.resolve(file), 'utf-8');
          const methodBody = extractMethodBody(source, method);
          expect(methodBody).not.toBeNull();

          // All guard checks must exist
          for (const guard of guards) {
            expect(methodBody).toContain(guard);
          }

          // Repo call and audit must exist
          expect(methodBody).toContain(repoCall);
          expect(methodBody).toContain('audit->log');
        }
      ),
      { numRuns: deleteMethods.length }
    );
  });
});
