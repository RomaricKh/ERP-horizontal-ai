# Requirements Document

## Introduction

This spec covers all bugs, data integrity issues, architectural weaknesses, and missing core features identified in the full system analysis of ERP-Horizontal-AI (May 2026). Work is organized into four phases: critical bug fixes that break existing functionality, data integrity and schema fixes, core missing features required for a professional ERP, and architectural improvements for long-term maintainability.

The analysis identified 12 confirmed bugs (5 critical, 4 high, 3 medium), 8 redundant or unused database tables, and 25+ missing features. This spec addresses all of them in priority order.

## Requirements

### Phase 1 — Critical Bug Fixes

#### REQ-001: Fix sales_invoices PAID status

**Problem:** `sales_invoices.status` ENUM is `('DRAFT','DELIVERED','CANCELLED')` — the value `'PAID'` is missing. The dashboard revenue query, business profit calculation, and settlement engine all reference `status = 'PAID'`, causing revenue to always return 0 and profit distribution to always distribute 0.

**Requirements:**
- The system MUST add `'PAID'` to the `sales_invoices.status` ENUM via a SQL migration
- The settlement engine MUST set invoice status to `'PAID'` when a customer's balance reaches 0 and all DELIVERED invoices are fully covered by collection slips
- The dashboard revenue query MUST return the correct sum of paid invoices
- The business profit calculation MUST use the corrected revenue figure
- Existing DELIVERED invoices that are fully paid (collection slips cover the total) MUST be migrated to `'PAID'` status as part of the migration

#### REQ-002: Fix DashboardRepository payables query

**Problem:** `DashboardRepository::getMonthlyPayables()` references `YEAR(order_date)` and `MONTH(order_date)` but the `purchase_orders` table column is named `po_date`, not `order_date`. This causes a SQL error when the dashboard loads.

**Requirements:**
- The payables query MUST reference `po_date` instead of `order_date`
- The query MUST correctly return the sum of outstanding purchase orders for the current month
- The dashboard MUST display payables without throwing a SQL error

#### REQ-003: Fix AuditRepository user name JOIN

**Problem:** `AuditRepository::findForBusiness()` JOINs `users` and selects `u.name` and `u.email`, but the `users` table has `first_name` and `last_name` columns — there is no `name` column. The audit log viewer shows NULL for all user names.

**Requirements:**
- The audit log query MUST use `CONCAT(u.first_name, ' ', u.last_name) AS user_name`
- The audit log viewer MUST display the full name of the user who performed each action
- The `user_email` field MUST continue to be returned correctly

#### REQ-004: Fix payment_slips reference_type ENUM inconsistency

**Problem:** `payment_slips.reference_type` ENUM contains both `'PO'` and `'PURCHASE_ORDER'` as valid values. The settlement engine uses `'PURCHASE_ORDER'` but legacy code may use `'PO'`, causing settlement calculations to miss payments.

**Requirements:**
- The ENUM MUST be standardized to use only `'PURCHASE_ORDER'`
- A SQL migration MUST update any existing rows with `reference_type = 'PO'` to `'PURCHASE_ORDER'`
- All PHP code referencing `'PO'` as a reference_type MUST be updated to `'PURCHASE_ORDER'`
- The settlement engine's `computePoPaymentStatus()` MUST correctly sum all payment slips for a PO

#### REQ-005: Fix roles.name UNIQUE constraint scope

**Problem:** `roles.name` has a global UNIQUE constraint. Two different businesses cannot create a role with the same name (e.g., both wanting a "Manager" role). This prevents normal multi-business operation.

**Requirements:**
- The UNIQUE constraint on `roles.name` MUST be changed to a composite unique constraint on `(name, business_id)`
- System roles with `business_id = NULL` MUST remain globally unique
- Existing role data MUST NOT be affected by the migration
- The role creation API MUST validate uniqueness within the same business only

#### REQ-026: Prevent tenant owners from being assigned business roles

**Problem:** A user who holds the `TENANT_OWNER` role in a tenant can currently also be assigned a business-scoped role (e.g., Staff, Manager) within a business that belongs to the same tenant. This is logically incorrect — a tenant owner already has full cross-business authority and should not hold a subordinate business role in their own tenant. Allowing it creates ambiguous permission resolution and pollutes the user-roles table with redundant entries.

**Requirements:**
- When assigning a role to a user for a business, the system MUST check whether that user already holds a `TENANT_OWNER` role for the same tenant
- If the user is a tenant owner of that tenant, the assignment MUST be rejected with a clear error: "Tenant owners cannot be assigned business-level roles within their own tenant"
- This validation MUST be enforced server-side in `UserService` (not only in the UI)
- The user assignment UI MUST hide or disable the role assignment form for users who are already tenant owners of the current tenant
- Existing `user_roles` rows that violate this rule (tenant owner also has a business role in the same tenant) MUST be identified and cleaned up via a migration

#### REQ-006: Fix stock going negative on OUT transactions

**Problem:** No validation prevents stock from going negative when items are consumed (invoice delivery, production orders, manual adjustments). Negative stock silently corrupts inventory data.

**Requirements:**
- Before any stock OUT transaction, the system MUST check that current stock quantity >= quantity being consumed
- If stock is insufficient, the operation MUST be rejected with a clear error message indicating the item name and available quantity
- This validation MUST apply to: sales invoice delivery, production order creation, and manual stock adjustments of type OUT
- The validation MUST be performed within the same database transaction as the stock update to prevent race conditions

---

### Phase 2 — Data Integrity & Schema Fixes

#### REQ-007: Add is_active flag to users table

**Problem:** The `users` table has no `is_active` column. Administrators cannot suspend or deactivate a user account without permanently deleting it, which also destroys audit history.

**Requirements:**
- A migration MUST add `is_active TINYINT(1) NOT NULL DEFAULT 1` to the `users` table
- The `AuthMiddleware` MUST reject login attempts from users where `is_active = 0` with a clear "account disabled" message
- The Users management page MUST display an active/inactive toggle per user
- Deactivating a user MUST revoke all their active refresh tokens
- All existing users MUST default to `is_active = 1`

#### REQ-008: Fix recipes.yield_quantity column type

**Problem:** `recipes.yield_quantity` is `INT`, preventing fractional yields (e.g., a recipe that produces 1.5 kg of output).

**Requirements:**
- A migration MUST change `recipes.yield_quantity` from `INT` to `DECIMAL(12,4)`
- The recipe form MUST accept decimal values for yield quantity
- Existing integer values MUST be preserved
- Production order cost calculations MUST continue to work correctly with decimal yields

#### REQ-009: Add updated_at to services table

**Problem:** The `services` table has no `updated_at` column, making it impossible to track when a service was last modified.

**Requirements:**
- A migration MUST add `updated_at DATETIME NULL` to the `services` table
- The `ServiceRepository::update()` method MUST set `updated_at = NOW()` on every update
- The `ServiceRepository::create()` method MUST set `updated_at = NOW()` on insert

#### REQ-010: Add is_active flag to businesses table

**Problem:** The `businesses` table has no `is_active` column. A tenant owner cannot suspend a business without deleting it and all its data.

**Requirements:**
- A migration MUST add `is_active TINYINT(1) NOT NULL DEFAULT 1` to the `businesses` table
- The `AuthMiddleware` MUST reject requests scoped to a business where `is_active = 0`
- The Tenant → Businesses management page MUST display an active/inactive toggle
- All existing businesses MUST default to `is_active = 1`

#### REQ-011: Replace business_module_overrides with tenant-owned business types

**Problem:** The `business_module_overrides` table is a band-aid that exists because system business types are shared and immutable. Module activation requires two-table lookups (overrides first, then type defaults), and the Settings → Modules page never persisted changes to the overrides table anyway. The root fix is to give tenant owners the ability to create and manage their own business types derived from system templates, so module configuration lives on the type itself — not on per-business overrides.

**Requirements:**
- System business types (`is_system = 1`, `tenant_id = NULL`) MUST be immutable — they serve as templates only and MUST NOT be directly assigned to businesses
- Tenant owners MUST be able to copy any system business type to create a tenant-owned business type (`is_system = 0`, `tenant_id = <tenant>`)
- Tenant owners MUST be able to add or remove modules from their own business types at any time
- When a tenant-owned business type's modules change, all businesses assigned to that type MUST immediately reflect the updated module set
- Businesses MUST only be assignable to tenant-owned business types, never directly to system types
- A migration MUST re-assign any businesses currently pointing to system types to a tenant-owned copy of that type (auto-created during migration)
- The `business_module_overrides` table MUST be deprecated — `ModuleActivationService::isModuleActive()` MUST resolve module state solely from the business's assigned `business_type_id` via `business_type_modules`
- The Tenant → Business Types management page MUST allow: listing tenant types, creating a type by copying a system type, editing module assignments on a tenant type, and deleting an unused tenant type
- The `code` unique constraint on `business_types` MUST be scoped to `(code, tenant_id)` so tenant types can reuse system type codes as a base name without collision
- The UI for assigning a business type when creating or editing a business MUST only show tenant-owned types for that tenant

#### REQ-012: Fix notification table redundancy

**Problem:** The `notifications` table already has `recipient_id`, `is_read`, and `read_at` columns. The `user_notifications` table duplicates this tracking. Additionally, `notification_types`, `notification_preferences`, and `notification_roles` are all empty and never used.

**Requirements:**
- The `user_notifications` table MUST be deprecated — all read/unread tracking MUST use `notifications.is_read` and `notifications.read_at` directly
- `notification_types` MUST be populated with the notification types used in the system
- `notification_preferences` MUST be wired up so users can opt in/out of specific notification types

---

### Phase 3 — Core Missing Features

#### REQ-013: Invoice and PO PDF generation

**Problem:** There is no way to generate a printable/sendable PDF for sales invoices or purchase orders.

**Requirements:**
- The system MUST generate a PDF for any sales invoice using business name, invoice number, date, customer details, line items, totals, and notes
- The system MUST generate a PDF for any purchase order using the same structure with supplier details
- PDFs MUST be generated server-side using DOMPDF
- A "Download PDF" button MUST appear on the invoice/PO list view
- PDFs MUST respect the business's currency and number format settings

#### REQ-014: Tax/VAT fields on invoice and PO lines

**Problem:** There are no tax or VAT fields anywhere in the invoicing or purchasing flow.

**Requirements:**
- Each `si_details` line MUST support an optional `tax_rate` (percentage) and `tax_amount_fc` / `tax_amount_bc`
- Each `po_details` line MUST support the same tax fields
- The invoice and PO forms MUST allow selecting a tax rate per line
- Invoice and PO totals MUST show subtotal (before tax), total tax, and grand total
- The `sales_invoices` and `purchase_orders` tables MUST store `tax_total_fc` and `tax_total_bc`

#### REQ-015: Invoice due date and AR aging

**Problem:** Sales invoices have no due date or payment terms. There is no way to track overdue invoices or generate an accounts receivable aging report.

**Requirements:**
- `sales_invoices` MUST have a `due_date DATE` column
- The invoice form MUST allow setting a due date (defaulting to invoice_date + 30 days)
- The Sales Invoices list MUST show a visual indicator for overdue invoices
- A new AR Aging report MUST show: Current, 1-30 days, 31-60 days, 61-90 days, 90+ days overdue — with totals per bucket per customer

#### REQ-016: Financial reports (P&L, Balance Sheet, Cash Flow)

**Problem:** There are no financial reports. A business cannot see its profit and loss, balance sheet, or cash flow statement.

**Requirements:**
- A Profit & Loss Statement MUST be available showing revenue, COGS, gross profit, operating expenses, and net profit — filterable by date range
- A Cash Flow Statement MUST be available showing cash inflows, outflows, and net movement — filterable by date range
- A Balance Sheet MUST be available showing assets, liabilities, and equity
- All reports MUST be exportable to PDF

#### REQ-017: Email-based password reset

**Problem:** Password reset requires admin intervention. Users cannot reset their own passwords.

**Requirements:**
- The system MUST support SMTP email configuration in Settings
- The forgot password flow MUST send a time-limited reset link (valid 1 hour) to the user's registered email
- Reset tokens MUST be stored hashed in the database with an expiry timestamp
- The existing admin-reset flow MUST remain available as a fallback

#### REQ-018: Cron job for recurring instance generation

**Problem:** Recurring expense and salary instances are never auto-generated. The recurrence system only works if manually triggered.

**Requirements:**
- A cron-accessible PHP endpoint MUST exist at `public/api/cron/generate-recurrences.php` protected by a secret token
- The endpoint MUST generate all pending recurrence instances for the current date across all active schedules up to 30 days in advance
- Instances that have passed their due date without payment MUST be automatically marked as `overdue`
- The endpoint MUST be idempotent — running it multiple times MUST NOT create duplicate instances
- Documentation MUST be provided for setting up the Windows Task Scheduler or cron job

#### REQ-019: Discount fields on invoices and POs

**Problem:** There is no way to apply discounts to invoice lines or to the invoice total.

**Requirements:**
- Each `si_details` line MUST support a `discount_percent DECIMAL(5,2)` field (0-100)
- The line total calculation MUST apply the discount before tax
- The invoice form MUST show a discount column per line
- The same discount fields MUST be added to `po_details` and `purchase_orders`

---

### Phase 4 — Architecture Improvements

#### REQ-020: Move secrets to environment variables

**Problem:** The JWT secret and database credentials are hardcoded in `src/Config/config.php`.

**Requirements:**
- A `.env` file MUST be used for all secrets (DB host, DB name, DB user, DB password, JWT secret)
- `src/Config/config.php` MUST read from `$_ENV` with fallback defaults for development
- A `.env.example` file MUST be committed to the repository with placeholder values
- `.env` MUST be listed in `.gitignore`

#### REQ-021: Fix settlement engine architecture with payment allocations

**Problem:** The settlement engine determines if a document is paid by checking if the payee's global balance reaches 0. This is fragile and causes cross-business contamination. Additionally, a single payment slip can only reference one document — there is no way to record a lump-sum payment that covers multiple POs, expenses, or salaries in one transaction. This forces users to create multiple payment slips for a single bank transfer.

**Requirements:**

*Allocation tables:*
- A `payment_allocations` table MUST be created to record how each payment slip's amount is distributed across documents (POs, expenses, salaries, partner profit distributions)
- A `collection_allocations` table MUST be created to record how each collection slip's amount is distributed across sales invoices
- Each allocation row MUST store: `reference_type`, `reference_id`, `amount_fc`, `amount_bc`
- The sum of allocations for a slip MUST equal the slip's total amount (enforced at the service layer)
- The existing `reference_type` and `reference_id` columns on `payment_slips` and `collection_slips` MUST be removed — allocations are the single source of truth

*FIFO auto-allocation:*
- When creating a payment slip, the system MUST offer auto-allocation: distribute the amount across the payee's unpaid documents in FIFO order (oldest document date first)
- When creating a collection slip, the system MUST offer auto-allocation: distribute across the customer's unpaid invoices in FIFO order (oldest invoice_date first)
- The user MUST also be able to manually select which documents to allocate to and specify amounts per document (override FIFO)
- Partial allocation is allowed — a document can be partially covered by one slip and the remainder by a later slip

*Settlement status updates:*
- A sales invoice is PAID when the sum of `collection_allocations` referencing it >= `invoice.total_amount_bc`
- A purchase order is CLOSED when the sum of `payment_allocations` referencing it >= `po.total_amount_bc`
- An expense is considered paid when a `payment_allocations` row references it with the full amount
- A salary is considered paid when a `payment_allocations` row references it with the full amount
- The `SettlementEngine` MUST be rewritten to use allocation-based payment tracking
- The payee balance MUST remain as a convenience summary field but MUST NOT be used for settlement decisions

*UI requirements:*
- The payment slip creation form MUST show a list of the payee's outstanding documents with checkboxes and amount fields; default behavior is FIFO auto-fill
- The collection slip creation form MUST show the customer's outstanding invoices with the same pattern
- The payment slip detail view MUST show which documents were covered and how much was allocated to each
- The collection slip detail view MUST show which invoices were covered
- The payee statement page MUST show a ledger view: each document (invoice/PO/expense/salary) as a debit, each allocation as a credit, with running balance

#### REQ-022: Add database foreign key constraints

**Problem:** The database has no foreign key constraints. Data integrity relies entirely on application-level logic.

**Requirements:**
- Foreign key constraints MUST be added for all major relationships via a migration
- At minimum: payees → people, payees → businesses, sales_invoices → businesses, sales_invoices → payees, si_details → sales_invoices, purchase_orders → businesses, po_details → purchase_orders, user_roles → users, user_roles → roles
- All foreign keys MUST use `ON DELETE RESTRICT` to prevent accidental cascading deletes

#### REQ-023: Item price history UI

**Problem:** The `item_price_history` table is populated when prices change but there is no UI to view it.

**Requirements:**
- The Items list MUST include a "Price History" button per item
- The price history view MUST show: date changed, old cost price, new cost price, old sale price, new sale price, changed by (user name)
- The history MUST be accessible without leaving the items page (modal)

#### REQ-024: Goods return / credit note for sales

**Problem:** There is no way to process a goods return from a customer or issue a credit note against a delivered invoice.

**Requirements:**
- A credit note MUST be creatable against any DELIVERED or PAID sales invoice
- A credit note MUST reduce the customer's payee balance
- Credit notes MUST appear in the customer statement
- Credit notes MUST reverse the stock for returned items
- The credit note MUST reference the original invoice number

#### REQ-025: Goods return / debit note for purchases

**Problem:** There is no way to return goods to a supplier or issue a debit note against a received PO.

**Requirements:**
- A debit note MUST be creatable against any RECEIVED purchase order
- A debit note MUST reduce the supplier's payee balance
- Debit notes MUST appear in the supplier statement
- Debit notes MUST reverse the stock for returned items
- The debit note MUST reference the original PO number

---

### Phase 5 — Schema Cleanup (from ERD Analysis)

#### REQ-027: Drop deprecated and orphaned tables

**Problem:** Several tables are either empty and never used, or have been superseded by better designs: `business_module_overrides` (replaced by REQ-011), `user_notifications` (replaced by REQ-012), `notification_roles` (orphaned), `business_payees_types` (the old junction table — being replaced by a proper redesign in REQ-031), `item_cost_flags` (merged into items by REQ-028).

**Requirements:**
- `business_module_overrides` MUST be dropped after REQ-011 migration completes
- `user_notifications` MUST be dropped after REQ-012 migration moves read tracking to `notifications`
- `notification_roles` MUST be dropped (0 rows, no code references it)
- `business_payees_types` MUST be dropped and replaced by the `people_types` junction from REQ-031
- A single migration MUST handle all drops in dependency order with existence checks

#### REQ-028: Merge item_auto_markup and item_cost_flags into items

**Problem:** `item_auto_markup` is a one-to-one table with `items` — its two columns (`markup_percent`, `is_enabled`) belong on the `items` table directly. `item_cost_flags` duplicates data already in `item_price_history`; the only unique piece of state it holds (`is_dismissed`) is already mirrored by `items.has_cost_change_flag`.

**Requirements:**
- A migration MUST add `markup_percent DECIMAL(5,2) NULL` and `markup_enabled TINYINT(1) NOT NULL DEFAULT 0` to `items`, copying data from `item_auto_markup`
- `item_auto_markup` MUST be dropped after data migration
- `item_cost_flags` MUST be dropped — `items.has_cost_change_flag` is the authoritative dismissed state
- All PHP code referencing `item_auto_markup` or `item_cost_flags` MUST be updated to use `items` columns directly

#### REQ-029: Add missing created_by and audit columns

**Problem:** Several tables that record business-critical events are missing `created_by` (who performed the action), making audit trails incomplete. `item_price_history` is specifically called out in REQ-023 as needing "changed by" for the UI.

**Requirements:**
- `item_price_history` MUST add `created_by INT NULL FK→users` — populated on every price change
- `stock_transactions` MUST add `created_by INT NULL FK→users` — populated on every stock movement
- `batch_transactions` MUST add `created_by INT NULL FK→users`
- `partner_equity` MUST add `created_by INT NULL FK→users`
- `business_profit` MUST add `created_by INT NULL FK→users`
- `assets` MUST add `created_by INT NULL FK→users`
- `people` MUST add `updated_at DATETIME NULL`
- All existing rows MUST default to NULL for the new `created_by` columns

#### REQ-030: Fix data consistency issues

**Problem:** Several smaller inconsistencies reduce data quality and make queries unreliable.

**Requirements:**
- `supplier_item_prices.supplier_id` currently references `people.id` but MUST reference `payees.id` to be consistent with all other supplier references in the system; a migration MUST re-map existing rows
- `expenses` MUST add `reference_number VARCHAR(100) NULL` (vendor's invoice/receipt number), `reference_date DATE NULL` (date on vendor's document), and `attachment_path VARCHAR(500) NULL` (uploaded scan/PDF path) for document traceability
- `batch_transactions.transaction_type` ENUM values use lowercase (`in`, `out`, `write_off`) while `stock_transactions.type` uses uppercase (`IN`, `OUT`, `ADJ`); MUST be standardized to uppercase (`IN`, `OUT`, `WRITE_OFF`)
- `stock_transactions` MUST add `reference_type ENUM('PURCHASE_ORDER','SALES_INVOICE','PRODUCTION','MANUAL','CREDIT_NOTE','DEBIT_NOTE') NULL` and `reference_id INT NULL` to replace the loose `reference VARCHAR(100)` text field; existing `reference` column data MUST be migrated where possible, then the column dropped
- `users.password_reset` INT column MUST be dropped (replaced by `password_reset_tokens` table from REQ-017)
- `recurrence_schedules.entity_type` and `recurrence_instances.entity_type` ENUM values use lowercase (`expense_category`, `salary`); MUST be standardized to uppercase (`EXPENSE`, `SALARY`)
- `recurrence_instances` columns `is_advance_payment`, `bulk_advance_payment_id`, and `metadata` are unused and MUST be dropped
- `tbl_translations` MUST be renamed to `translations`
- `business_accounts` MUST add `account_type ENUM('BANK','CASH','CREDIT','OTHER') NOT NULL DEFAULT 'CASH'`
- `notifications` MUST add `recipient_id INT NOT NULL FK→users` and `notification_preferences.type` ENUM MUST be replaced with `notification_type_id FK→notification_types`
- NOTE: `asset_categories` creation and `assets.category` migration are handled by REQ-031 as part of the unified system-types pattern

#### REQ-034: Add user profile page and remove module override page

**Problem:** The `#/profile` route exists in the router but points to a 404 page — users cannot change their own password or view their account details. Additionally, the `#/settings/modules` page (module override UI) is obsolete after REQ-011 replaces per-business overrides with tenant-owned business types. Module management now lives on the Tenant → Business Types page, not in per-business Settings.

**Requirements:**
- A user profile page MUST be created at `#/profile` showing: username (read-only), first name, last name, email, and a "Change Password" section
- The Change Password section MUST require: current password, new password, confirm new password
- The backend MUST verify the current password before allowing the change
- The backend MUST reject weak passwords (minimum 8 characters)
- The `#/settings/modules` route MUST be removed from the router
- The Settings → Modules link MUST be removed from the sidebar/navigation
- The `public/assets/js/pages/Settings/modules.js` and `public/html/Settings/modules.html` files MUST be deleted
- The `ModulesController` endpoint for per-business module override (`POST /settings/modules/override`) MUST be removed
- The `ModuleService.fetchActiveModules()` frontend service MUST remain — it still reads active modules for the current business (now resolved via business type), but it should call a read-only endpoint

#### REQ-031: System-defined types with module visibility gating

**Problem:** All classification tables in the system (`item_types`, `payee_types`, `expense_categories`, `asset_categories`, `service_categories`) either have no predefined values forcing users to start from scratch, or use free-text fields with no consistency. Additionally, there is no link between these types/categories and the modules that use them — a business without the Manufacturing module still sees "Manufactured" as an item type, and a business without Assets still sees asset categories. Professional ERPs (SAP B1, Odoo, ERPNext) all ship with immutable system-defined types that carry semantic meaning used by application logic, plus the ability for users to add their own.

**Requirements:**

**Schema pattern (applied to all classification tables):**
- Every classification table MUST have: `is_system TINYINT(1) NOT NULL DEFAULT 0`, `module_code VARCHAR(50) NULL FK→modules.code`, `tenant_id INT NULL FK→tenants`, `code VARCHAR(50) NULL` (set for system types, NULL for user-defined)
- System types (`is_system = 1`, `tenant_id = NULL`) MUST be immutable — no user can edit or delete them
- Tenant-defined types (`is_system = 0`, `tenant_id = X`) are scoped to the tenant and freely editable
- Business-scoped types (`is_system = 0`, `business_id = X`) are scoped to a single business
- When fetching available types for a business, the query MUST filter by active modules: system types with `module_code` set are only returned if that module is active for the business; system types with `module_code = NULL` are always returned
- The UI for all type/category selectors MUST only show types relevant to the business's active modules

**Item Types — system seed data:**

| code | name | module_code |
|------|------|-------------|
| `TRADING` | Trading Good | NULL |
| `CONSUMABLE` | Consumable | NULL |
| `FINISHED_GOOD` | Finished Good | NULL |
| `RAW_MATERIAL` | Raw Material | `manufacturing` |
| `MANUFACTURED` | Manufactured | `manufacturing` |
| `SERVICE_ITEM` | Service Item | `services` |
| `ASSET_ITEM` | Asset Item | `assets` |

- The manufacturing module MUST validate that BOM components use `RAW_MATERIAL` or `CONSUMABLE` types and BOM outputs use `MANUFACTURED` or `FINISHED_GOOD` types
- The existing 3 rows in `item_types` MUST be mapped to the nearest system type or migrated

**Payee/Contact Types — redesign `payee_types` and `business_payees_types`:**
- `payee_types` MUST be redesigned with the system-types pattern (add `is_system`, `module_code`, `code` columns)
- `business_payees_types` MUST be renamed to `people_types` (junction: `people_id` ↔ `payee_type_id`) to correctly represent that a person can have multiple roles
- System seed data:

| code | name | module_code |
|------|------|-------------|
| `CUSTOMER` | Customer | NULL |
| `SUPPLIER` | Supplier | NULL |
| `EMPLOYEE` | Employee | NULL |
| `PARTNER` | Partner | `partners` |
| `OTHER` | Other | NULL |

- The invoice customer selector MUST only show people with type `CUSTOMER`
- The PO supplier selector MUST only show people with type `SUPPLIER`
- The salary payee selector MUST only show people with type `EMPLOYEE`
- The partner shares selector MUST only show people with type `PARTNER`
- A person MAY have multiple types (e.g., someone who is both a supplier and a partner)

**Expense Categories — system seed data:**

| code | name | module_code |
|------|------|-------------|
| `RENT` | Rent | NULL |
| `UTILITIES` | Utilities | NULL |
| `MARKETING` | Marketing | NULL |
| `MAINTENANCE` | Maintenance | NULL |
| `TRANSPORT` | Transport | NULL |
| `OTHER` | Other | NULL |

**Asset Categories — system seed data (replaces free-text `assets.category`):**

| code | name | module_code |
|------|------|-------------|
| `LAND_BUILDINGS` | Land & Buildings | `assets` |
| `EQUIPMENT` | Equipment & Machinery | `assets` |
| `VEHICLES` | Vehicles | `assets` |
| `FURNITURE` | Furniture & Fixtures | `assets` |
| `IT_EQUIPMENT` | IT Equipment | `assets` |
| `INTANGIBLE` | Intangible Assets | `assets` |
| `INVENTORY_ASSET` | Inventory | `assets` |

**Service Categories — system seed data:**

| code | name | module_code |
|------|------|-------------|
| `CONSULTING` | Consulting | `services` |
| `MAINTENANCE` | Maintenance & Repair | `services` |
| `DELIVERY` | Delivery & Logistics | `services` |
| `INSTALLATION` | Installation | `services` |
| `OTHER` | Other | `services` |

**API requirements:**
- `GET /item-types`, `GET /payee-types`, `GET /expense-categories`, `GET /asset-categories`, `GET /service-categories` MUST all accept a `?business_id=` param and filter by active modules automatically
- POST/PUT/DELETE on any type MUST reject requests targeting system types (`is_system = 1`) with HTTP 403
- Each module MUST have a management UI section for its user-defined types/categories

#### REQ-032: Fix partner equity and profit distribution architecture

**Problem:** The partner/profit system has several architectural issues:
1. `business_profit` table schema doesn't match the code — the table has `profit_bc`, `safety_net_type`, `safety_net_bc` but the code writes `total_revenue`, `total_expenses`, `net_profit`, `safety_net_percent`, `status`. The table is missing a `status` column entirely.
2. Revenue calculation uses collection slip sums (cash-basis) instead of PAID invoice totals (accrual-basis). This means revenue is recognized when cash arrives, not when the sale is earned — incorrect for a proper P&L.
3. Expense calculation sums both `expenses` and `payment_slips` (excluding PARTNER_PROFIT), which double-counts expenses that have been paid (the expense creates the obligation, the payment settles it — summing both counts it twice).
4. Partner equity contributions/withdrawals don't create collection/payment slips — the `invested_capital` changes but no cash movement is recorded in any account.
5. There is no `profit_distributions` table — when profit is distributed, individual partner amounts are only stored as payment slips. There's no permanent record of "Period X: Partner A got $Y at Z% ownership."
6. The `partner_equity.reference_id` is ambiguous — it points to nothing specific.

**Requirements:**

*Schema fixes:*
- `business_profit` MUST be redesigned with: `id` (auto-increment PK replacing composite PK), `total_revenue`, `total_cogs`, `gross_profit`, `total_expenses`, `net_profit`, `safety_net_percent`, `safety_net_amount`, `distributable_profit`, `status ENUM('DRAFT','FINALIZED','DISTRIBUTED')`, `created_by`, `created_at`, `updated_at`
- A `profit_distributions` table MUST be created to permanently record per-partner payouts: `business_profit_id`, `partner_id`, `share_percent` (snapshot at distribution time), `amount_bc`, `payment_slip_id` (FK to the actual cash outflow)
- `partner_equity` MUST replace `reference_type`/`reference_id` with `slip_type ENUM('COLLECTION_SLIP','PAYMENT_SLIP')` and `slip_id INT` — every equity entry MUST link to the actual cash movement

*Revenue calculation (accrual basis):*
- Revenue MUST be calculated as the sum of `sales_invoices.total_amount_bc` WHERE `status = 'PAID'` AND `invoice_date` is within the period — NOT collection slip sums
- COGS MUST be calculated as the sum of cost prices for items sold on PAID invoices in the period (from `si_details` joined with `items.cost_price`)
- Gross profit = revenue - COGS

*Expense calculation (no double-counting):*
- Total expenses MUST be the sum of `expenses.amount_bc` WHERE `expense_date` is within the period
- Salary expenses MUST be the sum of `salaries.net_amount_bc` WHERE `period_start` is within the period
- Payment slips MUST NOT be included in expense calculation — they are settlement of obligations, not new expenses

*Equity → slip linkage:*
- When a partner contributes capital, the system MUST create a collection slip (money IN to a business account) AND an equity record with `slip_type = 'COLLECTION_SLIP'`, `slip_id = <collection_slip.id>`
- When a partner withdraws capital, the system MUST create a payment slip (money OUT from a business account) AND an equity record with `slip_type = 'PAYMENT_SLIP'`, `slip_id = <payment_slip.id>`
- The collection/payment slip creation MUST use the allocation system (REQ-021) with `reference_type = 'PARTNER_CONTRIBUTION'` for collections

*Profit distribution flow:*
- Distribution MUST create one `profit_distributions` row per partner with a snapshot of their `ownership_pct` at that moment
- Distribution MUST create one payment slip per partner (using the allocation system with `reference_type = 'PARTNER_PROFIT'`)
- The `profit_distributions.payment_slip_id` MUST reference the created payment slip
- The profit detail view MUST show the full distribution breakdown with partner names, percentages, amounts, and payment slip references

*UI requirements:*
- The Business Profit page MUST show a clear workflow: Create (DRAFT) → Finalize → Distribute
- The DRAFT view MUST show the calculated P&L breakdown (revenue, COGS, gross profit, expenses, net profit, safety net, distributable)
- The DISTRIBUTED view MUST show the distribution table with partner names, ownership %, amount, and a link to each payment slip
- The Partner Equity page MUST show a ledger per partner: contributions as credits, withdrawals as debits, with links to the corresponding collection/payment slips
- The Partner Shares page MUST show current ownership with invested capital and a "Contribute" / "Withdraw" action per partner that opens a form creating both the slip and the equity record atomically

---

### Phase 6 — Deployment

#### REQ-033: Generate deployment database creation script

**Problem:** The database currently exists only as a series of incremental migrations applied over time. There is no single script that creates the complete, clean end-state schema from scratch. This makes fresh deployments impossible without replaying all migrations in order, and makes it difficult to verify the database matches the target ERD.

**Requirements:**
- A `database/schema.sql` file MUST exist that creates the entire database schema from scratch in a single run
- The script MUST create all tables in FK-dependency order with all columns in their final target state
- The script MUST include all PRIMARY KEY, UNIQUE, and INDEX definitions
- The script MUST include all FOREIGN KEY constraints with correct ON DELETE/ON UPDATE behavior
- The script MUST include all CHECK constraints and ENUM definitions with their final values
- The script MUST use `CREATE TABLE IF NOT EXISTS` for idempotency
- A separate `database/seed_system_data.sql` file MUST insert all system/seed data (system types, modules, permissions, languages, notification types) using `INSERT IGNORE` for idempotency
- A `database/README.md` MUST document the deployment process: create database → run schema → run seed → run first-installation flow
- The final schema MUST match the target ERD (`docs/erd-target-state.md`) exactly — same tables, same columns, same types, same constraints
- The application MUST start successfully against a fresh database with only seed data (no tenant exists → first-installation flow activates)
- The `database/migrations/` folder MUST remain for incremental upgrades of existing deployments, but `schema.sql` is the authoritative source for new deployments

## Glossary

| Term | Definition |
|------|-----------|
| PAID status | A sales invoice status indicating the customer has fully paid the invoice amount |
| Settlement | The process of marking a document (invoice or PO) as paid based on linked payment/collection slips |
| Payee balance | A running ledger balance on the `payees` table representing net amount owed between a business and a contact |
| FC | Foreign Currency — the currency of the transaction |
| BC | Base Currency — the business's home currency |
| ENUM | MySQL column type that restricts values to a predefined list |
| Credit note | A document issued to a customer reducing the amount they owe, typically for returned goods |
| Debit note | A document issued to a supplier reducing the amount owed to them, typically for returned goods |
| AR Aging | Accounts Receivable Aging — a report showing outstanding customer invoices grouped by how overdue they are |
| BOM | Bill of Materials — a recipe defining the components needed to manufacture an item |
| DOMPDF | A PHP library for generating PDFs from HTML |
| System business type | A predefined, immutable business type (`is_system = 1`, `tenant_id = NULL`) that serves as a template only |
| Tenant business type | A tenant-owned copy of a system type (`is_system = 0`, `tenant_id = X`) that can be freely modified and assigned to businesses |
| Tenant owner | A user holding the `TENANT_OWNER` role for a tenant, granting full cross-business authority within that tenant |
| System type | Any classification record (`is_system = 1`) that is predefined, immutable, and carries semantic meaning used by application logic |
| Module-gated type | A system type with a `module_code` set — only visible to businesses that have that module active |
| People type | A classification tag on a person (Customer, Supplier, Employee, Partner) — a person can hold multiple types simultaneously |
| Accrual-basis revenue | Revenue recognized when the sale is earned (invoice PAID), not when cash is received (collection slip created) |
| Safety net | A percentage of net profit retained in the business for operational continuity, not distributed to partners |
| Profit distribution | The act of splitting distributable profit among partners proportional to their ownership percentages |
| Invested capital | The cumulative sum of a partner's contributions minus withdrawals — represents their capital stake in the business |
