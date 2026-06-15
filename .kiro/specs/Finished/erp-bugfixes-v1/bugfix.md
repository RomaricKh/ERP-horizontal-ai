# Bugfix Requirements Document

## Introduction

This document covers eight bugs discovered during a comprehensive code review of the multi-tenant PHP ERP system. The bugs span frontend JavaScript (item loading, modal edit behavior), backend PHP (number generation collisions, missing authorization, race conditions, validation gaps, audit logging outside transactions), and database permissions. Collectively, these bugs affect core purchasing, invoicing, finance, salary, expense, and permission functionality.

---

## Bug Analysis

### Current Behavior (Defect)

**Bug A — Items list not loading in PO/Invoice form modals:**

1.1 WHEN the PO form modal opens and calls `loadItems()` THEN the system assigns `json.data` (an object `{ items: [...] }`) directly to `itemsList` instead of extracting the `items` array, resulting in empty item select dropdowns

1.2 WHEN the Invoice form modal opens and calls `loadItems()` THEN the system assigns `json.data` (an object `{ items: [...] }`) directly to `itemsList` instead of extracting the `items` array, resulting in empty item select dropdowns

**Bug B — PO/Invoice/Slip number generation can produce duplicates:**

1.3 WHEN a purchase order has been deleted for the current year and a new PO is created THEN the system uses `COUNT(*)` to determine the next sequence number, which can produce a PO number that already exists (e.g., delete PO-2026-002 of 3, next generated is PO-2026-003 which is a duplicate)

1.4 WHEN a sales invoice has been deleted for the current year and a new invoice is created THEN the system uses `COUNT(*)` to determine the next invoice number, which can produce an invoice number that already exists

1.5 WHEN a collection slip has been deleted for the current year and a new slip is created THEN the system uses `COUNT(*)` to determine the next slip number, which can produce a slip number that already exists

**Bug C — Sales Invoice PAY: no validation that account belongs to the business:**

1.6 WHEN a sales invoice is marked as PAID with an `account_id` THEN the system creates a collection slip and credits the account balance without verifying that the account belongs to the same business, allowing cross-business account manipulation

**Bug D — Expense category deletion race condition:**

1.7 WHEN two concurrent requests occur — one adding an expense to a category and another deleting that category — THEN the system checks `countExpensesForCategory()` and then deletes without a transaction, allowing the delete to succeed after an expense was added, violating referential integrity

**Bug E — Salary validation gaps:**

1.8 WHEN a salary record is created or updated with `period_end` earlier than `period_start` THEN the system accepts the invalid date range without error

1.9 WHEN a salary record is created or updated with `tax_fc` greater than `gross_amount_fc` THEN the system produces a negative `net_amount_fc` without error

**Bug F — PO/Invoice edit modal sends PUT to a no-op endpoint:**

1.10 WHEN a user opens the PO form modal for an existing purchase order and clicks save THEN the system sends a PUT request to `/api/purchase-orders/{id}` which returns a 422 error "Use specific endpoints to modify a purchase order", so the save always fails

1.11 WHEN a user opens the Invoice form modal for an existing sales invoice and clicks save THEN the system sends a PUT request to `/api/sales-invoices/{id}` which returns a 422 error "Use specific endpoints to modify a sales invoice", so the save always fails

**Bug G — Audit logging outside transaction in Payment/Collection services:**

1.12 WHEN a payment slip is created and the transaction commits successfully but the subsequent `audit->log()` call fails THEN the payment is persisted without an audit trail entry

1.13 WHEN a payment slip is deleted and the transaction commits successfully but the subsequent `audit->log()` call fails THEN the deletion is persisted without an audit trail entry

1.14 WHEN a collection slip is created and the transaction commits successfully but the subsequent `audit->log()` call fails THEN the collection is persisted without an audit trail entry

1.15 WHEN a collection slip is deleted and the transaction commits successfully but the subsequent `audit->log()` call fails THEN the deletion is persisted without an audit trail entry

**Bug H — Menu items not showing for BUSINESS_OWNER:**

1.16 WHEN the original migration used `WHERE id > 25` to grant new permissions to BUSINESS_OWNER and the permissions table did not have exactly 25 pre-existing rows THEN some ERP permissions were not granted, causing `hasPermission()` to return false and hiding menu items

**Bug I — Sidebar menu contains dummy entries and lacks proper module organization:**

1.17 WHEN the sidebar menu renders THEN it shows "Charts" and "Tables" entries that point to dummy demo pages with no real ERP functionality, cluttering the navigation

1.18 WHEN the sidebar menu renders THEN the ERP module entries (Exchange Rates, Accounts, Expenses, etc.) are listed as a flat unorganized list without logical grouping, making it hard for users to find related modules

1.19 WHEN the router resolves `#/charts` or `#/tables` THEN it loads demo pages (`charts.js`, `tables.js`) that serve no purpose in the production ERP system

---

### Expected Behavior (Correct)

**Bug A — Items list not loading in PO/Invoice form modals:**

2.1 WHEN the PO form modal opens and calls `loadItems()` THEN the system SHALL extract the items array via `json.data?.items ?? []` so that `itemsList` contains the correct array of item objects and the select dropdowns are populated

2.2 WHEN the Invoice form modal opens and calls `loadItems()` THEN the system SHALL extract the items array via `json.data?.items ?? []` so that `itemsList` contains the correct array of item objects and the select dropdowns are populated

**Bug B — PO/Invoice/Slip number generation can produce duplicates:**

2.3 WHEN generating a new PO number THEN the system SHALL use `MAX()` to find the highest existing numeric suffix for the current year and business, parse it, and add 1, ensuring no duplicate PO numbers even after deletions

2.4 WHEN generating a new invoice number THEN the system SHALL use `MAX()` to find the highest existing numeric suffix for the current year and business, parse it, and add 1, ensuring no duplicate invoice numbers even after deletions

2.5 WHEN generating a new collection slip number THEN the system SHALL use `MAX()` to find the highest existing numeric suffix for the current year and business, parse it, and add 1, ensuring no duplicate slip numbers even after deletions

**Bug C — Sales Invoice PAY: no validation that account belongs to the business:**

2.6 WHEN a sales invoice is marked as PAID with an `account_id` THEN the system SHALL validate that the account belongs to the current business (via `AccountRepository::findById($accountId, $ctx->business_id)`) before creating the collection slip, and SHALL throw an error if the account does not belong to the business

**Bug D — Expense category deletion race condition:**

2.7 WHEN deleting an expense category THEN the system SHALL wrap the `countExpensesForCategory()` check and the `deleteCategory()` call inside a database transaction to prevent race conditions

**Bug E — Salary validation gaps:**

2.8 WHEN a salary record is created or updated THEN the system SHALL validate that `period_end >= period_start` and throw an `InvalidArgumentException` if the end date is before the start date

2.9 WHEN a salary record is created or updated THEN the system SHALL validate that `gross_amount_fc >= tax_fc` and throw an `InvalidArgumentException` if the tax exceeds the gross amount (which would produce a negative net amount)

**Bug F — PO/Invoice edit modal sends PUT to a no-op endpoint:**

2.10 WHEN the PO form modal is opened for an existing purchase order (i.e., `currentPoId` is set) THEN the system SHALL render the form in read-only mode by disabling all form fields and hiding the save button, preventing the user from attempting a PUT request that will fail

2.11 WHEN the Invoice form modal is opened for an existing sales invoice (i.e., `currentInvoiceId` is set) THEN the system SHALL render the form in read-only mode by disabling all form fields and hiding the save button, preventing the user from attempting a PUT request that will fail

**Bug G — Audit logging outside transaction in Payment/Collection services:**

2.12 WHEN a payment slip is created THEN the system SHALL call `audit->log()` inside the transaction (before `commit()`) so that if the audit insert fails, the entire operation is rolled back

2.13 WHEN a payment slip is deleted THEN the system SHALL call `audit->log()` inside the transaction (before `commit()`) so that if the audit insert fails, the entire operation is rolled back

2.14 WHEN a collection slip is created THEN the system SHALL call `audit->log()` inside the transaction (before `commit()`) so that if the audit insert fails, the entire operation is rolled back

2.15 WHEN a collection slip is deleted THEN the system SHALL call `audit->log()` inside the transaction (before `commit()`) so that if the audit insert fails, the entire operation is rolled back

**Bug H — Menu items not showing for BUSINESS_OWNER:**

2.16 WHEN the fix migration `fix_business_owner_permissions.sql` is executed THEN the system SHALL grant all permissions in the `permissions` table to BUSINESS_OWNER (role_id = 1) using `INSERT IGNORE` with a subquery that selects all permission IDs not already assigned, ensuring idempotent and complete permission assignment regardless of pre-existing row counts

**Bug I — Sidebar menu contains dummy entries and lacks proper module organization:**

2.17 WHEN the sidebar menu renders THEN the system SHALL NOT show "Charts" or "Tables" entries — these dummy demo pages SHALL be removed from `MENU_CONFIG` in `appShell.config.js`

2.18 WHEN the sidebar menu renders THEN the system SHALL organize ERP modules in a logical order: Dashboard, then core data (Businesses, Users, People, Items), then operations (Purchase Orders, Sales Invoices, Expenses, Salaries), then finance (Accounts, Exchange Rates, Payments, Collections)

2.19 WHEN the router resolves routes THEN the `#/charts` and `#/tables` dummy routes SHALL be removed from `router.config.js`

---

### Unchanged Behavior (Regression Prevention)

**Bug A — Items list not loading in PO/Invoice form modals:**

3.1 WHEN the API `GET /api/items` returns an empty items array THEN the system SHALL CONTINUE TO set `itemsList` to an empty array and show "— Select item —" as the only dropdown option

3.2 WHEN the API `GET /api/items` fails or returns `success: false` THEN the system SHALL CONTINUE TO set `itemsList` to an empty array gracefully

**Bug B — PO/Invoice/Slip number generation can produce duplicates:**

3.3 WHEN no purchase orders exist for the current year and business THEN the system SHALL CONTINUE TO generate `PO-{YYYY}-001` as the first PO number

3.4 WHEN no sales invoices exist for the current year and business THEN the system SHALL CONTINUE TO generate `INV-{YYYY}-001` as the first invoice number

3.5 WHEN no collection slips exist for the current year and business THEN the system SHALL CONTINUE TO generate `CS-{YYYY}-001` as the first slip number

3.6 WHEN purchase orders exist sequentially without deletions THEN the system SHALL CONTINUE TO generate the next sequential PO number correctly

**Bug C — Sales Invoice PAY: no validation that account belongs to the business:**

3.7 WHEN a sales invoice is marked as PAID with a valid `account_id` that belongs to the business THEN the system SHALL CONTINUE TO create the collection slip and credit the account balance as before

3.8 WHEN a sales invoice is marked as PAID without an `account_id` (null) THEN the system SHALL CONTINUE TO mark the invoice as PAID without creating a collection slip

**Bug D — Expense category deletion race condition:**

3.9 WHEN deleting an expense category that has no linked expenses and no concurrent requests THEN the system SHALL CONTINUE TO delete the category successfully and log the audit entry

3.10 WHEN attempting to delete an expense category that has linked expenses THEN the system SHALL CONTINUE TO reject the deletion with the error "Cannot delete: category has linked expenses"

**Bug E — Salary validation gaps:**

3.11 WHEN a salary record is created with valid data where `period_end >= period_start` and `gross_amount_fc >= tax_fc` THEN the system SHALL CONTINUE TO calculate and store all derived amounts correctly

3.12 WHEN a salary record is created with `tax_fc` equal to zero THEN the system SHALL CONTINUE TO set `net_amount_fc` equal to `gross_amount_fc`

**Bug F — PO/Invoice edit modal sends PUT to a no-op endpoint:**

3.13 WHEN the PO form modal is opened for creating a new purchase order (i.e., `currentPoId` is null) THEN the system SHALL CONTINUE TO allow full form editing and POST submission as before

3.14 WHEN the Invoice form modal is opened for creating a new sales invoice (i.e., `currentInvoiceId` is null) THEN the system SHALL CONTINUE TO allow full form editing and POST submission as before

**Bug G — Audit logging outside transaction in Payment/Collection services:**

3.15 WHEN a payment or collection operation succeeds including the audit log THEN the system SHALL CONTINUE TO commit all changes atomically

3.16 WHEN a payment or collection operation fails before commit THEN the system SHALL CONTINUE TO roll back all changes including the audit log entry

**Bug H — Menu items not showing for BUSINESS_OWNER:**

3.17 WHEN the fix migration is run on a system where BUSINESS_OWNER already has all permissions THEN the system SHALL CONTINUE TO have all permissions intact (idempotent — `INSERT IGNORE` skips duplicates)

3.18 WHEN other roles (non-BUSINESS_OWNER) have their own permission sets THEN the system SHALL CONTINUE TO leave those role permissions unchanged

**Bug I — Sidebar menu contains dummy entries and lacks proper module organization:**

3.19 WHEN the sidebar menu renders THEN the system SHALL CONTINUE TO show Dashboard, People, Items, Businesses, Users, and all ERP module entries that the user has permission for

3.20 WHEN the sidebar menu renders THEN the system SHALL CONTINUE TO use `hasPermission()` to gate each menu item — only items the user has permission for SHALL be shown

3.21 WHEN the user navigates to Settings, Activity Log, or Profile THEN those SHALL CONTINUE TO be accessible from the user dropdown menu (USER_INFO_CONFIG), not the sidebar
