# Implementation Plan: People Module Review

## Overview

Refactor the People module into proper 3-layer architecture by extracting InsightsRepository/Service and PayeeStatementRepository/Service, consolidating junction table ownership, adding comprehensive input validation, implementing tiered exception handling with structured logging, fixing N+1 queries, adding module-gating to type queries, and improving frontend modals. Property-based tests validate correctness properties throughout.

## Tasks

- [x] 1. Create shared infrastructure and repository foundations
  - [x] 1.1 Add `verifyPayeeBelongsToBusiness()` method to PeopleRepository
    - Implement the shared verification query that returns the payee record (id, people_id, balance, payee_name) or null
    - Add region comments grouping existing methods by concern: Person CRUD, Payee Lifecycle, Junction Management, Transaction Queries
    - _Requirements: 11.3, 13.5, 17.3_

  - [x] 1.2 Enhance `PeopleRepository::countTransactionsForPayee()` to include salaries and profit_distributions
    - Add `salaries.payee_id` and `profit_distributions.partner_id` to the union count query
    - Ensure the method returns descriptive info about which table blocks deletion
    - _Requirements: 9.1, 9.2, 9.3_

  - [x] 1.3 Add `logError()` helper method to PeopleController (or a shared trait/base)
    - Implement structured logging: `[People] {method}: {Class} - {message} (user={id}, business={id})`
    - Log stack trace on a separate `error_log()` call
    - Use `AuthContext::userId()` and `AuthContext::businessId()` with 'unknown' fallback
    - _Requirements: 16.1, 16.2, 16.3_

- [x] 2. Create InsightsRepository and InsightsService
  - [x] 2.1 Create `src/Repository/InsightsRepository.php`
    - Extract all SQL from InsightsController into repository methods: `getSupplierItemInsights`, `getCustomerItemInsights`, `getCustomerInvoiceFallback`, `getGeneralInsights`, `getPartnerInsights`, `getCustomerItems`
    - Accept PDO via constructor; all methods take `$payeeId` and `$businessId` parameters
    - Replace N+1 per-item loop with window-function query (ROW_NUMBER) for last-price in supplier and customer item insights
    - _Requirements: 4.1, 4.4, 10.1, 10.2, 10.3_

  - [x] 2.2 Create `src/Services/InsightsService.php`
    - Instantiate InsightsRepository and PeopleRepository in constructor
    - Implement `requirePayee()` private method using `PeopleRepository::verifyPayeeBelongsToBusiness()`
    - Implement `getSupplierInsights`, `getCustomerInsights`, `getGeneralInsights`, `getPartnerInsights`, `getCustomerItems` — each calls `requirePayee()` first
    - _Requirements: 4.2, 11.1, 11.3, 17.1_

  - [x] 2.3 Refactor `InsightsController` to use InsightsService
    - Remove all inline PDO/SQL code from controller methods
    - Instantiate InsightsService in constructor
    - Delegate data retrieval to service methods
    - Implement tiered exception handling: InvalidArgumentException → 422, RuntimeException → 422, Throwable → 500 (generic message)
    - Add `logError()` calls before all error responses
    - _Requirements: 2.4, 2.6, 3.1, 3.4, 4.3, 4.5, 17.1_

  - [x] 2.4 Write property test: Error response never leaks internal messages (InsightsController)
    - **Property 1: Error response never leaks internal exception messages**
    - **Validates: Requirements 2.4**
    - Test file: `tests/Property/PeopleInsightsErrorHandlingPropertyTest.php`

  - [x] 2.5 Write property test: Payee business verification rejects cross-business access
    - **Property 8: Payee business verification rejects cross-business access**
    - **Validates: Requirements 11.1, 11.2, 11.4**
    - Test file: `tests/Property/PayeeVerificationPropertyTest.php`

- [x] 3. Create PayeeStatementRepository and PayeeStatementService
  - [x] 3.1 Create `src/Repository/PayeeStatementRepository.php`
    - Extract `getTransactions()` method: multi-table UNION query (POs, invoices, expenses, salaries, payments, collections, debit/credit notes, profit distributions) sorted by date
    - Extract `calculateOpeningBalance()` method: signed sum of pre-period transactions
    - Accept PDO via constructor; scope by `$payeeId` and `$businessId`
    - _Requirements: 5.1, 5.5_

  - [x] 3.2 Create `src/Services/PayeeStatementService.php`
    - Instantiate PayeeStatementRepository and PeopleRepository in constructor
    - Implement `requirePayee()` using shared verification method
    - Implement `validateDateRange()` with format/calendar/logic checks
    - Implement `getStatement()` orchestrating: verify payee → validate dates → get opening balance → get transactions → compute running balances
    - Implement `computeRunningBalances()` preserving existing signed debit/credit conventions
    - _Requirements: 5.2, 5.5, 11.2, 12.1, 12.2_

  - [x] 3.3 Refactor `PayeeStatementController` to use PayeeStatementService
    - Remove private `getTransactions()` and `calculateOpeningBalance()` methods
    - Instantiate PayeeStatementService in constructor
    - Retain only HTTP concerns: JSON response, CSV streaming, PDF generation
    - Implement tiered exception handling with structured logging
    - _Requirements: 2.5, 2.6, 5.3, 5.4, 12.3, 17.2_

  - [x] 3.4 Write property test: Running balance computation is algebraically correct
    - **Property 10: Running balance computation is algebraically correct**
    - **Validates: Requirements 5.5**
    - Test file: `tests/Property/StatementBalancePropertyTest.php`

  - [x] 3.5 Write property test: Date validation rejects malformed and illogical ranges
    - **Property 9: Date validation rejects malformed and illogical ranges**
    - **Validates: Requirements 12.1, 12.2, 12.3**
    - Test file: `tests/Property/DateValidationPropertyTest.php`

- [x] 4. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [x] 5. Consolidate junction table ownership and add input validation to PeopleService
  - [x] 5.1 Remove `PayeeTypeRepository::setTypesForPerson()` method
    - Verify no other callers invoke this method (search codebase)
    - Remove the method, retaining read-only queries: `getTypesForPerson`, `personHasType`, `findPeopleByType`, `countUsage`
    - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.5_

  - [x] 5.2 Add comprehensive input validation to PeopleService
    - Implement `validatePersonInput(array $data, bool $isCreate = true): void`
    - Validate: name (non-empty after trim, max 255), email (filter_var if provided), phone (allowed chars + max 50), payee_type_ids (each positive integer)
    - Remove duplicate `payee_type_ids` empty-check in `create()` so validation appears once
    - Throw `InvalidArgumentException` with descriptive messages for each violation
    - _Requirements: 1.1, 1.2, 7.1, 7.2, 7.3, 7.4, 7.5, 7.6_

  - [x] 5.3 Add payee type ownership verification to PeopleService
    - Implement `validatePayeeTypeOwnership(array $typeIds, int $tenantId): void`
    - Query `payee_types` to verify each ID exists and belongs to current tenant (is_system=1 OR tenant_id matches)
    - Throw `InvalidArgumentException` identifying invalid IDs on failure
    - Call within transaction, before junction insert
    - _Requirements: 8.1, 8.2, 8.3_

  - [x] 5.4 Write property test: Input validation rejects all invalid person data
    - **Property 4: Input validation rejects all invalid person data**
    - **Validates: Requirements 7.1, 7.2, 7.3, 7.4, 7.5, 7.6**
    - Test file: `tests/Property/PeopleInputValidationPropertyTest.php`

  - [x] 5.5 Write property test: Invalid payee_type_ids input is always rejected
    - **Property 5: Invalid payee_type_ids input is always rejected**
    - **Validates: Requirements 1.2**
    - Test file: `tests/Property/PeopleInputValidationPropertyTest.php` (additional test method)

  - [x] 5.6 Write property test: Cross-tenant payee type assignment is rejected
    - **Property 6: Cross-tenant payee type assignment is rejected**
    - **Validates: Requirements 8.1, 8.2**
    - Test file: `tests/Property/PayeeTypeOwnershipPropertyTest.php`

  - [x] 5.7 Write property test: Delete is blocked when any transaction exists
    - **Property 7: Delete is blocked when any transaction exists**
    - **Validates: Requirements 9.1, 9.2, 9.3**
    - Test file: `tests/Property/DeleteSafetyPropertyTest.php`

- [x] 6. Add tiered exception handling to PeopleController
  - [x] 6.1 Refactor PeopleController::store() and PeopleController::update() error handling
    - Replace single `catch (\Throwable)` with tiered: InvalidArgumentException → 422 (with message), RuntimeException → 422 (with message), Throwable → 500 (generic "Failed to create/update person")
    - Remove `$e->getMessage()` from generic Throwable responses
    - Add `logError()` call in every catch block
    - _Requirements: 2.1, 2.2, 2.6, 3.1, 3.2, 3.3, 3.4_

  - [x] 6.2 Refactor PeopleController::storePayeeType() error handling
    - Apply same tiered exception pattern
    - Return generic message for unexpected Throwable
    - Add structured logging
    - _Requirements: 2.3, 2.6, 3.1, 3.4_

  - [x] 6.3 Write property test: Exception type determines HTTP status code
    - **Property 2: Exception type determines HTTP status code**
    - **Validates: Requirements 3.1, 3.2, 3.3, 3.4**
    - Test file: `tests/Property/PeopleControllerErrorHandlingPropertyTest.php`

  - [x] 6.4 Write property test: All caught exceptions are logged with structured context
    - **Property 3: All caught exceptions are logged with structured context**
    - **Validates: Requirements 2.6, 16.1, 16.2, 16.3**
    - Test file: `tests/Property/PeopleControllerErrorHandlingPropertyTest.php` (additional test method)

- [x] 7. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [x] 8. Add module-gating to payee type queries
  - [x] 8.1 Add module-gated filtering to payee type listing endpoint
    - Modify the `GET /people/payee-types` query to filter by active modules: include types where `module_code IS NULL` or `module_code` is in the business's active module set
    - Ensure person create/edit form type selectors apply the same filter
    - _Requirements: 14.1, 14.2_

  - [x] 8.2 Add module-gated filtering to `PeopleRepository::findAllByBusiness()` type joins
    - When joining to `payee_types` in the people listing, exclude types linked to inactive modules
    - _Requirements: 14.3_

  - [x] 8.3 Write property test: Module-gated type filtering excludes inactive modules
    - **Property 11: Module-gated type filtering excludes inactive modules**
    - **Validates: Requirements 14.1, 14.3**
    - Test file: `tests/Property/ModuleGatingPropertyTest.php`

- [x] 9. Frontend improvements
  - [x] 9.1 Add client-side validation to person form modal (`personForm.modal.js`)
    - Validate email format with JS regex
    - Validate phone characters (digits, spaces, dashes, plus, parens only)
    - Show inline validation feedback using Bootstrap `.is-invalid` + `.invalid-feedback`
    - Ensure `unmount()` removes all event listeners (search input, document click handler)
    - _Requirements: 15.1, 15.2, 15.3_

  - [x] 9.2 Add client-side validation to payee types modal (`payeeTypes.modal.js`)
    - Block submission when name is empty or whitespace-only
    - Show inline validation feedback on the name input
    - Ensure `unmount()` cleanup is complete (event listener removal)
    - _Requirements: 15.2, 15.4_

  - [x] 9.3 Write JS property test: Client-side validation blocks invalid form submissions
    - **Property 12: Client-side validation blocks invalid form submissions**
    - **Validates: Requirements 15.3, 15.4**
    - Test file: `tests/people-module-review/frontend-validation.test.js`
    - Use Vitest + fast-check to generate random form states and verify validation behavior

- [x] 10. Final checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP
- Each task references specific requirements for traceability
- Checkpoints ensure incremental validation
- Property tests validate universal correctness properties from the design document
- Unit tests validate specific examples and edge cases
- PHP tests use PHPUnit; JS tests use Vitest + fast-check
- The refactoring preserves all existing API response shapes and behavior

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1", "1.2", "1.3"] },
    { "id": 1, "tasks": ["2.1", "3.1", "5.1"] },
    { "id": 2, "tasks": ["2.2", "3.2", "5.2", "5.3"] },
    { "id": 3, "tasks": ["2.3", "3.3", "6.1", "6.2"] },
    { "id": 4, "tasks": ["2.4", "2.5", "3.4", "3.5", "5.4", "5.5", "5.6", "5.7", "6.3", "6.4"] },
    { "id": 5, "tasks": ["8.1", "8.2"] },
    { "id": 6, "tasks": ["8.3", "9.1", "9.2"] },
    { "id": 7, "tasks": ["9.3"] }
  ]
}
```
