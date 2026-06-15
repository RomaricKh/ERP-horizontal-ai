# ERP System — Technical Design

## System Architecture (existing, must be followed)

```
public/
  index.html              ← SPA entry point
  api/
    index.php             ← API front controller
    routes/               ← Route definitions (one file per module)
  assets/
    js/
      app.js              ← Bootstrap: router + loading overlay
      Auth/               ← auth.js, apiFetch.js, reauth.js
      router/             ← router.js, router.config.js, resolve.js, renderPage.js
      Shells/             ← appShell.js, appShell.config.js
      components/         ← modals.js, componentManager.js, loading.js, etc.
      pages/              ← one folder per module
        Core/pageActions.js
      templates/          ← datatables.design.js
    css/, vendor/
  html/                   ← HTML templates (one folder per module)

src/
  Config/                 ← Database.php, config.php
  Controllers/            ← one per module
  Services/               ← one per module
  Repository/             ← one per module
  Middleware/             ← AuthMiddleware.php
  Support/                ← Router.php, AuthContext.php
```

## Database Schema Reference

### New tables used by remaining modules

**exchange_rates**: tenant_id, business_id, currency VARCHAR(10), rate_date DATE, rate DECIMAL(12,6), source, created_at

**business_accounts**: business_id, name, currency, balance DECIMAL(18,2), created_at, updated_at

**expense_categories**: tenant_id, business_id, name, is_fixed TINYINT(1), created_at, updated_at, created_by

**expenses**: business_id, payee_id (→ payees.id), expense_category_id, expense_date, currency, exchange_rate, amount_fc, amount_bc, notes, created_at, updated_at

**purchase_orders**: business_id, supplier_id (→ payees.id), po_number, po_date, status ENUM('DRAFT','APPROVED','RECEIVED','CLOSED','CANCELLED'), currency, exchange_rate, total_amount_fc, total_amount_bc, notes, created_at, updated_at, created_by

**po_details**: purchase_order_id, item_id, quantity DECIMAL(12,3), unit_price_fc, unit_price_bc, total_price_fc, total_price_bc, created_at

**sales_invoices**: business_id, customer_id (→ payees.id), invoice_number, invoice_date, status ENUM('DRAFT','APPROVED','PAID','CANCELLED'), currency, exchange_rate, total_amount_fc, total_amount_bc, notes, created_at, updated_at, created_by

**si_details**: sales_invoice_id, item_id, quantity DECIMAL(12,3), unit_price_fc, unit_price_bc, total_price_fc, total_price_bc, created_at

**payment_slips**: business_id, account_id, payee_id, reference_type ENUM('EXPENSE','PO','SALARY','PARTNER_PROFIT'), reference_id, payment_number, payment_date, currency, exchange_rate, amount_fc, amount_bc, method ENUM('CASH','BANK','CHECK','TRANSFER'), notes, created_at, updated_at, created_by

**collection_slips**: business_id, account_id, payee_id, reference_type ENUM('CUSTOMER','PARTNER_CONTRIBUTION'), reference_id, slip_number, slip_date, currency, exchange_rate, amount_fc, amount_bc, method, notes, created_at, updated_at, created_by

**salaries**: business_id, payee_id, period_start DATE, period_end DATE, currency, exchange_rate, gross_amount_fc, gross_amount_bc, tax_fc, tax_bc, net_amount_fc, net_amount_bc, notes, created_at, updated_at

**settings_regional**: business_id PK, language, timezone, date_format, number_format, currency_format, phone_format

**audit_logs**: tenant_id, business_id, user_id, event_type, table_name, record_id, action VARCHAR(250), old_values JSON, new_values JSON, ip_address, user_agent, created_at

## Module Design

### D1 — Exchange Rates

**Backend files**:
- `src/Repository/ExchangeRateRepository.php` — findAllForBusiness, getLatestRate(currency, businessId), create, update, delete
- `src/Services/ExchangeRateService.php` — CRUD with audit
- `src/Controllers/ExchangeRatesController.php` — index, store, update(id), delete(id), latest (GET /exchange-rates/latest?currency=USD)
- `public/api/routes/exchange_rates.php`

**Frontend files**:
- `public/html/Finance/exchangeRates.html` — page with DataTable + manage button
- `public/html/Finance/exchangeRateForm.html` — create/edit modal
- `public/assets/js/pages/Finance/exchangeRates.js` — page module
- `public/assets/js/pages/Finance/exchangeRateForm.modal.js`

**Route**: `#/exchange-rates`, permission: `exchange-rates.manage`

---

### D2 — Business Accounts

**Backend files**:
- `src/Repository/AccountRepository.php` — findAllForBusiness, findById, create, update, delete, countSlips(accountId), updateBalance(accountId, delta)
- `src/Services/AccountService.php` — CRUD with guard on delete
- `src/Controllers/AccountsController.php` — index, store, update(id), delete(id)
- `public/api/routes/accounts.php`

**Frontend files**:
- `public/html/Finance/accounts.html` — page with DataTable
- `public/html/Finance/accountForm.html` — create/edit modal
- `public/assets/js/pages/Finance/accounts.js`
- `public/assets/js/pages/Finance/accountForm.modal.js`

**Route**: `#/accounts`, permission: `accounts.manage`

---

### D3 — Expenses (Categories + Expenses)

**Backend files**:
- `src/Repository/ExpenseRepository.php` — categories CRUD + expenses CRUD, findAllWithDetails (joins payees+people+categories)
- `src/Services/ExpenseService.php` — CRUD with audit, amount_bc = amount_fc * exchange_rate
- `src/Controllers/ExpensesController.php` — index, store, update(id), delete(id), indexCategories, storeCategory, updateCategory(id), deleteCategory(id)
- `public/api/routes/expenses.php`

**Frontend files**:
- `public/html/Expenses/expenses.html` — page with DataTable + Categories button
- `public/html/Expenses/expenseForm.html` — create/edit modal (payee select, category select, date, currency, rate, amount)
- `public/html/Expenses/expenseCategories.html` — manage categories modal (same inline pattern as payee types)
- `public/assets/js/pages/Expenses/expenses.js`
- `public/assets/js/pages/Expenses/expenseForm.modal.js`
- `public/assets/js/pages/Expenses/expenseCategories.modal.js`

**Route**: `#/expenses`, permission: `expenses.view`

---

### D4 — Purchase Orders

**Backend files**:
- `src/Repository/PurchaseOrderRepository.php` — findAllForBusiness (with supplier name), findById (with lines), create, addLine, removeLine, updateStatus, delete, recalculateTotals, generatePoNumber
- `src/Services/PurchaseOrderService.php` — create (with lines), updateStatus (APPROVE/RECEIVE triggers stock IN), delete (DRAFT only), addLine, removeLine
- `src/Controllers/PurchaseOrdersController.php` — index, store, viewById(id), update(id), delete(id), updateStatus(id), addLine(id), removeLine(id, lineId)
- `public/api/routes/purchase_orders.php`

**Frontend files**:
- `public/html/PurchaseOrders/purchaseOrders.html` — page with DataTable + status badges
- `public/html/PurchaseOrders/poForm.html` — create/edit modal with header fields + dynamic line items table
- `public/assets/js/pages/PurchaseOrders/purchaseOrders.js`
- `public/assets/js/pages/PurchaseOrders/poForm.modal.js` — handles line item add/remove/edit inline, recalculates totals

**Route**: `#/purchase-orders`, permission: `po.view`

**Status badge colors**: DRAFT=secondary, APPROVED=primary, RECEIVED=success, CLOSED=dark, CANCELLED=danger

---

### D5 — Sales Invoices

**Backend files**:
- `src/Repository/SalesInvoiceRepository.php` — findAllForBusiness (with customer name), findById (with lines), create, addLine, removeLine, updateStatus, delete, recalculateTotals, generateInvoiceNumber
- `src/Services/SalesInvoiceService.php` — create, updateStatus (APPROVE, PAY triggers collection slip creation, CANCEL), delete (DRAFT only), addLine, removeLine
- `src/Controllers/SalesInvoicesController.php` — index, store, viewById(id), update(id), delete(id), updateStatus(id), addLine(id), removeLine(id, lineId)
- `public/api/routes/sales_invoices.php`

**Frontend files**:
- `public/html/SalesInvoices/salesInvoices.html` — page with DataTable + status badges
- `public/html/SalesInvoices/invoiceForm.html` — create/edit modal with header + line items
- `public/assets/js/pages/SalesInvoices/salesInvoices.js`
- `public/assets/js/pages/SalesInvoices/invoiceForm.modal.js`

**Route**: `#/invoices`, permission: `invoices.view`

---

### D6 — Payments

**Backend files**:
- `src/Repository/PaymentRepository.php` — findAllForBusiness (with payee name, account name), findById, create, delete, updateAccountBalance
- `src/Services/PaymentService.php` — create (updates account balance), delete (reverses balance)
- `src/Controllers/PaymentsController.php` — index, store, viewById(id), delete(id)
- `public/api/routes/payments.php`

**Frontend files**:
- `public/html/Finance/payments.html` — page with DataTable
- `public/html/Finance/paymentForm.html` — create modal (account select, payee, ref type/id, number, date, currency, rate, amount, method)
- `public/assets/js/pages/Finance/payments.js`
- `public/assets/js/pages/Finance/paymentForm.modal.js`

**Route**: `#/payments`, permission: `payments.view`

---

### D7 — Collections

**Backend files**:
- `src/Repository/CollectionRepository.php` — findAllForBusiness, findById, create, delete, updateAccountBalance
- `src/Services/CollectionService.php` — create (updates account balance), delete (reverses balance)
- `src/Controllers/CollectionsController.php` — index, store, viewById(id), delete(id)
- `public/api/routes/collections.php`

**Frontend files**:
- `public/html/Finance/collections.html` — page with DataTable
- `public/html/Finance/collectionForm.html` — create modal
- `public/assets/js/pages/Finance/collections.js`
- `public/assets/js/pages/Finance/collectionForm.modal.js`

**Route**: `#/collections`, permission: `collections.view`

---

### D8 — Salaries

**Backend files**:
- `src/Repository/SalaryRepository.php` — findAllForBusiness (with employee name), findById, create, update, delete
- `src/Services/SalaryService.php` — CRUD, net = gross - tax (calculated server-side)
- `src/Controllers/SalariesController.php` — index, store, viewById(id), update(id), delete(id)
- `public/api/routes/salaries.php`

**Frontend files**:
- `public/html/Salaries/salaries.html` — page with DataTable
- `public/html/Salaries/salaryForm.html` — create/edit modal
- `public/assets/js/pages/Salaries/salaries.js`
- `public/assets/js/pages/Salaries/salaryForm.modal.js`

**Route**: `#/salaries`, permission: `salaries.view`

---

### D9 — Dashboard

**Backend files**:
- `src/Repository/DashboardRepository.php` — getMonthlyRevenue, getMonthlyExpenses, getAccountBalances, getRecentTransactions, getLowStockItems
- `src/Services/DashboardService.php` — aggregates all dashboard data
- `src/Controllers/DashboardController.php` — summary (GET /dashboard/summary)
- `public/api/routes/dashboard.php`

**Frontend files**:
- `public/html/dashboard.html` — replace existing dummy with real layout (stat cards + tables)
- `public/assets/js/pages/Others/dashboard.js` — replace existing dummy with real data fetch

**Route**: `#/dashboard`, permission: `dashboard.view` (already exists)

---

### D10 — Activity Log

**Backend files**:
- `src/Repository/AuditRepository.php` — already exists, add `findForBusiness(businessId, filters, limit, offset)`
- `src/Services/ActivityLogService.php` — wraps repo with pagination
- `src/Controllers/ActivityLogController.php` — index (GET /activity-log?page=1&event_type=&from=&to=)
- `public/api/routes/activity_log.php`

**Frontend files**:
- `public/html/ActivityLog/activityLog.html` — page with DataTable + filter bar
- `public/assets/js/pages/ActivityLog/activityLog.js`

**Route**: `#/activityLog`, permission: `activityLog.view` (already exists, currently points to 404)

---

### D11 — Settings

**Backend files**:
- `src/Repository/SettingsRepository.php` — findByBusiness, upsert
- `src/Services/SettingsService.php` — get, save
- `src/Controllers/SettingsController.php` — show (GET /settings), save (PUT /settings)
- `public/api/routes/settings.php`

**Frontend files**:
- `public/html/Settings/settings.html` — settings form page (not a modal)
- `public/assets/js/pages/Settings/settings.js`

**Route**: `#/settings`, permission: `settings.view` (already exists, currently points to 404)

## Shared Utilities Needed

### Currency/Amount Helper (frontend)
A shared JS utility `public/assets/js/utils/currency.js`:
```js
export function calcBc(amountFc, exchangeRate) { return Math.round(amountFc * exchangeRate * 100) / 100; }
export function formatAmount(value, decimals = 2) { return parseFloat(value || 0).toFixed(decimals); }
```

### Payee Selector (frontend)
A reusable function `public/assets/js/utils/payeeSelect.js` that populates a `<select>` with payees from `GET /api/people` (filtered to those with a payee record for the current business).

### Auto-number Generator (backend)
`PurchaseOrderRepository::generatePoNumber(businessId)` → `PO-{YYYY}-{NNN}` (padded sequential)
`SalesInvoiceRepository::generateInvoiceNumber(businessId)` → `INV-{YYYY}-{NNN}`

## Permissions to Add to DB

Run this SQL before executing tasks:

```sql
INSERT INTO permissions (name, code, created_at) VALUES
('exchange rates manage', 'exchange-rates.manage', NOW()),
('accounts manage',       'accounts.manage',       NOW()),
('expenses view',         'expenses.view',         NOW()),
('expenses create',       'expenses.create',       NOW()),
('expenses edit',         'expenses.edit',         NOW()),
('expenses delete',       'expenses.delete',       NOW()),
('expenses manage',       'expenses.manage',       NOW()),
('po view',               'po.view',               NOW()),
('po create',             'po.create',             NOW()),
('po edit',               'po.edit',               NOW()),
('po delete',             'po.delete',             NOW()),
('po approve',            'po.approve',            NOW()),
('po receive',            'po.receive',            NOW()),
('invoices view',         'invoices.view',         NOW()),
('invoices create',       'invoices.create',       NOW()),
('invoices edit',         'invoices.edit',         NOW()),
('invoices delete',       'invoices.delete',       NOW()),
('invoices approve',      'invoices.approve',      NOW()),
('invoices pay',          'invoices.pay',          NOW()),
('payments view',         'payments.view',         NOW()),
('payments create',       'payments.create',       NOW()),
('payments delete',       'payments.delete',       NOW()),
('collections view',      'collections.view',      NOW()),
('collections create',    'collections.create',    NOW()),
('collections delete',    'collections.delete',    NOW()),
('salaries view',         'salaries.view',         NOW()),
('salaries create',       'salaries.create',       NOW()),
('salaries edit',         'salaries.edit',         NOW()),
('salaries delete',       'salaries.delete',       NOW());

-- Grant all to Business_Owner (role 1)
INSERT INTO role_permissions (role_id, permission_id)
SELECT 1, id FROM permissions WHERE id > 25;

-- Grant subset to Accountant (role 2)
INSERT INTO role_permissions (role_id, permission_id)
SELECT 2, id FROM permissions
WHERE code IN (
  'exchange-rates.manage','accounts.manage',
  'expenses.view','expenses.create','expenses.edit',
  'expenses.manage',
  'po.view','po.create','po.edit','po.approve','po.receive',
  'invoices.view','invoices.create','invoices.edit','invoices.approve','invoices.pay',
  'payments.view','payments.create',
  'collections.view','collections.create',
  'salaries.view','salaries.create','salaries.edit'
);
```

## Menu Updates (appShell.config.js)

Add to `MENU_CONFIG` in order:
```js
{ id: 'goToExchangeRates', label: 'Exchange Rates', icon: 'fas fa-fw fa-exchange-alt', hash: '#/exchange-rates', permission: 'exchange-rates.manage' },
{ id: 'goToAccounts',      label: 'Accounts',       icon: 'fas fa-fw fa-university',   hash: '#/accounts',       permission: 'accounts.manage' },
{ id: 'goToExpenses',      label: 'Expenses',       icon: 'fas fa-fw fa-receipt',      hash: '#/expenses',       permission: 'expenses.view' },
{ id: 'goToPurchaseOrders',label: 'Purchase Orders',icon: 'fas fa-fw fa-shopping-cart',hash: '#/purchase-orders',permission: 'po.view' },
{ id: 'goToInvoices',      label: 'Invoices',       icon: 'fas fa-fw fa-file-invoice', hash: '#/invoices',       permission: 'invoices.view' },
{ id: 'goToPayments',      label: 'Payments',       icon: 'fas fa-fw fa-money-bill',   hash: '#/payments',       permission: 'payments.view' },
{ id: 'goToCollections',   label: 'Collections',    icon: 'fas fa-fw fa-hand-holding-usd', hash: '#/collections', permission: 'collections.view' },
{ id: 'goToSalaries',      label: 'Salaries',       icon: 'fas fa-fw fa-user-tie',     hash: '#/salaries',       permission: 'salaries.view' },
```

## Router Updates (router.config.js)

Add routes:
```js
{path: '#/exchange-rates',   auth: true, permission: 'exchange-rates.manage', load: () => import('../pages/Finance/exchangeRates.js')},
{path: '#/accounts',         auth: true, permission: 'accounts.manage',       load: () => import('../pages/Finance/accounts.js')},
{path: '#/expenses',         auth: true, permission: 'expenses.view',         load: () => import('../pages/Expenses/expenses.js')},
{path: '#/purchase-orders',  auth: true, permission: 'po.view',               load: () => import('../pages/PurchaseOrders/purchaseOrders.js')},
{path: '#/invoices',         auth: true, permission: 'invoices.view',         load: () => import('../pages/SalesInvoices/salesInvoices.js')},
{path: '#/payments',         auth: true, permission: 'payments.view',         load: () => import('../pages/Finance/payments.js')},
{path: '#/collections',      auth: true, permission: 'collections.view',      load: () => import('../pages/Finance/collections.js')},
{path: '#/salaries',         auth: true, permission: 'salaries.view',         load: () => import('../pages/Salaries/salaries.js')},
```

Update existing stubs:
```js
{path: '#/activityLog', auth: true, permission: 'activityLog.view', load: () => import('../pages/ActivityLog/activityLog.js')},
{path: '#/settings',    auth: true, permission: 'settings.view',    load: () => import('../pages/Settings/settings.js')},
```
