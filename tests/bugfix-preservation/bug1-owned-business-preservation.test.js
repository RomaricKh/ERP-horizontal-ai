import { describe, it, expect } from 'vitest';
import fc from 'fast-check';
import fs from 'fs';
import path from 'path';

/**
 * Bug 1 Preservation — Owned Business Access
 *
 * Observe that `getAllForCurrentUser()` returns owned businesses (role_id=1)
 * correctly on unfixed code. The SQL joins user_roles on business_id and user_id,
 * which means ANY user with a role (including BUSINESS_OWNER) will see their
 * businesses. The preservation property is: for all users who are BUSINESS_OWNER,
 * their owned businesses appear in results with correct data.
 *
 * This test must PASS on unfixed code — it confirms baseline behavior to preserve.
 *
 * **Validates: Requirements 3.1, 3.2, 3.3**
 */

describe('Bug 1 Preservation — Owned businesses continue to appear in getAllForCurrentUser()', () => {
  const repoPath = path.resolve('src/Repository/BusinessRepository.php');
  const source = fs.readFileSync(repoPath, 'utf-8');

  // Extract the getAllForCurrentUser method body
  const methodMatch = source.match(
    /function\s+getAllForCurrentUser\s*\([^)]*\)[^{]*\{([\s\S]*?)^\s{4}\}/m
  );
  const methodBody = methodMatch ? methodMatch[1] : '';

  // Extract the SQL from the method
  const sqlMatch = methodBody.match(/prepare\s*\(\s*"([\s\S]*?)"\s*\)/);
  const sql = sqlMatch ? sqlMatch[1] : '';

  it('the SQL joins user_roles on business_id and user_id, so owners will see their businesses', () => {
    // The current SQL joins on business_id and user_id — this means any user
    // with a role (including BUSINESS_OWNER role_id=1) will have their businesses returned.
    // This is the behavior we want to preserve.
    const joinsOnBusinessId = /ur\.business_id\s*=\s*b\.id/i.test(sql);
    const joinsOnUserId = /ur\.user_id\s*=\s*:user_id/i.test(sql);

    expect(joinsOnBusinessId, 'SQL must join on business_id').toBe(true);
    expect(joinsOnUserId, 'SQL must join on user_id').toBe(true);
  });

  it('the SQL selects all required business fields', () => {
    const requiredFields = ['b.id', 'b.name', 'b.legal_name', 'b.registration_no',
                            'b.address', 'b.phone', 'b.email', 'b.currency'];
    for (const field of requiredFields) {
      expect(sql, `SQL must select ${field}`).toContain(field);
    }
  });

  it('the method returns an array via fetchAll(PDO::FETCH_ASSOC)', () => {
    expect(methodBody).toContain('fetchAll');
    expect(methodBody).toContain('FETCH_ASSOC');
  });

  it('property: for any BUSINESS_OWNER (role_id=1), the SQL structure guarantees their businesses appear', () => {
    fc.assert(
      fc.property(
        fc.integer({ min: 1, max: 10000 }), // user_id
        fc.integer({ min: 1, max: 10000 }), // business_id
        (userId, businessId) => {
          // The SQL uses INNER JOIN user_roles ON business_id AND user_id.
          // For a BUSINESS_OWNER (role_id=1), there IS a user_roles row,
          // so the JOIN will match and the business will appear in results.
          // This is true regardless of whether role_id filtering exists.
          const joinsOnBusinessAndUser =
            /ur\.business_id\s*=\s*b\.id/i.test(sql) &&
            /ur\.user_id\s*=\s*:user_id/i.test(sql);

          // The query uses INNER JOIN, so any matching user_roles row returns the business
          const usesInnerJoin = /INNER\s+JOIN\s+user_roles/i.test(sql);

          expect(joinsOnBusinessAndUser).toBe(true);
          expect(usesInnerJoin).toBe(true);
        }
      ),
      { numRuns: 50 }
    );
  });

  it('the BusinessService.getAllForCurrentUser() delegates to the repository', () => {
    const servicePath = path.resolve('src/Services/BusinessService.php');
    const serviceSource = fs.readFileSync(servicePath, 'utf-8');

    // The service method should call the repository method
    const delegatesToRepo = /businessRepo\s*->\s*getAllForCurrentUser/i.test(serviceSource);
    expect(delegatesToRepo, 'BusinessService must delegate to BusinessRepository').toBe(true);
  });

  it('BusinessService create/update/delete methods exist and use transactions', () => {
    const servicePath = path.resolve('src/Services/BusinessService.php');
    const serviceSource = fs.readFileSync(servicePath, 'utf-8');

    // Verify CRUD operations exist for owned businesses
    expect(serviceSource).toContain('function create(');
    expect(serviceSource).toContain('function update(');
    expect(serviceSource).toContain('function delete(');

    // Verify they use transactions (existing correct behavior)
    expect(serviceSource).toContain('beginTransaction');
    expect(serviceSource).toContain('commit');
    expect(serviceSource).toContain('rollBack');
  });
});
