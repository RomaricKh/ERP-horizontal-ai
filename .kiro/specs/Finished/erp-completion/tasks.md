# ERP Completion — Implementation Tasks

## Task 0: Database & Config Setup

- [x] 0.1 Insert all new permissions into the DB and grant them to roles (run the SQL from design.md "Permissions to Add to DB" section)
- [x] 0.2 Create shared frontend utility `public/assets/js/utils/currency.js` with `calcBc(amountFc, rate)` and `formatAmount(value, decimals)` exports
- [x] 0.3 Create shared frontend utility `public/assets/js/utils/payeeSelect.js` that exports `populatePayeeSelect(selectEl)` — fetches `GET /api/people`, filters to entries with a `payee_id`, populates `<select>` with `<option value="{payee_id}">{name}</option>`

## Task 1: Exchange Rates Module

- [x] 1.1 Create `src/Repository/ExchangeRateRepository.php` with methods: `findAllForBusiness(tenantId, businessId)`, `getLatestRate(currency, businessId)`, `findById(id, tenantId)`, `create(data)`, `update(id, tenantId, data)`, `delete(id, tenantId)`
- [x] 1.2 Create `src/Services/ExchangeRateService.php` with: `listForBusiness()`, `getLatest(currency)`, `create(data)`, `update(id, data)`, `delete(id)` — all using AuthContext, with audit logging
- [x] 1.3 Create `src/Controllers/ExchangeRatesController.php` with: `index()`, `latest()`, `store()`, `update(id)`, `delete(id)`
- [x] 1.4 Create `public/api/routes/exchange_rates.php` — register GET /exchange-rates, GET /exchange-rates/latest, POST, PUT /{id}, DELETE /{id} with permission `exchange-rates.manage`
- [x] 1.5 Register route file in `public/api/index.php`
- [x] 1.6 Create `public/html/Finance/exchangeRates.html` — page with DataTable (columns: ID, Currency, Date, Rate, Source, Actions) + Add Rate button
- [x] 1.7 Create `public/html/Finance/exchangeRateForm.html` — modal with fields: currency (select: USD/EUR/GBP/LBP/AED/SAR/EGP/JOD/KWD/TRY), rate_date, rate, source (optional)
- [x] 1.8 Create `public/assets/js/pages/Finance/exchangeRates.js` — page module with create/edit/delete actions
- [x] 1.9 Create `public/assets/js/pages/Finance/exchangeRateForm.modal.js` — create/edit modal controller
- [x] 1.10 Add route `#/exchange-rates` to `router.config.js` and menu entry to `appShell.config.js`

## Task 2: Business Accounts Module

- [x] 2.1 Create `src/Repository/AccountRepository.php` with: `findAllForBusiness(businessId)`, `findById(id, businessId)`, `create(data)`, `update(id, businessId, data)`, `delete(id, businessId)`, `countSlips(accountId)`, `updateBalance(accountId, delta, db)` (delta can be positive or negative)
- [x] 2.2 Create `src/Services/AccountService.php` with: `list()`, `getById(id)`, `create(data)`, `update(id, data)`, `delete(id)` — delete guards: balance must be 0 AND no slips
- [x] 2.3 Create `src/Controllers/AccountsController.php` with: `index()`, `viewById(id)`, `store()`, `update(id)`, `delete(id)`
- [x] 2.4 Create `public/api/routes/accounts.php` — GET /accounts, GET /accounts/{id}, POST, PUT /{id}, DELETE /{id} with permission `accounts.manage`
- [x] 2.5 Register route file in `public/api/index.php`
- [x] 2.6 Create `public/html/Finance/accounts.html` — page with DataTable (columns: ID, Name, Currency, Balance, Actions) + Add Account button
- [x] 2.7 Create `public/html/Finance/accountForm.html` — modal with fields: name (required), currency (select, required)
- [x] 2.8 Create `public/assets/js/pages/Finance/accounts.js` — page module
- [x] 2.9 Create `public/assets/js/pages/Finance/accountForm.modal.js` — create/edit modal (name + currency only; balance is read-only)
- [x] 2.10 Add route `#/accounts` to `router.config.js` and menu entry to `appShell.config.js`

## Task 3: Expenses Module

- [x] 3.1 Create `src/Repository/ExpenseRepository.php` with:
  - Categories: `findAllCategories(tenantId, businessId)`, `findCategoryById(id, tenantId)`, `createCategory(data)`, `updateCategory(id, tenantId, data)`, `deleteCategory(id, tenantId)`, `countExpensesForCategory(categoryId)`
  - Expenses: `findAllForBusiness(businessId)` (JOIN payees→people for name, JOIN categories), `findById(id, businessId)`, `create(data)`, `update(id, businessId, data)`, `delete(id, businessId)`
- [x] 3.2 Create `src/Services/ExpenseService.php` with: `listCategories()`, `createCategory(name, isFixed)`, `updateCategory(id, name, isFixed)`, `deleteCategory(id)`, `list()`, `getById(id)`, `create(data)` (calculates amount_bc = amount_fc * exchange_rate), `update(id, data)`, `delete(id)`
- [x] 3.3 Create `src/Controllers/ExpensesController.php` with: `index()`, `store()`, `update(id)`, `delete(id)`, `indexCategories()`, `storeCategory()`, `updateCategory(id)`, `deleteCategory(id)`
- [x] 3.4 Create `public/api/routes/expenses.php` — static routes (GET /expenses/categories, POST, PUT /{id}, DELETE /{id}) before dynamic routes (GET /expenses, POST, PUT /{id}, DELETE /{id})
- [x] 3.5 Register route file in `public/api/index.php`
- [x] 3.6 Create `public/html/Expenses/expenses.html` — page with DataTable (columns: ID, Date, Payee, Category, Currency, Amount FC, Amount BC, Actions) + Categories button + Add Expense button
- [x] 3.7 Create `public/html/Expenses/expenseForm.html` — modal with: payee select (populated from payees), category select, expense_date, currency select, exchange_rate, amount_fc (amount_bc auto-calculated and shown read-only), notes
- [x] 3.8 Create `public/html/Expenses/expenseCategories.html` — manage categories modal (inline add/edit/delete, same pattern as payeeTypes.html)
- [x] 3.9 Create `public/assets/js/pages/Expenses/expenses.js` — page module
- [x] 3.10 Create `public/assets/js/pages/Expenses/expenseForm.modal.js` — on amount_fc or exchange_rate change, auto-calculate and display amount_bc; use `populatePayeeSelect()` utility
- [x] 3.11 Create `public/assets/js/pages/Expenses/expenseCategories.modal.js` — inline management
- [x] 3.12 Add route `#/expenses` to `router.config.js` and menu entry to `appShell.config.js`

## Task 4: Purchase Orders Module

- [x] 4.1 Create `src/Repository/PurchaseOrderRepository.php` with:
  - `findAllForBusiness(businessId)` — JOIN payees→people for supplier name
  - `findById(id, businessId)` — includes lines with item names
  - `create(data)` — inserts header only
  - `addLine(poId, data)` — inserts po_details row
  - `removeLine(lineId, poId)` — deletes po_details row
  - `updateStatus(id, businessId, status)`
  - `delete(id, businessId)` — only if DRAFT
  - `recalculateTotals(id, db)` — SUM lines, UPDATE purchase_orders totals
  - `generatePoNumber(businessId)` — returns `PO-{YYYY}-{NNN}` (padded, sequential per business per year)
  - `getLines(poId)` — returns all po_details for a PO
- [x] 4.2 Create `src/Services/PurchaseOrderService.php` with:
  - `list()`, `getById(id)`, `create(data, lines)` (transaction: insert header + lines + recalculate)
  - `addLine(poId, data)` (recalculates totals after), `removeLine(poId, lineId)` (recalculates)
  - `updateStatus(id, newStatus)` — RECEIVE triggers stock IN for each line item via ItemService
  - `delete(id)` — only DRAFT allowed
- [x] 4.3 Create `src/Controllers/PurchaseOrdersController.php` with: `index()`, `store()`, `viewById(id)`, `update(id)`, `delete(id)`, `updateStatus(id)`, `addLine(id)`, `removeLine(id, lineId)`
- [x] 4.4 Create `public/api/routes/purchase_orders.php` — all routes with correct permissions; note: `/purchase-orders/{id}/lines/{lineId}` for DELETE line
- [x] 4.5 Register route file in `public/api/index.php`
- [x] 4.6 Create `public/html/PurchaseOrders/purchaseOrders.html` — page with DataTable (columns: ID, PO Number, Supplier, Date, Status badge, Total FC, Total BC, Actions)
- [x] 4.7 Create `public/html/PurchaseOrders/poForm.html` — modal-xl with two sections: header form (supplier select, po_number, po_date, currency, exchange_rate, notes) + line items table (item select, quantity, unit_price_fc, unit_price_bc auto-calc, total auto-calc, remove button) + Add Line button + grand total display
- [x] 4.8 Create `public/assets/js/pages/PurchaseOrders/purchaseOrders.js` — page module; status workflow buttons rendered per row based on current status and permissions
- [x] 4.9 Create `public/assets/js/pages/PurchaseOrders/poForm.modal.js` — handles dynamic line items: add row, remove row, auto-calculate unit_price_bc = unit_price_fc * exchange_rate, total = qty * unit_price_fc, grand total = sum of totals; on save: POST header + lines in one request
- [x] 4.10 Add route `#/purchase-orders` to `router.config.js` and menu entry to `appShell.config.js`

## Task 5: Sales Invoices Module

- [x] 5.1 Create `src/Repository/SalesInvoiceRepository.php` with same pattern as PurchaseOrderRepository but for sales_invoices/si_details; `generateInvoiceNumber(businessId)` → `INV-{YYYY}-{NNN}`
- [x] 5.2 Create `src/Services/SalesInvoiceService.php` — PAY status triggers: create collection_slip record (reference_type='CUSTOMER', reference_id=invoice_id) if an account is provided; stock OUT for each line item
- [x] 5.3 Create `src/Controllers/SalesInvoicesController.php` with: `index()`, `store()`, `viewById(id)`, `update(id)`, `delete(id)`, `updateStatus(id)`, `addLine(id)`, `removeLine(id, lineId)`
- [x] 5.4 Create `public/api/routes/sales_invoices.php`
- [x] 5.5 Register route file in `public/api/index.php`
- [x] 5.6 Create `public/html/SalesInvoices/salesInvoices.html` — page with DataTable (columns: ID, Invoice Number, Customer, Date, Status badge, Total FC, Total BC, Actions)
- [x] 5.7 Create `public/html/SalesInvoices/invoiceForm.html` — same structure as poForm.html but for invoices (customer instead of supplier, sale_price pre-filled as unit_price_fc)
- [x] 5.8 Create `public/assets/js/pages/SalesInvoices/salesInvoices.js`
- [x] 5.9 Create `public/assets/js/pages/SalesInvoices/invoiceForm.modal.js`
- [x] 5.10 Add route `#/invoices` to `router.config.js` and menu entry to `appShell.config.js`

## Task 6: Payments Module

- [x] 6.1 Create `src/Repository/PaymentRepository.php` with: `findAllForBusiness(businessId)` (JOIN payees→people, JOIN accounts), `findById(id, businessId)`, `create(data)`, `delete(id, businessId)`
- [x] 6.2 Create `src/Services/PaymentService.php` — `create(data)`: inserts slip + calls `AccountRepository::updateBalance(accountId, -amount_bc)` (outgoing = negative delta); `delete(id)`: deletes slip + reverses balance
- [x] 6.3 Create `src/Controllers/PaymentsController.php` with: `index()`, `store()`, `viewById(id)`, `delete(id)`
- [x] 6.4 Create `public/api/routes/payments.php`
- [x] 6.5 Register route file in `public/api/index.php`
- [x] 6.6 Create `public/html/Finance/payments.html` — page with DataTable (columns: ID, Number, Date, Payee, Account, Ref Type, Amount FC, Amount BC, Method, Actions)
- [x] 6.7 Create `public/html/Finance/paymentForm.html` — modal with: account select, payee select, reference_type select, reference_id (optional text), payment_number, payment_date, currency, exchange_rate, amount_fc (amount_bc auto-calc), method select, notes
- [x] 6.8 Create `public/assets/js/pages/Finance/payments.js`
- [x] 6.9 Create `public/assets/js/pages/Finance/paymentForm.modal.js`
- [x] 6.10 Add route `#/payments` to `router.config.js` and menu entry to `appShell.config.js`

## Task 7: Collections Module

- [x] 7.1 Create `src/Repository/CollectionRepository.php` with: `findAllForBusiness(businessId)`, `findById(id, businessId)`, `create(data)`, `delete(id, businessId)`
- [x] 7.2 Create `src/Services/CollectionService.php` — `create(data)`: inserts slip + calls `AccountRepository::updateBalance(accountId, +amount_bc)` (incoming = positive delta); `delete(id)`: deletes + reverses
- [x] 7.3 Create `src/Controllers/CollectionsController.php` with: `index()`, `store()`, `viewById(id)`, `delete(id)`
- [x] 7.4 Create `public/api/routes/collections.php`
- [x] 7.5 Register route file in `public/api/index.php`
- [x] 7.6 Create `public/html/Finance/collections.html` — page with DataTable (columns: ID, Slip Number, Date, Payee, Account, Ref Type, Amount FC, Amount BC, Method, Actions)
- [x] 7.7 Create `public/html/Finance/collectionForm.html` — modal with: account select, payee select, reference_type select, reference_id (optional), slip_number, slip_date, currency, exchange_rate, amount_fc (amount_bc auto-calc), method, notes
- [x] 7.8 Create `public/assets/js/pages/Finance/collections.js`
- [x] 7.9 Create `public/assets/js/pages/Finance/collectionForm.modal.js`
- [x] 7.10 Add route `#/collections` to `router.config.js` and menu entry to `appShell.config.js`

## Task 8: Salaries Module

- [x] 8.1 Create `src/Repository/SalaryRepository.php` with: `findAllForBusiness(businessId)` (JOIN payees→people for employee name), `findById(id, businessId)`, `create(data)`, `update(id, businessId, data)`, `delete(id, businessId)`
- [x] 8.2 Create `src/Services/SalaryService.php` — `create/update`: net_amount_fc = gross_amount_fc - tax_fc; net_amount_bc = gross_amount_bc - tax_bc; all bc values = fc * exchange_rate
- [x] 8.3 Create `src/Controllers/SalariesController.php` with: `index()`, `store()`, `viewById(id)`, `update(id)`, `delete(id)`
- [x] 8.4 Create `public/api/routes/salaries.php`
- [x] 8.5 Register route file in `public/api/index.php`
- [x] 8.6 Create `public/html/Salaries/salaries.html` — page with DataTable (columns: ID, Employee, Period, Currency, Gross FC, Tax FC, Net FC, Net BC, Actions)
- [x] 8.7 Create `public/html/Salaries/salaryForm.html` — modal with: employee select (payees), period_start, period_end, currency, exchange_rate, gross_amount_fc, tax_fc (optional, default 0), net_amount_fc (auto-calculated read-only), notes
- [x] 8.8 Create `public/assets/js/pages/Salaries/salaries.js`
- [x] 8.9 Create `public/assets/js/pages/Salaries/salaryForm.modal.js` — auto-calculate net = gross - tax on input change
- [x] 8.10 Add route `#/salaries` to `router.config.js` and menu entry to `appShell.config.js`

## Task 9: Dashboard (Real Data)

- [x] 9.1 Create `src/Repository/DashboardRepository.php` with:
  - `getMonthlyRevenue(businessId, year, month)` — SUM total_amount_bc from sales_invoices WHERE status='PAID'
  - `getMonthlyExpenses(businessId, year, month)` — SUM amount_bc from expenses
  - `getAccountBalances(businessId)` — SELECT name, currency, balance FROM business_accounts
  - `getRecentTransactions(businessId, limit=10)` — UNION of expenses + payment_slips + collection_slips, ordered by created_at DESC
  - `getLowStockItems(businessId, threshold=0)` — items with stock.quantity <= threshold
- [x] 9.2 Create `src/Services/DashboardService.php` — `getSummary()` calls all repo methods, returns structured array
- [x] 9.3 Create `src/Controllers/DashboardController.php` — `summary()` (GET /dashboard/summary)
- [x] 9.4 Create `public/api/routes/dashboard.php`
- [x] 9.5 Register route file in `public/api/index.php`
- [x] 9.6 Replace `public/html/dashboard.html` with real layout: 3 stat cards (Revenue, Expenses, Net Profit) + Accounts table + Recent Transactions table + Low Stock alerts
- [x] 9.7 Replace `public/assets/js/pages/Others/dashboard.js` — fetch `/api/dashboard/summary`, render stat cards and tables with real data

## Task 10: Activity Log

- [x] 10.1 Add `findForBusiness(businessId, filters, limit, offset)` method to existing `src/Repository/AuditRepository.php` — supports filtering by event_type, from_date, to_date; returns paginated results with total count
- [x] 10.2 Create `src/Services/ActivityLogService.php` — `getLog(filters)` wraps repo, returns `{entries, total}`
- [x] 10.3 Create `src/Controllers/ActivityLogController.php` — `index()` (GET /activity-log?page=1&event_type=&from=&to=)
- [x] 10.4 Create `public/api/routes/activity_log.php`
- [x] 10.5 Register route file in `public/api/index.php`
- [x] 10.6 Create `public/html/ActivityLog/activityLog.html` — page with filter bar (event_type select, from/to date inputs, Apply button) + DataTable (columns: Date, User, Event Type, Table, Action, Record ID, Details toggle)
- [x] 10.7 Create `public/assets/js/pages/ActivityLog/activityLog.js` — page module; expandable rows show old_values/new_values as formatted JSON diff
- [x] 10.8 Update `router.config.js` — change `#/activityLog` from `404.js` to `ActivityLog/activityLog.js`

## Task 11: Settings

- [x] 11.1 Create `src/Repository/SettingsRepository.php` with: `findByBusiness(businessId)`, `upsert(businessId, data)` (INSERT ... ON DUPLICATE KEY UPDATE)
- [x] 11.2 Create `src/Services/SettingsService.php` — `get()`, `save(data)`
- [x] 11.3 Create `src/Controllers/SettingsController.php` — `show()` (GET /settings), `save()` (PUT /settings)
- [x] 11.4 Create `public/api/routes/settings.php`
- [x] 11.5 Register route file in `public/api/index.php`
- [x] 11.6 Create `public/html/Settings/settings.html` — settings form page (not a modal): language select, timezone select, date_format input, number_format input, currency_format input, phone_format input + Save button
- [x] 11.7 Create `public/assets/js/pages/Settings/settings.js` — page module: render form, fetch current settings on mount, save on button click
- [x] 11.8 Update `router.config.js` — change `#/settings` from `404.js` to `Settings/settings.js`
