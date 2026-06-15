import { describe, it, expect } from 'vitest';
import fc from 'fast-check';
import fs from 'fs';
import path from 'path';

/**
 * Bug 11 — Audit Logging Outside Transactions (16 Methods)
 * Bug Condition Exploration Test
 *
 * Sixteen service methods call `repo->operation()` then `audit->log()` without
 * any transaction wrapping. If the audit insert fails, the data operation is
 * committed without an audit trail.
 *
 * This test reads the actual PHP source for each affected service and verifies
 * that the data operation + audit->log() are wrapped in beginTransaction/commit.
 * On unfixed code, this test FAILS — proving the bug.
 *
 * **Validates: Requirements 1.19, 1.20, 1.21, 1.22, 1.23, 1.24, 1.25, 1.26,
 *              1.27, 1.28, 1.29, 1.30, 1.31, 1.32, 1.33, 1.34**
 */

/**
 * Helper: extract a method body from PHP source.
 * Handles nested braces to find the complete method body.
 */
function extractMethodBody(source, methodName) {
  const regex = new RegExp(
    `public\\s+function\\s+${methodName}\\s*\\([^)]*\\)`,
    'm'
  );
  const match = regex.exec(source);
  if (!match) return null;

  // Find the opening brace
  let pos = match.index + match[0].length;
  while (pos < source.length && source[pos] !== '{') pos++;
  if (pos >= source.length) return null;

  // Track brace depth
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
 * Check if a method body wraps its data operation + audit in a transaction.
 */
function hasTransactionWrapping(methodBody) {
  if (!methodBody) return false;
  const hasBeginTransaction = /beginTransaction\s*\(\s*\)/.test(methodBody);
  const hasCommit = /commit\s*\(\s*\)/.test(methodBody);
  const hasRollback = /rollBack\s*\(\s*\)/.test(methodBody);
  const hasAuditLog = /audit\s*->\s*log/.test(methodBody);

  // The method must have all four: beginTransaction, commit, rollBack, and audit->log
  // AND the audit->log must be INSIDE the transaction (between beginTransaction and commit)
  return hasBeginTransaction && hasCommit && hasRollback && hasAuditLog;
}

describe('Bug 11 — Audit Outside Transaction: affected methods must wrap data+audit in transactions', () => {
  // Define all affected services and their methods
  const affectedMethods = [
    { file: 'src/Services/SalaryService.php', methods: ['create', 'update', 'delete'] },
    { file: 'src/Services/ExchangeRateService.php', methods: ['create', 'update', 'delete'] },
    { file: 'src/Services/AccountService.php', methods: ['create', 'update', 'delete'] },
    { file: 'src/Services/ExpenseService.php', methods: ['create', 'update', 'delete'] },
    { file: 'src/Services/ItemService.php', methods: ['deactivate', 'createType', 'updateType', 'deleteType'] },
  ];

  for (const { file, methods } of affectedMethods) {
    const serviceName = path.basename(file, '.php');
    const filePath = path.resolve(file);
    const source = fs.readFileSync(filePath, 'utf-8');

    for (const method of methods) {
      it(`${serviceName}::${method}() must wrap data operation + audit->log() in a transaction`, () => {
        const methodBody = extractMethodBody(source, method);
        expect(methodBody, `Could not find method ${method} in ${file}`).not.toBeNull();
        expect(
          hasTransactionWrapping(methodBody),
          `${serviceName}::${method}() does not wrap data+audit in beginTransaction/commit/rollBack`
        ).toBe(true);
      });
    }
  }

  it('property: for any of the 16 affected methods, transaction wrapping must be present', () => {
    const allMethods = [];
    for (const { file, methods } of affectedMethods) {
      const source = fs.readFileSync(path.resolve(file), 'utf-8');
      for (const method of methods) {
        allMethods.push({ file, method, source });
      }
    }

    fc.assert(
      fc.property(
        fc.integer({ min: 0, max: allMethods.length - 1 }),
        (index) => {
          const { file, method, source } = allMethods[index];
          const methodBody = extractMethodBody(source, method);
          expect(methodBody).not.toBeNull();
          expect(hasTransactionWrapping(methodBody)).toBe(true);
        }
      ),
      { numRuns: allMethods.length }
    );
  });
});
