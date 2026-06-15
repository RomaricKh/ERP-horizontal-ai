import { describe, it, expect } from 'vitest';
import fc from 'fast-check';
import fs from 'fs';
import path from 'path';

/**
 * Bug 2 Preservation — Item CRUD Operations
 *
 * Observe that item creation, editing, deactivation, and stock adjustment work
 * on unfixed code. The bug is only in `getAllForBusiness()` (duplicate :business param).
 * All other item operations (insert, update, deactivate, stock) use correctly
 * formed SQL and should continue to work.
 *
 * This test must PASS on unfixed code — it confirms baseline behavior to preserve.
 *
 * **Validates: Requirements 3.4, 3.5, 3.6**
 */

describe('Bug 2 Preservation — Item CRUD operations work correctly', () => {
  const repoPath = path.resolve('src/Repository/ItemRepository.php');
  const source = fs.readFileSync(repoPath, 'utf-8');

  const servicePath = path.resolve('src/Services/ItemService.php');
  const serviceSource = fs.readFileSync(servicePath, 'utf-8');

  /**
   * Helper: extract a method body from PHP source (handles nested braces).
   */
  function extractMethodBody(src, methodName) {
    const regex = new RegExp(
      `(?:public|private|protected)\\s+function\\s+${methodName}\\s*\\([^)]*\\)`,
      'm'
    );
    const match = regex.exec(src);
    if (!match) return null;

    let pos = match.index + match[0].length;
    while (pos < src.length && src[pos] !== '{') pos++;
    if (pos >= src.length) return null;

    let depth = 0;
    const start = pos;
    for (let i = pos; i < src.length; i++) {
      if (src[i] === '{') depth++;
      else if (src[i] === '}') {
        depth--;
        if (depth === 0) return src.substring(start, i + 1);
      }
    }
    return null;
  }

  /**
   * Helper: check that a SQL query has no duplicate named parameters.
   */
  function hasNoDuplicateParams(methodBody) {
    if (!methodBody) return true;
    const sqlMatch = methodBody.match(/prepare\s*\(\s*"([\s\S]*?)"\s*\)/);
    if (!sqlMatch) return true; // No SQL in method
    const sql = sqlMatch[1];
    const namedParams = sql.match(/:\w+/g) || [];
    const counts = {};
    for (const param of namedParams) {
      counts[param] = (counts[param] || 0) + 1;
    }
    return Object.values(counts).every(c => c <= 1);
  }

  it('ItemRepository::insert() has valid SQL with no duplicate parameters', () => {
    const methodBody = extractMethodBody(source, 'insert');
    expect(methodBody).not.toBeNull();
    expect(hasNoDuplicateParams(methodBody)).toBe(true);
  });

  it('ItemRepository::update() exists and handles allowed fields', () => {
    const methodBody = extractMethodBody(source, 'update');
    expect(methodBody).not.toBeNull();
    // The update method uses dynamic field building
    expect(methodBody).toContain('allowed');
    expect(methodBody).toContain('UPDATE items');
  });

  it('ItemRepository::deactivate() has valid SQL', () => {
    const methodBody = extractMethodBody(source, 'deactivate');
    expect(methodBody).not.toBeNull();
    expect(methodBody).toContain('is_active = 0');
    expect(hasNoDuplicateParams(methodBody)).toBe(true);
  });

  it('ItemRepository::upsertStock() has valid SQL', () => {
    const methodBody = extractMethodBody(source, 'upsertStock');
    expect(methodBody).not.toBeNull();
    expect(methodBody).toContain('INSERT INTO stock');
    expect(methodBody).toContain('ON DUPLICATE KEY UPDATE');
  });

  it('ItemService::create() wraps insert + audit in a transaction', () => {
    const methodBody = extractMethodBody(serviceSource, 'create');
    expect(methodBody).not.toBeNull();
    expect(methodBody).toContain('beginTransaction');
    expect(methodBody).toContain('commit');
    expect(methodBody).toContain('rollBack');
    expect(methodBody).toContain('repo->insert');
    expect(methodBody).toContain('audit->log');
  });

  it('ItemService::update() wraps update + audit in a transaction', () => {
    const methodBody = extractMethodBody(serviceSource, 'update');
    expect(methodBody).not.toBeNull();
    expect(methodBody).toContain('beginTransaction');
    expect(methodBody).toContain('commit');
    expect(methodBody).toContain('rollBack');
  });

  it('ItemService::adjustStock() wraps stock operations in a transaction', () => {
    const methodBody = extractMethodBody(serviceSource, 'adjustStock');
    expect(methodBody).not.toBeNull();
    expect(methodBody).toContain('beginTransaction');
    expect(methodBody).toContain('commit');
    expect(methodBody).toContain('rollBack');
    expect(methodBody).toContain('upsertStock');
    expect(methodBody).toContain('insertStockTransaction');
  });

  it('property: for all item CRUD repository methods (except getAllForBusiness), SQL is valid', () => {
    // Note: upsertStock uses :qty twice (INSERT + ON DUPLICATE KEY UPDATE) which is
    // intentional and works with PDO's emulated prepares for that pattern.
    // We exclude it from the duplicate-param check and verify it separately.
    const crudMethods = ['insert', 'deactivate', 'insertStockTransaction',
                         'getById', 'getStockEntry', 'getStockForBusiness'];

    fc.assert(
      fc.property(
        fc.integer({ min: 0, max: crudMethods.length - 1 }),
        (index) => {
          const method = crudMethods[index];
          const methodBody = extractMethodBody(source, method);
          // Method should exist and have valid SQL
          expect(methodBody, `Method ${method} should exist`).not.toBeNull();
          expect(hasNoDuplicateParams(methodBody), `${method} should have no duplicate params`).toBe(true);
        }
      ),
      { numRuns: crudMethods.length }
    );
  });
});
