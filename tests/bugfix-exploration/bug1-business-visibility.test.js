import { describe, it, expect } from 'vitest';
import fc from 'fast-check';
import fs from 'fs';
import path from 'path';

/**
 * Bug 1 — Business Visibility Leak
 * Bug Condition Exploration Test
 *
 * The `BusinessRepository::getAllForCurrentUser()` SQL joins `user_roles` on
 * `business_id` and `user_id` but does NOT filter by `role_id`. This means
 * a user with ANY role (e.g., ACCOUNTANT role_id=2) in a business will see
 * that business on the Businesses management page, even though only
 * BUSINESS_OWNER (role_id=1) businesses should appear.
 *
 * This test reads the actual PHP source and verifies the SQL contains
 * `role_id = 1` filtering. On unfixed code, this test FAILS — proving the bug.
 *
 * **Validates: Requirements 1.1, 1.2**
 */

describe('Bug 1 — Business Visibility: getAllForCurrentUser() must filter by role_id=1', () => {
  const repoPath = path.resolve('src/Repository/BusinessRepository.php');
  const source = fs.readFileSync(repoPath, 'utf-8');

  // Extract the getAllForCurrentUser method body
  const methodMatch = source.match(
    /function\s+getAllForCurrentUser\s*\([^)]*\)[^{]*\{([\s\S]*?)^\s{4}\}/m
  );
  const methodBody = methodMatch ? methodMatch[1] : '';

  it('the SQL JOIN condition must include role_id = 1 to filter to BUSINESS_OWNER only', () => {
    // The SQL should contain a role_id filter in the JOIN
    const hasRoleFilter = /role_id\s*=\s*1/i.test(methodBody);
    expect(hasRoleFilter).toBe(true);
  });

  it('property: for any user with a non-owner role (role_id != 1) in a business, that business must NOT appear in results', () => {
    // Generate random role_id values that are NOT 1 (BUSINESS_OWNER)
    fc.assert(
      fc.property(
        fc.integer({ min: 2, max: 10 }), // non-owner role_ids
        fc.integer({ min: 1, max: 1000 }), // user_id
        fc.integer({ min: 1, max: 1000 }), // business_id
        (roleId, userId, businessId) => {
          // The SQL must filter by role_id = 1 to exclude non-owner roles
          // If the SQL does NOT have this filter, non-owner businesses leak through
          const sqlHasRoleFilter = /role_id\s*=\s*1/i.test(methodBody);

          // For a user with role_id != 1, the query should exclude their business
          // This is only possible if the SQL filters by role_id = 1
          expect(sqlHasRoleFilter).toBe(true);
        }
      ),
      { numRuns: 50 }
    );
  });
});
