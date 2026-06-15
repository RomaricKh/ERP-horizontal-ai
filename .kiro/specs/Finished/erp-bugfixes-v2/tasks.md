# Implementation Plan

- [x] 1. Write bug condition exploration tests
  - **Property 1: Bug Condition** - ERP Bugfixes V2 Bug Conditions
  - **CRITICAL**: These tests MUST FAIL on unfixed code — failure confirms the bugs exist
  - **DO NOT attempt to fix the tests or the code when they fail**
  - **NOTE**: These tests encode the expected behavior — they will validate the fixes when they pass after implementation
  - **GOAL**: Surface counterexamples that demonstrate each bug exists
  - **Bug 1 — Business Visibility**: Test that `BusinessRepository::getAllForCurrentUser()` for a user with role_id=2 (ACCOUNTANT) in Business B returns Business B (bug: no role_id filter). Expected counterexample: non-owned business appears in results.
  - **Bug 2 — Items Query**: Test that `ItemRepository::getAllForBusiness()` throws a `PDOException` due to duplicate `:business` named parameter with native prepared statements. Expected counterexample: `SQLSTATE[HY093]`.
  - **Bugs 3–8 — Payee Select**: Test that `populatePayeeSelect()` in `public/assets/js/utils/payeeSelect.js` throws `TypeError: .filter is not a function` when given `{ data: { people: [...] } }` because `json.data` is an object, not an array.
  - **Bug 10 — Default Account**: Test that `BusinessService::create()` in `src/Services/BusinessService.php` does NOT create a default `business_accounts` record. Expected counterexample: zero accounts after business creation.
  - **Bug 11 — Audit Outside Transaction**: Test that when `audit->log()` throws, the data operation (e.g., salary insert) is still committed — confirming no transaction wrapping. Expected counterexample: salary record exists but no audit entry.
  - **Bug 12 — Race Condition**: Test that `AccountService::delete()`, `ItemService::deleteType()`, and `PeopleService::delete()` perform guard checks outside the transaction, allowing TOCTOU. Expected counterexample: guard passes, concurrent modification occurs, delete proceeds.
  - **Bug 14 — Fallback Pattern**: Test that `accounts.js` and `exchangeRates.js` pass the wrapper object `data.data` (not `[]`) to `renderTable()` when the expected key is missing.
  - Run tests on UNFIXED code
  - **EXPECTED OUTCOME**: Tests FAIL (this is correct — it proves the bugs exist)
  - Document counterexamples found to understand root causes
  - Mark task complete when tests are written, run, and failures are documented
  - _Requirements: 1.1, 1.2, 1.3, 1.4–1.9, 1.18, 1.19–1.34, 1.35–1.37, 1.40, 1.41_

- [x] 2. Write preservation property tests (BEFORE implementing fixes)
  - **Property 2: Preservation** - ERP Existing Behavior Preservation
  - **IMPORTANT**: Follow observation-first methodology
  - **Bug 1 Preservation**: Observe that `getAllForCurrentUser()` returns owned businesses (role_id=1) correctly on unfixed code. Write property: for all users who are BUSINESS_OWNER, their owned businesses appear in results with correct data.
  - **Bug 2 Preservation**: Observe item creation, editing, deactivation, and stock adjustment work on unfixed code. Write property: for all item CRUD operations, behavior is unchanged.
  - **Bugs 3–8 Preservation**: Observe that modals NOT using `payeeSelect.js` (Business form, Item form, Item Types) work on unfixed code. Write property: for all non-payee modals, behavior is unchanged. Also verify form submission for POs, invoices, expenses, salaries, payments, collections saves records.
  - **Bug 9+13 Preservation**: Observe that Businesses and Users pages already follow the card-header pattern. Write property: for all pages already following the pattern, layout is unchanged. Verify all `data-action` attributes are preserved.
  - **Bug 10 Preservation**: Observe that existing businesses have no retroactive account creation. Write property: for all existing businesses, no default account is added. Manual account creation continues to work.
  - **Bug 11 Preservation**: Observe that when both data operation and audit succeed, the outcome is committed. Write property: for all 16 methods where both succeed, observable outcome is identical.
  - **Bug 12 Preservation**: Observe that successful deletes (no concurrent modifications) work. Write property: for all non-concurrent deletes, result is identical (delete if guard passes, reject if guard fails).
  - **Bug 14 Preservation**: Observe that when `data.data.accounts` / `data.data.exchange_rates` keys are present, `renderTable()` receives the correct array. Write property: for all responses with expected keys, rendering is identical.
  - Verify tests pass on UNFIXED code
  - **EXPECTED OUTCOME**: Tests PASS (this confirms baseline behavior to preserve)
  - Mark task complete when tests are written, run, and passing on unfixed code
  - _Requirements: 3.1–3.30_

- [x] 3. Bug 1 — Fix business visibility leak in BusinessRepository

  - [x] 3.1 Add `AND ur.role_id = 1` to the JOIN condition in `BusinessRepository::getAllForCurrentUser()`
    - **File**: `src/Repository/BusinessRepository.php`
    - **Method**: `getAllForCurrentUser()`
    - Change the INNER JOIN line from `INNER JOIN user_roles ur ON ur.business_id = b.id AND ur.user_id = :user_id` to `INNER JOIN user_roles ur ON ur.business_id = b.id AND ur.user_id = :user_id AND ur.role_id = 1`
    - _Bug_Condition: isBugCondition(input) where input.role_id != 1 AND user has role in business_
    - _Expected_Behavior: Only businesses where user has role_id=1 (BUSINESS_OWNER) are returned_
    - _Preservation: Owned businesses continue to display with edit/delete buttons_
    - _Requirements: 2.1, 2.2_

- [x] 4. Bug 2 — Fix duplicate PDO parameter in ItemRepository

  - [x] 4.1 Replace duplicate `:business` parameter with `:business1` and `:business2` in `ItemRepository::getAllForBusiness()`
    - **File**: `src/Repository/ItemRepository.php`
    - **Method**: `getAllForBusiness()`
    - Change `LEFT JOIN stock s ON s.item_id = i.id AND s.business_id = :business` to use `:business1`
    - Change `AND (i.business_id = :business OR i.business_id IS NULL)` to use `:business2`
    - Update `execute()` call from `['tenant' => $tenantId, 'business' => $businessId]` to `['tenant' => $tenantId, 'business1' => $businessId, 'business2' => $businessId]`
    - _Bug_Condition: Every items list request triggers the duplicate parameter error_
    - _Expected_Behavior: Query executes successfully with distinct named parameters_
    - _Preservation: Same result set returned — all item data, type names, stock quantities unchanged_
    - _Requirements: 2.3_

- [x] 5. Bugs 3–8 — Fix payeeSelect.js API response access

  - [x] 5.1 Change `json.data || []` to `json.data?.people || []` on line 15 of `payeeSelect.js`
    - **File**: `public/assets/js/utils/payeeSelect.js`
    - **Line**: 15
    - Change `const payees = (json.data || []).filter(p => p.payee_id != null);` to `const payees = (json.data?.people || []).filter(p => p.payee_id != null);`
    - This fixes all 6 modals: New Purchase Order, New Sales Invoice, New Expense, New Salary, New Payment, New Collection
    - _Bug_Condition: People API returns { data: { people: [...] } } — json.data is an object, not an array_
    - _Expected_Behavior: json.data?.people extracts the array; || [] handles missing key_
    - _Preservation: Form submission for POs, invoices, expenses, salaries, payments, collections unchanged_
    - _Requirements: 2.4, 2.5, 2.6, 2.7, 2.8, 2.9_

- [x] 6. Bugs 9+13 — Fix action-button placement across 10 HTML templates
  - Apply the card-header reference pattern to all 10 affected pages. For each template:
    1. Extract `<h1>` heading to be standalone above the card with `mb-2` class
    2. Remove the flex wrapper div that contained the heading and buttons
    3. Add or update `card-header` div with `py-3 d-flex flex-row align-items-center justify-content-between` classes
    4. Move all action buttons inside the `card-header`, wrapped in a `d-flex gap-2` container
    5. Add `<h6 class="m-0 font-weight-bold text-primary">` card title inside the `card-header`
    6. Convert all buttons to `btn-icon-split btn-sm` pattern with `<span class="text">` and `<span class="icon">`
    7. Preserve all existing `data-action` attributes, button color classes (primary vs outline-secondary), and icon classes

  - [x] 6.1 Fix `public/html/Items/items.html` — move Types, Stock, Add Item buttons into card-header
    - Preserve outline-secondary styling for Types and Stock; primary for Add Item
    - _Requirements: 2.10_

  - [x] 6.2 Fix `public/html/PurchaseOrders/purchaseOrders.html` — move New PO button into card-header
    - _Requirements: 2.11_

  - [x] 6.3 Fix `public/html/SalesInvoices/salesInvoices.html` — remove `container-fluid` wrapper, add `card-header`, convert button to `btn-icon-split` pattern
    - Special case: this page has no `card-header` at all and uses a different button style
    - _Requirements: 2.12_

  - [x] 6.4 Fix `public/html/Expenses/expenses.html` — move Categories, Add Expense buttons into card-header
    - Preserve secondary styling for Categories; primary for Add Expense
    - _Requirements: 2.13_

  - [x] 6.5 Fix `public/html/Salaries/salaries.html` — move Add Salary button into card-header
    - _Requirements: 2.14_

  - [x] 6.6 Fix `public/html/Finance/payments.html` — move Add Payment button into card-header
    - _Requirements: 2.15_

  - [x] 6.7 Fix `public/html/Finance/collections.html` — move Add Collection button into card-header
    - _Requirements: 2.16_

  - [x] 6.8 Fix `public/html/People/people.html` — move Types, Add Person buttons into card-header
    - Preserve outline-secondary styling for Types; primary for Add Person
    - _Requirements: 2.17_

  - [x] 6.9 Fix `public/html/Finance/accounts.html` — move Add Account button into card-header
    - _Requirements: 2.39_

  - [x] 6.10 Fix `public/html/Finance/exchangeRates.html` — move Add Rate button into card-header
    - _Requirements: 2.40_

- [x] 7. Bug 10 — Add default account creation in BusinessService

  - [x] 7.1 Add `AccountRepository` dependency to `BusinessService`
    - **File**: `src/Services/BusinessService.php`
    - Add `use App\Repository\AccountRepository;` import
    - Add `private AccountRepository $accountRepo;` property
    - Add `$this->accountRepo = new AccountRepository($this->db);` in constructor
    - _Requirements: 2.18_

  - [x] 7.2 Call `AccountRepository::create()` inside the existing transaction in `BusinessService::create()`
    - **File**: `src/Services/BusinessService.php`
    - **Method**: `create()`
    - After `$business_id = $this->businessRepo->create(...)` and before `$this->audit->log(...)`, add:
      ```php
      $currency = $data['currency'] ?? 'USD';
      $accountName = $data['name'] . ' ' . $currency;
      $this->accountRepo->create([
          'business_id' => $business_id,
          'name'        => $accountName,
          'currency'    => $currency,
      ]);
      ```
    - _Bug_Condition: Every new business creation triggers this — no default account was created_
    - _Expected_Behavior: Default account created with name="BusinessName Currency", currency from data, balance=0_
    - _Preservation: Existing businesses unaffected; manual account creation still works_
    - _Requirements: 2.18, 2.19_

- [x] 8. Bugs 11+12 — Wrap service methods in transactions and fix race conditions

  - [x] 8.1 Wrap SalaryService `create()`, `update()`, `delete()` in transactions
    - **File**: `src/Services/SalaryService.php`
    - For each method: add `$this->db->beginTransaction()` before the repo call, wrap repo call + `audit->log()` in try/catch, `commit()` on success, `rollBack()` on failure
    - _Bug_Condition: audit->log() fails → data operation committed without audit trail_
    - _Expected_Behavior: Both data + audit committed atomically; audit failure rolls back data_
    - _Requirements: 2.20, 2.21, 2.22_

  - [x] 8.2 Wrap ExchangeRateService `create()`, `update()`, `delete()` in transactions
    - **File**: `src/Services/ExchangeRateService.php`
    - Same pattern as 8.1 for all three methods
    - _Requirements: 2.23, 2.24, 2.25_

  - [x] 8.3 Wrap AccountService `create()`, `update()` in transactions, and wrap `delete()` with guard checks inside transaction (co-fixes Bug 12)
    - **File**: `src/Services/AccountService.php`
    - `create()` and `update()`: standard transaction wrapping around repo call + audit
    - `delete()`: move `findById`, balance check, `countSlips` check, `repo->delete()`, and `audit->log()` ALL inside a single `beginTransaction()`/`commit()`/`rollBack()` block — this eliminates the TOCTOU window (Bug 12) and ensures audit atomicity (Bug 11)
    - _Bug_Condition (Bug 11): audit failure leaves data committed; (Bug 12): concurrent payment between guard check and delete_
    - _Expected_Behavior: Atomic guard-check + delete + audit; rollback on any failure_
    - _Requirements: 2.26, 2.27, 2.28, 2.36_

  - [x] 8.4 Wrap ExpenseService `create()`, `update()`, `delete()` in transactions
    - **File**: `src/Services/ExpenseService.php`
    - Same pattern as 8.1 for all three methods (expense CRUD, not categories — `deleteCategory()` already has correct transaction wrapping)
    - _Requirements: 2.29, 2.30, 2.31_

  - [x] 8.5 Wrap ItemService `deactivate()`, `createType()`, `updateType()` in transactions, and wrap `deleteType()` with guard check inside transaction (co-fixes Bug 12)
    - **File**: `src/Services/ItemService.php`
    - `deactivate()`, `createType()`, `updateType()`: standard transaction wrapping around repo call + audit
    - `deleteType()`: move `countItemsForType` check, `getTypeById`, `repo->deleteType()`, and `audit->log()` ALL inside a single transaction — this eliminates the TOCTOU window (Bug 12) and ensures audit atomicity (Bug 11)
    - _Bug_Condition (Bug 11): audit failure leaves data committed; (Bug 12): concurrent item creation between guard check and deleteType_
    - _Expected_Behavior: Atomic guard-check + delete + audit; rollback on any failure_
    - _Requirements: 2.32, 2.33, 2.34, 2.35, 2.37_

  - [x] 8.6 Move `countTransactionsForPayee()` check inside the existing transaction in `PeopleService::delete()` (Bug 12 only)
    - **File**: `src/Services/PeopleService.php`
    - **Method**: `delete()`
    - Move the `findPayee()` + `countTransactionsForPayee()` guard check from BEFORE `beginTransaction()` to AFTER `beginTransaction()` inside the try block
    - This method already has a transaction for the delete + audit — just move the guard inside it
    - _Bug_Condition: concurrent transaction creation between guard check and delete_
    - _Expected_Behavior: Guard check and delete are atomic within the same transaction_
    - _Preservation: Non-concurrent deletes produce identical results_
    - _Requirements: 2.38_

- [x] 9. Bug 14 — Fix API response fallback patterns

  - [x] 9.1 Change `data.data.accounts ?? data.data` to `data.data.accounts ?? []` in `accounts.js`
    - **File**: `public/assets/js/pages/Finance/accounts.js`
    - **Function**: `fetchAccounts()`
    - Change `renderTable(data.data.accounts ?? data.data)` to `renderTable(data.data.accounts ?? [])`
    - _Requirements: 2.41_

  - [x] 9.2 Change `data.data.exchange_rates ?? data.data` to `data.data.exchange_rates ?? []` in `exchangeRates.js`
    - **File**: `public/assets/js/pages/Finance/exchangeRates.js`
    - **Function**: `fetchExchangeRates()`
    - Change `renderTable(data.data.exchange_rates ?? data.data)` to `renderTable(data.data.exchange_rates ?? [])`
    - _Requirements: 2.42_

- [x] 10. Verify all fixes

  - [x] 10.1 Verify bug condition exploration tests now pass
    - **Property 1: Expected Behavior** - ERP Bugfixes V2 Bug Conditions Resolved
    - **IMPORTANT**: Re-run the SAME tests from task 1 — do NOT write new tests
    - The tests from task 1 encode the expected behavior for all 14 bugs
    - When these tests pass, it confirms the expected behavior is satisfied
    - Run bug condition exploration tests from step 1
    - **EXPECTED OUTCOME**: Tests PASS (confirms all bugs are fixed)
    - _Requirements: 2.1–2.42_

  - [x] 10.2 Verify preservation tests still pass
    - **Property 2: Preservation** - ERP Existing Behavior Preserved
    - **IMPORTANT**: Re-run the SAME tests from task 2 — do NOT write new tests
    - Run preservation property tests from step 2
    - **EXPECTED OUTCOME**: Tests PASS (confirms no regressions)
    - Confirm all tests still pass after fixes (no regressions)

- [x] 12. Bug 15 — Fix stock badge overwriting Actions column in items DataTable

  - [x] 12.1 Fix `cells[7]` → `cells[6]` in the `draw` event handler in `items.js`
    - **File**: `public/assets/js/pages/Items/items.js`
    - **Function**: `renderTable()` → `draw` event handler
    - Column 0 (ID) is `visible: false`, so it has no `<td>` in the DOM. The `querySelectorAll('td')` returns only 8 elements (indices 0–7). Data index 7 (Stock) maps to visible `td` index 6, not 7. Using `cells[7]` was targeting the Actions column, overwriting the edit/delete/stock-adjust buttons with the stock badge.
    - Change `cells[7]` to `cells[6]`
    - _Bug_Condition: Every items table render overwrites the Actions column with the stock badge_
    - _Expected_Behavior: Stock badge renders in the Stock column; Actions column shows edit/delete/adjust buttons_

- [x] 13. Bug 16 — Fix wrong import path in payeeSelect.js breaking all modals that use it

  - [x] 13.1 Change `../../Auth/apiFetch.js` to `../Auth/apiFetch.js` in `payeeSelect.js`
    - **File**: `public/assets/js/utils/payeeSelect.js`
    - **Line**: 1
    - `payeeSelect.js` is at `public/assets/js/utils/payeeSelect.js`. The import `../../Auth/apiFetch.js` resolves to `public/assets/Auth/apiFetch.js` (one level too high), which doesn't exist. The server returns the SPA fallback `index.html` with `text/html` MIME type, causing `Failed to load module script` error. This cascading failure prevents all 6 modal controllers that import `payeeSelect.js` from loading.
    - Change `import { apiFetch } from '../../Auth/apiFetch.js'` to `import { apiFetch } from '../Auth/apiFetch.js'`
    - _Bug_Condition: Every modal that uses populatePayeeSelect() fails to open — PO, Sales Invoice, Expense, Salary, Payment, Collection_
    - _Expected_Behavior: Dynamic import resolves correctly; modals open and payee select populates_

- [x] 14. Bug 17 — Fix wrong column name `py.person_id` → `py.people_id` in 7 repositories

  - [x] 14.1 Fix `PurchaseOrderRepository.php` — `findAllForBusiness()` and `findById()`
  - [x] 14.2 Fix `SalesInvoiceRepository.php` — `findAllForBusiness()` and `findById()`
  - [x] 14.3 Fix `SalaryRepository.php` — `findAllForBusiness()` and `findById()`
  - [x] 14.4 Fix `PaymentRepository.php` — `findAllForBusiness()` and `findById()`
  - [x] 14.5 Fix `CollectionRepository.php` — `findAllForBusiness()` and `findById()`
  - [x] 14.6 Fix `ExpenseRepository.php` — `findAllForBusiness()` and `findById()`
  - [x] 14.7 Fix `DashboardRepository.php` — 3 UNION ALL subqueries for expenses, payments, collections
    - All 7 repositories join `payees py` to `people p` using `ON p.id = py.person_id`, but the `payees` table column is `people_id`, not `person_id`. This causes every query to fail with a SQL error, returning 500 on all API endpoints for POs, Sales Invoices, Expenses, Salaries, Payments, Collections, and Dashboard.
    - _Bug_Condition: Every API call that joins payees→people fails with unknown column error_
    - _Expected_Behavior: Queries execute successfully, pages load data correctly_

- [x] 15. Bug 18 — Fix nested transaction error in `ItemService::adjustStock()`

  - [x] 15.1 Make `adjustStock()` transaction-aware using `$this->db->inTransaction()`
    - **File**: `src/Services/ItemService.php`
    - **Method**: `adjustStock()`
    - `PurchaseOrderService::updateStatus()` starts a transaction and calls `adjustStock()` for each PO line when status → RECEIVED. `SalesInvoiceService::updateStatus()` does the same when status → PAID. Both share the same PDO connection. `adjustStock()` also called `beginTransaction()`, causing "There is already an active transaction" error.
    - Fix: check `$this->db->inTransaction()` before starting a new transaction. If already in one, skip `beginTransaction()`/`commit()`/`rollBack()` — the caller manages the transaction.
    - _Bug_Condition: Marking a PO as RECEIVED or an Invoice as PAID triggers nested beginTransaction()_
    - _Expected_Behavior: Stock adjustments execute within the caller's transaction; PO receive and invoice pay complete successfully_

- [x] 16. Bug 19 — Fix duplicate named parameters in `upsertStock()` and `countTransactionsForPayee()`

  - [x] 16.1 Fix `:qty` used twice in `ItemRepository::upsertStock()` — change to `:qty1` and `:qty2`
    - **File**: `src/Repository/ItemRepository.php`
    - Same root cause as Bug 2 — native prepared statements reject duplicate named parameters
    - _Bug_Condition: Every stock upsert (manual adjust, PO receive, invoice pay) fails with HY093_

  - [x] 16.2 Fix `:id` used 5 times in `PeopleRepository::countTransactionsForPayee()` — change to `:id1` through `:id5`
    - **File**: `src/Repository/PeopleRepository.php`
    - _Bug_Condition: Deleting a person with the guard check fails with HY093_

- [x] 17. Bug 20 — Add payee balance tracking across PO receive, payments, collections, and invoice approval

  - [x] 17.1 Add `updatePayeeBalance()` method to `PeopleRepository`
    - **File**: `src/Repository/PeopleRepository.php`
    - Atomically adjusts `payees.balance` by a delta (positive = owed more, negative = paid)

  - [x] 17.2 Update `PurchaseOrderService::updateStatus()` — increase supplier balance on RECEIVED
    - **File**: `src/Services/PurchaseOrderService.php`
    - When PO → RECEIVED, add PO total (base currency) to supplier's payee balance

  - [x] 17.3 Update `PaymentService::create()` and `delete()` — adjust payee balance on payment
    - **File**: `src/Services/PaymentService.php`
    - Create: decrease payee balance by payment amount (you paid them)
    - Delete: increase payee balance back (payment reversed)

  - [x] 17.4 Update `CollectionService::create()` and `delete()` — adjust payee balance on collection
    - **File**: `src/Services/CollectionService.php`
    - Create: decrease payee balance by collection amount (they paid you)
    - Delete: increase payee balance back (collection reversed)

  - [x] 17.5 Update `SalesInvoiceService::updateStatus()` — increase customer balance on APPROVED, decrease on PAID with collection
    - **File**: `src/Services/SalesInvoiceService.php`
    - APPROVED: increase customer payee balance (they owe for goods)
    - PAID with collection slip: decrease customer payee balance (payment received)

- [x] 18. Bug 21 — Filter payee dropdowns by type (Customer, Supplier, Employee)

  - [x] 18.1 Add optional `typeFilter` parameter to `populatePayeeSelect()` in `payeeSelect.js`
    - **File**: `public/assets/js/utils/payeeSelect.js`
    - Filters payees by matching `payee_types` string (case-insensitive) when `options.typeFilter` is provided
    - No filter applied when `typeFilter` is omitted (backward compatible)

  - [x] 18.2 Pass `{ typeFilter: 'Customer' }` in `invoiceForm.modal.js`
    - **File**: `public/assets/js/pages/SalesInvoices/invoiceForm.modal.js`

  - [x] 18.3 Pass `{ typeFilter: 'Employee' }` in `salaryForm.modal.js`
    - **File**: `public/assets/js/pages/Salaries/salaryForm.modal.js`

  - [x] 18.4 Pass `{ typeFilter: 'Supplier' }` in `poForm.modal.js`
    - **File**: `public/assets/js/pages/PurchaseOrders/poForm.modal.js`

  - Expense, Payment, and Collection modals continue to show all payees (no filter)

- [x] 11. Checkpoint — Ensure all tests pass
  - Run the full test suite to confirm all bug condition tests pass and all preservation tests pass
  - Verify no regressions across the 14 bug fixes
  - Ensure all tests pass, ask the user if questions arise
