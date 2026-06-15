# Implementation Plan

## Bug A — Items list not loading in PO/Invoice form modals

- [x] 1. Fix item list parsing in PO form modal
  - [x] 1.1 Update `loadItems()` in `public/assets/js/pages/PurchaseOrders/poForm.modal.js`
    - Change `itemsList = json.success ? (json.data ?? []) : [];`
    - To `itemsList = json.success ? (json.data?.items ?? []) : [];`
    - This extracts the `items` array from the `{ items: [...] }` wrapper object
    - _Requirements: 2.1_

  - [x] 1.2 Update `loadItems()` in `public/assets/js/pages/SalesInvoices/invoiceForm.modal.js`
    - Change `itemsList = json.success ? (json.data ?? []) : [];`
    - To `itemsList = json.success ? (json.data?.items ?? []) : [];`
    - Same fix as PO form — extracts the `items` array from the wrapper object
    - _Requirements: 2.2_

## Bug B — PO/Invoice/Slip number generation produces duplicates

- [x] 2. Fix number generation to use MAX instead of COUNT
  - [x] 2.1 Update `generatePoNumber()` in `src/Repository/PurchaseOrderRepository.php`
    - Replace `SELECT COUNT(*) AS cnt` query with `SELECT MAX(CAST(SUBSTRING(po_number, :prefixLen + 1) AS UNSIGNED)) AS max_seq`
    - Filter with `po_number LIKE :prefix` instead of `YEAR(po_date) = :year`
    - Build prefix as `'PO-' . $year . '-'` and compute `$prefixLen = strlen($prefix)`
    - Set `$next = ($row['max_seq'] !== null) ? ((int) $row['max_seq'] + 1) : 1`
    - Return `$prefix . str_pad((string) $next, 3, '0', STR_PAD_LEFT)`
    - _Requirements: 2.3_

  - [x] 2.2 Update `generateInvoiceNumber()` in `src/Repository/SalesInvoiceRepository.php`
    - Replace `SELECT COUNT(*) AS cnt` query with `SELECT MAX(CAST(SUBSTRING(invoice_number, :prefixLen + 1) AS UNSIGNED)) AS max_seq`
    - Filter with `invoice_number LIKE :prefix` instead of `YEAR(invoice_date) = :year`
    - Build prefix as `'INV-' . $year . '-'` and compute `$prefixLen = strlen($prefix)`
    - Set `$next = ($row['max_seq'] !== null) ? ((int) $row['max_seq'] + 1) : 1`
    - Return `$prefix . str_pad((string) $next, 3, '0', STR_PAD_LEFT)`
    - _Requirements: 2.4_

  - [x] 2.3 Update `generateSlipNumber()` in `src/Repository/SalesInvoiceRepository.php`
    - Replace `SELECT COUNT(*) AS cnt` query with `SELECT MAX(CAST(SUBSTRING(slip_number, :prefixLen + 1) AS UNSIGNED)) AS max_seq`
    - Filter with `slip_number LIKE :prefix` instead of `YEAR(slip_date) = :year`
    - Build prefix as `'CS-' . $year . '-'` and compute `$prefixLen = strlen($prefix)`
    - Set `$next = ($row['max_seq'] !== null) ? ((int) $row['max_seq'] + 1) : 1`
    - Return `$prefix . str_pad((string) $next, 3, '0', STR_PAD_LEFT)`
    - _Requirements: 2.5_

## Bug C — Sales Invoice PAY: no account-business validation

- [x] 3. Add account-business ownership validation in `src/Services/SalesInvoiceService.php`
  - [x] 3.1 Add account validation in `updateStatus()` method
    - Inside the `if ($accountId !== null)` block, before `$this->repo->generateSlipNumber()`
    - Call `$account = $this->accountRepo->findById($accountId, $ctx->business_id);`
    - If `!$account`, throw `new \RuntimeException('Account not found or does not belong to this business')`
    - The `$this->accountRepo` property already exists on the service
    - _Bug_Condition: account_id provided AND account.business_id !== current business_
    - _Expected_Behavior: RuntimeException thrown before collection slip creation_
    - _Preservation: Valid same-business accounts and null account_id continue to work_
    - _Requirements: 2.6_

## Bug D — Expense category deletion race condition

- [x] 4. Wrap category deletion in a transaction in `src/Services/ExpenseService.php`
  - [x] 4.1 Update `deleteCategory()` to use a transaction
    - After the `findCategoryById()` check (keep that outside the transaction)
    - Add `$this->db->beginTransaction();` and wrap the `countExpensesForCategory()` check, `deleteCategory()` call, and `audit->log()` call inside a `try/catch`
    - Call `$this->db->commit()` after audit log
    - In the `catch (\Throwable $e)` block, call `$this->db->rollBack()` then `throw $e`
    - _Bug_Condition: concurrent delete + create expense on same category_
    - _Expected_Behavior: atomic check-then-delete prevents TOCTOU race_
    - _Preservation: Non-concurrent deletes behave identically_
    - _Requirements: 2.7_

## Bug E — Salary validation gaps

- [x] 5. Add date range and tax validation in `src/Services/SalaryService.php`
  - [x] 5.1 Add `period_end >= period_start` validation in `validateData()`
    - After the existing required-field checks, add:
    - `if ($data['period_end'] < $data['period_start'])` → throw `new \InvalidArgumentException('Period end must not be before period start')`
    - String comparison works correctly for `YYYY-MM-DD` date format
    - _Requirements: 2.8_

  - [x] 5.2 Add `tax_fc <= gross_amount_fc` validation in `validateData()`
    - After the date range check, add:
    - `$grossAmountFc = (float) $data['gross_amount_fc'];`
    - `$taxFc = isset($data['tax_fc']) && $data['tax_fc'] !== '' ? (float) $data['tax_fc'] : 0.0;`
    - `if ($taxFc > $grossAmountFc)` → throw `new \InvalidArgumentException('Tax cannot exceed gross amount')`
    - _Requirements: 2.9_

## Bug F — PO/Invoice edit modal sends PUT to no-op endpoint

- [x] 6. Make edit modals read-only for existing records
  - [x] 6.1 Update `mount()` in `public/assets/js/pages/PurchaseOrders/poForm.modal.js`
    - After the `if (currentPoId) { await fetchAndFill(currentPoId); }` block
    - Add code to disable all `input`, `select`, `textarea` elements via `el.disabled = true`
    - Hide all elements with `data-action="po.save"`, `data-action="po.add-line"`, and `data-action="po.remove-line"` via `el.style.display = 'none'`
    - This prevents the user from triggering the unsupported PUT endpoint
    - _Requirements: 2.10_

  - [x] 6.2 Update `mount()` in `public/assets/js/pages/SalesInvoices/invoiceForm.modal.js`
    - After the `if (currentInvoiceId) { await fetchAndFill(currentInvoiceId); }` block
    - Add code to disable all `input`, `select`, `textarea` elements via `el.disabled = true`
    - Hide all elements with `data-action="invoice.save"`, `data-action="invoice.add-line"`, and `data-action="invoice.remove-line"` via `el.style.display = 'none'`
    - This prevents the user from triggering the unsupported PUT endpoint
    - _Requirements: 2.11_

## Bug G — Audit logging outside transaction in Payment/Collection services

- [x] 7. Move audit logging inside transactions
  - [x] 7.1 Move `audit->log()` inside the transaction in `PaymentService::create()` (`src/Services/PaymentService.php`)
    - Move the `$this->audit->log('PAYMENTS', ...)` call from after the `try/catch` block to inside the `try` block, before `$this->db->commit()`
    - Keep the `return $id;` after the `try/catch` block
    - _Requirements: 2.12_

  - [x] 7.2 Move `audit->log()` inside the transaction in `PaymentService::delete()` (`src/Services/PaymentService.php`)
    - Move the `$this->audit->log('PAYMENTS', ...)` call from after the `try/catch` block to inside the `try` block, before `$this->db->commit()`
    - _Requirements: 2.13_

  - [x] 7.3 Move `audit->log()` inside the transaction in `CollectionService::create()` (`src/Services/CollectionService.php`)
    - Move the `$this->audit->log('COLLECTIONS', ...)` call from after the `try/catch` block to inside the `try` block, before `$this->db->commit()`
    - Keep the `return $id;` after the `try/catch` block
    - _Requirements: 2.14_

  - [x] 7.4 Move `audit->log()` inside the transaction in `CollectionService::delete()` (`src/Services/CollectionService.php`)
    - Move the `$this->audit->log('COLLECTIONS', ...)` call from after the `try/catch` block to inside the `try` block, before `$this->db->commit()`
    - _Requirements: 2.15_

## Bug H — BUSINESS_OWNER missing menu permissions

- [x] 8. Verify the fix migration SQL is correct
  - [x] 8.1 Review `database/migrations/fix_business_owner_permissions.sql`
    - Confirm the migration uses `INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT 1, p.id FROM permissions p WHERE p.id NOT IN (...)`
    - Confirm it is idempotent (safe to run multiple times)
    - Confirm it grants ALL permissions to role_id=1 (BUSINESS_OWNER) regardless of permission ID ranges
    - No code changes needed — the migration file already exists and is correct
    - Note: after running this migration, users must log out and log back in for the new permissions to appear in their JWT token and for menu items to become visible
    - _Requirements: 2.16_

## Bug I — Sidebar menu cleanup and reorganization

- [x] 9. Clean up dummy menu entries and reorganize sidebar in `public/assets/js/Shells/appShell.config.js`
  - [x] 9.1 Remove the "Charts" and "Tables" dummy entries from `MENU_CONFIG`
    - Remove the object with `id: 'goToCharts'` (hash: `#/charts`, permission: `charts.view`)
    - Remove the object with `id: 'goToTables'` (hash: `#/tables`, permission: `tables.view`)
    - These are demo pages with no real ERP functionality
    - _Requirements: 2.17_

  - [x] 9.2 Reorganize `MENU_CONFIG` entries in a logical ERP order
    - Reorder the remaining entries into logical groups:
    - **Overview**: Dashboard
    - **Core Data**: Businesses, Users, People, Items
    - **Operations**: Purchase Orders, Invoices, Expenses, Salaries
    - **Finance**: Accounts, Exchange Rates, Payments, Collections
    - Keep all existing `id`, `label`, `icon`, `hash`, and `permission` values unchanged — only change the order
    - _Requirements: 2.18_

  - [x] 9.3 Remove dummy routes from `public/assets/js/router/router.config.js`
    - Remove the route `{path: '#/charts', auth: true, permission: 'charts.view', load: () => import('../pages/Others/charts.js')}`
    - Remove the route `{path: '#/tables', auth: true, permission: 'tables.view', load: () => import('../pages/Others/tables.js')}`
    - _Requirements: 2.19_

## Final Checkpoint

- [x] 10. Checkpoint — Verify all changes
  - Review all modified files for correctness
  - Ensure PHP 7.4 compatibility (no match expressions, no named arguments, no union types)
  - Ensure no new dependencies were introduced
  - Confirm all nine bugs (A–I) have been addressed
  - Verify the sidebar menu shows the correct modules in logical order without dummy entries
