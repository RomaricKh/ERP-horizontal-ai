# Bugfix Requirements Document

## Introduction

This document covers 14 bugs reported in the ERP system's second round of fixes. The bugs fall into nine categories:

1. **Business visibility leak** — The Businesses management page shows businesses the user does not own (Bug 1).
2. **Items list rendering failure** — The Items page DataTable fails to render because the SQL query uses a duplicate named parameter (Bug 2).
3. **Six broken modals** — The "New"/"Add" modals for Purchase Orders, Sales Invoices, Expenses, Salaries, Payments, and Collections all crash because the shared `payeeSelect.js` utility incorrectly accesses the People API response structure (Bugs 3–8).
4. **UI consistency — action button placement** — Ten pages place their "Add New"/"Create" action buttons above the card in a flex row next to the `<h1>` heading, instead of inside the `card-header` as established by the Business and Users reference pages (Bug 9, Bug 13).
5. **Missing default account on business creation** — When a new business is created via `BusinessService::create()`, no default `business_accounts` record is created, forcing the user to manually create an account before using payments, collections, or marking invoices as paid (Bug 10).
6. **Audit logging outside transactions** — Sixteen service methods across SalaryService, ExchangeRateService, AccountService, ExpenseService, and ItemService call `audit->log()` outside of any database transaction, meaning a failed audit insert leaves the data operation committed without an audit trail (Bug 11).
7. **Race conditions in delete operations** — Three delete methods (`AccountService::delete()`, `ItemService::deleteType()`, `PeopleService::delete()`) perform guard-condition checks outside of a transaction, creating TOCTOU (time-of-check-to-time-of-use) windows where concurrent operations can invalidate the guard before the delete executes (Bug 12).
8. **Accounts and Exchange Rates pages missing from button placement fix** — Two additional pages (`accounts.html`, `exchangeRates.html`) have the same button-above-card layout issue identified in Bug 9 but were not included in the original list (Bug 13).
9. **API response fallback patterns** — Two JS files (`accounts.js`, `exchangeRates.js`) use `?? data.data` fallback patterns that would pass the entire wrapper object to `renderTable()` if the expected key were missing, causing silent failures instead of clear errors (Bug 14).

---

## Bug Analysis

### Current Behavior (Defect)

**Bug 1 — Businesses page shows non-owned businesses**

1.1 WHEN a user navigates to the Businesses management page AND the user has roles in multiple businesses (e.g., owner of one, accountant of another) THEN the system displays all businesses the user has any role in, including businesses they do not own

1.2 WHEN a user views the Businesses management page for businesses they do not own THEN the system renders edit and delete action buttons for those non-owned businesses

**Bug 2 — Items list not rendering**

1.3 WHEN the Items page loads and calls the `GET /api/items` endpoint THEN the system executes a SQL query that references the `:business` named parameter twice but only binds it once, causing the query to fail and the items DataTable to not render

**Bugs 3–8 — Six modals crash on open**

1.4 WHEN a user opens the New Purchase Order modal THEN the system crashes with a TypeError because `populatePayeeSelect()` calls `.filter()` on a plain object instead of an array

1.5 WHEN a user opens the New Sales Invoice modal THEN the system crashes with a TypeError because `populatePayeeSelect()` calls `.filter()` on a plain object instead of an array

1.6 WHEN a user opens the New Expense modal THEN the system crashes with a TypeError because `populatePayeeSelect()` calls `.filter()` on a plain object instead of an array

1.7 WHEN a user opens the New Salary modal THEN the system crashes with a TypeError because `populatePayeeSelect()` calls `.filter()` on a plain object instead of an array

1.8 WHEN a user opens the New Payment modal THEN the system crashes with a TypeError because `populatePayeeSelect()` calls `.filter()` on a plain object instead of an array

1.9 WHEN a user opens the New Collection modal THEN the system crashes with a TypeError because `populatePayeeSelect()` calls `.filter()` on a plain object instead of an array

**Bug 9 — Inconsistent action-button placement across pages**

1.10 WHEN a user navigates to the Items page THEN the system renders the three action buttons (Types, Stock, Add Item) in a flex row above the card next to the `<h1>` heading, instead of inside the `card-header`

1.11 WHEN a user navigates to the Purchase Orders page THEN the system renders the "New PO" button in a flex row above the card next to the `<h1>` heading, instead of inside the `card-header`

1.12 WHEN a user navigates to the Sales Invoices page THEN the system renders the "New Invoice" button above the card next to the `<h1>` heading inside a `container-fluid` wrapper, uses a different button style (`btn-primary btn-sm shadow-sm` with inline icon instead of `btn-icon-split`), and the card has no `card-header` at all

1.13 WHEN a user navigates to the Expenses page THEN the system renders the two action buttons (Categories, Add Expense) in a flex row above the card next to the `<h1>` heading, instead of inside the `card-header`

1.14 WHEN a user navigates to the Salaries page THEN the system renders the "Add Salary" button in a flex row above the card next to the `<h1>` heading, instead of inside the `card-header`

1.15 WHEN a user navigates to the Payments page THEN the system renders the "Add Payment" button in a flex row above the card next to the `<h1>` heading, instead of inside the `card-header`

1.16 WHEN a user navigates to the Collections page THEN the system renders the "Add Collection" button in a flex row above the card next to the `<h1>` heading, instead of inside the `card-header`

1.17 WHEN a user navigates to the People page THEN the system renders the two action buttons (Types, Add Person) in a flex row above the card next to the `<h1>` heading, instead of inside the `card-header`

**Bug 10 — No default account created when a new business is created**

1.18 WHEN a user creates a new business via `BusinessService::create()` THEN the system only inserts the business record and does not create a default `business_accounts` record, forcing the user to manually navigate to the Accounts page and create an account before they can use payments, collections, or mark invoices as paid

**Bug 11 — Audit logging outside transactions in multiple services**

1.19 WHEN `SalaryService::create()` executes THEN the system calls `repo->create()` and then `audit->log()` with no wrapping database transaction, so if the audit insert fails the salary record is committed without an audit trail

1.20 WHEN `SalaryService::update()` executes THEN the system calls `repo->update()` and then `audit->log()` with no wrapping database transaction, so if the audit insert fails the salary update is committed without an audit trail

1.21 WHEN `SalaryService::delete()` executes THEN the system calls `repo->delete()` and then `audit->log()` with no wrapping database transaction, so if the audit insert fails the salary deletion is committed without an audit trail

1.22 WHEN `ExchangeRateService::create()` executes THEN the system calls `repo->create()` and then `audit->log()` with no wrapping database transaction, so if the audit insert fails the exchange rate record is committed without an audit trail

1.23 WHEN `ExchangeRateService::update()` executes THEN the system calls `repo->update()` and then `audit->log()` with no wrapping database transaction, so if the audit insert fails the exchange rate update is committed without an audit trail

1.24 WHEN `ExchangeRateService::delete()` executes THEN the system calls `repo->delete()` and then `audit->log()` with no wrapping database transaction, so if the audit insert fails the exchange rate deletion is committed without an audit trail

1.25 WHEN `AccountService::create()` executes THEN the system calls `repo->create()` and then `audit->log()` with no wrapping database transaction, so if the audit insert fails the account record is committed without an audit trail

1.26 WHEN `AccountService::update()` executes THEN the system calls `repo->update()` and then `audit->log()` with no wrapping database transaction, so if the audit insert fails the account update is committed without an audit trail

1.27 WHEN `AccountService::delete()` executes THEN the system calls `repo->delete()` and then `audit->log()` with no wrapping database transaction, so if the audit insert fails the account deletion is committed without an audit trail

1.28 WHEN `ExpenseService::create()` executes THEN the system calls `repo->create()` and then `audit->log()` with no wrapping database transaction, so if the audit insert fails the expense record is committed without an audit trail

1.29 WHEN `ExpenseService::update()` executes THEN the system calls `repo->update()` and then `audit->log()` with no wrapping database transaction, so if the audit insert fails the expense update is committed without an audit trail

1.30 WHEN `ExpenseService::delete()` executes THEN the system calls `repo->delete()` and then `audit->log()` with no wrapping database transaction, so if the audit insert fails the expense deletion is committed without an audit trail

1.31 WHEN `ItemService::deactivate()` executes THEN the system calls `repo->deactivate()` and then `audit->log()` with no wrapping database transaction, so if the audit insert fails the item deactivation is committed without an audit trail

1.32 WHEN `ItemService::createType()` executes THEN the system calls `repo->insertType()` and then `audit->log()` with no wrapping database transaction, so if the audit insert fails the item type record is committed without an audit trail

1.33 WHEN `ItemService::updateType()` executes THEN the system calls `repo->updateType()` and then `audit->log()` with no wrapping database transaction, so if the audit insert fails the item type update is committed without an audit trail

1.34 WHEN `ItemService::deleteType()` executes THEN the system calls `repo->deleteType()` and then `audit->log()` with no wrapping database transaction, so if the audit insert fails the item type deletion is committed without an audit trail

**Bug 12 — Race conditions in delete operations (check-then-act without transactions)**

1.35 WHEN `AccountService::delete()` executes THEN the system checks `balance != 0` and `countSlips() > 0` then calls `repo->delete()` and `audit->log()`, all without a transaction — between the check and delete, a concurrent payment or collection could change the balance or add a slip, invalidating the guard

1.36 WHEN `ItemService::deleteType()` executes THEN the system checks `countItemsForType() > 0` then calls `repo->deleteType()` and `audit->log()`, all without a transaction — between the check and delete, a concurrent item creation could assign an item to this type, invalidating the guard

1.37 WHEN `PeopleService::delete()` executes THEN the system checks `countTransactionsForPayee() > 0` OUTSIDE the transaction (before `beginTransaction()`), then starts a transaction for the actual delete — between the check and the transaction start, a concurrent operation could create a transaction for this payee, invalidating the guard

**Bug 13 — Accounts and Exchange Rates pages missing from Bug 9 button placement fix**

1.38 WHEN a user navigates to the Accounts page THEN the system renders the "Add Account" button in a flex row above the card next to the `<h1>` heading, instead of inside the `card-header` — the same layout issue identified in Bug 9 for eight other pages

1.39 WHEN a user navigates to the Exchange Rates page THEN the system renders the "Add Rate" button in a flex row above the card next to the `<h1>` heading, instead of inside the `card-header` — the same layout issue identified in Bug 9 for eight other pages

**Bug 14 — API response fallback patterns in accounts.js and exchangeRates.js**

1.40 WHEN `accounts.js` fetches accounts from the API THEN the system uses `renderTable(data.data.accounts ?? data.data)` where the `?? data.data` fallback would pass the entire wrapper object to `renderTable()` if the `accounts` key were ever missing, causing silent rendering failures instead of a clear error

1.41 WHEN `exchangeRates.js` fetches exchange rates from the API THEN the system uses `renderTable(data.data.exchange_rates ?? data.data)` where the `?? data.data` fallback would pass the entire wrapper object to `renderTable()` if the `exchange_rates` key were ever missing, causing silent rendering failures instead of a clear error

### Expected Behavior (Correct)

**Bug 1 — Businesses page shows only owned businesses**

2.1 WHEN a user navigates to the Businesses management page THEN the system SHALL display only businesses where the user has the BUSINESS_OWNER role (role_id = 1), hiding all other businesses entirely

2.2 WHEN a user views the Businesses management page THEN the system SHALL NOT display any businesses where the user's only role is non-owner (e.g., accountant, viewer)

**Bug 2 — Items list renders correctly**

2.3 WHEN the Items page loads and calls the `GET /api/items` endpoint THEN the system SHALL execute the items query successfully by using distinct named parameters for each occurrence of the business ID, and the items DataTable SHALL render the full list of items

**Bugs 3–8 — Six modals open correctly**

2.4 WHEN a user opens the New Purchase Order modal THEN the system SHALL populate the payee select dropdown with people who have a payee_id and display the modal form without errors

2.5 WHEN a user opens the New Sales Invoice modal THEN the system SHALL populate the payee select dropdown with people who have a payee_id and display the modal form without errors

2.6 WHEN a user opens the New Expense modal THEN the system SHALL populate the payee select dropdown with people who have a payee_id and display the modal form without errors

2.7 WHEN a user opens the New Salary modal THEN the system SHALL populate the payee select dropdown with people who have a payee_id and display the modal form without errors

2.8 WHEN a user opens the New Payment modal THEN the system SHALL populate the payee select dropdown with people who have a payee_id and display the modal form without errors

2.9 WHEN a user opens the New Collection modal THEN the system SHALL populate the payee select dropdown with people who have a payee_id and display the modal form without errors

**Bug 9 — Action buttons inside card-header (reference pattern)**

2.10 WHEN a user navigates to the Items page THEN the `<h1>` heading SHALL be standalone above the card, and the three action buttons (Types, Stock, Add Item) SHALL be inside the `card-header` using `d-flex flex-row align-items-center justify-content-between` layout, with each button using the `btn-icon-split btn-sm` pattern (`<span class="text">Label</span><span class="icon"><i class="fas fa-fw fa-icon"></i></span>`), preserving the existing outline/secondary styling for Types and Stock and primary styling for Add Item

2.11 WHEN a user navigates to the Purchase Orders page THEN the `<h1>` heading SHALL be standalone above the card, and the "New PO" button SHALL be inside the `card-header` using `d-flex flex-row align-items-center justify-content-between` layout with the `btn-primary btn-icon-split btn-sm` pattern

2.12 WHEN a user navigates to the Sales Invoices page THEN the `container-fluid` wrapper SHALL be removed, the `<h1>` heading SHALL be standalone above the card, a `card-header` SHALL be added with `d-flex flex-row align-items-center justify-content-between` layout containing a card title and the "New Invoice" button using the `btn-primary btn-icon-split btn-sm` pattern

2.13 WHEN a user navigates to the Expenses page THEN the `<h1>` heading SHALL be standalone above the card, and the two action buttons (Categories, Add Expense) SHALL be inside the `card-header` using `d-flex flex-row align-items-center justify-content-between` layout with the `btn-icon-split btn-sm` pattern, preserving the existing secondary styling for Categories and primary styling for Add Expense

2.14 WHEN a user navigates to the Salaries page THEN the `<h1>` heading SHALL be standalone above the card, and the "Add Salary" button SHALL be inside the `card-header` using `d-flex flex-row align-items-center justify-content-between` layout with the `btn-primary btn-icon-split btn-sm` pattern

2.15 WHEN a user navigates to the Payments page THEN the `<h1>` heading SHALL be standalone above the card, and the "Add Payment" button SHALL be inside the `card-header` using `d-flex flex-row align-items-center justify-content-between` layout with the `btn-primary btn-icon-split btn-sm` pattern

2.16 WHEN a user navigates to the Collections page THEN the `<h1>` heading SHALL be standalone above the card, and the "Add Collection" button SHALL be inside the `card-header` using `d-flex flex-row align-items-center justify-content-between` layout with the `btn-primary btn-icon-split btn-sm` pattern

2.17 WHEN a user navigates to the People page THEN the `<h1>` heading SHALL be standalone above the card, and the two action buttons (Types, Add Person) SHALL be inside the `card-header` using `d-flex flex-row align-items-center justify-content-between` layout with the `btn-icon-split btn-sm` pattern, preserving the existing outline-secondary styling for Types and primary styling for Add Person

**Bug 10 — Default account auto-created with new business**

2.18 WHEN a user creates a new business via `BusinessService::create()` THEN the system SHALL automatically create a default `business_accounts` record inside the same database transaction, after the business is inserted and before the commit, by calling `AccountRepository::create()` with `business_id` set to the newly created business ID, `name` set to the business name concatenated with a space and the currency code (e.g., "LibanCorps USD"), and `currency` set to the business's default currency (from `$data['currency']`, defaulting to `'USD'`)

2.19 WHEN the default account is auto-created for a new business THEN the account SHALL have a `balance` of 0 (as enforced by `AccountRepository::create()` which hardcodes `balance = 0` in the INSERT statement)

**Bug 11 — All 16 methods wrapped in transactions**

2.20 WHEN `SalaryService::create()` executes THEN the system SHALL wrap `repo->create()` and `audit->log()` inside a database transaction (`beginTransaction` / `commit` / `rollBack`), so that if the audit insert fails the salary record is rolled back

2.21 WHEN `SalaryService::update()` executes THEN the system SHALL wrap `repo->update()` and `audit->log()` inside a database transaction, so that if the audit insert fails the salary update is rolled back

2.22 WHEN `SalaryService::delete()` executes THEN the system SHALL wrap `repo->delete()` and `audit->log()` inside a database transaction, so that if the audit insert fails the salary deletion is rolled back

2.23 WHEN `ExchangeRateService::create()` executes THEN the system SHALL wrap `repo->create()` and `audit->log()` inside a database transaction, so that if the audit insert fails the exchange rate record is rolled back

2.24 WHEN `ExchangeRateService::update()` executes THEN the system SHALL wrap `repo->update()` and `audit->log()` inside a database transaction, so that if the audit insert fails the exchange rate update is rolled back

2.25 WHEN `ExchangeRateService::delete()` executes THEN the system SHALL wrap `repo->delete()` and `audit->log()` inside a database transaction, so that if the audit insert fails the exchange rate deletion is rolled back

2.26 WHEN `AccountService::create()` executes THEN the system SHALL wrap `repo->create()` and `audit->log()` inside a database transaction, so that if the audit insert fails the account record is rolled back

2.27 WHEN `AccountService::update()` executes THEN the system SHALL wrap `repo->update()` and `audit->log()` inside a database transaction, so that if the audit insert fails the account update is rolled back

2.28 WHEN `AccountService::delete()` executes THEN the system SHALL wrap the entire method — balance check, slip count check, `repo->delete()`, and `audit->log()` — inside a single database transaction, so that if any step fails all changes are rolled back (this also addresses Bug 12 for this method)

2.29 WHEN `ExpenseService::create()` executes THEN the system SHALL wrap `repo->create()` and `audit->log()` inside a database transaction, so that if the audit insert fails the expense record is rolled back

2.30 WHEN `ExpenseService::update()` executes THEN the system SHALL wrap `repo->update()` and `audit->log()` inside a database transaction, so that if the audit insert fails the expense update is rolled back

2.31 WHEN `ExpenseService::delete()` executes THEN the system SHALL wrap `repo->delete()` and `audit->log()` inside a database transaction, so that if the audit insert fails the expense deletion is rolled back

2.32 WHEN `ItemService::deactivate()` executes THEN the system SHALL wrap `repo->deactivate()` and `audit->log()` inside a database transaction, so that if the audit insert fails the item deactivation is rolled back

2.33 WHEN `ItemService::createType()` executes THEN the system SHALL wrap `repo->insertType()` and `audit->log()` inside a database transaction, so that if the audit insert fails the item type record is rolled back

2.34 WHEN `ItemService::updateType()` executes THEN the system SHALL wrap `repo->updateType()` and `audit->log()` inside a database transaction, so that if the audit insert fails the item type update is rolled back

2.35 WHEN `ItemService::deleteType()` executes THEN the system SHALL wrap the entire method — count check, `repo->deleteType()`, and `audit->log()` — inside a single database transaction, so that if any step fails all changes are rolled back (this also addresses Bug 12 for this method)

**Bug 12 — Guard checks inside transactions to prevent race conditions**

2.36 WHEN `AccountService::delete()` executes THEN the system SHALL perform the balance check, slip count check, `repo->delete()`, and `audit->log()` all inside a single database transaction, eliminating the TOCTOU window (covered by 2.28)

2.37 WHEN `ItemService::deleteType()` executes THEN the system SHALL perform the `countItemsForType()` check, `repo->deleteType()`, and `audit->log()` all inside a single database transaction, eliminating the TOCTOU window (covered by 2.35)

2.38 WHEN `PeopleService::delete()` executes THEN the system SHALL move the `countTransactionsForPayee()` check INSIDE the existing transaction block, so the guard and the delete are protected by the same transaction, eliminating the TOCTOU window

**Bug 13 — Accounts and Exchange Rates pages follow card-header pattern**

2.39 WHEN a user navigates to the Accounts page THEN the `<h1>` heading SHALL be standalone above the card, and the "Add Account" button SHALL be inside the `card-header` using `d-flex flex-row align-items-center justify-content-between` layout with the `btn-primary btn-icon-split btn-sm` pattern, matching the Bug 9 reference pattern

2.40 WHEN a user navigates to the Exchange Rates page THEN the `<h1>` heading SHALL be standalone above the card, and the "Add Rate" button SHALL be inside the `card-header` using `d-flex flex-row align-items-center justify-content-between` layout with the `btn-primary btn-icon-split btn-sm` pattern, matching the Bug 9 reference pattern

**Bug 14 — Correct fallback to empty array**

2.41 WHEN `accounts.js` fetches accounts from the API THEN the system SHALL use `renderTable(data.data.accounts ?? [])`, falling back to an empty array instead of the wrapper object, so that `renderTable()` always receives an array

2.42 WHEN `exchangeRates.js` fetches exchange rates from the API THEN the system SHALL use `renderTable(data.data.exchange_rates ?? [])`, falling back to an empty array instead of the wrapper object, so that `renderTable()` always receives an array

### Unchanged Behavior (Regression Prevention)

**Bug 1 — Business operations for owned businesses**

3.1 WHEN a user who is the BUSINESS_OWNER of a business navigates to the Businesses management page THEN the system SHALL CONTINUE TO display that owned business with edit and delete action buttons

3.2 WHEN a user creates, edits, or deletes a business they own THEN the system SHALL CONTINUE TO perform those operations successfully

3.3 WHEN a user switches to a business they have a non-owner role in (via the business switcher, not the Businesses management page) THEN the system SHALL CONTINUE TO allow access to that business's data according to their role permissions

**Bug 2 — Other item operations**

3.4 WHEN a user creates a new item via the Items form THEN the system SHALL CONTINUE TO insert the item into the database successfully

3.5 WHEN a user edits or deactivates an existing item THEN the system SHALL CONTINUE TO update the item record successfully

3.6 WHEN a user adjusts stock for an item THEN the system SHALL CONTINUE TO process the stock adjustment correctly

**Bugs 3–8 — Other modal and page functionality**

3.7 WHEN a user opens any modal that does NOT use the payee select (e.g., Business form, Item form, Item Types) THEN the system SHALL CONTINUE TO display those modals without errors

3.8 WHEN the People API is called from the People management page THEN the system SHALL CONTINUE TO return the full list of people correctly

3.9 WHEN a user submits a completed Purchase Order, Sales Invoice, Expense, Salary, Payment, or Collection form THEN the system SHALL CONTINUE TO save the record successfully

**Bug 9 — Regression prevention for UI consistency fix**

3.10 WHEN a user navigates to the Businesses page THEN the system SHALL CONTINUE TO display the `<h1>` heading standalone above the card and the "Create" button inside the `card-header` with the `btn-primary btn-icon-split btn-sm` pattern (this page already follows the reference pattern)

3.11 WHEN a user navigates to the Users page THEN the system SHALL CONTINUE TO display the `<h1>` heading standalone above the card and the "Create" button inside the `card-header` with the `btn-primary btn-icon-split btn-sm` pattern (this page already follows the reference pattern)

3.12 WHEN a user clicks any action button (Add, Create, Types, Stock, Categories) on any page THEN the system SHALL CONTINUE TO trigger the same `data-action` event as before the fix, ensuring all associated JavaScript handlers still fire correctly

3.13 WHEN a user views any page's DataTable THEN the system SHALL CONTINUE TO render the table with the same columns, data, and functionality as before the fix

3.14 WHEN a user interacts with secondary/outline-styled buttons (Types on Items, Stock on Items, Categories on Expenses, Types on People) THEN the system SHALL CONTINUE TO preserve their distinct non-primary button styling to visually differentiate them from the primary action button

**Bug 10 — Existing businesses and manual account creation unaffected**

3.15 WHEN an existing business (created before this fix) is viewed or edited THEN the system SHALL CONTINUE TO function normally without retroactively creating a default account for that business

3.16 WHEN a user manually creates an account via the Accounts page THEN the system SHALL CONTINUE TO allow manual account creation with user-specified name, currency, and business association, independent of the auto-created default account

**Bug 11 — Existing transactional methods and successful audit logging unaffected**

3.17 WHEN any of the 16 affected methods executes successfully (both data operation and audit insert succeed) THEN the system SHALL CONTINUE TO commit the data operation and the audit log entry exactly as before — the transaction wrapping SHALL NOT change the observable outcome for successful operations

3.18 WHEN `SalaryService`, `ExchangeRateService`, `AccountService`, `ExpenseService`, or `ItemService` methods that already use transactions (e.g., `ItemService::create()`, `ItemService::update()`, `ItemService::adjustStock()`, `PeopleService::create()`, `PeopleService::update()`) execute THEN the system SHALL CONTINUE TO use their existing transaction patterns without modification

3.19 WHEN `ExpenseService::deleteCategory()` executes THEN the system SHALL CONTINUE TO use its existing transaction wrapping without modification (this method already correctly wraps the guard check, delete, and audit inside a transaction)

**Bug 12 — Successful delete operations and non-delete operations unaffected**

3.20 WHEN `AccountService::delete()` is called for an account with zero balance and no linked slips AND no concurrent operations occur THEN the system SHALL CONTINUE TO delete the account and log the audit entry successfully

3.21 WHEN `ItemService::deleteType()` is called for a type with no assigned items AND no concurrent operations occur THEN the system SHALL CONTINUE TO delete the type and log the audit entry successfully

3.22 WHEN `PeopleService::delete()` is called for a person with no transactions AND no concurrent operations occur THEN the system SHALL CONTINUE TO delete the person and log the audit entry successfully

3.23 WHEN `AccountService::create()`, `AccountService::update()`, `AccountService::list()`, or `AccountService::getById()` execute THEN the system SHALL CONTINUE TO function without any changes from the Bug 12 fix

3.24 WHEN `ItemService::createType()`, `ItemService::updateType()`, or `ItemService::listTypes()` execute THEN the system SHALL CONTINUE TO function without any changes from the Bug 12 fix

**Bug 13 — Other pages and button functionality unaffected**

3.25 WHEN a user navigates to any page already fixed by Bug 9 (Items, Purchase Orders, Sales Invoices, Expenses, Salaries, Payments, Collections, People) THEN the system SHALL CONTINUE TO display those pages with the card-header button layout applied by the Bug 9 fix

3.26 WHEN a user clicks the "Add Account" button on the Accounts page or the "Add Rate" button on the Exchange Rates page THEN the system SHALL CONTINUE TO trigger the same `data-action` event (`accounts.create` / `exchange-rates.create`) as before the fix, ensuring all associated JavaScript handlers still fire correctly

3.27 WHEN a user views the Accounts or Exchange Rates DataTable THEN the system SHALL CONTINUE TO render the table with the same columns, data, and functionality as before the fix

**Bug 14 — Successful API responses and other page JS files unaffected**

3.28 WHEN the accounts API returns a response with the `data.data.accounts` key present THEN the system SHALL CONTINUE TO pass the accounts array to `renderTable()` and render the table identically to before the fix

3.29 WHEN the exchange rates API returns a response with the `data.data.exchange_rates` key present THEN the system SHALL CONTINUE TO pass the exchange rates array to `renderTable()` and render the table identically to before the fix

3.30 WHEN other page JS files (salaries.js, payments.js, collections.js, expenses.js, people.js, items.js, etc.) fetch data from their respective APIs THEN the system SHALL CONTINUE TO use their existing response access patterns without modification

---

## Bug Condition Derivation

### Bug 1 — Business Visibility

```pascal
FUNCTION isBugCondition_BusinessVisibility(X)
  INPUT: X of type BusinessPageRequest (user_id, business_id, user_role)
  OUTPUT: boolean

  // Returns true when the user has a role in the business but is NOT the owner
  RETURN X.user_role != BUSINESS_OWNER
END FUNCTION
```

```pascal
// Property: Fix Checking — Only owned businesses shown
FOR ALL X WHERE isBugCondition_BusinessVisibility(X) DO
  result ← getAllForCurrentUser'(X.user_id)
  ASSERT X.business_id NOT IN result
END FOR
```

```pascal
// Property: Preservation Checking — Owned businesses still shown
FOR ALL X WHERE NOT isBugCondition_BusinessVisibility(X) DO
  ASSERT getAllForCurrentUser(X.user_id) = getAllForCurrentUser'(X.user_id)
END FOR
```

### Bug 2 — Items Query Failure

```pascal
FUNCTION isBugCondition_ItemsQuery(X)
  INPUT: X of type ItemsPageRequest (tenant_id, business_id)
  OUTPUT: boolean

  // The bug triggers on every items list request because the query always
  // uses the duplicate :business parameter
  RETURN true
END FUNCTION
```

```pascal
// Property: Fix Checking — Items query executes successfully
FOR ALL X WHERE isBugCondition_ItemsQuery(X) DO
  result ← getAllForBusiness'(X.tenant_id, X.business_id)
  ASSERT result IS Array AND no_error(result)
END FOR
```

```pascal
// Property: Preservation Checking — Item data integrity unchanged
FOR ALL X WHERE NOT isBugCondition_ItemsQuery(X) DO
  ASSERT getAllForBusiness(X.tenant_id, X.business_id) = getAllForBusiness'(X.tenant_id, X.business_id)
END FOR
```

### Bugs 3–8 — Payee Select Crash

```pascal
FUNCTION isBugCondition_PayeeSelect(X)
  INPUT: X of type PeopleApiResponse (data: object)
  OUTPUT: boolean

  // The bug triggers whenever the People API returns its standard response
  // structure { people: [...] } because .filter() is called on the object
  RETURN typeof(X.data) = "object" AND X.data IS NOT Array
END FUNCTION
```

```pascal
// Property: Fix Checking — Payee select populates correctly
FOR ALL X WHERE isBugCondition_PayeeSelect(X) DO
  result ← populatePayeeSelect'(X)
  ASSERT result IS Array of payees AND no_error(result)
END FOR
```

```pascal
// Property: Preservation Checking — Payee filtering unchanged
FOR ALL X WHERE NOT isBugCondition_PayeeSelect(X) DO
  ASSERT populatePayeeSelect(X) = populatePayeeSelect'(X)
END FOR
```

### Bug 9 — UI Consistency (Action Button Placement)

```pascal
FUNCTION isBugCondition_ButtonPlacement(X)
  INPUT: X of type PageTemplate (page_name, heading_element, card_header_element, action_buttons)
  OUTPUT: boolean

  // Returns true when the page places action buttons outside the card-header
  // (i.e., in a flex row next to the <h1> heading above the card)
  RETURN X.action_buttons ARE NOT children of X.card_header_element
      OR X.card_header_element DOES NOT EXIST
END FUNCTION
```

```pascal
// Property: Fix Checking — Buttons moved into card-header
FOR ALL X WHERE isBugCondition_ButtonPlacement(X) DO
  result ← renderPage'(X)
  ASSERT result.heading IS standalone <h1> above card
     AND result.card_header EXISTS
     AND result.card_header USES "d-flex flex-row align-items-center justify-content-between"
     AND result.action_buttons ARE children of result.card_header
     AND EACH button IN result.action_buttons USES "btn-icon-split btn-sm" pattern
         WITH <span class="text"> AND <span class="icon">
END FOR
```

```pascal
// Property: Preservation Checking — Reference pages and functionality unchanged
FOR ALL X WHERE NOT isBugCondition_ButtonPlacement(X) DO
  ASSERT renderPage(X) = renderPage'(X)
END FOR
```

### Bug 10 — Missing Default Account on Business Creation

```pascal
FUNCTION isBugCondition_MissingDefaultAccount(X)
  INPUT: X of type BusinessCreateRequest (name, currency, tenant_id)
  OUTPUT: boolean

  // The bug triggers on every new business creation because
  // BusinessService::create() never calls AccountRepository::create()
  RETURN true
END FUNCTION
```

```pascal
// Property: Fix Checking — Default account created with new business
FOR ALL X WHERE isBugCondition_MissingDefaultAccount(X) DO
  business_id ← BusinessService::create'(X)
  accounts ← AccountRepository::findAllForBusiness(business_id)
  currency ← X.currency ?? 'USD'
  expected_name ← X.name + " " + currency
  ASSERT accounts.length >= 1
     AND EXISTS account IN accounts WHERE
         account.business_id = business_id
         AND account.name = expected_name
         AND account.currency = currency
         AND account.balance = 0
END FOR
```

```pascal
// Property: Preservation Checking — Existing businesses unaffected
FOR ALL X WHERE NOT isBugCondition_MissingDefaultAccount(X) DO
  ASSERT BusinessService::create(X) = BusinessService::create'(X)
END FOR
```

### Bug 11 — Audit Logging Outside Transactions

```pascal
FUNCTION isBugCondition_AuditOutsideTransaction(X)
  INPUT: X of type ServiceMethodCall (service_name, method_name, audit_fails: boolean)
  OUTPUT: boolean

  // Returns true when the audit insert fails for any of the 16 affected methods
  // that lack transaction wrapping around the data operation + audit log
  affected_methods ← {
    ("SalaryService", "create"), ("SalaryService", "update"), ("SalaryService", "delete"),
    ("ExchangeRateService", "create"), ("ExchangeRateService", "update"), ("ExchangeRateService", "delete"),
    ("AccountService", "create"), ("AccountService", "update"), ("AccountService", "delete"),
    ("ExpenseService", "create"), ("ExpenseService", "update"), ("ExpenseService", "delete"),
    ("ItemService", "deactivate"), ("ItemService", "createType"), ("ItemService", "updateType"), ("ItemService", "deleteType")
  }
  RETURN (X.service_name, X.method_name) IN affected_methods AND X.audit_fails = true
END FUNCTION
```

```pascal
// Property: Fix Checking — Audit failure rolls back data operation
FOR ALL X WHERE isBugCondition_AuditOutsideTransaction(X) DO
  result ← executeMethod'(X)
  ASSERT result IS error
     AND data_operation_was_rolled_back(X)
     AND database_state_unchanged(X)
END FOR
```

```pascal
// Property: Preservation Checking — Successful operations unchanged
FOR ALL X WHERE NOT isBugCondition_AuditOutsideTransaction(X) DO
  ASSERT executeMethod(X) = executeMethod'(X)
END FOR
```

### Bug 12 — Race Conditions in Delete Operations

```pascal
FUNCTION isBugCondition_DeleteRaceCondition(X)
  INPUT: X of type DeleteRequest (service_name, method_name, concurrent_modification: boolean)
  OUTPUT: boolean

  // Returns true when a concurrent modification occurs between the guard check
  // and the delete operation for the three affected methods
  affected_methods ← {
    ("AccountService", "delete"),
    ("ItemService", "deleteType"),
    ("PeopleService", "delete")
  }
  RETURN (X.service_name, X.method_name) IN affected_methods AND X.concurrent_modification = true
END FUNCTION
```

```pascal
// Property: Fix Checking — Guard check and delete are atomic
FOR ALL X WHERE isBugCondition_DeleteRaceCondition(X) DO
  result ← executeDelete'(X)
  // The guard check and delete are inside the same transaction,
  // so the concurrent modification either:
  // (a) is blocked until the transaction completes, or
  // (b) causes the guard to fail within the transaction, rolling back the delete
  ASSERT guard_and_delete_are_atomic(X)
     AND (result IS error OR guard_was_valid_at_delete_time(X))
END FOR
```

```pascal
// Property: Preservation Checking — Non-concurrent deletes unchanged
FOR ALL X WHERE NOT isBugCondition_DeleteRaceCondition(X) DO
  ASSERT executeDelete(X) = executeDelete'(X)
END FOR
```

### Bug 13 — Accounts and Exchange Rates Button Placement

```pascal
FUNCTION isBugCondition_MissingButtonFix(X)
  INPUT: X of type PageTemplate (page_name, heading_element, card_header_element, action_buttons)
  OUTPUT: boolean

  // Returns true for the two pages missed by Bug 9
  RETURN X.page_name IN {"accounts", "exchangeRates"}
     AND X.action_buttons ARE NOT children of X.card_header_element
END FUNCTION
```

```pascal
// Property: Fix Checking — Buttons moved into card-header
FOR ALL X WHERE isBugCondition_MissingButtonFix(X) DO
  result ← renderPage'(X)
  ASSERT result.heading IS standalone <h1> above card
     AND result.card_header EXISTS
     AND result.card_header USES "d-flex flex-row align-items-center justify-content-between"
     AND result.action_buttons ARE children of result.card_header
     AND EACH button IN result.action_buttons USES "btn-icon-split btn-sm" pattern
END FOR
```

```pascal
// Property: Preservation Checking — Other pages unchanged
FOR ALL X WHERE NOT isBugCondition_MissingButtonFix(X) DO
  ASSERT renderPage(X) = renderPage'(X)
END FOR
```

### Bug 14 — API Response Fallback Patterns

```pascal
FUNCTION isBugCondition_FallbackPattern(X)
  INPUT: X of type ApiFetchResult (file_name, data_key_present: boolean)
  OUTPUT: boolean

  // Returns true when the expected data key is missing from the API response
  // in accounts.js or exchangeRates.js, triggering the incorrect fallback
  RETURN X.file_name IN {"accounts.js", "exchangeRates.js"}
     AND X.data_key_present = false
END FUNCTION
```

```pascal
// Property: Fix Checking — Missing key falls back to empty array
FOR ALL X WHERE isBugCondition_FallbackPattern(X) DO
  result ← fetchAndRender'(X)
  ASSERT renderTable_received_argument IS Array
     AND renderTable_received_argument = []
END FOR
```

```pascal
// Property: Preservation Checking — Present key still used directly
FOR ALL X WHERE NOT isBugCondition_FallbackPattern(X) DO
  ASSERT fetchAndRender(X) = fetchAndRender'(X)
END FOR
```
