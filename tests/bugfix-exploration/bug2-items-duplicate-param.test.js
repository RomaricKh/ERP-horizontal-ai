import { describe, it, expect } from 'vitest';
import fc from 'fast-check';
import fs from 'fs';
import path from 'path';

/**
 * Bug 2 — Items List Not Rendering (Duplicate PDO Parameter)
 * Bug Condition Exploration Test
 *
 * `ItemRepository::getAllForBusiness()` uses the named parameter `:business`
 * three times in the SQL query. With `PDO::ATTR_EMULATE_PREPARES => false`,
 * native prepared statements reject duplicate named parameters, causing
 * `SQLSTATE[HY093]`.
 *
 * This test reads the actual PHP source and verifies that no named parameter
 * appears more than once in the SQL. On unfixed code, this test FAILS.
 *
 * **Validates: Requirements 1.3**
 */

describe('Bug 2 — Items Query: getAllForBusiness() must not have duplicate named parameters', () => {
  const repoPath = path.resolve('src/Repository/ItemRepository.php');
  const source = fs.readFileSync(repoPath, 'utf-8');

  // Extract the getAllForBusiness method body
  const methodMatch = source.match(
    /function\s+getAllForBusiness\s*\([^)]*\)[^{]*\{([\s\S]*?)^\s{4}\}/m
  );
  const methodBody = methodMatch ? methodMatch[1] : '';

  // Extract the SQL string from the method
  const sqlMatch = methodBody.match(/prepare\s*\(\s*"([\s\S]*?)"\s*\)/);
  const sql = sqlMatch ? sqlMatch[1] : '';

  it('the SQL query must not use the same named parameter more than once', () => {
    // Find all named parameters in the SQL (e.g., :business, :tenant)
    const namedParams = sql.match(/:\w+/g) || [];

    // Count occurrences of each parameter
    const counts = {};
    for (const param of namedParams) {
      counts[param] = (counts[param] || 0) + 1;
    }

    // Each named parameter should appear at most once
    for (const [param, count] of Object.entries(counts)) {
      expect(count, `Parameter ${param} appears ${count} times — native PDO rejects duplicates`).toBe(1);
    }
  });

  it('property: for any tenant/business ID pair, the SQL must use unique named parameters', () => {
    fc.assert(
      fc.property(
        fc.integer({ min: 1, max: 10000 }),
        fc.integer({ min: 1, max: 10000 }),
        (tenantId, businessId) => {
          // The SQL is static — it doesn't change per input.
          // But the bug triggers on EVERY request because the duplicate param is in the SQL itself.
          const namedParams = sql.match(/:\w+/g) || [];
          const counts = {};
          for (const param of namedParams) {
            counts[param] = (counts[param] || 0) + 1;
          }

          // No parameter should appear more than once
          const hasDuplicates = Object.values(counts).some(c => c > 1);
          expect(hasDuplicates).toBe(false);
        }
      ),
      { numRuns: 20 }
    );
  });
});
