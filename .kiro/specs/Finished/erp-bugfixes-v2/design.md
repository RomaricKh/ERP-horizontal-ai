# ERP Bugfixes V2 — Bugfix Design

## Overview

This design addresses 14 bugs discovered in the second round of fixes for the multi-tenant PHP ERP system. The bugs span backend PHP (incorrect SQL queries, missing role filtering, missing default account creation, audit logging outside transactions, race conditions in delete operations), frontend JavaScript (incorrect API response property access, incorrect fallback patterns), and HTML templates (inconsistent action-button placement across 10 pages). Each fix is minimal and targeted: we change only the code paths that exhibit the defect, preserving all surrounding behavior.

## Glossary

- **Bug_Condition (C)**: The specific input or state that triggers each bug — e.g., a user with non-owner roles sees businesses they don't own on the Businesses page
- **Property (P)**: The desired correct behavior when the bug condition holds — e.g., only businesses where the user has `role_id = 1` (BUSINESS_OWNER) are returned
- **Preservation**: Existing behavior that must remain unchanged by each fix — e.g., owned businesses continue to display with edit/delete buttons
- **F**: The original (unfixed) function
- **F'**: The fixed function
- **`AuthContext`**: Singleton in `App\Support\AuthContext` that holds the authenticated user's `user_id`, `tenant_id`, `business_id`, and `permissions`
- **`BaseController::json()`**: Response helper that wraps data in `{ success, data, message }` — so `$this->json(true, ['people' => $people])` produces `{ success: true, data: { people: [...] }, message: null }`
- **`PDO::ATTR_EMULATE_PREPARES => false`**: The database connection uses native prepared statements, which do not allow duplicate named parameters in a single query
- **TOCTOU**: Time-of-check-to-time-of-use — a race condition where a guard check becomes stale before the guarded operation executes
- **Card-header pattern**: The reference UI layout from `businesses.html` and `users.html` where action buttons are placed inside the `.card-header` div using `d-flex flex-row align-items-center justify-content-between`, with the `<h1>` heading standalone above the card

## Bug Details

### Bug 1 — Business Visibility Leak

#### Bug Condition

The `BusinessRepository::getAllForCurrentUser()` method joins `user_roles` on `business_id` and `user_id` but does not filter by `role_id`. This returns all businesses where the user has ANY role, not just businesses they own.

**Formal Specification:**
```
FUNCTION isBugCondition_BusinessVisibility(input)
  INPUT: input of type BusinessPageRequest (user_id, business_id, role_id)
  OUTPUT: boolean

  RETURN input.role_id != 1
         AND userHasRoleInBusiness(input.user_id, input.business_id, input.role_id)
END FUNCTION
```

#### Examples

- User is BUSINESS_OWNER (role_id=1) of Business A and ACCOUNTANT (role_id=2) of Business B → Both A and B appear on the Businesses page (should only show A)
- User is VIEWER (role_id=3) of Business C → Business C appears with edit/delete buttons (should not appear at all)
- User is BUSINESS_OWNER of Business A only → Business A appears correctly (not a bug condition)

### Bug 2 — Items List Not Rendering (Duplicate PDO Parameter)

#### Bug Condition

`ItemRepository::getAllForBusiness()` uses the named parameter `:business` three times in the SQL query (once in the LEFT JOIN, twice in the WHERE clause). With `PDO::ATTR_EMULATE_PREPARES => false`, native prepared statements reject duplicate named parameters, causing the query to fail.

**Formal Specification:**
```
FUNCTION isBugCondition_ItemsQuery(input)
  INPUT: input of type ItemsPageRequest (tenant_id, business_id)
  OUTPUT: boolean

  // The bug triggers on every items list request because the query
  // always uses the duplicate :business parameter
  RETURN true
END FUNCTION
```

#### Examples

- Any user navigates to the Items page → PDO throws an exception, DataTable renders empty
- The SQL has `LEFT JOIN stock s ON s.item_id = i.id AND s.business_id = :business` and `WHERE ... AND (i.business_id = :business OR i.business_id IS NULL)` — three occurrences of `:business`

### Bugs 3–8 — Six Modals Crash (payeeSelect.js)

#### Bug Condition

`payeeSelect.js` line 15 does `(json.data || []).filter(...)` but the People API returns `{ data: { people: [...] } }`, so `json.data` is an object (not an array). Calling `.filter()` on a plain object throws a TypeError.

**Formal Specification:**
```
FUNCTION isBugCondition_PayeeSelect(input)
  INPUT: input of type PeopleApiResponse (data: object)
  OUTPUT: boolean

  RETURN typeof(input.data) = "object"
         AND input.data IS NOT Array
         AND input.data HAS key "people"
END FUNCTION
```

#### Examples

- User opens New Purchase Order modal → `populatePayeeSelect()` crashes with `TypeError: (json.data || []).filter is not a function`
- User opens New Sales Invoice modal → same crash
- User opens New Expense, Salary, Payment, or Collection modal → same crash
- API returns `{ success: true, data: { people: [{name: "John", payee_id: 1}] } }` → `json.data` is `{ people: [...] }`, not an array

### Bug 9 — Inconsistent Action-Button Placement (8 Pages)

#### Bug Condition

Eight pages place their action buttons in a flex row above the card next to the `<h1>` heading, instead of inside the `card-header` div as established by the Business and Users reference pages.

**Formal Specification:**
```
FUNCTION isBugCondition_ButtonPlacement(input)
  INPUT: input of type PageTemplate (page_name)
  OUTPUT: boolean

  affected_pages = {"items", "purchaseOrders", "salesInvoices", "expenses",
                    "salaries", "payments", "collections", "people"}
  RETURN input.page_name IN affected_pages
         AND input.action_buttons ARE NOT children of card_header
END FUNCTION
```

#### Examples

- Items page: Three buttons (Types, Stock, Add Item) sit in a `d-flex` div next to the `<h1>`, above the card
- Sales Invoices page: "New Invoice" button uses a completely different style (`btn-primary btn-sm shadow-sm` with inline icon), wrapped in `container-fluid`, and the card has no `card-header` at all
- People page: Two buttons (Types, Add Person) sit in a `d-flex` div next to the `<h1>`, above the card

### Bug 10 — No Default Account on Business Creation

#### Bug Condition

`BusinessService::create()` inserts the business record and logs the audit entry but does not create a default `business_accounts` record. Users must manually create an account before they can use payments, collections, or mark invoices as paid.

**Formal Specification:**
```
FUNCTION isBugCondition_MissingDefaultAccount(input)
  INPUT: input of type BusinessCreateRequest (name, currency, tenant_id)
  OUTPUT: boolean

  // The bug triggers on every new business creation
  RETURN true
END FUNCTION
```

#### Examples

- User creates "LibanCorps" with currency "USD" → Business created but no account exists → User cannot record payments until they manually create an account
- User creates "TestCo" with currency "EUR" → Same issue

### Bug 11 — Audit Logging Outside Transactions (16 Methods)

#### Bug Condition

Sixteen service methods call `repo->operation()` then `audit->log()` without any transaction wrapping. If the audit insert fails, the data operation is committed without an audit trail.

**Formal Specification:**
```
FUNCTION isBugCondition_AuditOutsideTransaction(input)
  INPUT: input of type ServiceMethodCall (service_name, method_name, audit_fails: boolean)
  OUTPUT: boolean

  affected_methods = {
    ("SalaryService", "create"), ("SalaryService", "update"), ("SalaryService", "delete"),
    ("ExchangeRateService", "create"), ("ExchangeRateService", "update"), ("ExchangeRateService", "delete"),
    ("AccountService", "create"), ("AccountService", "update"), ("AccountService", "delete"),
    ("ExpenseService", "create"), ("ExpenseService", "update"), ("ExpenseService", "delete"),
    ("ItemService", "deactivate"), ("ItemService", "createType"),
    ("ItemService", "updateType"), ("ItemService", "deleteType")
  }
  RETURN (input.service_name, input.method_name) IN affected_methods
         AND input.audit_fails = true
END FUNCTION
```

#### Examples

- `SalaryService::create()` inserts a salary record, then `audit->log()` fails → salary is committed without audit trail
- `AccountService::delete()` deletes an account, then `audit->log()` fails → account is deleted without audit trail

### Bug 12 — Race Conditions in Delete Operations (3 Methods)

#### Bug Condition

Three delete methods perform guard-condition checks outside of a transaction, creating TOCTOU windows where concurrent operations can invalidate the guard before the delete executes.

**Formal Specification:**
```
FUNCTION isBugCondition_DeleteRaceCondition(input)
  INPUT: input of type DeleteRequest (service_name, method_name, concurrent_modification: boolean)
  OUTPUT: boolean

  affected_methods = {
    ("AccountService", "delete"),
    ("ItemService", "deleteType"),
    ("PeopleService", "delete")
  }
  RETURN (input.service_name, input.method_name) IN affected_methods
         AND input.concurrent_modification = true
END FUNCTION
```

#### Examples

- `AccountService::delete()` checks balance=0 and countSlips=0, then a concurrent payment adds a slip before the delete executes → account deleted despite having a linked transaction
- `ItemService::deleteType()` checks countItemsForType=0, then a concurrent item creation assigns an item to this type → type deleted despite having items
- `PeopleService::delete()` checks countTransactionsForPayee=0 outside the transaction, then a concurrent operation creates a transaction → person deleted despite having transactions

### Bug 13 — Accounts + Exchange Rates Button Placement (2 Pages)

#### Bug Condition

Same layout issue as Bug 9 but for two additional pages that were not included in the original list.

**Formal Specification:**
```
FUNCTION isBugCondition_MissingButtonFix(input)
  INPUT: input of type PageTemplate (page_name)
  OUTPUT: boolean

  RETURN input.page_name IN {"accounts", "exchangeRates"}
         AND input.action_buttons ARE NOT children of card_header
END FUNCTION
```

### Bug 14 — API Response Fallback Patterns

#### Bug Condition

`accounts.js` and `exchangeRates.js` use `?? data.data` as a fallback, which passes the entire wrapper object instead of an empty array when the expected key is missing.

**Formal Specification:**
```
FUNCTION isBugCondition_FallbackPattern(input)
  INPUT: input of type ApiFetchResult (file_name, data_key_present: boolean)
  OUTPUT: boolean

  RETURN input.file_name IN {"accounts.js", "exchangeRates.js"}
         AND input.data_key_present = false
END FUNCTION
```

#### Examples

- `accounts.js` calls `renderTable(data.data.accounts ?? data.data)` — if `accounts` key is missing, `data.data` (the wrapper object) is passed to `renderTable()`, causing silent rendering failure
- `exchangeRates.js` calls `renderTable(data.data.exchange_rates ?? data.data)` — same issue

## Expected Behavior

### Preservation Requirements

**Unchanged Behaviors:**
- All businesses where the user is BUSINESS_OWNER continue to display with edit/delete buttons on the Businesses page
- Business creation, editing, and deletion for owned businesses continue to work
- Business switching for non-owner roles via the business switcher continues to work
- Item creation, editing, deactivation, and stock adjustment continue to work
- All modals that do NOT use `payeeSelect.js` (Business form, Item form, Item Types, etc.) continue to work
- Form submission for POs, invoices, expenses, salaries, payments, and collections continues to save records
- The Businesses and Users pages (already following the card-header pattern) remain unchanged
- All `data-action` attributes on buttons remain the same, ensuring JS handlers fire correctly
- DataTable columns, data, and functionality remain unchanged on all pages
- Secondary/outline-styled buttons (Types, Stock, Categories) preserve their distinct non-primary styling
- Existing businesses (created before this fix) are not retroactively given default accounts
- Manual account creation via the Accounts page continues to work independently
- All 16 affected methods continue to commit both data and audit when both succeed
- Methods that already use transactions (`ItemService::create()`, `ItemService::update()`, `ItemService::adjustStock()`, `PeopleService::create()`, `PeopleService::update()`, `ExpenseService::deleteCategory()`) remain unchanged
- Successful delete operations (no concurrent modifications) continue to work identically
- Non-delete operations on AccountService, ItemService, PeopleService remain unchanged
- When API responses contain the expected data keys, `renderTable()` receives the correct array
- Other page JS files continue to use their existing response access patterns

**Scope:**
All inputs that do NOT match any of the 14 bug conditions should be completely unaffected by these fixes.

## Hypothesized Root Cause

### Bug 1 — Missing Role Filter in SQL JOIN

The `BusinessRepository::getAllForCurrentUser()` SQL joins `user_roles` on `business_id` and `user_id` but has no `AND ur.role_id = 1` condition. This returns all businesses where the user has any role assignment.

**Root cause**: Missing `role_id` filter in the JOIN condition.

### Bug 2 — Duplicate Named Parameter in Native Prepared Statement

The `ItemRepository::getAllForBusiness()` SQL uses `:business` three times. With `PDO::ATTR_EMULATE_PREPARES => false`, the MySQL native prepared statement driver rejects duplicate named parameters.

**Root cause**: Reusing the same named parameter (`:business`) multiple times in a single query with native prepared statements.

### Bugs 3–8 — Incorrect Property Access on API Response

The People API controller returns `$this->json(true, ['people' => $people])`, producing `{ success: true, data: { people: [...] } }`. The `payeeSelect.js` utility accesses `json.data` directly (which is the wrapper object `{ people: [...] }`), then calls `.filter()` on it. Objects don't have a `.filter()` method.

**Root cause**: Missing `.people` property access — should be `json.data?.people || []` instead of `json.data || []`.

### Bug 9 — Template Layout Inconsistency

Eight HTML templates were created with action buttons in a flex row above the card, next to the `<h1>` heading. The reference pattern (from `businesses.html` and `users.html`) places buttons inside the `card-header` div.

**Root cause**: Templates were not built following the established card-header layout pattern.

### Bug 10 — Missing Account Creation in Business Service

`BusinessService::create()` inserts the business record and logs the audit entry but never calls `AccountRepository::create()` to set up a default account.

**Root cause**: The `create()` method was not designed to auto-provision dependent resources.

### Bug 11 — No Transaction Wrapping Around Data + Audit

Sixteen service methods perform the data operation and then call `audit->log()` as two independent database operations. Neither is wrapped in a transaction, so a failure in the audit insert leaves the data operation committed.

**Root cause**: Missing `beginTransaction()`/`commit()`/`rollBack()` wrapper around the data operation + audit log call.

### Bug 12 — Guard Checks Outside Transaction Boundary

Three delete methods check guard conditions (balance, count, etc.) before starting a transaction or outside the transaction boundary. A concurrent operation can modify the guarded state between the check and the delete.

**Root cause**: TOCTOU vulnerability — guard checks are not atomic with the delete operation.

### Bug 13 — Two Pages Missed in Bug 9 Fix Scope

The `accounts.html` and `exchangeRates.html` templates have the same above-card button layout as the eight pages in Bug 9 but were not included in the original fix scope.

**Root cause**: Incomplete audit of affected pages.

### Bug 14 — Fallback to Wrapper Object Instead of Empty Array

`accounts.js` uses `data.data.accounts ?? data.data` and `exchangeRates.js` uses `data.data.exchange_rates ?? data.data`. The `??` operator only triggers on `null`/`undefined`, so if the key is missing, `data.data` (the wrapper object) is passed to `renderTable()`.

**Root cause**: Incorrect fallback value — should fall back to `[]` instead of `data.data`.

## Correctness Properties

Property 1: Bug Condition 1 — Business Visibility Filtering

_For any_ user who has roles in multiple businesses, the fixed `getAllForCurrentUser()` query SHALL return only businesses where the user has `role_id = 1` (BUSINESS_OWNER), excluding all businesses where the user's only roles are non-owner.

**Validates: Requirements 2.1, 2.2**

Property 2: Preservation 1 — Owned Business Access

_For any_ user who is BUSINESS_OWNER of a business, the fixed query SHALL continue to return that business with the same data as the original query, preserving edit/delete functionality.

**Validates: Requirements 3.1, 3.2, 3.3**

Property 3: Bug Condition 2 — Items Query Execution

_For any_ items list request, the fixed `getAllForBusiness()` query SHALL execute successfully using distinct named parameters for each occurrence of the business ID, returning the correct list of items.

**Validates: Requirements 2.3**

Property 4: Preservation 2 — Item Data Integrity

_For any_ items list request, the fixed query SHALL return the same result set as the original query would have returned if it could execute, preserving all item data, type names, and stock quantities.

**Validates: Requirements 3.4, 3.5, 3.6**

Property 5: Bug Condition 3–8 — Payee Select Population

_For any_ modal that uses `populatePayeeSelect()`, the fixed function SHALL correctly access `json.data?.people` to extract the people array, filter for entries with a `payee_id`, and populate the select dropdown without errors.

**Validates: Requirements 2.4, 2.5, 2.6, 2.7, 2.8, 2.9**

Property 6: Preservation 3–8 — Non-Payee Modals and Form Submission

_For any_ modal that does NOT use `populatePayeeSelect()`, and for any form submission on modals that do use it, the fixed code SHALL produce the same behavior as the original code.

**Validates: Requirements 3.7, 3.8, 3.9**

Property 7: Bug Condition 9+13 — Button Placement in Card-Header

_For any_ of the 10 affected pages (Items, Purchase Orders, Sales Invoices, Expenses, Salaries, Payments, Collections, People, Accounts, Exchange Rates), the fixed HTML template SHALL place the `<h1>` heading standalone above the card and move all action buttons inside the `card-header` div using `d-flex flex-row align-items-center justify-content-between` layout with the `btn-icon-split btn-sm` pattern.

**Validates: Requirements 2.10, 2.11, 2.12, 2.13, 2.14, 2.15, 2.16, 2.17, 2.39, 2.40**

Property 8: Preservation 9+13 — Reference Pages and Button Functionality

_For any_ page already following the card-header pattern (Businesses, Users) and for all `data-action` attributes on buttons across all pages, the fixed code SHALL preserve the existing layout and ensure all JavaScript handlers continue to fire correctly.

**Validates: Requirements 3.10, 3.11, 3.12, 3.13, 3.14, 3.25, 3.26, 3.27**

Property 9: Bug Condition 10 — Default Account Auto-Creation

_For any_ new business created via `BusinessService::create()`, the fixed method SHALL create a default `business_accounts` record inside the same transaction with `name = businessName + " " + currency`, `currency = data['currency'] ?? 'USD'`, `business_id = newBusinessId`, and `balance = 0`.

**Validates: Requirements 2.18, 2.19**

Property 10: Preservation 10 — Existing Businesses and Manual Accounts

_For any_ existing business created before this fix, the system SHALL NOT retroactively create a default account. Manual account creation via the Accounts page SHALL continue to work independently.

**Validates: Requirements 3.15, 3.16**

Property 11: Bug Condition 11 — Transaction-Wrapped Audit Logging

_For any_ of the 16 affected service methods, the fixed code SHALL wrap the data operation and `audit->log()` call inside a `beginTransaction()`/`commit()`/`rollBack()` block, so that if the audit insert fails the data operation is rolled back.

**Validates: Requirements 2.20, 2.21, 2.22, 2.23, 2.24, 2.25, 2.26, 2.27, 2.28, 2.29, 2.30, 2.31, 2.32, 2.33, 2.34, 2.35**

Property 12: Preservation 11 — Successful Operations Unchanged

_For any_ of the 16 affected methods where both the data operation and audit insert succeed, the fixed code SHALL commit all changes atomically, producing the same observable outcome as the original code.

**Validates: Requirements 3.17, 3.18, 3.19**

Property 13: Bug Condition 12 — Atomic Guard-and-Delete

_For any_ delete operation on `AccountService::delete()`, `ItemService::deleteType()`, or `PeopleService::delete()`, the fixed code SHALL perform the guard check and the delete inside the same transaction, eliminating the TOCTOU window.

**Validates: Requirements 2.36, 2.37, 2.38**

Property 14: Preservation 12 — Non-Concurrent Deletes Unchanged

_For any_ delete operation where no concurrent modifications occur, the fixed code SHALL produce the same result as the original code (successful delete if guard passes, rejection if guard fails).

**Validates: Requirements 3.20, 3.21, 3.22, 3.23, 3.24**

Property 15: Bug Condition 14 — Correct Empty Array Fallback

_For any_ API fetch in `accounts.js` or `exchangeRates.js` where the expected data key is missing, the fixed code SHALL fall back to an empty array `[]` instead of the wrapper object, ensuring `renderTable()` always receives an array.

**Validates: Requirements 2.41, 2.42**

Property 16: Preservation 14 — Successful API Responses Unchanged

_For any_ API fetch where the expected data key is present, the fixed code SHALL pass the same array to `renderTable()` as the original code, rendering the table identically.

**Validates: Requirements 3.28, 3.29, 3.30**


## Fix Implementation

### Changes Required

### Bug 1 Fix — `BusinessRepository.php`

**File**: `src/Repository/BusinessRepository.php`
**Function**: `getAllForCurrentUser()`

**Current code:**
```php
public function getAllForCurrentUser(int $user_id): array {
    $stmt = $this->db->prepare("
                    SELECT  b.id, b.name, b.legal_name, b.registration_no, 
                            b.address, b.phone, b.email, b.currency
                    FROM    businesses b
                    INNER JOIN user_roles ur ON ur.business_id = b.id AND ur.user_id = :user_id
    ");
    $stmt->execute([
        'user_id' => $user_id
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
```

**Fixed code:**
```php
public function getAllForCurrentUser(int $user_id): array {
    $stmt = $this->db->prepare("
                    SELECT  b.id, b.name, b.legal_name, b.registration_no, 
                            b.address, b.phone, b.email, b.currency
                    FROM    businesses b
                    INNER JOIN user_roles ur ON ur.business_id = b.id AND ur.user_id = :user_id AND ur.role_id = 1
    ");
    $stmt->execute([
        'user_id' => $user_id
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
```

**Rationale**: Adding `AND ur.role_id = 1` to the JOIN condition filters to only BUSINESS_OWNER roles, so non-owned businesses are excluded from the result set.

---

### Bug 2 Fix — `ItemRepository.php`

**File**: `src/Repository/ItemRepository.php`
**Function**: `getAllForBusiness()`

**Current code:**
```php
public function getAllForBusiness(int $tenantId, int $businessId): array {
    $stmt = $this->db->prepare("
        SELECT i.id, i.name, i.sku, i.unit,
               i.cost_price, i.sale_price, i.is_active, i.notes,
               i.business_id,
               it.name AS type_name,
               COALESCE(s.quantity, 0) AS stock_qty
        FROM items i
        JOIN item_types it ON it.id = i.item_type_id
        LEFT JOIN stock s ON s.item_id = i.id AND s.business_id = :business
        WHERE i.tenant_id = :tenant
          AND (i.business_id = :business OR i.business_id IS NULL)
          AND i.is_active = 1
        ORDER BY i.name
    ");
    $stmt->execute(['tenant' => $tenantId, 'business' => $businessId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
```

**Fixed code:**
```php
public function getAllForBusiness(int $tenantId, int $businessId): array {
    $stmt = $this->db->prepare("
        SELECT i.id, i.name, i.sku, i.unit,
               i.cost_price, i.sale_price, i.is_active, i.notes,
               i.business_id,
               it.name AS type_name,
               COALESCE(s.quantity, 0) AS stock_qty
        FROM items i
        JOIN item_types it ON it.id = i.item_type_id
        LEFT JOIN stock s ON s.item_id = i.id AND s.business_id = :business1
        WHERE i.tenant_id = :tenant
          AND (i.business_id = :business2 OR i.business_id IS NULL)
          AND i.is_active = 1
        ORDER BY i.name
    ");
    $stmt->execute(['tenant' => $tenantId, 'business1' => $businessId, 'business2' => $businessId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
```

**Rationale**: Native prepared statements (with `ATTR_EMULATE_PREPARES => false`) require unique named parameters. Using `:business1` and `:business2` with both bound to the same value resolves the PDO error.

---

### Bugs 3–8 Fix — `payeeSelect.js`

**File**: `public/assets/js/utils/payeeSelect.js`
**Line**: 15

**Current code:**
```js
const payees = (json.data || []).filter(p => p.payee_id != null);
```

**Fixed code:**
```js
const payees = (json.data?.people || []).filter(p => p.payee_id != null);
```

**Rationale**: The People API returns `{ data: { people: [...] } }`. Accessing `json.data?.people` extracts the actual array. The `|| []` fallback handles cases where `people` is missing or the response structure changes.

---

### Bug 9 Fix — 8 HTML Templates

For each of the 8 affected templates, the fix follows the same pattern:
1. Extract the `<h1>` heading to be standalone above the card (with `mb-2` class)
2. Remove the flex wrapper div that contained the heading and buttons
3. Add or update the `card-header` div with `py-3 d-flex flex-row align-items-center justify-content-between` classes
4. Move all action buttons inside the `card-header`, wrapped in a `d-flex gap-2` container
5. Add an `<h6>` card title inside the `card-header`
6. Preserve all existing `data-action` attributes, button styles, and icon classes

**Files affected:**
- `public/html/Items/items.html`
- `public/html/PurchaseOrders/purchaseOrders.html`
- `public/html/SalesInvoices/salesInvoices.html`
- `public/html/Expenses/expenses.html`
- `public/html/Salaries/salaries.html`
- `public/html/Finance/payments.html`
- `public/html/Finance/collections.html`
- `public/html/People/people.html`

**Reference pattern (from `businesses.html`):**
```html
<h1 class="h3 mb-2 text-gray-800">Page Title</h1>
<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
        <h6 class="m-0 font-weight-bold text-primary">Card Title</h6>
        <div class="d-flex gap-2">
            <button class="btn btn-primary btn-icon-split btn-sm" data-action="...">
                <span class="text">Label</span>
                <span class="icon"><i class="fas fa-fw fa-icon"></i></span>
            </button>
        </div>
    </div>
    <div class="card-body">...</div>
</div>
```

**Example — Items page transformation:**

Current:
```html
<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h3 mb-0 text-gray-800">Items &amp; Inventory</h1>
    <div class="d-flex gap-2">
        <!-- buttons here -->
    </div>
</div>
<div class="card shadow mb-4">
    <div class="card-header py-3">
        <h6 class="m-0 font-weight-bold text-primary">Items</h6>
    </div>
    ...
</div>
```

Fixed:
```html
<h1 class="h3 mb-2 text-gray-800">Items &amp; Inventory</h1>
<div class="card shadow mb-4">
    <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
        <h6 class="m-0 font-weight-bold text-primary">Items</h6>
        <div class="d-flex gap-2">
            <!-- same buttons, same data-action attributes, same styles -->
        </div>
    </div>
    ...
</div>
```

**Special case — Sales Invoices page:**
- Remove the `<div class="container-fluid">` wrapper
- Add a `card-header` (currently missing)
- Convert the button from `btn-primary btn-sm shadow-sm` with inline icon to the `btn-primary btn-icon-split btn-sm` pattern with `<span class="text">` and `<span class="icon">`

---

### Bug 10 Fix — `BusinessService.php`

**File**: `src/Services/BusinessService.php`
**Function**: `create()`

**Change**: Add `AccountRepository` as a dependency and call `AccountRepository::create()` inside the existing transaction, after the business insert and audit log, before the commit.

**Current code (inside the try block):**
```php
$business_id = $this->businessRepo->create([
    ...$data,
    'tenant_id' => $ctx->tenant_id
]);

$this->audit->log(
    'BUSINESS',
    'businesses',
    $business_id,
    'CREATE',
    null,
    [...],
    $editing_user_id,
    null,
    $business_id
);

$this->db->commit();
```

**Fixed code:**
```php
$business_id = $this->businessRepo->create([
    ...$data,
    'tenant_id' => $ctx->tenant_id
]);

// Create default account for the new business
$currency = $data['currency'] ?? 'USD';
$accountName = $data['name'] . ' ' . $currency;
$this->accountRepo->create([
    'business_id' => $business_id,
    'name'        => $accountName,
    'currency'    => $currency,
]);

$this->audit->log(
    'BUSINESS',
    'businesses',
    $business_id,
    'CREATE',
    null,
    [...],
    $editing_user_id,
    null,
    $business_id
);

$this->db->commit();
```

**Additional change**: Add `AccountRepository` as a class property and instantiate it in the constructor:

```php
use App\Repository\AccountRepository;

class BusinessService {
    private BusinessRepository $businessRepo;
    private AccountRepository  $accountRepo;
    private AuditService $audit;
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->businessRepo = new BusinessRepository($this->db);
        $this->accountRepo  = new AccountRepository($this->db);
        $this->audit = new AuditService($this->db);
    }
```

---

### Bug 11 Fix — 16 Methods Across 5 Services

For each of the 16 affected methods, wrap the data operation and `audit->log()` call in `beginTransaction()`/`commit()`/`rollBack()`:

**Pattern:**
```php
public function methodName(...): ... {
    // ... validation and setup ...

    $this->db->beginTransaction();
    try {
        // data operation
        $this->repo->operation(...);

        // audit log
        $this->audit->log(...);

        $this->db->commit();
    } catch (\Throwable $e) {
        $this->db->rollBack();
        throw $e;
    }
}
```

**Files and methods:**
- `src/Services/SalaryService.php` — `create()`, `update()`, `delete()`
- `src/Services/ExchangeRateService.php` — `create()`, `update()`, `delete()`
- `src/Services/AccountService.php` — `create()`, `update()`, `delete()`
- `src/Services/ExpenseService.php` — `create()`, `update()`, `delete()` (expense CRUD, not categories)
- `src/Services/ItemService.php` — `deactivate()`, `createType()`, `updateType()`, `deleteType()`

**Special cases for Bug 12 co-fix:**
- `AccountService::delete()` — the entire method (findById, balance check, countSlips check, repo->delete, audit->log) goes inside the transaction
- `ItemService::deleteType()` — the entire method (countItemsForType check, getTypeById, repo->deleteType, audit->log) goes inside the transaction

---

### Bug 12 Fix — 3 Delete Methods

**File**: `src/Services/AccountService.php` — `delete()`

The Bug 11 transaction wrapping for this method also addresses Bug 12. The balance check, slip count check, `repo->delete()`, and `audit->log()` are all inside a single transaction:

```php
public function delete(int $id): void {
    $ctx = AuthContext::get();

    $this->db->beginTransaction();
    try {
        $account = $this->repo->findById($id, $ctx->business_id);
        if (!$account) {
            throw new \RuntimeException('Account not found');
        }

        if ((float) $account['balance'] != 0) {
            throw new \RuntimeException('Cannot delete: account has a non-zero balance');
        }

        if ($this->repo->countSlips($id) > 0) {
            throw new \RuntimeException('Cannot delete: account has linked transactions');
        }

        $this->repo->delete($id, $ctx->business_id);

        $this->audit->log(
            'ACCOUNTS', 'business_accounts', $id, 'DELETE',
            $account, null,
            $ctx->user_id, $ctx->tenant_id, $ctx->business_id
        );

        $this->db->commit();
    } catch (\Throwable $e) {
        $this->db->rollBack();
        throw $e;
    }
}
```

**File**: `src/Services/ItemService.php` — `deleteType()`

Same approach — the entire method goes inside a transaction:

```php
public function deleteType(int $id): void {
    $ctx = AuthContext::get();

    $this->db->beginTransaction();
    try {
        $usage = $this->repo->countItemsForType($id);
        if ($usage > 0) {
            throw new \RuntimeException('Cannot delete: item type is in use by active items');
        }

        $before = $this->repo->getTypeById($id, $ctx->tenant_id);
        if (!$before) {
            throw new \RuntimeException('Item type not found');
        }

        $this->repo->deleteType($id, $ctx->tenant_id);

        $this->audit->log('ITEMS', 'item_types', $id, 'DELETE', $before, null,
            $ctx->user_id, $ctx->tenant_id, $ctx->business_id);

        $this->db->commit();
    } catch (\Throwable $e) {
        $this->db->rollBack();
        throw $e;
    }
}
```

**File**: `src/Services/PeopleService.php` — `delete()`

Move the `countTransactionsForPayee()` check inside the existing transaction block:

**Current code:**
```php
public function delete(int $id, int $tenantId, int $businessId, int $editorId): void {
    // Check if payee has transactions
    $payee = $this->peopleRepo->findPayee($id, $businessId);
    if ($payee) {
        $txCount = $this->peopleRepo->countTransactionsForPayee($payee['id']);
        if ($txCount > 0) {
            throw new \RuntimeException(
                'Cannot delete: this person has transactions in this business'
            );
        }
    }

    $this->db->beginTransaction();
    try {
        $before = $this->peopleRepo->findById($id, $tenantId);
        ...
```

**Fixed code:**
```php
public function delete(int $id, int $tenantId, int $businessId, int $editorId): void {
    $this->db->beginTransaction();
    try {
        // Check if payee has transactions (inside transaction to prevent TOCTOU)
        $payee = $this->peopleRepo->findPayee($id, $businessId);
        if ($payee) {
            $txCount = $this->peopleRepo->countTransactionsForPayee($payee['id']);
            if ($txCount > 0) {
                throw new \RuntimeException(
                    'Cannot delete: this person has transactions in this business'
                );
            }
        }

        $before = $this->peopleRepo->findById($id, $tenantId);
        if (!$before) throw new \RuntimeException('Person not found');

        $this->peopleRepo->delete($id, $tenantId);

        $this->audit->log('PEOPLE', 'people', $id, 'DELETE', $before, null,
            $editorId, $tenantId, $businessId);

        $this->db->commit();
    } catch (\Throwable $e) {
        $this->db->rollBack();
        throw $e;
    }
}
```

---

### Bug 13 Fix — 2 HTML Templates

Same card-header pattern as Bug 9, applied to:
- `public/html/Finance/accounts.html`
- `public/html/Finance/exchangeRates.html`

These follow the exact same transformation described in Bug 9.

---

### Bug 14 Fix — `accounts.js` and `exchangeRates.js`

**File**: `public/assets/js/pages/Finance/accounts.js`
**Function**: `fetchAccounts()`

**Current code:**
```js
renderTable(data.data.accounts ?? data.data);
```

**Fixed code:**
```js
renderTable(data.data.accounts ?? []);
```

**File**: `public/assets/js/pages/Finance/exchangeRates.js`
**Function**: `fetchExchangeRates()`

**Current code:**
```js
renderTable(data.data.exchange_rates ?? data.data);
```

**Fixed code:**
```js
renderTable(data.data.exchange_rates ?? []);
```

**Rationale**: Falling back to `[]` ensures `renderTable()` always receives an array, even if the expected key is missing from the API response.

## Testing Strategy

### Validation Approach

The testing strategy follows a two-phase approach: first, surface counterexamples that demonstrate the bugs on unfixed code, then verify the fixes work correctly and preserve existing behavior.

### Exploratory Bug Condition Checking

**Goal**: Surface counterexamples that demonstrate the bugs BEFORE implementing the fixes. Confirm or refute the root cause analysis. If we refute, we will need to re-hypothesize.

**Test Plan**: Write tests that exercise each bug condition on the UNFIXED code to observe failures and confirm root causes.

**Test Cases**:
1. **Bug 1 — Business Visibility**: Query `getAllForCurrentUser()` for a user with both owner and non-owner roles → expect non-owned businesses to appear (will fail on unfixed code by returning too many results)
2. **Bug 2 — Items Query**: Call `getAllForBusiness()` with any valid tenant/business IDs → expect PDO exception due to duplicate named parameter (will fail on unfixed code)
3. **Bugs 3–8 — Payee Select**: Call `populatePayeeSelect()` with a mock API response `{ data: { people: [...] } }` → expect TypeError on `.filter()` (will fail on unfixed code)
4. **Bug 9+13 — Button Placement**: Parse each affected HTML template and check if action buttons are children of `.card-header` → expect false (will fail on unfixed code)
5. **Bug 10 — Default Account**: Call `BusinessService::create()` and then query accounts for the new business → expect zero accounts (will fail on unfixed code by returning empty)
6. **Bug 11 — Audit Outside Transaction**: Mock `audit->log()` to throw, call any of the 16 methods → expect data operation to be committed despite audit failure (will fail on unfixed code)
7. **Bug 12 — Race Condition**: Simulate concurrent modification between guard check and delete → expect guard to be bypassed (will fail on unfixed code)
8. **Bug 14 — Fallback Pattern**: Call `fetchAccounts()` with a response missing the `accounts` key → expect `renderTable()` to receive the wrapper object (will fail on unfixed code)

**Expected Counterexamples**:
- Bug 1: User with role_id=2 in Business B sees Business B on the Businesses page
- Bug 2: PDO throws `PDOException: SQLSTATE[HY093]` for duplicate parameter
- Bugs 3–8: `TypeError: json.data.filter is not a function`
- Bug 11: Salary record exists in database but no audit log entry exists

### Fix Checking

**Goal**: Verify that for all inputs where the bug condition holds, the fixed function produces the expected behavior.

**Pseudocode:**
```
FOR ALL input WHERE isBugCondition(input) DO
  result := fixedFunction(input)
  ASSERT expectedBehavior(result)
END FOR
```

### Preservation Checking

**Goal**: Verify that for all inputs where the bug condition does NOT hold, the fixed function produces the same result as the original function.

**Pseudocode:**
```
FOR ALL input WHERE NOT isBugCondition(input) DO
  ASSERT originalFunction(input) = fixedFunction(input)
END FOR
```

**Testing Approach**: Property-based testing is recommended for preservation checking because:
- It generates many test cases automatically across the input domain
- It catches edge cases that manual unit tests might miss
- It provides strong guarantees that behavior is unchanged for all non-buggy inputs

**Test Plan**: Observe behavior on UNFIXED code first for non-bug inputs, then write property-based tests capturing that behavior.

**Test Cases**:
1. **Business Visibility Preservation**: Verify that businesses where the user IS the owner continue to appear in the query results
2. **Item Data Preservation**: Verify that the fixed query returns the same columns and data as the original would have
3. **Payee Select Preservation**: Verify that when `json.data.people` is a valid array, the same payees are filtered and populated
4. **Button Functionality Preservation**: Verify that all `data-action` attributes are preserved after the HTML restructuring
5. **Business Creation Preservation**: Verify that the business record itself is created identically, with the default account as an addition
6. **Audit Success Preservation**: Verify that when both data operation and audit succeed, the outcome is identical to the original
7. **Delete Success Preservation**: Verify that deletes with no concurrent modifications produce the same result
8. **API Response Preservation**: Verify that when the expected data key is present, `renderTable()` receives the same array

### Unit Tests

- Test `getAllForCurrentUser()` with users having multiple roles across businesses
- Test `getAllForBusiness()` executes without PDO errors and returns correct items
- Test `populatePayeeSelect()` with the actual API response structure `{ data: { people: [...] } }`
- Test each HTML template has buttons inside `.card-header`
- Test `BusinessService::create()` creates both a business and a default account
- Test each of the 16 methods with a failing audit mock to verify rollback
- Test `AccountService::delete()`, `ItemService::deleteType()`, `PeopleService::delete()` guard checks are inside transactions
- Test `fetchAccounts()` and `fetchExchangeRates()` fallback to empty array

### Property-Based Tests

- Generate random user-role assignments and verify only BUSINESS_OWNER businesses are returned
- Generate random tenant/business IDs and verify the items query executes successfully
- Generate random People API responses and verify `populatePayeeSelect()` handles all shapes correctly
- Generate random business creation data and verify a default account is always created with correct name/currency
- Generate random service method calls with audit success/failure and verify transaction atomicity
- Generate random API responses with/without expected keys and verify correct fallback behavior

### Integration Tests

- Test full flow: create business → verify default account exists → create payment using default account
- Test full flow: navigate to Items page → verify DataTable renders with items
- Test full flow: open each of the 6 modals → verify payee select populates correctly
- Test full flow: navigate to each of the 10 affected pages → verify button placement in card-header
- Test full flow: create salary with audit failure → verify salary record is rolled back
- Test full flow: delete account with concurrent payment → verify guard prevents deletion