# Implementation Plan: ERP Polish & Fixes

## Overview

34 tasks organized across 6 phases. Phase 1 (tasks 1-6, 26) fixes critical bugs that break core functionality. Phase 2 (tasks 7-12, 34) addresses schema and data integrity gaps plus the profile page. Phase 3 (tasks 13-19) adds missing core ERP features. Phase 4 (tasks 20-25) improves architecture and maintainability. Phase 5 (tasks 27-32) cleans up the schema, introduces system-types with module gating, and fixes the partner/profit architecture. Phase 6 (task 33) generates the deployment database creation script — the final deliverable that produces a clean, production-ready schema matching the target ERD.

Each task maps to a requirement in `requirements.md`. Tasks within a phase can generally be worked in parallel unless a dependency is noted.

## Tasks

- [x] 1. Fix sales_invoices PAID status (REQ-001)
  - [x] 1.1 Create migration `database/migrations/fix_sales_invoice_paid_status.sql` — add `'PAID'` to `sales_invoices.status` ENUM and backfill fully-paid DELIVERED invoices using collection slip sums
  - [x] 1.2 Run the migration against the `erp` database using `& "C:\wamp64\bin\mysql\mysql9.1.0\bin\mysql.exe" -u root erp < migration_file.sql`
  - [x] 1.3 Update `src/Services/CollectionService.php` — after creating a collection slip, call `SettlementEngine::evaluateCustomerSettlement()` and set invoice status to `'PAID'` when fully covered
  - [x] 1.4 Verify `src/Repository/DashboardRepository::getMonthlyRevenue()` returns correct values with the new PAID status
  - [x] 1.5 Verify `src/Services/BusinessProfitService.php` revenue calculation works correctly
  - [x] 1.6 Test: create an invoice, deliver it, collect full payment — verify status becomes PAID and dashboard revenue updates

- [x] 2. Fix DashboardRepository payables query (REQ-002)
  - [x] 2.1 In `src/Repository/DashboardRepository.php`, change `YEAR(order_date)` and `MONTH(order_date)` to `YEAR(po_date)` and `MONTH(po_date)` in `getMonthlyPayables()`
  - [x] 2.2 Change the status filter from `NOT IN ('PAID', 'CANCELLED')` to `NOT IN ('CLOSED', 'CANCELLED')`
  - [x] 2.3 Test: load the dashboard and verify no SQL errors and payables shows a value

- [x] 3. Fix AuditRepository user name JOIN (REQ-003)
  - [x] 3.1 In `src/Repository/AuditRepository.php`, change `u.name AS user_name` to `CONCAT(u.first_name, ' ', u.last_name) AS user_name` in `findForBusiness()`
  - [x] 3.2 Test: open the Activity Log page and verify user names display correctly instead of NULL

- [x] 4. Fix payment_slips reference_type ENUM (REQ-004)
  - [x] 4.1 Create migration `database/migrations/fix_payment_slips_reference_type.sql` — update existing `'PO'` rows to `'PURCHASE_ORDER'` and remove `'PO'` from ENUM
  - [x] 4.2 Search all PHP files for `reference_type = 'PO'` or `'PO'` as a reference_type value and update to `'PURCHASE_ORDER'`
  - [x] 4.3 Run the migration
  - [x] 4.4 Test: create a payment against a PO and verify settlement engine correctly sums the payment

- [x] 5. Fix roles.name UNIQUE constraint (REQ-005)
  - [x] 5.1 Create migration `database/migrations/fix_roles_name_unique.sql` — drop global unique index on `roles.name`, add composite unique on `(name, business_id)`
  - [x] 5.2 Run the migration
  - [x] 5.3 Test: create two roles with the same name in different businesses — verify both succeed; verify two roles with the same name in the same business are rejected

- [x] 6. Prevent negative stock (REQ-006)
  - [x] 6.1 Add `assertSufficientStock(int $itemId, int $businessId, float $quantity): void` to `src/Services/StockValidator.php`
  - [x] 6.2 Call `assertSufficientStock()` in `src/Services/SalesInvoiceService.php` before delivering an invoice for each line item
  - [x] 6.3 Call `assertSufficientStock()` in `src/Services/ManufacturingService.php` before creating a production order for each component
  - [x] 6.4 Call `assertSufficientStock()` in `src/Services/ItemService.php` before processing a manual OUT stock adjustment
  - [x] 6.5 Test: attempt to deliver an invoice with more quantity than available stock — verify a clear error message is returned and stock is unchanged

- [x] 7. Add is_active to users (REQ-007)
  - [x] 7.1 Create and run migration `database/migrations/add_users_is_active.sql` — add `is_active TINYINT(1) NOT NULL DEFAULT 1`
  - [x] 7.2 Update `src/Middleware/AuthMiddleware.php` to reject login for `is_active = 0` users with "Account is disabled" message
  - [x] 7.3 Add `setActive(int $userId, bool $active): void` to `src/Repository/UserRepository.php`
  - [x] 7.4 Add `toggleActive(int $userId): void` to `src/Services/UserService.php` — revoke all refresh tokens on deactivation
  - [x] 7.5 Add `PATCH /users/{id}/active` endpoint to `src/Controllers/UsersController.php` and register in `public/api/routes/users.php`
  - [x] 7.6 Update `public/assets/js/pages/Users/users.js` and `public/html/Users/users.html` to show active/inactive toggle per user row
  - [x] 7.7 Test: deactivate a user, attempt login — verify 401 response; reactivate, attempt login — verify success

- [x] 8. Fix recipes.yield_quantity type (REQ-008)
  - [x] 8.1 Create and run migration `database/migrations/fix_recipes_yield_quantity.sql` — change column to `DECIMAL(12,4)`
  - [x] 8.2 Update `public/html/Items/recipeForm.html` — set `step="0.0001"` on the yield quantity input
  - [x] 8.3 Test: create a recipe with yield quantity 1.5 — verify it saves and production cost calculation is correct

- [x] 9. Add updated_at to services (REQ-009)
  - [x] 9.1 Create and run migration `database/migrations/add_services_updated_at.sql` — add `updated_at DATETIME NULL`, set existing rows to `created_at`
  - [x] 9.2 Update `src/Repository/ServiceRepository.php` INSERT and UPDATE statements to include `updated_at = NOW()`
  - [x] 9.3 Test: update a service and verify `updated_at` is set to the current timestamp

- [x] 10. Add is_active to businesses (REQ-010)
  - [x] 10.1 Create and run migration `database/migrations/add_businesses_is_active.sql` — add `is_active TINYINT(1) NOT NULL DEFAULT 1`
  - [x] 10.2 Update `src/Middleware/AuthMiddleware.php` to return 403 when business context is an inactive business
  - [x] 10.3 Add `setActive(int $businessId, int $tenantId, bool $active): void` to `src/Repository/BusinessRepository.php`
  - [x] 10.4 Add `PATCH /businesses/{id}/active` endpoint to `src/Controllers/BusinessesController.php` and register in `public/api/routes/businesses.php`
  - [x] 10.5 Update `public/assets/js/pages/Tenant/businesses.js` to show active/inactive toggle per business row
  - [x] 10.6 Test: deactivate a business, attempt an API call scoped to it — verify 403 response

- [x] 11. Replace business_module_overrides with tenant-owned business types (REQ-011)
  - [x] 11.1 Create migration `database/migrations/migrate_to_tenant_business_types.sql` — change `business_types.code` unique constraint to `(code, tenant_id)`; for each tenant that has businesses pointing to system types, auto-create a tenant-owned copy of that type (copying all `business_type_modules` rows) and re-point those businesses to the new type
  - [x] 11.2 Add cleanup step to the migration: truncate `business_module_overrides` (it is no longer used)
  - [x] 11.3 Run the migration
  - [x] 11.4 Create `src/Repository/BusinessTypeRepository.php` with `copyFromSystem()`, `setModules()`, `findByTenant()`, `findSystemTypes()`, and `hasModule()` methods; all write methods MUST guard against modifying system types
  - [x] 11.5 Simplify `src/Services/ModuleActivationService::isModuleActive()` to a single lookup via `business_type_modules` — remove all `business_module_overrides` fallback logic
  - [x] 11.6 Create `src/Controllers/BusinessTypesController.php` with endpoints: `GET /tenant/business-types`, `POST /tenant/business-types` (copy from system), `PUT /tenant/business-types/{id}/modules`, `DELETE /tenant/business-types/{id}`, `GET /tenant/business-types/system`; register in `public/api/routes/businesses.php`
  - [x] 11.7 Create `public/html/Tenant/businessTypes.html` and `public/assets/js/pages/Tenant/businessTypes.js` — page listing tenant-owned types with module toggles per type
  - [x] 11.8 Create `public/html/Tenant/businessTypeForm.html` and `public/assets/js/pages/Tenant/businessTypeForm.modal.js` — modal to create a type by selecting a system template and naming it
  - [x] 11.9 Update the business create/edit form to populate the business type dropdown from `GET /tenant/business-types` (tenant-owned types only)
  - [x] 11.10 Test: create a tenant type from the "Retail" system template; toggle a module off on the tenant type; verify all businesses using that type no longer see the module; verify the system "Retail" type is unchanged

- [x] 12. Fix notification table redundancy (REQ-012)
  - [x] 12.1 Create migration `database/migrations/cleanup_notification_tables.sql` — seed `notification_types` with system types (low_stock, overdue_payment, expiry_alert, invoice_overdue, system)
  - [x] 12.2 Update `src/Repository/NotificationRepository.php` — remove all references to `user_notifications` table; use `notifications` table directly for all read/unread tracking
  - [x] 12.3 Update `src/Services/NotificationService.php` — remove `user_notifications` usage
  - [x] 12.4 Create `src/Repository/NotificationPreferenceRepository.php` with `setPreference()` and `getPreferences()` methods
  - [x] 12.5 Update `NotificationService::send()` to check user preferences before creating a notification
  - [x] 12.6 Test: send a notification, verify it appears in the notifications page; mark as read, verify state persists

- [x] 13. Invoice and PO PDF generation (REQ-013)
  - [x] 13.1 Add `dompdf/dompdf` to `composer.json` and run `composer update`
  - [x] 13.2 Create `src/Services/PdfService.php` wrapping DOMPDF with a `generate(string $html, string $filename): void` method that outputs PDF headers
  - [x] 13.3 Create `src/Templates/InvoicePdf.php` — PHP HTML template for invoice PDF (business header, customer info, line items table with qty/price/tax/total, subtotal/tax/grand total, notes)
  - [x] 13.4 Create `src/Templates/PurchaseOrderPdf.php` — same structure for POs with supplier details
  - [x] 13.5 Add `GET /sales-invoices/{id}/pdf` endpoint to `src/Controllers/SalesInvoicesController.php` and register in `public/api/routes/sales_invoices.php`
  - [x] 13.6 Add `GET /purchase-orders/{id}/pdf` endpoint to `src/Controllers/PurchaseOrdersController.php` and register in `public/api/routes/purchase_orders.php`
  - [x] 13.7 Add "Download PDF" button to `public/assets/js/pages/SalesInvoices/salesInvoices.js` per invoice row
  - [x] 13.8 Add "Download PDF" button to `public/assets/js/pages/PurchaseOrders/purchaseOrders.js` per PO row
  - [x] 13.9 Test: download a PDF for an invoice and a PO — verify layout is correct and all data is present

- [x] 14. Tax/VAT fields on invoices and POs (REQ-014)
  - [x] 14.1 Create and run migration `database/migrations/add_tax_fields.sql` — add `tax_rate`, `tax_amount_fc`, `tax_amount_bc` to `si_details` and `po_details`; add `tax_total_fc`, `tax_total_bc` to `sales_invoices` and `purchase_orders`
  - [x] 14.2 Update `src/Repository/SalesInvoiceRepository.php` — `addLine()` accepts tax fields; `recalculateTotals()` sums tax amounts into `tax_total_fc` and `tax_total_bc`
  - [x] 14.3 Update `src/Repository/PurchaseOrderRepository.php` — same changes
  - [x] 14.4 Update `public/html/SalesInvoices/invoiceForm.html` — add Tax Rate column to line items table and subtotal/tax/grand total summary section
  - [x] 14.5 Update `public/html/PurchaseOrders/poForm.html` — same changes
  - [x] 14.6 Update `public/assets/js/pages/SalesInvoices/invoiceForm.modal.js` — calculate tax amounts client-side on line entry; update totals display
  - [x] 14.7 Update `public/assets/js/pages/PurchaseOrders/poForm.modal.js` — same
  - [x] 14.8 Test: create an invoice with a 10% tax line — verify tax amount is calculated correctly and stored; verify totals are correct

- [x] 15. Invoice due date and AR aging (REQ-015)
  - [x] 15.1 Create and run migration `database/migrations/add_invoice_due_date.sql` — add `due_date DATE NULL` to `sales_invoices`
  - [x] 15.2 Update `src/Repository/SalesInvoiceRepository.php` — include `due_date` in all SELECT, INSERT, and UPDATE queries
  - [x] 15.3 Add `getAgingReport(int $businessId): array` to `src/Repository/SalesInvoiceRepository.php` returning buckets: current, 1-30, 31-60, 61-90, 90+ days overdue
  - [x] 15.4 Add `GET /sales-invoices/aging` endpoint to `src/Controllers/SalesInvoicesController.php` and register in routes
  - [x] 15.5 Update `public/html/SalesInvoices/invoiceForm.html` — add Due Date field (default: invoice_date + 30 days)
  - [x] 15.6 Update `public/html/SalesInvoices/salesInvoices.html` — add Due Date column; highlight overdue rows in red/orange
  - [x] 15.7 Create `public/html/Finance/arAging.html` and `public/assets/js/pages/Finance/arAging.js` — AR aging report page with date filter and per-customer breakdown
  - [x] 15.8 Register the AR aging page in the router and add a navigation link under Finance
  - [x] 15.9 Test: create an invoice with a past due date — verify it appears in the correct overdue bucket of the aging report

- [x] 16. Financial reports — P&L, Cash Flow, Balance Sheet (REQ-016)
  - [x] 16.1 Add `getProfitLoss(int $businessId, string $start, string $end): array` to `src/Services/ReportService.php` — returns revenue, COGS, gross profit, expenses by category, net profit
  - [x] 16.2 Add `getCashFlow(int $businessId, string $start, string $end): array` to `src/Services/ReportService.php` — returns inflows (collections), outflows (payments, salaries, expenses), net movement
  - [x] 16.3 Add `getBalanceSheet(int $businessId, string $asOf): array` to `src/Services/ReportService.php` — returns assets (accounts + inventory + fixed assets), liabilities (outstanding payables), equity (partner shares)
  - [x] 16.4 Add `GET /reports/profit-loss`, `GET /reports/cash-flow`, `GET /reports/balance-sheet` endpoints to `src/Controllers/StatementController.php` and register in `public/api/routes/` (new file `reports.php`)
  - [x] 16.5 Create `public/html/Finance/profitLoss.html` and `public/assets/js/pages/Finance/profitLoss.js` with date range pickers and formatted output
  - [x] 16.6 Create `public/html/Finance/cashFlow.html` and `public/assets/js/pages/Finance/cashFlow.js`
  - [x] 16.7 Create `public/html/Finance/balanceSheet.html` and `public/assets/js/pages/Finance/balanceSheet.js`
  - [x] 16.8 Register all three report pages in the router and add navigation links under Finance
  - [x] 16.9 Test: generate a P&L report for a period with known invoices and expenses — verify totals match manual calculation

- [x] 17. Email-based password reset (REQ-017)
  - [x] 17.1 Create and run migration `database/migrations/add_password_reset_tokens.sql` — create `password_reset_tokens` table with `user_id`, `token_hash`, `expires_at`, `used_at`
  - [x] 17.2 Create and run migration `database/migrations/add_settings_smtp.sql` — create `settings_smtp` table with SMTP configuration fields
  - [x] 17.3 Add `phpmailer/phpmailer` to `composer.json` and run `composer update`
  - [x] 17.4 Create `src/Services/EmailService.php` using PHPMailer with `send(string $to, string $subject, string $htmlBody): void`
  - [x] 17.5 Add `requestPasswordReset(string $email): void` and `resetPassword(string $token, string $newPassword): void` to `src/Services/AuthService.php`
  - [x] 17.6 Add `POST /auth/forgot-password` and `POST /auth/reset-password` endpoints to `src/Controllers/AuthController.php` and register in `public/api/routes/auth.php`
  - [x] 17.7 Update `public/html/Auth/forgotPassword.html` — add email input and "Send Reset Link" button; update `public/assets/js/pages/Auth/forgot-password.js`
  - [x] 17.8 Create `public/html/Auth/resetPassword.html` and `public/assets/js/pages/Auth/reset-password.js` — form to set new password using token from URL
  - [x] 17.9 Add SMTP settings form to `public/html/Settings/settings.html` and wire up to a new settings endpoint
  - [x] 17.10 Test: request a password reset, verify token is created; use the token to reset password, verify login works with new password; verify expired token is rejected

- [x] 18. Cron job for recurring instance generation (REQ-018)
  - [x] 18.1 Add `generateForAllBusinesses(string $upToDate): array` to `src/Services/ScheduleGeneratorService.php` — generates instances for all active schedules across all businesses up to the given date
  - [x] 18.2 Add `markOverdueInstances(): int` to `src/Services/RecurrenceService.php` — marks pending instances with `due_date < today` as `overdue`
  - [x] 18.3 Create `public/api/cron/generate-recurrences.php` — protected by `CRON_SECRET` query param; calls both methods and returns JSON summary
  - [x] 18.4 Create `docs/cron-setup.md` with Windows Task Scheduler setup instructions (using `curl` or `php.exe`) and Linux cron setup
  - [x] 18.5 Test: run the cron endpoint manually — verify instances are generated for active schedules; run again — verify no duplicates are created; verify past-due instances are marked overdue

- [x] 19. Discount fields on invoices and POs (REQ-019)
  - [x] 19.1 Create and run migration `database/migrations/add_discount_fields.sql` — add `discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00` to `si_details`, `po_details`, `sales_invoices`, `purchase_orders`
  - [x] 19.2 Update `src/Repository/SalesInvoiceRepository.php` — include `discount_percent` in `addLine()`; update line total formula to apply discount before tax
  - [x] 19.3 Update `src/Repository/PurchaseOrderRepository.php` — same changes
  - [x] 19.4 Update `public/html/SalesInvoices/invoiceForm.html` — add Discount % column to line items table
  - [x] 19.5 Update `public/html/PurchaseOrders/poForm.html` — same
  - [x] 19.6 Update `public/assets/js/pages/SalesInvoices/invoiceForm.modal.js` — recalculate line total when discount changes
  - [x] 19.7 Update `public/assets/js/pages/PurchaseOrders/poForm.modal.js` — same
  - [x] 19.8 Test: create an invoice line with 10% discount — verify line total is reduced correctly; verify 0% discount leaves total unchanged

- [x] 20. Move secrets to environment variables (REQ-020)
  - [x] 20.1 Create `.env.example` with placeholder values: `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `JWT_SECRET`
  - [x] 20.2 Update `src/Config/config.php` to load from `.env` file if it exists, falling back to hardcoded defaults
  - [x] 20.3 Add `.env` to `.gitignore` (verify it is not already tracked)
  - [x] 20.4 Create a local `.env` file with the actual credentials
  - [x] 20.5 Test: verify the application starts correctly with credentials loaded from `.env`; verify removing `.env` falls back to defaults

- [x] 21. Fix settlement engine architecture with payment allocations (REQ-021)
  - [x] 21.1 Create migration `database/migrations/add_payment_allocations.sql` — create `payment_allocations` table (`id`, `payment_slip_id FK→payment_slips`, `reference_type ENUM('PURCHASE_ORDER','EXPENSE','SALARY','PARTNER_PROFIT')`, `reference_id INT`, `amount_fc DECIMAL(12,2)`, `amount_bc DECIMAL(12,2)`, `created_at`); create `collection_allocations` table (`id`, `collection_slip_id FK→collection_slips`, `reference_type ENUM('SALES_INVOICE')`, `reference_id INT`, `amount_fc DECIMAL(12,2)`, `amount_bc DECIMAL(12,2)`, `created_at`)
  - [x] 21.2 Create a data migration step: for each existing `payment_slips` row that has `reference_type` and `reference_id`, insert a corresponding `payment_allocations` row with the full slip amount; for `collection_slips` rows with `reference_type = 'SALES_INVOICE'` and a valid `reference_id`, insert into `collection_allocations`; for `collection_slips` rows with `reference_type = 'CUSTOMER'` (unlinked payments) or `'PARTNER_CONTRIBUTION'`, these become slips with no allocations (the amount is on-account credit — handled by the payee balance)
  - [x] 21.3 Drop `reference_type` and `reference_id` columns from `payment_slips` and `collection_slips` (data now lives in allocations)
  - [x] 21.4 Run the migration
  - [x] 21.5 Rewrite `src/Services/SettlementEngine.php` — replace payee-balance-based settlement with allocation-based logic: invoice is PAID when `SUM(collection_allocations.amount_bc) WHERE reference_id = invoice.id >= invoice.total_amount_bc`; PO is CLOSED when `SUM(payment_allocations.amount_bc) WHERE reference_id = po.id >= po.total_amount_bc`
  - [x] 21.6 Add `autoAllocateFIFO(int $payeeId, int $businessId, float $amountBc, string $slipType): array` to `SettlementEngine` — fetches unpaid documents for the payee ordered by date ASC, allocates amount across them until exhausted, returns allocation rows
  - [x] 21.7 Create `src/Repository/PaymentAllocationRepository.php` with `createBatch(int $slipId, array $allocations): void`, `findBySlip(int $slipId): array`, `sumForDocument(string $type, int $id): float`
  - [x] 21.8 Create `src/Repository/CollectionAllocationRepository.php` with same methods
  - [x] 21.9 Update `src/Services/PaymentService.php` — when creating a payment slip, accept an optional `allocations` array; if empty, call `autoAllocateFIFO()`; validate sum of allocations equals slip amount; after saving, call `SettlementEngine` to update document statuses (PO→CLOSED, expense→paid, salary→paid)
  - [x] 21.10 Update `src/Services/CollectionService.php` — same pattern: accept optional allocations, default to FIFO, validate sum, update invoice statuses to PAID
  - [x] 21.11 Update `public/html/Payments/paymentForm.html` — redesign the payment slip form: show payee selector, then display a DataTable of the payee's outstanding documents (POs, expenses, salaries) with columns: Document #, Date, Total, Already Paid, Remaining, Allocate Amount (editable input); add "Auto-fill FIFO" button that populates amounts automatically; show running total of allocated vs. slip amount with validation
  - [x] 21.12 Update `public/assets/js/pages/Payments/paymentForm.modal.js` — implement the allocation UI logic: fetch outstanding documents on payee select, handle FIFO auto-fill, validate totals match, submit allocations array with the slip
  - [x] 21.13 Update `public/html/Collections/collectionForm.html` — same redesign for collection slips: show customer's outstanding invoices with allocation inputs and FIFO auto-fill
  - [x] 21.14 Update `public/assets/js/pages/Collections/collectionForm.modal.js` — same allocation UI logic for collections
  - [x] 21.15 Update `public/html/Payments/paymentSlips.html` — add an "Allocations" expandable row or detail modal per slip showing which documents were covered and amounts; use a clean sub-table with document links
  - [x] 21.16 Update `public/html/Collections/collectionSlips.html` — same: show allocation detail per collection slip with invoice links
  - [x] 21.17 Update the payee statement view (`public/html/People/payeeStatement.html` or equivalent) — show a full ledger: documents as debits (invoices delivered, POs received, expenses created), allocations as credits (payments/collections applied), with running balance and status badges per document
  - [x] 21.18 Test: create a payee with 3 outstanding POs ($2000, $1500, $1500); create a single payment slip for $5000 with FIFO auto-allocation; verify all 3 POs become CLOSED; verify the payment detail view shows 3 allocation rows; verify payee balance is updated correctly
  - [x] 21.19 Test: create a customer with 2 invoices ($4000, $6000); collect $7000; verify first invoice is PAID, second is partially paid ($3000 remaining); verify the collection detail shows 2 allocations ($4000 + $3000)
  - [x] 21.20 Test: create a payee linked to two businesses; pay an invoice in business A; verify business B's invoices are not affected

- [x] 22. Add database foreign key constraints (REQ-022)
  - [x] 22.1 Query each FK relationship to verify no orphaned records exist before running the migration
  - [x] 22.2 Create migration `database/migrations/add_foreign_keys.sql` with FK constraints for: payees → people, payees → businesses, sales_invoices → businesses, sales_invoices → payees, si_details → sales_invoices (CASCADE), purchase_orders → businesses, po_details → purchase_orders (CASCADE), user_roles → users (CASCADE), user_roles → roles
  - [x] 22.3 Run the migration
  - [x] 22.4 Test: attempt to delete a person who has payee records — verify the operation is rejected with a FK constraint error

- [x] 23. Item price history UI (REQ-023)
  - [x] 23.1 Verify `GET /items/{id}/price-history` endpoint is registered in `public/api/routes/items.php`; add it if missing
  - [x] 23.2 Create `public/html/Items/priceHistory.html` — modal template with DataTable showing date, old cost, new cost, old sale price, new sale price, changed by
  - [x] 23.3 Create `public/assets/js/pages/Items/priceHistory.modal.js` — modal controller that fetches and displays price history
  - [x] 23.4 Add "Price History" button to `public/assets/js/pages/Items/items.js` per item row
  - [x] 23.5 Test: change an item's cost price, open price history — verify the change is recorded with old and new values and the user's name

- [x] 24. Credit notes for sales returns (REQ-024)
  - [x] 24.1 Create migration `database/migrations/add_credit_debit_notes.sql` — create `credit_notes` and `credit_note_items` tables
  - [x] 24.2 Create `src/Repository/CreditNoteRepository.php` with `create()`, `addLine()`, `findById()`, `findAllForBusiness()` methods
  - [x] 24.3 Create `src/Services/CreditNoteService.php` — issuing a credit note MUST reverse stock for returned items and reduce the customer's payee balance
  - [x] 24.4 Create `src/Controllers/CreditNoteController.php` with CRUD endpoints
  - [x] 24.5 Register credit note routes in `public/api/routes/sales_invoices.php`
  - [x] 24.6 Create `public/html/SalesInvoices/creditNoteForm.html` and `public/assets/js/pages/SalesInvoices/creditNoteForm.modal.js`
  - [x] 24.7 Add "Issue Credit Note" button on the Sales Invoices page for DELIVERED/PAID invoices
  - [x] 24.8 Test: issue a credit note for a delivered invoice — verify stock is reversed, payee balance is reduced, and the credit note appears in the customer statement

- [x] 25. Debit notes for purchase returns (REQ-025)
  - [x] 25.1 Add `debit_notes` and `debit_note_items` tables to the migration from task 24.1
  - [x] 25.2 Create `src/Repository/DebitNoteRepository.php` with `create()`, `addLine()`, `findById()`, `findAllForBusiness()` methods
  - [x] 25.3 Create `src/Services/DebitNoteService.php` — issuing a debit note MUST reverse stock for returned items and reduce the supplier's payee balance
  - [x] 25.4 Create `src/Controllers/DebitNoteController.php` with CRUD endpoints
  - [x] 25.5 Register debit note routes in `public/api/routes/purchase_orders.php`
  - [x] 25.6 Create `public/html/PurchaseOrders/debitNoteForm.html` and `public/assets/js/pages/PurchaseOrders/debitNoteForm.modal.js`
  - [x] 25.7 Add "Issue Debit Note" button on the Purchase Orders page for RECEIVED/CLOSED orders
  - [x] 25.8 Test: issue a debit note for a received PO — verify stock is reversed, payee balance is reduced, and the debit note appears in the supplier statement

- [x] 26. Prevent tenant owners from being assigned business roles (REQ-026)
  - [x] 26.1 Create migration `database/migrations/fix_tenant_owner_business_roles.sql` — delete any `user_roles` rows where the user is a tenant owner of the same tenant AND the row has a non-null `business_id`
  - [x] 26.2 Run the migration
  - [x] 26.3 Add `isTenantOwner(int $userId, int $tenantId): bool` to `src/Repository/UserRepository.php`
  - [x] 26.4 Add a guard in `src/Services/UserService.php::assignRole()` — if the user is a tenant owner of the target tenant and `$businessId` is not null, throw an `InvalidArgumentException` with message "Tenant owners cannot be assigned business-level roles within their own tenant"
  - [x] 26.5 Update the user role assignment UI to hide the business role form when the selected user is already a tenant owner of the current tenant
  - [x] 26.6 Test: attempt to assign a business role to a tenant owner via the API — verify 422 response with the correct message; verify the UI hides the form for tenant owner users

- [x] 27. Drop deprecated and orphaned tables (REQ-027)
  - [x] 27.1 Verify zero code references to `notification_roles`, `business_payees_types` using grep before dropping
  - [x] 27.2 Create migration `database/migrations/drop_deprecated_tables.sql` — drop in dependency order: `notification_roles`, `business_payees_types`, `business_module_overrides` (after task 11), `user_notifications` (after task 12)
  - [x] 27.3 Run the migration
  - [x] 27.4 Test: verify application starts without errors; verify no 500 errors on pages that previously touched these tables

- [x] 28. Merge item_auto_markup and item_cost_flags into items (REQ-028)
  - [x] 28.1 Create migration `database/migrations/merge_item_auxiliary_tables.sql` — add `markup_percent DECIMAL(5,2) NULL` and `markup_enabled TINYINT(1) NOT NULL DEFAULT 0` to `items`; copy data from `item_auto_markup`; drop `item_auto_markup` and `item_cost_flags`
  - [x] 28.2 Run the migration
  - [x] 28.3 Update all PHP code referencing `item_auto_markup` or `item_cost_flags` to use `items.markup_percent` and `items.markup_enabled` directly
  - [x] 28.4 Test: verify item markup still applies correctly; verify `has_cost_change_flag` on items still drives the cost-change alert UI

- [x] 29. Add missing created_by and audit columns (REQ-029)
  - [x] 29.1 Create migration `database/migrations/add_missing_audit_columns.sql` — add `created_by INT NULL FK→users` to: `item_price_history`, `stock_transactions`, `batch_transactions`, `partner_equity`, `business_profit`, `assets`; add `updated_at DATETIME NULL` to `people`
  - [x] 29.2 Run the migration
  - [x] 29.3 Update all Repository INSERT methods for the affected tables to populate `created_by` from `AuthContext::userId()`
  - [x] 29.4 Update `src/Repository/ItemPriceHistoryRepository.php` (or wherever price history is written) to pass `created_by` — this is required for the REQ-023 "changed by" display
  - [x] 29.5 Test: change an item price; open price history modal — verify the user's name appears in the "Changed By" column

- [x] 30. Fix data consistency issues (REQ-030)
  - [x] 30.1 Create migration `database/migrations/fix_data_consistency.sql` with the following changes (each wrapped in a transaction):
    - Rename `tbl_translations` to `translations`
    - Add `account_type ENUM('BANK','CASH','CREDIT','OTHER') NOT NULL DEFAULT 'CASH'` to `business_accounts`
    - Add `reference_number VARCHAR(100) NULL`, `reference_date DATE NULL`, `attachment_path VARCHAR(500) NULL` to `expenses`
    - Fix `supplier_item_prices.supplier_id` to reference `payees.id` instead of `people.id` (re-map existing rows via JOIN on `payees.people_id`)
    - Standardize `batch_transactions.transaction_type` ENUM to uppercase: `IN`, `OUT`, `WRITE_OFF`
    - Replace `stock_transactions.reference VARCHAR(100)` with `reference_type ENUM('PURCHASE_ORDER','SALES_INVOICE','PRODUCTION','MANUAL','CREDIT_NOTE','DEBIT_NOTE') NULL` and `reference_id INT NULL`; migrate existing data where parseable; drop old `reference` column
    - Drop `users.password_reset` INT column (replaced by `password_reset_tokens` table)
    - Standardize `recurrence_schedules.entity_type` and `recurrence_instances.entity_type` ENUM to uppercase: `EXPENSE`, `SALARY`
    - Drop `recurrence_instances.is_advance_payment`, `bulk_advance_payment_id`, `metadata` columns
    - Add `recipient_id INT NOT NULL FK→users` to `notifications` (backfill from `user_notifications` before dropping it)
    - Replace `notification_preferences.type` ENUM with `notification_type_id INT FK→notification_types`
  - [x] 30.2 Run the migration
  - [x] 30.3 Update all PHP code referencing `tbl_translations` to use `translations`
  - [x] 30.4 Update `src/Repository/ExpenseRepository.php` — include `reference_number`, `reference_date`, `attachment_path` in INSERT/UPDATE/SELECT queries
  - [x] 30.5 Update `public/html/Expenses/expenseForm.html` — add "Vendor Invoice #" field, "Invoice Date" field, and "Attach Document" file upload button; style as a collapsible "Document Reference" section
  - [x] 30.6 Update `public/assets/js/pages/Expenses/expenseForm.modal.js` — handle file upload to a `/uploads/expenses/` endpoint; store returned path in `attachment_path`
  - [x] 30.7 Update `public/html/Expenses/expenses.html` — add a document icon indicator per row (filled if attachment exists, empty if not); clicking opens the attachment in a new tab
  - [x] 30.8 Update `src/Repository/SupplierItemPriceRepository.php` to use `payees.id` for supplier lookups
  - [x] 30.9 Update `src/Repository/NotificationRepository.php` to use `recipient_id` and `notification_type_id`
  - [x] 30.10 Update recurrence-related repositories to use uppercase entity_type values
  - [x] 30.11 Test: create an expense with a vendor invoice reference and attachment; verify the reference displays in the expense list; verify the attachment is downloadable; verify supplier price lookup works; verify notifications display correctly

- [x] 31. System-defined types with module visibility gating (REQ-031)
  - [x] 31.1 Create migration `database/migrations/add_system_types_pattern.sql` — apply the system-types schema pattern to all classification tables:
    - `item_types`: add `is_system TINYINT(1) NOT NULL DEFAULT 0`, `module_code VARCHAR(50) NULL`, `code VARCHAR(50) NULL`; seed system types (TRADING, CONSUMABLE, FINISHED_GOOD, RAW_MATERIAL, MANUFACTURED, SERVICE_ITEM, ASSET_ITEM); map existing 3 rows to nearest system type
    - `payee_types`: add `is_system TINYINT(1) NOT NULL DEFAULT 0`, `module_code VARCHAR(50) NULL`, `code VARCHAR(50) NULL`; seed system types (CUSTOMER, SUPPLIER, EMPLOYEE, PARTNER, OTHER); rename `business_payees_types` to `people_types` (junction: people_id ↔ payee_type_id)
    - `expense_categories`: add `is_system TINYINT(1) NOT NULL DEFAULT 0`, `module_code VARCHAR(50) NULL`, `code VARCHAR(50) NULL`; seed system types (RENT, UTILITIES, MARKETING, MAINTENANCE, TRANSPORT, OTHER)
    - `asset_categories`: CREATE TABLE with `id, tenant_id, business_id, code, name, module_code, is_system, created_at`; seed system types (LAND_BUILDINGS, EQUIPMENT, VEHICLES, FURNITURE, IT_EQUIPMENT, INTANGIBLE, INVENTORY_ASSET); migrate `assets.category` free text to `assets.category_id FK→asset_categories`
    - `service_categories`: add `is_system TINYINT(1) NOT NULL DEFAULT 0`, `module_code VARCHAR(50) NULL`, `code VARCHAR(50) NULL`; seed system types (CONSULTING, MAINTENANCE, DELIVERY, INSTALLATION, OTHER)
  - [x] 31.2 Run the migration
  - [x] 31.3 Create `src/Repository/SystemTypeRepository.php` — generic repository with `findForBusiness(string $table, int $businessId): array` that filters by active modules; `isSystemType(string $table, int $id): bool` guard method
  - [x] 31.4 Update `src/Repository/ItemTypeRepository.php` — use module-gated query; reject edits/deletes on system types
  - [x] 31.5 Update `src/Repository/PayeeTypeRepository.php` (or create if missing) — use module-gated query; manage `people_types` junction
  - [x] 31.6 Update `src/Repository/ExpenseCategoryRepository.php` — use module-gated query; reject edits/deletes on system types
  - [x] 31.7 Create `src/Repository/AssetCategoryRepository.php` — use module-gated query; reject edits/deletes on system types
  - [x] 31.8 Update `src/Repository/ServiceCategoryRepository.php` — use module-gated query; reject edits/deletes on system types
  - [x] 31.9 Update `src/Services/ManufacturingService.php` — validate BOM components are RAW_MATERIAL or CONSUMABLE; validate BOM outputs are MANUFACTURED or FINISHED_GOOD
  - [x] 31.10 Update all type/category selector UIs (item form, people form, expense form, asset form, service form) to fetch types via the module-gated API endpoints
  - [x] 31.11 Update people create/edit form to allow assigning multiple types via the `people_types` junction; update invoice/PO/salary forms to filter people by type (CUSTOMER for invoices, SUPPLIER for POs, EMPLOYEE for salaries)
  - [x] 31.12 Test: disable Manufacturing module on a business type → verify RAW_MATERIAL and MANUFACTURED item types are not shown in the item form; re-enable → verify they reappear
  - [x] 31.13 Test: create a person with type SUPPLIER only → verify they cannot be selected as a customer on an invoice; add CUSTOMER type → verify they now appear in the customer selector

- [x] 32. Fix partner equity and profit distribution architecture (REQ-032)
  - [x] 32.1 Create migration `database/migrations/fix_partner_profit_schema.sql`:
    - Drop the composite PK on `business_profit`; add `id INT AUTO_INCREMENT PRIMARY KEY`; add columns: `total_revenue`, `total_cogs`, `gross_profit`, `total_expenses`, `net_profit`, `safety_net_percent`, `safety_net_amount`, `distributable_profit`, `status ENUM('DRAFT','FINALIZED','DISTRIBUTED') DEFAULT 'DRAFT'`, `updated_at`; drop old columns (`profit_bc`, `safety_net_type`, `safety_net_bc`, `distributable_profit_bc`); add UQ on `(business_id, period_start)`
    - Create `profit_distributions` table: `id`, `business_profit_id FK→business_profit`, `partner_id FK→payees`, `share_percent DECIMAL(5,2)`, `amount_bc DECIMAL(12,2)`, `payment_slip_id FK→payment_slips`, `created_at`
    - Alter `partner_equity`: drop `reference_type` and `reference_id`; add `slip_type ENUM('COLLECTION_SLIP','PAYMENT_SLIP') NOT NULL`, `slip_id INT NOT NULL`, `notes TEXT NULL`
  - [x] 32.2 Run the migration
  - [x] 32.3 Rewrite `src/Services/BusinessProfitService::create()` — revenue = sum of PAID invoices `total_amount_bc` in period; COGS = sum of `si_details` cost for PAID invoices (join `items.cost_price × quantity`); gross_profit = revenue - COGS; expenses = sum of `expenses.amount_bc` + sum of `salaries.net_amount_bc` in period; net_profit = gross_profit - expenses; apply safety_net_percent
  - [x] 32.4 Rewrite `src/Services/BusinessProfitService::distribute()` — create `profit_distributions` rows with ownership snapshot; create payment slips via the allocation system (reference_type = 'PARTNER_PROFIT'); link `profit_distributions.payment_slip_id` to each created slip
  - [x] 32.5 Rewrite `src/Services/PartnerEquityService::create()` — for CONTRIBUTION: create a collection slip first (money IN to selected account), then create equity record with `slip_type = 'COLLECTION_SLIP'`, `slip_id = <new_slip_id>`, then update `invested_capital`; for WITHDRAWAL: create a payment slip first (money OUT), then equity record with `slip_type = 'PAYMENT_SLIP'`, `slip_id = <new_slip_id>`, then update `invested_capital`
  - [x] 32.6 Update `src/Repository/BusinessProfitRepository.php` — adapt all queries to the new schema (id-based PK, new columns, status filtering)
  - [x] 32.7 Update `src/Repository/PartnerEquityRepository.php` — adapt INSERT to use `slip_type`/`slip_id` instead of `reference_type`/`reference_id`; add JOIN to collection_slips/payment_slips for the list view
  - [x] 32.8 Create `src/Repository/ProfitDistributionRepository.php` with `createBatch(int $profitId, array $distributions): void` and `findByProfitId(int $profitId): array`
  - [x] 32.9 Update `public/html/Finance/businessProfit.html` — redesign the profit detail view: show P&L breakdown (revenue, COGS, gross profit, expenses, salaries, net profit, safety net, distributable) in a clean card layout; show status badge and action buttons (Finalize / Distribute); when DISTRIBUTED, show a distribution table with partner name, ownership %, amount, and clickable payment slip link
  - [x] 32.10 Update `public/assets/js/pages/Finance/businessProfit.js` — implement the workflow UI: DRAFT shows editable safety_net_percent with live recalculation; FINALIZED shows "Distribute" button; DISTRIBUTED shows distribution breakdown table
  - [x] 32.11 Update `public/html/Finance/partnerEquity.html` — show a per-partner ledger: contributions as credits (green), withdrawals as debits (red), with slip reference links and running invested_capital balance; add "Contribute" and "Withdraw" action buttons
  - [x] 32.12 Update `public/assets/js/pages/Finance/partnerEquity.modal.js` — the contribute/withdraw form MUST include account selector (which business account the money flows to/from) and amount; on submit, the backend creates both the slip and the equity record atomically
  - [x] 32.13 Update `public/html/Finance/partnerShares.html` — show current ownership table with: partner name, ownership %, invested capital, and action buttons (Contribute / Withdraw) per row; show a summary card with total ownership allocated and remaining %
  - [x] 32.14 Test: create a profit record for a period with known PAID invoices → verify revenue matches invoice totals (not collection sums); verify COGS is calculated from item cost prices; verify expenses don't double-count paid expenses
  - [x] 32.15 Test: finalize and distribute a profit record with 2 partners (60%/40%) → verify `profit_distributions` rows are created with correct amounts; verify payment slips are created; verify the detail view shows the breakdown
  - [x] 32.16 Test: contribute $10,000 to a partner → verify a collection slip is created in the selected account; verify `invested_capital` increases; verify the equity ledger shows the entry with a slip link
  - [x] 32.17 Test: withdraw $5,000 from a partner → verify a payment slip is created; verify `invested_capital` decreases; verify withdrawal is rejected if amount exceeds invested_capital

- [x] 33. Generate deployment database creation script (REQ-033)
  - [x] 33.1 Create `database/schema.sql` — a single idempotent script that creates the entire database from scratch in the correct dependency order. This script MUST:
    - Create all tables in FK-dependency order (tenants first, then users, businesses, etc.)
    - Include all columns in their final target state (no migration steps — this is the clean end-state)
    - Include all indexes (PRIMARY, UNIQUE, KEY) as defined in the target ERD
    - Include all FOREIGN KEY constraints with correct ON DELETE behavior
    - Include all CHECK constraints (e.g., `si_details` item_or_service check, `roles` business_scope check)
    - Include all ENUM definitions with their final values
    - Use `IF NOT EXISTS` on CREATE TABLE for idempotency
  - [x] 33.2 Create `database/seed_system_data.sql` — a separate script that inserts all system/seed data:
    - System business types (General, Retail, Wholesale, Manufacturing, Service) with `is_system = 1`
    - All modules with codes, names, icons, route_prefixes, permission_prefixes, categories, sort_order
    - Default `business_type_modules` mappings for each system type
    - All permissions (the full set of ~70+ permission codes)
    - System roles (Tenant Owner template)
    - System item types (TRADING, CONSUMABLE, FINISHED_GOOD, RAW_MATERIAL, MANUFACTURED, SERVICE_ITEM, ASSET_ITEM)
    - System payee types (CUSTOMER, SUPPLIER, EMPLOYEE, PARTNER, OTHER)
    - System expense categories (RENT, UTILITIES, MARKETING, MAINTENANCE, TRANSPORT, OTHER)
    - System asset categories (LAND_BUILDINGS, EQUIPMENT, VEHICLES, FURNITURE, IT_EQUIPMENT, INTANGIBLE, INVENTORY_ASSET)
    - System service categories (CONSULTING, MAINTENANCE, DELIVERY, INSTALLATION, OTHER)
    - System notification types (low_stock, overdue_payment, expiry_alert, invoice_overdue, system)
    - Languages (en, ar, fr) with direction
    - Use `INSERT IGNORE` for idempotency
  - [x] 33.3 Create `database/README.md` documenting:
    - How to create a fresh database: `CREATE DATABASE erp CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;`
    - How to run the schema: `mysql -u root erp < database/schema.sql`
    - How to seed system data: `mysql -u root erp < database/seed_system_data.sql`
    - How to run the first-installation flow after seeding (reference to the first-installation-flow spec)
    - Table count, relationship summary, and a note that `database/migrations/` contains incremental changes for existing deployments
  - [x] 33.4 Validate `database/schema.sql` by running it against a fresh empty database — verify zero errors, all tables created, all FKs valid
  - [x] 33.5 Validate `database/seed_system_data.sql` by running it after schema — verify all system data is inserted correctly
  - [x] 33.6 Run the full application against the fresh database (with seed data) — verify the first-installation flow spec can take over from here (no tenant exists → setup wizard activates)
  - [x] 33.7 Cross-reference the final table list against the target ERD (`docs/erd-target-state.md`) — verify every table in the ERD exists in `schema.sql` and vice versa; verify column types, constraints, and indexes match

- [x] 34. Add user profile page and remove module override page (REQ-034)
  - [x] 34.1 Create `public/html/Profile/profile.html` — page template with: read-only username field, editable first name / last name / email fields with "Save" button, and a separate "Change Password" card with current password, new password, confirm password fields and "Update Password" button
  - [x] 34.2 Create `public/assets/js/pages/Profile/profile.js` — page controller that loads current user data from `GET /auth/me` (or equivalent), handles profile update via `PATCH /users/me`, and handles password change via `POST /users/me/password`
  - [x] 34.3 Add `PATCH /users/me` endpoint to `src/Controllers/UsersController.php` — allows the authenticated user to update their own first_name, last_name, email; register in `public/api/routes/users.php`
  - [x] 34.4 Add `POST /users/me/password` endpoint to `src/Controllers/UsersController.php` — accepts `current_password`, `new_password`; verifies current password matches hash; validates new password >= 8 chars; updates `password_hash`; returns success
  - [x] 34.5 Update `public/assets/js/router/router.config.js` — change the `#/profile` route to load `../pages/Profile/profile.js` instead of `../pages/Others/404.js`
  - [x] 34.6 Remove the `#/settings/modules` route from `router.config.js`
  - [x] 34.7 Remove the Settings → Modules link from `public/assets/js/Shells/appShell.config.js` (the entry with `hash: '#/settings/modules'`)
  - [x] 34.8 Delete `public/assets/js/pages/Settings/modules.js` and `public/html/Settings/modules.html`
  - [x] 34.9 Remove the `POST /settings/modules/override` endpoint from `src/Controllers/ModulesController.php` (the override logic is dead code after REQ-011)
  - [x] 34.10 Verify `ModuleService.fetchActiveModules()` still works — it should call `GET /api/settings/modules` which returns the read-only list of active modules for the current business (resolved via business_type_modules)
  - [x] 34.11 Test: navigate to `#/profile` → verify user details load; change last name → verify it persists; change password with correct current password → verify login works with new password; change password with wrong current password → verify rejection with clear error

## Task Dependency Graph

```json
{
  "waves": [
    {
      "wave": 1,
      "label": "Phase 1 — Critical Bug Fixes",
      "tasks": [1, 2, 3, 4, 5, 6, 26]
    },
    {
      "wave": 2,
      "label": "Phase 2 — Schema & Data Integrity",
      "tasks": [7, 8, 9, 10, 11, 12, 34],
      "dependsOn": []
    },
    {
      "wave": 3,
      "label": "Phase 3 — Core Features (batch A)",
      "tasks": [14, 15, 17, 18, 19],
      "dependsOn": []
    },
    {
      "wave": 4,
      "label": "Phase 3 — Core Features (batch B, needs tax fields)",
      "tasks": [13, 16],
      "dependsOn": [1, 14]
    },
    {
      "wave": 5,
      "label": "Phase 4 — Architecture",
      "tasks": [20, 21, 23, 24],
      "dependsOn": [1, 4]
    },
    {
      "wave": 6,
      "label": "Phase 4 — Final (needs prior schema changes)",
      "tasks": [22, 25],
      "dependsOn": [24]
    },
    {
      "wave": 7,
      "label": "Phase 5 — Schema Cleanup",
      "tasks": [27, 28, 29, 30],
      "dependsOn": [11, 12, 23]
    },
    {
      "wave": 8,
      "label": "Phase 5 — System Types & Module Gating (needs cleanup done first)",
      "tasks": [31],
      "dependsOn": [11, 27, 30]
    },
    {
      "wave": 9,
      "label": "Phase 5 — Partner & Profit Architecture (needs allocation system)",
      "tasks": [32],
      "dependsOn": [21, 29]
    },
    {
      "wave": 10,
      "label": "Phase 6 — Deployment Script (must run LAST after all migrations are stable)",
      "tasks": [33],
      "dependsOn": [22, 27, 28, 29, 30, 31, 32]
    }
  ]
}
```

## Notes

- All SQL migrations should be run using: `& "C:\wamp64\bin\mysql\mysql9.1.0\bin\mysql.exe" -u root erp < path/to/migration.sql`
- Run `composer dump-autoload` after creating any new PHP class files
- Run `npx vitest --run` after any JS changes to verify no regressions
- Run `vendor/bin/phpunit` after any PHP changes to verify no regressions
- Phase 1 tasks 1-4 are the highest priority — they fix broken core functionality that affects every user session
- Task 22 (foreign keys) should be run last as it validates data integrity after all other schema changes are in place
- For Task 13 (PDF), DOMPDF requires `allow_url_fopen = On` in `php.ini` — verify this is set in the WAMP configuration
