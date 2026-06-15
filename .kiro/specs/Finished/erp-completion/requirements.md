# ERP System — Completion Requirements

## Project Context

A multi-tenant ERP system built with PHP 7.4 (no framework) + Vanilla JS ES6 SPA.
Already completed: Auth, Users, Businesses, People/Payees, Items/Inventory.
This spec covers all remaining modules needed to complete the system.

## Environment Constraints

- **PHP 7.4** — no `match` expressions, no named arguments, no union types in some positions
- **MySQL 9.1** via PDO, running on localhost via WAMP
- **No Composer packages** beyond `firebase/php-jwt` (already installed)
- **Frontend**: Vanilla JS ES6 modules, Bootstrap 5, DataTables, jQuery (for DataTables only)
- All monetary values stored as `amount_fc` (foreign currency) + `amount_bc` (base currency)
- Exchange rate always required for multi-currency entries

## Architecture Conventions (must be followed exactly)

### Backend
- Controller → Service → Repository pattern
- All repositories accept `PDO $db` via constructor injection
- `BaseController` methods: `json(bool, ?array, ?string, int)`, `input(array)`, `auth()`, `forbidden()`, `notFound()`, `serverError()`
- All API responses: `{ success: bool, data: array|null, message: string|null }`
- Audit logging via `AuditService->log(eventType, table, recordId, action, old, new, userId, tenantId, businessId)`
- Transactions (`beginTransaction/commit/rollBack`) for multi-step writes
- Static routes (e.g. `/expenses/categories`) must be registered **before** `{id}` routes in the routes file
- Router `{id}` pattern matches digits only (`\d+`), so static segments won't conflict

### Frontend
- Page modules export: `render(container)`, `mount()`, `unmount()`
- Modal modules export: `mount({modalRoot, data})`, `unmount()`
- `apiFetch()` for all authenticated API calls (auto-handles 401/refresh)
- `registerPageActions({action: handler})` for delegated event handling — returns unregister fn
- `createDataTable(selector, options)` for all DataTables
- `openModal({scope, template, controller, data, options})` for all modals
- `MODAL_SCOPE.PAGE` for modals inside `#content`, `MODAL_SCOPE.GLOBAL` for nested modals
- `confirm({title, message})` and `alert({title, alertText})` for user prompts
- `hasPermission(code)` for permission-gated UI elements
- Dispatch `document.dispatchEvent(new CustomEvent('module.updated'))` after successful saves
- `escHtml(str)` helper needed in any module that renders user data into innerHTML

## Remaining Modules

### R1 — Exchange Rates
Manage currency exchange rates per business. Required before financial modules.

**DB table**: `exchange_rates` (id, tenant_id, business_id, currency, rate_date, rate, source, created_at)

**Requirements**:
- List all exchange rates for the current business, sorted by date desc
- Create a new rate entry (currency, rate_date, rate, optional source)
- Edit an existing rate
- Delete a rate (no transaction guard needed)
- The latest rate for each currency should be easily queryable (used by other modules)
- Permission: `exchange-rates.manage` (single permission for all operations)

---

### R2 — Business Accounts
Manage bank/cash accounts per business. Required before payments/collections.

**DB table**: `business_accounts` (id, business_id, name, currency, balance, created_at, updated_at)

**Requirements**:
- List all accounts for the current business
- Create an account (name, currency required)
- Edit an account (name only — balance is system-managed)
- Delete an account (guard: refuse if balance != 0 or if account has payment/collection slips)
- Permission: `accounts.manage`

---

### R3 — Expense Categories
Manage expense categories per business. Required before expenses.

**DB table**: `expense_categories` (id, tenant_id, business_id, name, is_fixed, created_at, updated_at, created_by)

**Requirements**:
- List all categories for the current business/tenant
- Create a category (name, is_fixed flag)
- Edit a category
- Delete a category (guard: refuse if category has expenses)
- Managed via a modal (same pattern as payee types / item types)
- Permission: `expenses.manage` (covers categories + expenses)

---

### R4 — Expenses
Record business expenses.

**DB table**: `expenses` (id, business_id, payee_id, expense_category_id, expense_date, currency, exchange_rate, amount_fc, amount_bc, notes, created_at, updated_at)

**Requirements**:
- List all expenses for the current business with payee name, category name, amounts
- Create an expense: select payee (from people/payees), category, date, currency, exchange_rate, amount_fc (amount_bc = amount_fc * exchange_rate), notes
- Edit an expense
- Delete an expense
- Permissions: `expenses.view`, `expenses.create`, `expenses.edit`, `expenses.delete`

---

### R5 — Purchase Orders
Manage supplier purchase orders with line items.

**DB tables**: `purchase_orders` (id, business_id, supplier_id, po_number, po_date, status, currency, exchange_rate, total_amount_fc, total_amount_bc, notes, created_at, updated_at, created_by), `po_details` (id, purchase_order_id, item_id, quantity, unit_price_fc, unit_price_bc, total_price_fc, total_price_bc, created_at)

**Requirements**:
- List all POs for the current business with supplier name, status badge, totals
- Create a PO: select supplier (payee), po_number (auto-suggest), po_date, currency, exchange_rate, notes, then add line items (item + quantity + unit_price_fc; unit_price_bc and totals calculated automatically)
- View/Edit a PO (only DRAFT status allows editing line items)
- Status workflow actions (buttons): DRAFT → APPROVE, APPROVED → RECEIVE (triggers stock IN for each line item), APPROVED/RECEIVED → CANCEL, RECEIVED → CLOSE
- Delete a PO (only DRAFT status)
- Totals recalculated on every line item add/remove/edit
- Permissions: `po.view`, `po.create`, `po.edit`, `po.delete`, `po.approve`, `po.receive`

---

### R6 — Sales Invoices
Manage customer sales invoices with line items.

**DB tables**: `sales_invoices` (id, business_id, customer_id, invoice_number, invoice_date, status, currency, exchange_rate, total_amount_fc, total_amount_bc, notes, created_at, updated_at, created_by), `si_details` (id, sales_invoice_id, item_id, quantity, unit_price_fc, unit_price_bc, total_price_fc, total_price_bc, created_at)

**Requirements**:
- List all invoices for the current business with customer name, status badge, totals
- Create an invoice: select customer (payee), invoice_number (auto-suggest), invoice_date, currency, exchange_rate, notes, then add line items (item + quantity + unit_price_fc; calculated fields auto-filled)
- View/Edit an invoice (only DRAFT allows editing)
- Status workflow: DRAFT → APPROVE, APPROVED → MARK PAID (triggers collection slip creation), APPROVED/DRAFT → CANCEL
- Delete an invoice (only DRAFT)
- Permissions: `invoices.view`, `invoices.create`, `invoices.edit`, `invoices.delete`, `invoices.approve`, `invoices.pay`

---

### R7 — Payments
Record outgoing payments linked to expenses, POs, salaries, or partner profit.

**DB table**: `payment_slips` (id, business_id, account_id, payee_id, reference_type ENUM('EXPENSE','PO','SALARY','PARTNER_PROFIT'), reference_id, payment_number, payment_date, currency, exchange_rate, amount_fc, amount_bc, method ENUM('CASH','BANK','CHECK','TRANSFER'), notes, created_at, updated_at, created_by)

**Requirements**:
- List all payment slips for the current business with payee name, reference, amount, method
- Create a payment: select account, payee, reference_type + reference_id (optional), payment_number, date, currency, exchange_rate, amount_fc, method, notes
- View a payment (read-only after creation)
- Delete a payment (with confirmation; updates account balance)
- Account balance updated on create/delete
- Permissions: `payments.view`, `payments.create`, `payments.delete`

---

### R8 — Collections
Record incoming payments from customers or partner contributions.

**DB table**: `collection_slips` (id, business_id, account_id, payee_id, reference_type ENUM('CUSTOMER','PARTNER_CONTRIBUTION'), reference_id, slip_number, slip_date, currency, exchange_rate, amount_fc, amount_bc, method, notes, created_at, updated_at, created_by)

**Requirements**:
- List all collection slips for the current business
- Create a collection: select account, payee, reference_type + reference_id (optional), slip_number, date, currency, exchange_rate, amount_fc, method, notes
- View a collection (read-only)
- Delete a collection (with confirmation; updates account balance)
- Account balance updated on create/delete
- Permissions: `collections.view`, `collections.create`, `collections.delete`

---

### R9 — Salaries
Record salary payments per employee per period.

**DB table**: `salaries` (id, business_id, payee_id, period_start, period_end, currency, exchange_rate, gross_amount_fc, gross_amount_bc, tax_fc, tax_bc, net_amount_fc, net_amount_bc, notes, created_at, updated_at)

**Requirements**:
- List all salary records for the current business with employee name, period, net amount
- Create a salary record: select employee (payee), period_start, period_end, currency, exchange_rate, gross_amount_fc (gross_bc auto-calculated), tax_fc (optional, tax_bc auto-calculated), net = gross - tax (auto-calculated)
- Edit a salary record
- Delete a salary record
- Permissions: `salaries.view`, `salaries.create`, `salaries.edit`, `salaries.delete`

---

### R10 — Dashboard
Real-time business summary replacing the current dummy dashboard.

**Requirements**:
- Total revenue this month (sum of paid sales invoices)
- Total expenses this month (sum of expenses)
- Net profit this month (revenue - expenses)
- Account balances summary (list of accounts with balances)
- Recent transactions (last 10 expenses + payments + collections combined)
- Low stock alert (items with quantity <= 0)
- No new permissions needed (uses `dashboard.view`)

---

### R11 — Activity Log
View the audit log for the current business.

**DB table**: `audit_logs`

**Requirements**:
- List audit log entries for the current business, sorted by created_at desc
- Columns: date/time, user, event type, table, action, record ID
- Expandable row to show old_values / new_values JSON diff
- Filter by event_type and date range
- Read-only, no create/edit/delete
- Permission: `activityLog.view` (already exists)

---

### R12 — Settings (Regional)
Business-level regional settings.

**DB table**: `settings_regional` (business_id PK, language, timezone, date_format, number_format, currency_format, phone_format)

**Requirements**:
- View and edit regional settings for the current business
- Fields: language (select from `languages` table), timezone (select list), date_format, number_format, currency_format, phone_format
- Create record if it doesn't exist yet (upsert)
- Permission: `settings.view` (already exists)
