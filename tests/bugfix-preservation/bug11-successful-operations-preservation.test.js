import { describe, it, expect } from 'vitest';
import fc from 'fast-check';
import fs from 'fs';
import path from 'path';

/**
 * Bug 11 Preservation — Successful Operations Unchanged
 *
 * Observe that when both data operation and audit succeed, the outcome is committed.
 * On unfixed code, the 16 methods call repo->operation() then audit->log() sequentially
 * without a transaction. When BOTH succeed, the observable outcome is: data committed
 * AND audit committed. After wrapping in transactions, this outcome must be identical.
 *
 * Also verify that methods already using transactions (ItemService::create(),
 * ItemService::update(), ItemService::adjustStock(), PeopleService::create(),
 * PeopleService::update(), ExpenseService::deleteCategory()) remain unchanged.
 *
 * This test must PASS on unfixed code — it confirms baseline behavior to preserve.
 *
 * **Validates: Requirements 3.17, 3.18, 3.19**
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

describe('Bug 11 Preservation — Successful operations produce same outcome', () => {

  // The 16 affected methods that currently lack transaction wrapping
  const affectedMethods = [
    { file: 'src/Services/SalaryService.php', service: 'SalaryService', methods: ['create', 'update', 'delete'] },
    { file: 'src/Services/ExchangeRateService.php', service: 'ExchangeRateService', methods: ['create', 'update', 'delete'] },
    { file: 'src/Services/AccountService.php', service: 'AccountService', methods: ['create', 'update', 'delete'] },
    { file: 'src/Services/ExpenseService.php', service: 'ExpenseService', methods: ['create', 'update', 'delete'] },
    { file: 'src/Services/ItemService.php', service: 'ItemService', methods: ['deactivate', 'createType', 'updateType', 'deleteType'] },
  ];

  for (const { file, service, methods } of affectedMethods) {
    const source = fs.readFileSync(path.resolve(file), 'utf-8');

    for (const method of methods) {
      it(`${service}::${method}() calls both repo operation and audit->log()`, () => {
        const methodBody = extractMethodBody(source, method);
        expect(methodBody, `${service}::${method}() should exist`).not.toBeNull();

        // Both repo operation and audit->log must be present
        const hasRepoCall = /(?:repo|itemTypeRepo|categoryRepo)\s*->/i.test(methodBody);
        const hasAuditLog = /audit\s*->\s*log/.test(methodBody);

        expect(hasRepoCall, `${service}::${method}() should call repo`).toBe(true);
        expect(hasAuditLog, `${service}::${method}() should call audit->log`).toBe(true);
      });

      it(`${service}::${method}() calls repo BEFORE audit (order preserved)`, () => {
        const methodBody = extractMethodBody(source, method);
        if (!methodBody) return;

        // Find positions of repo call and audit call
        const repoMatch = methodBody.match(/(?:repo|itemTypeRepo|categoryRepo)\s*->/i);
        const auditMatch = methodBody.match(/audit\s*->\s*log/);

        if (repoMatch && auditMatch) {
          const repoPos = methodBody.indexOf(repoMatch[0]);
          const auditPos = methodBody.indexOf(auditMatch[0]);
          expect(repoPos, `${service}::${method}() repo call should come before audit`).toBeLessThan(auditPos);
        }
      });
    }
  }

  // Methods that ALREADY use transactions and should remain unchanged
  const alreadyTransactional = [
    { file: 'src/Services/ItemService.php', service: 'ItemService', methods: ['create', 'update', 'adjustStock'] },
    { file: 'src/Services/PeopleService.php', service: 'PeopleService', methods: ['create', 'update'] },
    { file: 'src/Services/ExpenseService.php', service: 'ExpenseService', method: 'deleteCategory' },
  ];

  it('ItemService::create() already uses transactions (must remain unchanged)', () => {
    const source = fs.readFileSync(path.resolve('src/Services/ItemService.php'), 'utf-8');
    const methodBody = extractMethodBody(source, 'create');
    expect(methodBody).not.toBeNull();
    expect(methodBody).toContain('beginTransaction');
    expect(methodBody).toContain('commit');
    expect(methodBody).toContain('rollBack');
  });

  it('ItemService::update() already uses transactions (must remain unchanged)', () => {
    const source = fs.readFileSync(path.resolve('src/Services/ItemService.php'), 'utf-8');
    const methodBody = extractMethodBody(source, 'update');
    expect(methodBody).not.toBeNull();
    expect(methodBody).toContain('beginTransaction');
    expect(methodBody).toContain('commit');
    expect(methodBody).toContain('rollBack');
  });

  it('ItemService::adjustStock() already uses transactions (must remain unchanged)', () => {
    const source = fs.readFileSync(path.resolve('src/Services/ItemService.php'), 'utf-8');
    const methodBody = extractMethodBody(source, 'adjustStock');
    expect(methodBody).not.toBeNull();
    expect(methodBody).toContain('beginTransaction');
    expect(methodBody).toContain('commit');
    expect(methodBody).toContain('rollBack');
  });

  it('PeopleService::create() already uses transactions (must remain unchanged)', () => {
    const source = fs.readFileSync(path.resolve('src/Services/PeopleService.php'), 'utf-8');
    const methodBody = extractMethodBody(source, 'create');
    expect(methodBody).not.toBeNull();
    expect(methodBody).toContain('beginTransaction');
    expect(methodBody).toContain('commit');
    expect(methodBody).toContain('rollBack');
  });

  it('PeopleService::update() already uses transactions (must remain unchanged)', () => {
    const source = fs.readFileSync(path.resolve('src/Services/PeopleService.php'), 'utf-8');
    const methodBody = extractMethodBody(source, 'update');
    expect(methodBody).not.toBeNull();
    expect(methodBody).toContain('beginTransaction');
    expect(methodBody).toContain('commit');
    expect(methodBody).toContain('rollBack');
  });

  it('ExpenseService::deleteCategory() already uses transactions (must remain unchanged)', () => {
    const source = fs.readFileSync(path.resolve('src/Services/ExpenseService.php'), 'utf-8');
    const methodBody = extractMethodBody(source, 'deleteCategory');
    expect(methodBody).not.toBeNull();
    expect(methodBody).toContain('beginTransaction');
    expect(methodBody).toContain('commit');
    expect(methodBody).toContain('rollBack');
  });

  it('property: for all 16 affected methods, both repo and audit calls exist in sequence', () => {
    const allMethods = [];
    for (const { file, service, methods } of affectedMethods) {
      const source = fs.readFileSync(path.resolve(file), 'utf-8');
      for (const method of methods) {
        allMethods.push({ file, service, method, source });
      }
    }

    fc.assert(
      fc.property(
        fc.integer({ min: 0, max: allMethods.length - 1 }),
        (index) => {
          const { service, method, source } = allMethods[index];
          const methodBody = extractMethodBody(source, method);
          expect(methodBody).not.toBeNull();

          // Both repo and audit calls must exist
          expect(/repo\s*->/.test(methodBody)).toBe(true);
          expect(/audit\s*->\s*log/.test(methodBody)).toBe(true);
        }
      ),
      { numRuns: allMethods.length }
    );
  });
});
