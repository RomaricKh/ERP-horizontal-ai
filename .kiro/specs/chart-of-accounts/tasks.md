# Implementation Plan: Chart of Accounts (COA)

## Overview

This plan implements a full Chart of Accounts system with double-entry journaling, per-contact sub-accounts, cross-type netting, and integration with existing financial operations. The approach is bottom-up: database schema first, then repositories, services, controllers, routes, and finally frontend UI — ensuring each layer builds on the previous one with no orphaned code.

## Tasks

- [ ] 1. Database schema and migration
  - [ ] 1.1 Create the COA database migration file
    - Create `database/migrations/add_chart_of_accounts.sql` containing CREATE TABLE statements for: `coa_accounts`, `coa_journal_entries`, `coa_journal_lines`, `coa_account_mappings`, `coa_netting_log`
    - Include all indexes, foreign keys, and constraints as defined in the design document
    - Use `IF NOT EXISTS` for idempotent execution
    - _Requirements: 1.1, 1.2, 1.3, 4.2, 6.6, 7.6, 9.1_

  - [ ] 1.2 Update schema.sql with COA tables
    - Add the five new table definitions to `database/schema.sql` in the correct position (after existing financial tables)
    - Ensure the schema matches the migration exactly
    - _Requirements: 1.1, 9.1_

  - [ ] 1.3 Add COA permissions to seed data
    - Add COA permission entries to `database/seed_system_data.sql`: `coa.view`, `coa.create`, `coa.edit`, `coa.delete`, `coa.netting`
    - Link permissions to the appropriate module code for module-gating
    - _Requirements: 9.2_

- [ ] 2. Repository layer
  - [ ] 2.1 Implement COARepository
    - Create `src/Repository/COARepository.php`
    - Implement methods: `findAll($businessId, $filters)`, `findById($id, $businessId)`, `create($data)`, `update($id, $data)`, `delete($id)`, `findByCode($code, $businessId)`, `findControlAccounts($businessId)`, `findSubAccounts($parentId)`, `getNextSubAccountSequence($parentId, $businessId)`, `updateBalance($id, $amount, $type)`, `search($term, $businessId)`
    - All queries MUST include `business_id` in WHERE clauses for tenant isolation
    - _Requirements: 9.1, 9.2, 9.3_

  - [ ] 2.2 Implement JournalEntryRepository
    - Create `src/Repository/JournalEntryRepository.php`
    - Implement methods: `createEntry($data)`, `createLines($entryId, $lines)`, `findByAccount($accountId)`, `findByReference($type, $id)`, `hasTransactions($accountId)`, `getAccountBalance($accountId)`
    - Ensure all journal line inserts validate that referenced accounts are active
    - _Requirements: 7.8, 10.2_

  - [ ] 2.3 Implement COAMappingRepository
    - Create `src/Repository/COAMappingRepository.php`
    - Implement methods: `createMapping($businessAccountId, $coaAccountId, $businessId)`, `findByBusinessAccount($businessAccountId)`, `findByCoaAccount($coaAccountId)`, `findAllForBusiness($businessId)`
    - _Requirements: 7.6_

- [ ] 3. Checkpoint - Repository layer complete
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 4. Core services - COAService and COASeedService
  - [ ] 4.1 Implement COAService
    - Create `src/Services/COAService.php`
    - Implement: `list($filters)`, `getById($id)`, `create($data)`, `update($id, $data)`, `deactivate($id, $confirmed)`, `reactivate($id)`, `delete($id)`, `trialBalance()`, `search($term)`
    - Implement `validateCodeRange($code, $classification)` static method with ranges: Assets 100–199, Liabilities 200–299, Equity 300–399, Revenue 400–499, Expenses 500–599
    - Enforce: duplicate code rejection, system account immutability (no delete, no code/classification change), control account deactivation hierarchy, deletion blocked for accounts with transactions
    - _Requirements: 1.2, 1.3, 1.4, 1.5, 1.6, 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 5.1, 5.4, 5.5, 10.1, 10.2, 10.4, 10.5, 10.6, 10.7_

  - [ ]* 4.2 Write property tests for account code range validation
    - **Property 1: Account code range validation**
    - **Validates: Requirements 1.3, 1.6, 3.2**
    - Create `tests/Property/COA/CodeRangeValidationTest.php`
    - Generate random integers 0–999 × 5 classifications, verify acceptance iff code is in valid range

  - [ ]* 4.3 Write property tests for account code uniqueness
    - **Property 2: Account code uniqueness per business**
    - **Validates: Requirements 1.4, 3.3**
    - Create `tests/Property/COA/CodeUniquenessTest.php`
    - Generate random sequences of account creations, verify second creation with same code is rejected

  - [ ]* 4.4 Write property tests for account guide validation
    - **Property 3: Account guide validation**
    - **Validates: Requirements 1.5, 5.1, 5.4, 5.5**
    - Create `tests/Property/COA/GuideValidationTest.php`
    - Generate random strings length 0–600 with whitespace variants, verify acceptance rules

  - [ ]* 4.5 Write property tests for required field validation
    - **Property 4: Required field validation on account creation**
    - **Validates: Requirements 3.1, 3.6**
    - Create `tests/Property/COA/RequiredFieldsTest.php`
    - Generate random subsets of required fields omitted, verify rejection with correct field identification

  - [ ]* 4.6 Write property tests for system account immutability
    - **Property 5: System account immutability**
    - **Validates: Requirements 1.2, 2.2, 2.3**
    - Create `tests/Property/COA/SystemImmutabilityTest.php`
    - Generate random mutations (delete, change code, change classification) on system accounts, verify all rejected

  - [ ]* 4.7 Write property tests for user account defaults
    - **Property 6: User-created accounts are non-system**
    - **Validates: Requirements 3.5, 10.1**
    - Create `tests/Property/COA/UserAccountDefaultsTest.php`
    - Generate random valid creation payloads, verify is_system=0 and is_active=1

  - [ ] 4.8 Implement COASeedService
    - Create `src/Services/COASeedService.php`
    - Implement `seedForBusiness($businessId)` that atomically creates all 18 default system accounts within a PDO transaction
    - Implement `getDefaultAccounts()` returning the full seed definition array with codes, names, classifications, normal balance types, is_control flags, and default account guides
    - On any failure, roll back the entire transaction so no partial seed exists
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5, 5.2_

- [ ] 5. Contact account and journal services
  - [ ] 5.1 Implement ContactAccountService
    - Create `src/Services/ContactAccountService.php`
    - Implement: `createForPayeeType($personId, $payeeTypeId, $businessId)`, `removeForPayeeType($personId, $payeeTypeId, $businessId)`, `getNextSequence($controlAccountId, $businessId)`, `findByPayee($payeeId, $controlAccountId)`, `ensureExists($payeeId, $payeeTypeCode, $businessId)`
    - Maintain payee type → control account mapping: CUSTOMER→411, SUPPLIER→201, EMPLOYEE→502, PARTNER→301
    - Sub-account code = controlCode * 1000 + sequence; naming = "{person_name} – {payee_type_name}"
    - On removal: deactivate if has balance/transactions, delete otherwise
    - Reject creation if mapped control account is missing or inactive
    - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 4.7_

  - [ ]* 5.2 Write property tests for contact sub-account creation
    - **Property 7: Contact sub-account creation on payee type assignment**
    - **Validates: Requirements 4.1, 4.3**
    - Create `tests/Property/COA/ContactAccountCreationTest.php`
    - Generate random person + payee type combinations, verify correct number of sub-accounts under correct control accounts

  - [ ]* 5.3 Write property tests for sequential numbering
    - **Property 8: Sub-account sequential numbering**
    - **Validates: Requirements 4.2**
    - Create `tests/Property/COA/SequentialNumberingTest.php`
    - Generate random sequences of sub-account creations, verify unique sequences 1–999 and correct code encoding

  - [ ]* 5.4 Write property tests for naming convention
    - **Property 9: Sub-account naming convention**
    - **Validates: Requirements 4.4**
    - Create `tests/Property/COA/NamingConventionTest.php`
    - Generate random person names × payee type names, verify naming pattern

  - [ ]* 5.5 Write property tests for sub-account lifecycle
    - **Property 10: Sub-account lifecycle on payee type removal**
    - **Validates: Requirements 4.5, 4.6**
    - Create `tests/Property/COA/SubAccountLifecycleTest.php`
    - Generate random balance/transaction states, verify deactivation vs deletion behavior

  - [ ] 5.6 Implement COAJournalService
    - Create `src/Services/COAJournalService.php`
    - Implement: `record($entry)`, `recordCollection(...)`, `recordPayment(...)`, `recordInvoiceDelivery(...)`, `recordPurchaseOrderReceipt(...)`, `recordExpense(...)`
    - Implement `validateBalance($lines)` static method ensuring sum(debits) == sum(credits)
    - All journal entries are atomic (PDO transaction), update account balances on successful post
    - Reject posting to inactive accounts
    - Auto-create contact accounts via ContactAccountService.ensureExists() if missing
    - _Requirements: 7.1, 7.2, 7.3, 7.4, 7.5, 7.7, 7.8, 10.2_

  - [ ]* 5.7 Write property tests for double-entry balance invariant
    - **Property 14: Double-entry balance invariant**
    - **Validates: Requirements 7.8**
    - Create `tests/Property/COA/DoubleEntryBalanceTest.php`
    - Generate random journal entries with lines, verify sum(debits) == sum(credits) for every entry

  - [ ]* 5.8 Write property tests for inactive account blocking
    - **Property 18: Inactive accounts block posting**
    - **Validates: Requirements 10.2**
    - Create `tests/Property/COA/InactiveBlocksPostingTest.php`
    - Generate random transactions targeting inactive accounts, verify all rejected

- [ ] 6. Netting service
  - [ ] 6.1 Implement NettingService
    - Create `src/Services/NettingService.php`
    - Implement: `execute($data)`, `validate($data)`
    - Validate: same person ownership, distinct accounts, sufficient balance, active accounts, positive amount
    - For cross-currency netting: require exchange_rate, apply conversion
    - Execute atomically: debit source, credit target, create journal entry, create netting log entry
    - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.5, 6.6, 6.7, 6.8_

  - [ ]* 6.2 Write property tests for netting same person validation
    - **Property 11: Netting requires same person ownership**
    - **Validates: Requirements 6.2**
    - Create `tests/Property/COA/NettingSamePersonTest.php`
    - Generate random pairs of sub-accounts with varying person ownership, verify rejection when different persons

  - [ ]* 6.3 Write property tests for netting balance sufficiency
    - **Property 12: Netting balance sufficiency**
    - **Validates: Requirements 6.4**
    - Create `tests/Property/COA/NettingBalanceTest.php`
    - Generate random amounts vs random balances, verify rejection when amount exceeds balance

  - [ ]* 6.4 Write property tests for netting conservation
    - **Property 13: Netting conserves total value**
    - **Validates: Requirements 6.5**
    - Create `tests/Property/COA/NettingConservationTest.php`
    - Generate random valid netting operations, verify S+T sum unchanged after netting

- [ ] 7. Checkpoint - Service layer complete
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 8. Controllers and API routes
  - [ ] 8.1 Implement COAController
    - Create `src/Controllers/COAController.php` extending BaseController
    - Implement: `index()` — list accounts with optional classification/active filters; `show($id)` — single account; `store()` — create account; `update($id)` — update account; `destroy($id)` — delete account; `deactivate($id)` — deactivate with confirmation; `reactivate($id)` — reactivate; `trialBalance()` — trial balance view; `search()` — search by code/name/contact
    - Validate all input, enforce business scoping via AuthContext
    - Return consistent JSON responses: `{ success: bool, data: ?array, message: ?string }`
    - _Requirements: 1.4, 1.6, 3.1, 3.6, 5.3, 8.1, 8.2, 8.3, 8.4, 8.5, 8.6, 8.7, 9.2, 9.3, 9.5, 10.4_

  - [ ] 8.2 Implement NettingController
    - Create `src/Controllers/NettingController.php` extending BaseController
    - Implement: `store()` — validate input and execute netting via NettingService
    - Return error details on validation failure, success with netting log ID on success
    - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.5, 6.7, 6.8_

  - [ ] 8.3 Create COA route file
    - Create `public/api/routes/coa.php`
    - Register all 10 endpoints with correct HTTP methods, paths, controller methods, and permission strings
    - Ensure `/coa/trial-balance` and `/coa/search` are registered before `/coa/{id}` to avoid route conflicts
    - Include the route file in `public/api/index.php`
    - _Requirements: 8.1, 8.3, 8.5, 8.6_

  - [ ]* 8.4 Write property tests for business scoping isolation
    - **Property 17: Business scoping isolation**
    - **Validates: Requirements 9.1, 9.2, 9.3**
    - Create `tests/Property/COA/BusinessScopingTest.php`
    - Generate random cross-business access attempts, verify all rejected or return empty

  - [ ]* 8.5 Write property tests for control account deactivation hierarchy
    - **Property 19: Control account deactivation hierarchy**
    - **Validates: Requirements 10.6**
    - Create `tests/Property/COA/ControlDeactivationTest.php`
    - Generate random control accounts with varying active sub-account counts, verify rejection when active subs exist

  - [ ]* 8.6 Write property tests for deletion blocked with transactions
    - **Property 20: Deletion blocked for accounts with transactions**
    - **Validates: Requirements 10.5**
    - Create `tests/Property/COA/DeletionBlockedTest.php`
    - Generate random accounts with varying transaction counts, verify deletion blocked when any exist

- [ ] 9. Integration with existing financial services
  - [ ] 9.1 Integrate COAJournalService with CollectionService
    - Modify `src/Services/CollectionService.php` to call `COAJournalService::recordCollection()` after a collection slip is successfully created
    - Use COAMappingRepository to resolve business_account → COA account for the debit side
    - Auto-create customer Contact_Account if not existing via ensureExists()
    - _Requirements: 7.1, 7.7_

  - [ ] 9.2 Integrate COAJournalService with PaymentService
    - Modify `src/Services/PaymentService.php` to call `COAJournalService::recordPayment()` after a payment slip is successfully created
    - Use COAMappingRepository to resolve business_account → COA account for the credit side
    - Auto-create supplier Contact_Account if not existing via ensureExists()
    - _Requirements: 7.2, 7.7_

  - [ ] 9.3 Integrate COAJournalService with InvoiceService
    - Modify `src/Services/InvoiceService.php` (or SalesInvoiceService) to call `COAJournalService::recordInvoiceDelivery()` when invoice status transitions to DELIVERED
    - Debit customer AR sub-account, credit Revenue (401)
    - _Requirements: 7.3, 7.7_

  - [ ] 9.4 Integrate COAJournalService with PurchaseOrderService
    - Modify `src/Services/PurchaseOrderService.php` to call `COAJournalService::recordPurchaseOrderReceipt()` when PO status transitions to RECEIVED
    - Debit Inventory (105) for item lines or mapped expense account for service lines, credit supplier AP sub-account
    - _Requirements: 7.4, 7.7_

  - [ ] 9.5 Integrate COAJournalService with ExpenseService
    - Modify `src/Services/ExpenseService.php` to call `COAJournalService::recordExpense()` after an expense is recorded
    - Debit COA expense account mapped to the expense category, credit supplier AP sub-account
    - _Requirements: 7.5, 7.7_

  - [ ] 9.6 Create business_account → COA mapping on business seed
    - Extend COASeedService to create `coa_account_mappings` entries linking existing `business_accounts` to their corresponding COA accounts in the Assets classification after seeding
    - For existing businesses, provide a one-time migration path that creates mappings for pre-existing business_accounts
    - _Requirements: 7.6_

- [ ] 10. Checkpoint - Backend complete
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 11. Reporting and display properties
  - [ ]* 11.1 Write property tests for control account balance sum
    - **Property 15: Control account balance equals sum of sub-accounts**
    - **Validates: Requirements 8.2**
    - Create `tests/Property/COA/ControlBalanceSumTest.php`
    - Generate random sub-account balance distributions, verify control account balance = sum of children

  - [ ]* 11.2 Write property tests for trial balance equation
    - **Property 16: Trial balance equation**
    - **Validates: Requirements 8.3**
    - Create `tests/Property/COA/TrialBalanceTest.php`
    - Generate random sequences of balanced entries, verify total debits == total credits

  - [ ]* 11.3 Write property tests for classification filter correctness
    - **Property 21: Classification filter correctness**
    - **Validates: Requirements 8.5**
    - Create `tests/Property/COA/ClassificationFilterTest.php`
    - Generate random classification filters, verify all results match the filter

  - [ ]* 11.4 Write property tests for search result relevance
    - **Property 22: Search result relevance**
    - **Validates: Requirements 8.6**
    - Create `tests/Property/COA/SearchRelevanceTest.php`
    - Generate random search terms × random account datasets, verify term appears in code/name/contact name

- [ ] 12. Frontend - COA listing page
  - [ ] 12.1 Create COA listing HTML template and page script
    - Create `public/html/COA/coa.html` with hierarchical table layout grouped by classification
    - Create `public/assets/js/pages/COA/coa.js` with DataTables initialization, classification tabs/filters, active/inactive toggle, search functionality
    - Show accounts in tree structure: control accounts with expandable sub-accounts nested underneath, sorted by account code
    - Display: code (formatted with hyphen for sub-accounts), name, classification, balance, status badge, action buttons
    - _Requirements: 8.1, 8.2, 8.5, 8.6, 8.7_

  - [ ] 12.2 Create COA account form modal
    - Create `public/html/COA/accountForm.html` — modal form for create/edit account
    - Create `public/assets/js/pages/COA/accountForm.modal.js` — modal controller
    - Fields: account code (integer input), account name, classification (dropdown), account guide (textarea with character counter), is_control_account (checkbox)
    - Validate code range client-side based on selected classification
    - On submit: POST/PUT to `/coa` endpoint, dispatch `coa.updated` event on success
    - _Requirements: 3.1, 3.2, 3.4, 5.1_

  - [ ] 12.3 Create trial balance view
    - Create `public/html/COA/trialBalance.html` — trial balance display
    - Create `public/assets/js/pages/COA/trialBalance.js` — page script
    - Show all accounts with debit/credit columns, totals row at bottom
    - Display out-of-balance warning if totals don't match
    - _Requirements: 8.3, 8.4_

  - [ ] 12.4 Create netting form modal
    - Create `public/html/COA/nettingForm.html` — netting operation form
    - Create `public/assets/js/pages/COA/nettingForm.modal.js` — modal controller
    - Fields: person selector, source sub-account (filtered by person), target sub-account (filtered by person, excludes source), amount, exchange rate (shown only for cross-currency), notes
    - Validate: amount > 0, source ≠ target, show available balance on source
    - On submit: POST to `/coa/netting`, dispatch `coa.updated` event on success
    - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.7_

- [ ] 13. Frontend - Route registration and navigation
  - [ ] 13.1 Register COA routes and navigation
    - Add COA page routes to the SPA router configuration
    - Add "Chart of Accounts" menu item to the sidebar navigation under Finance section
    - Add "Trial Balance" as a sub-item
    - Ensure proper permission gating (`coa.view`) for menu visibility
    - _Requirements: 8.1, 9.2_

- [ ] 14. Frontend JavaScript tests
  - [ ]* 14.1 Write frontend tests for COA display logic
    - Create `tests/chart-of-accounts/coaListing.test.js` — test hierarchical display logic, classification grouping, inactive account filtering
    - Create `tests/chart-of-accounts/codeFormatting.test.js` — property test: for any sub-account code (controlCode * 1000 + seq), display format SHALL be `{controlCode}-{seq:03d}`
    - Create `tests/chart-of-accounts/trialBalance.test.js` — property test: for any set of balanced journal entries, client-side total debits SHALL equal total credits
    - Create `tests/chart-of-accounts/searchFilter.test.js` — test search/filter UI logic
    - _Requirements: 8.1, 8.2, 8.3, 8.6_

- [ ] 15. Final checkpoint - All layers complete
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP
- Each task references specific requirements for traceability
- Checkpoints ensure incremental validation
- Property tests validate universal correctness properties from the design document
- Unit tests validate specific examples and edge cases
- Integration with existing services (task 9) is intentionally after the COA backend is complete — this ensures the COA layer is fully tested before modifying existing flows
- The frontend (tasks 12–14) depends on the API being fully functional
- Sub-account codes use the compound integer encoding (controlCode * 1000 + sequence) for storage, but display as `{code}-{seq:03d}`

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1", "1.2", "1.3"] },
    { "id": 1, "tasks": ["2.1", "2.2", "2.3"] },
    { "id": 2, "tasks": ["4.1", "4.8"] },
    { "id": 3, "tasks": ["4.2", "4.3", "4.4", "4.5", "4.6", "4.7", "5.1", "5.6"] },
    { "id": 4, "tasks": ["5.2", "5.3", "5.4", "5.5", "5.7", "5.8", "6.1"] },
    { "id": 5, "tasks": ["6.2", "6.3", "6.4", "8.1", "8.2"] },
    { "id": 6, "tasks": ["8.3", "8.4", "8.5", "8.6"] },
    { "id": 7, "tasks": ["9.1", "9.2", "9.3", "9.4", "9.5", "9.6"] },
    { "id": 8, "tasks": ["11.1", "11.2", "11.3", "11.4"] },
    { "id": 9, "tasks": ["12.1", "12.2", "12.3", "12.4"] },
    { "id": 10, "tasks": ["13.1"] },
    { "id": 11, "tasks": ["14.1"] }
  ]
}
```
