# ERD — Current State (As-Is)

> Generated from live schema inspection — May 2026.
> **NOTE:** This document captures the database state BEFORE the erp-polish-and-fixes spec was executed.
> For the target (post-spec) schema, see `erd-target-state.md` and `erd-target-mermaid.md`.
> Tables marked ⚠️ have identified problems. Tables marked ❌ are deprecated/redundant.

## Legend
- `PK` Primary Key | `FK` Foreign Key | `UQ` Unique | `NN` Not Null

---

## 1. Multi-Tenancy & Auth

```
tenants
├── id PK
├── name NN
├── description
├── base_currency NN DEFAULT 'USD'
└── created_at NN

users
├── id PK
├── username UQ NN
├── password_hash NN
├── password_reset INT DEFAULT 1   ⚠️ (flag, not a token — no expiry, no security)
├── first_name NN
├── last_name NN
├── email UQ NN
├── failed_attempts DEFAULT 0
├── lock_until
├── created_at NN
└── updated_at
  MISSING: is_active (REQ-007), tenant_id (users are not scoped to a tenant)

refresh_tokens
├── id PK
├── user_id FK→users
├── device_id CHAR(36)
├── token_hash CHAR(64)
├── expires_at NN
├── last_used_at
├── revoked_at
└── created_at

roles
├── id PK
├── name NN
├── description
├── type ENUM(SYSTEM_OWNER, CUSTOM, TEMPLATE, TENANT_OWNER) NN
├── business_id FK→businesses (NULL for system/tenant roles)
├── created_at NN
└── updated_at
  CHECK: type IN (SYSTEM_OWNER,CUSTOM) → business_id NOT NULL
  CHECK: type IN (TEMPLATE,TENANT_OWNER) → business_id IS NULL

permissions
├── id PK
├── name NN
├── code UQ NN
└── created_at NN

role_permissions (junction)
├── role_id FK→roles PK
└── permission_id FK→permissions PK

user_roles
├── id PK
├── user_id FK→users NN
├── role_id FK→roles NN
├── business_id FK→businesses (NULL for tenant-owner assignments)
├── tenant_id FK→tenants NN
└── is_active DEFAULT 1
  UQ: (user_id, role_id, business_id, tenant_id)
  ⚠️ BUG: no guard preventing tenant owners from also holding business roles
```

---

## 2. Business & Module Configuration

```
businesses
├── id PK
├── tenant_id FK→tenants NN
├── business_type_id FK→business_types NN DEFAULT 1
├── name NN
├── legal_name
├── registration_number
├── address
├── phone
├── email
├── currency NN
├── created_at NN
└── updated_at
  MISSING: is_active (REQ-010)
  ⚠️ business_type_id currently allows pointing to system types directly

business_types
├── id PK
├── tenant_id FK→tenants (NULL = system type)
├── code NN
├── name NN
├── description
├── is_system TINYINT DEFAULT 0
├── is_default TINYINT DEFAULT 0
└── created_at NN
  UQ: (code) — GLOBAL, needs to be (code, tenant_id) per REQ-011
  ⚠️ System types (tenant_id=NULL, is_system=1) are directly assigned to businesses

business_type_modules (junction)
├── id PK
├── business_type_id FK→business_types NN
└── module_id FK→modules NN
  UQ: (business_type_id, module_id)

modules
├── id PK
├── code UQ NN
├── name NN
├── icon
├── route_prefix NN
├── permission_prefixes JSON NN
├── category ENUM(core,operations,finance,other) NN
├── sort_order INT DEFAULT 0
└── created_at NN

business_module_overrides ❌ DEPRECATED
├── id PK
├── business_id FK→businesses NN
├── module_id FK→modules NN
├── action ENUM(activate,deactivate) NN
├── created_at NN
└── created_by FK→users NN
  0 rows. Replaced by tenant-owned business types (REQ-011).
  TO BE DROPPED.
```

---

## 3. People & Payees

```
people
├── id PK
├── tenant_id FK→tenants NN
├── name NN                        ⚠️ single name field (no first/last split)
├── phone NN
├── normalized_phone
├── email
├── address
├── tax_number
├── iban
├── created_at NN
└── created_by FK→users
  UQ: (tenant_id, normalized_phone)
  MISSING: updated_at, contact_type classification

payees
├── id PK
├── business_id FK→businesses NN
├── people_id FK→people NN
├── balance DECIMAL(12,2) DEFAULT 0
├── notes
├── created_at NN
└── updated_at
  UQ: (business_id, people_id)
  NOTE: balance is a convenience summary — NOT used for settlement decisions (REQ-021)

payee_types ⚠️ PARTIALLY REDUNDANT
├── id PK
├── tenant_id FK→tenants NN
├── business_id FK→businesses (NULL = tenant-wide)
├── name NN
├── created_at NN
└── updated_at
  3 rows: Customer, Supplier, Employee, Partner
  These are effectively hardcoded roles. The classification of a person
  as customer/supplier/employee/partner is implicit from which documents
  reference them (invoices→customer, POs→supplier, salaries→employee).
  The separate table adds complexity without value.

business_payees_types ❌ ORPHANED JUNCTION
├── payee_id FK→payees PK
└── payee_type_id FK→payee_types PK
  0 rows. Never populated. The payee→type relationship is never used in queries.
  TO BE DROPPED along with payee_types.
```

---

## 4. Inventory & Items

```
items
├── id PK
├── tenant_id FK→tenants NN
├── business_id FK→businesses (NULL = tenant-wide item)
├── customer_id FK→people (NULL = standard item)
├── item_type_id FK→item_types NN
├── name NN
├── sku UQ per tenant
├── unit
├── cost_price DECIMAL(12,2)
├── sale_price DECIMAL(12,2)
├── min_stock DECIMAL(12,2)
├── has_expiry TINYINT DEFAULT 0
├── is_active TINYINT DEFAULT 1
├── has_cost_change_flag TINYINT DEFAULT 0   ⚠️ denormalized flag, duplicates item_cost_flags
├── notes
├── created_at NN
├── updated_at
└── created_by FK→users

item_types
├── id PK
├── tenant_id FK→tenants NN
├── business_id FK→businesses (NULL = tenant-wide)
├── name NN
├── created_at NN
├── updated_at
└── created_by FK→users

stock
├── id PK
├── business_id FK→businesses NN
├── item_id FK→items NN
├── quantity DECIMAL(12,3) DEFAULT 0
└── updated_at NN
  UQ: (business_id, item_id)

stock_transactions
├── id PK
├── business_id FK→businesses NN
├── item_id FK→items NN
├── trx_date DATETIME NN
├── type ENUM(IN, OUT, ADJ) NN
├── reference VARCHAR(100)
├── quantity DECIMAL(12,3) NN
├── notes
└── created_at NN
  ⚠️ No created_by — cannot audit who made the adjustment
  ⚠️ No reference_type/reference_id FK — loose text reference only

item_price_history
├── id PK
├── item_id FK→items NN
├── tenant_id FK→tenants NN
├── price_type ENUM(COST, SALE) NN
├── old_price DECIMAL(15,4)
├── new_price DECIMAL(15,4) NN
├── effective_date DATE NN
├── source_type ENUM(INITIAL,PURCHASE_ORDER,MANUAL_EDIT,MANUFACTURING) NN
├── source_id INT
└── created_at NN
  ⚠️ No created_by — cannot show who changed the price (REQ-023 requires this)

item_cost_flags
├── id PK
├── item_id FK→items UQ NN
├── old_cost_price DECIMAL(12,4) NN
├── new_cost_price DECIMAL(12,4) NN
├── is_dismissed TINYINT DEFAULT 0
├── created_at NN
└── dismissed_at
  ⚠️ Duplicates data already in item_price_history. The flag state
  (is_dismissed) could live on items.has_cost_change_flag.
  Candidate for consolidation.

item_auto_markup
├── id PK
├── item_id FK→items UQ NN
├── markup_percent DECIMAL(5,2) NN
├── is_enabled TINYINT DEFAULT 1
├── created_at NN
└── updated_at
  ⚠️ One-to-one with items. Could be columns on items table.

item_batches
├── id PK
├── item_id FK→items NN
├── business_id FK→businesses NN
├── batch_number NN
├── quantity DECIMAL(15,4) DEFAULT 0
├── manufacturing_date DATE
├── expiry_date DATE NN
├── purchase_order_id FK→purchase_orders
├── status ENUM(active,expired,written_off) NN
├── created_at NN
└── updated_at
  UQ: (item_id, business_id, batch_number)

batch_transactions
├── id PK
├── batch_id FK→item_batches NN
├── transaction_type ENUM(in,out,write_off) NN
├── quantity DECIMAL(15,4) NN
├── reference VARCHAR(255)
├── notes
└── created_at NN
  ⚠️ Parallel to stock_transactions but batch-level. No created_by.
  ⚠️ Inconsistent naming: stock_transactions uses type IN/OUT/ADJ,
  batch_transactions uses in/out/write_off (mixed case).

supplier_item_prices
├── id PK
├── supplier_id FK→people NN
├── item_id FK→items NN
├── tenant_id FK→tenants NN
├── last_price DECIMAL(15,4) NN
├── avg_price DECIMAL(15,4) NN
├── min_price DECIMAL(15,4) NN
├── max_price DECIMAL(15,4) NN
├── last_purchase_date DATE NN
├── purchase_count INT DEFAULT 1
├── is_preferred TINYINT DEFAULT 0
├── created_at NN
└── updated_at NN
  UQ: (supplier_id, item_id, tenant_id)
  NOTE: supplier_id references people.id, not payees.id — inconsistent
```

---

## 5. Sales

```
sales_invoices
├── id PK
├── business_id FK→businesses NN
├── customer_id FK→payees NN
├── invoice_number NN
├── invoice_date DATE NN
├── status ENUM(DRAFT,DELIVERED,PAID,CANCELLED) NN DEFAULT DRAFT
├── currency NN
├── exchange_rate DECIMAL(12,6) NN
├── total_amount_fc DECIMAL(12,2) DEFAULT 0
├── total_amount_bc DECIMAL(12,2) DEFAULT 0
├── notes
├── created_at NN
├── updated_at
└── created_by FK→users
  MISSING: due_date (REQ-015), tax_total_fc/bc (REQ-014), discount_percent (REQ-019)

si_details
├── id PK
├── sales_invoice_id FK→sales_invoices NN
├── item_id FK→items (NULL if service)
├── service_id FK→services (NULL if item)
├── quantity DECIMAL(12,3) NN
├── unit_price_fc DECIMAL(12,2) NN
├── unit_price_bc DECIMAL(12,2) NN
├── total_price_fc DECIMAL(12,2) NN
├── total_price_bc DECIMAL(12,2) NN
└── created_at NN
  CHECK: exactly one of item_id or service_id must be set
  MISSING: tax_rate, tax_amount_fc/bc (REQ-014), discount_percent (REQ-019)

collection_slips
├── id PK
├── business_id FK→businesses NN
├── account_id FK→business_accounts NN
├── payee_id FK→payees NN
├── reference_type ENUM(CUSTOMER,PARTNER_CONTRIBUTION,SALES_INVOICE) NN
├── reference_id INT
├── slip_number NN
├── slip_date DATE NN
├── currency NN
├── exchange_rate DECIMAL(12,6) NN
├── amount_fc DECIMAL(12,2) NN
├── amount_bc DECIMAL(12,2) NN
├── method ENUM(CASH,BANK,CHECK,TRANSFER) NN
├── notes
├── created_at NN
├── updated_at
└── created_by FK→users
```

---

## 6. Purchasing

```
purchase_orders
├── id PK
├── business_id FK→businesses NN
├── supplier_id FK→payees NN
├── po_number NN
├── po_date DATE NN
├── status ENUM(DRAFT,APPROVED,RECEIVED,CLOSED,CANCELLED) NN DEFAULT DRAFT
├── currency NN
├── exchange_rate DECIMAL(12,6) NN
├── total_amount_fc DECIMAL(12,2) DEFAULT 0
├── total_amount_bc DECIMAL(12,2) DEFAULT 0
├── notes
├── created_at NN
├── updated_at
└── created_by FK→users
  MISSING: tax_total_fc/bc (REQ-014), discount_percent (REQ-019)

po_details
├── id PK
├── purchase_order_id FK→purchase_orders NN
├── item_id FK→items NN
├── quantity DECIMAL(12,3) NN
├── unit_price_fc DECIMAL(12,2) NN
├── unit_price_bc DECIMAL(12,2) NN
├── total_price_fc DECIMAL(12,2) NN
├── total_price_bc DECIMAL(12,2) NN
└── created_at NN
  MISSING: tax_rate, tax_amount_fc/bc (REQ-014), discount_percent (REQ-019)

payment_slips
├── id PK
├── business_id FK→businesses NN
├── account_id FK→business_accounts NN
├── payee_id FK→payees NN
├── reference_type ENUM(EXPENSE,SALARY,PARTNER_PROFIT,PURCHASE_ORDER) NN
├── reference_id INT NN
├── payment_number NN
├── payment_date DATE NN
├── currency NN
├── exchange_rate DECIMAL(12,6) NN DEFAULT 1
├── amount_fc DECIMAL(12,2) NN
├── amount_bc DECIMAL(12,2) NN
├── method ENUM(CASH,BANK,CHECK,TRANSFER) NN
├── notes
├── created_at NN
├── updated_at
└── created_by FK→users
```

---

## 7. Finance

```
business_accounts
├── id PK
├── business_id FK→businesses NN
├── name NN
├── currency NN
├── balance DECIMAL(18,2) DEFAULT 0
├── balance_fc DECIMAL(15,2) DEFAULT 0
├── created_at NN
└── updated_at
  ⚠️ balance is a running total — no account_type (bank/cash/credit)

expenses
├── id PK
├── business_id FK→businesses NN
├── payee_id FK→payees NN
├── expense_category_id FK→expense_categories NN
├── expense_date DATE NN
├── currency NN
├── exchange_rate DECIMAL(12,6) NN
├── amount_fc DECIMAL(12,2) NN
├── amount_bc DECIMAL(12,2) NN
├── notes
├── created_at NN
└── updated_at

expense_categories
├── id PK
├── tenant_id FK→tenants NN
├── business_id FK→businesses (NULL = tenant-wide)
├── name NN
├── is_fixed TINYINT DEFAULT 0
├── created_at NN
├── updated_at
└── created_by FK→users

salaries
├── id PK
├── business_id FK→businesses NN
├── payee_id FK→payees NN
├── period_start DATE NN
├── period_end DATE NN
├── currency NN
├── exchange_rate DECIMAL(12,6) NN
├── gross_amount_fc DECIMAL(12,2) NN
├── gross_amount_bc DECIMAL(12,2) NN
├── tax_fc DECIMAL(12,2) DEFAULT 0
├── tax_bc DECIMAL(12,2) DEFAULT 0
├── net_amount_fc DECIMAL(12,2) NN
├── net_amount_bc DECIMAL(12,2) NN
├── notes
├── created_at NN
└── updated_at

exchange_rates
├── id PK
├── tenant_id FK→tenants NN
├── business_id FK→businesses (NULL = tenant-wide rate)
├── currency NN
├── rate_date DATE NN
├── rate DECIMAL(12,6) NN
├── source VARCHAR(100)
└── created_at NN

business_profit
├── business_id FK→businesses PK (composite)
├── period_start DATE PK (composite)
├── period_end DATE
├── profit_bc DECIMAL(18,2) NN
├── safety_net_type ENUM(FIXED,PERCENTAGE,MIN_BALANCE) NN
├── safety_net_bc DECIMAL(18,2) NN
├── distributable_profit_bc DECIMAL(18,2) NN
└── created_at NN
  ⚠️ No created_by. No updated_at. Immutable once created?
```

---

## 8. Partners

```
partner_shares
├── id PK
├── business_id FK→businesses NN
├── partner_id FK→payees NN
├── ownership_pct DECIMAL(5,2) NN
├── invested_capital DECIMAL(12,2) DEFAULT 0
├── created_at NN
└── updated_at

partner_equity
├── id PK
├── partner_id FK→payees NN
├── business_id FK→businesses NN
├── equity_type ENUM(CONTRIBUTION,WITHDRAWAL) NN
├── amount DECIMAL(12,2) NN
├── reference_id INT
└── created_at NN
  ⚠️ No reference_type — reference_id is ambiguous (what does it point to?)
  ⚠️ No created_by
```

---

## 9. Manufacturing

```
recipes
├── id PK
├── tenant_id FK→tenants NN
├── business_id FK→businesses NN
├── manufactured_item_id FK→items NN
├── yield_quantity INT NN DEFAULT 1   ⚠️ should be DECIMAL(12,4) (REQ-008)
├── notes
├── is_active TINYINT DEFAULT 1
├── created_by FK→users NN
├── created_at NN
└── updated_at

recipe_items
├── id PK
├── recipe_id FK→recipes NN
├── component_item_id FK→items NN
└── quantity DECIMAL(12,4) NN
  UQ: (recipe_id, component_item_id)

production_orders
├── id PK
├── tenant_id FK→tenants NN
├── business_id FK→businesses NN
├── recipe_id FK→recipes NN
├── manufactured_item_id FK→items NN
├── production_quantity DECIMAL(12,4) NN
├── manufacturing_cost DECIMAL(12,4) NN
├── unit_manufacturing_cost DECIMAL(12,4) NN
├── status ENUM(COMPLETED,REVERSED) NN DEFAULT COMPLETED
├── production_date DATETIME NN
├── created_by FK→users NN
└── created_at NN

production_order_items
├── id PK
├── production_order_id FK→production_orders NN
├── component_item_id FK→items NN
├── quantity_consumed DECIMAL(12,4) NN
└── unit_cost DECIMAL(12,4) NN
```

---

## 10. Services

```
services
├── id PK
├── tenant_id FK→tenants NN
├── business_id FK→businesses NN
├── category_id FK→service_categories
├── name NN
├── description
├── unit NN
├── rate DECIMAL(15,2) DEFAULT 0
├── is_active TINYINT DEFAULT 1
├── created_by FK→users NN
└── created_at NN
  MISSING: updated_at (REQ-009)

service_categories
├── id PK
├── tenant_id FK→tenants NN
├── business_id FK→businesses NN
├── name NN
├── created_by FK→users NN
└── created_at NN
  UQ: (tenant_id, business_id, name)
```

---

## 11. Recurrences

```
recurrence_schedules
├── id PK (UNSIGNED)
├── entity_type ENUM(expense_category,salary) NN
├── entity_id INT NN
├── business_id FK→businesses NN
├── frequency ENUM(weekly,bi-weekly,monthly,quarterly,yearly) NN
├── default_amount DECIMAL(15,2) NN
├── default_currency VARCHAR(3) NN
├── start_date DATE NN
├── end_date DATE
├── is_active TINYINT DEFAULT 1
├── created_at NN
└── updated_at NN
  ⚠️ entity_id is a loose FK — no FK constraint, points to either
  expense_categories.id or salaries.id depending on entity_type

recurrence_instances
├── id PK (UNSIGNED)
├── recurrence_schedule_id FK→recurrence_schedules NN
├── entity_type ENUM(expense_category,salary) NN
├── business_id FK→businesses NN
├── due_date DATE NN
├── scheduled_amount DECIMAL(15,2) NN
├── actual_amount DECIMAL(15,2)
├── currency VARCHAR(3) NN
├── status ENUM(pending,paid,skipped,overdue) NN DEFAULT pending
├── paid_date DATE
├── notification_sent TINYINT DEFAULT 0
├── is_advance_payment TINYINT DEFAULT 0
├── bulk_advance_payment_id INT UNSIGNED
├── payment_record_id INT
├── period_start DATE
├── period_end DATE
├── metadata JSON
├── created_at NN
└── updated_at NN
  UQ: (recurrence_schedule_id, due_date)
  ⚠️ payment_record_id is a loose FK — no constraint
  ⚠️ bulk_advance_payment_id references nothing — orphaned concept
```

---

## 12. Notifications

```
notification_types
├── id PK
├── code UQ NN
└── description NN
  0 rows — never seeded (REQ-012)

notifications
├── id PK
├── business_id FK→businesses
├── notification_type FK→notification_types NN
├── title NN
├── message NN
├── type ENUM(INFO,WARNING,ALERT) NN DEFAULT INFO
├── reference_table VARCHAR(50)
├── reference_id INT
└── created_at NN
  ⚠️ No recipient_id — notifications are business-level, not user-level
  ⚠️ No is_read / read_at — read state is in user_notifications

user_notifications ❌ REDUNDANT
├── id PK
├── notification_id FK→notifications NN
├── user_id FK→users NN
├── is_read TINYINT DEFAULT 0
├── read_at DATETIME
└── created_at NN
  0 rows. Read tracking should be on notifications directly (REQ-012).
  TO BE DROPPED after adding recipient_id, is_read, read_at to notifications.

notification_preferences
├── id PK
├── user_id FK→users NN
├── business_id FK→businesses NN
├── type ENUM(PO_STATUS_CHANGE,INVOICE_DUE,STOCK_LOW,PAYMENT_RECEIVED,
│         USER_MESSAGE,SYSTEM_ALERT) NN
├── enabled TINYINT DEFAULT 1
└── updated_at NN
  UQ: (user_id, business_id, type)
  ⚠️ type ENUM is hardcoded here AND in notification_types — two sources of truth
  Should reference notification_types.id instead of duplicating the ENUM

notification_roles ❌ ORPHANED
├── id PK
├── notification_type FK→notification_types NN
└── role_id FK→roles NN
  0 rows. Purpose unclear — role-based notification routing was never implemented.
  TO BE DROPPED.
```

---

## 13. Assets

```
assets
├── id PK
├── business_id FK→businesses NN
├── name NN
├── category VARCHAR(50) NN   ⚠️ free text — no FK to a categories table
├── asset_type ENUM(FIXED,CURRENT,INTANGIBLE) NN
├── purchase_date DATE NN
├── purchase_value DECIMAL(12,2) NN
├── currency NN
├── exchange_rate DECIMAL(12,6) DEFAULT 1
├── value_bc DECIMAL(12,2) NN
├── depreciation_method ENUM(STRAIGHT_LINE,DECLINING_BALANCE) DEFAULT STRAIGHT_LINE
├── useful_life INT
├── accumulated_depreciation DECIMAL(12,2) DEFAULT 0
├── current_value_bc DECIMAL(12,2)
├── notes
├── created_at NN
└── updated_at
  ⚠️ No created_by
  ⚠️ category is free text — inconsistent categorization across businesses
```

---

## 14. Settings & i18n

```
settings_regional
├── business_id FK→businesses PK
├── language FK→languages NN
├── timezone NN
├── date_format NN
├── number_format NN
├── currency_format NN
├── phone_format NN
└── audit_enabled TINYINT DEFAULT 1

languages
├── code PK
├── name
└── direction ENUM(LTR,RTL) DEFAULT LTR

tbl_translations
├── lang FK→languages PK (composite)
├── key PK (composite)
└── value TEXT
  ⚠️ Table name prefix 'tbl_' is inconsistent with all other table names
```

---

## 15. Audit

```
audit_logs
├── id PK
├── tenant_id FK→tenants
├── business_id FK→businesses
├── user_id FK→users
├── event_type NN
├── table_name NN
├── record_id INT NN
├── action NN
├── old_values JSON
├── new_values JSON
├── ip_address
├── user_agent
└── created_at NN
```

---

## Summary of Issues Found

| Table | Issue | Action |
|-------|-------|--------|
| `business_module_overrides` | 0 rows, replaced by tenant-owned types | **DROP** |
| `user_notifications` | 0 rows, duplicates notifications read state | **DROP** (after migrating) |
| `notification_roles` | 0 rows, orphaned concept | **DROP** |
| `business_payees_types` | 0 rows, never used | **DROP** |
| `payee_types` | Hardcoded roles, never queried | **DROP** (classify by usage) |
| `item_cost_flags` | Duplicates item_price_history + items.has_cost_change_flag | **CONSOLIDATE** |
| `item_auto_markup` | One-to-one with items, should be columns | **MERGE into items** |
| `tbl_translations` | Inconsistent naming | **RENAME to translations** |
| `notifications` | Missing recipient_id, is_read, read_at | **ALTER** |
| `notification_preferences` | type ENUM duplicates notification_types | **ALTER to FK** |
| `people` | Single name field, no updated_at | **ALTER** |
| `stock_transactions` | No created_by, loose reference | **ALTER** |
| `batch_transactions` | No created_by, inconsistent type naming | **ALTER** |
| `partner_equity` | Ambiguous reference_id, no created_by | **ALTER** |
| `recurrence_schedules` | entity_id has no FK constraint | **ALTER** |
| `recurrence_instances` | Loose payment_record_id, orphaned bulk_advance_payment_id | **ALTER** |
| `supplier_item_prices` | supplier_id → people, not payees (inconsistent) | **ALTER** |
| `business_accounts` | No account_type column | **ALTER** |
| `assets` | category is free text, no created_by | **ALTER** |
| `users` | password_reset is an INT flag, not a secure token | **ALTER** (REQ-017 adds proper table) |
| `item_price_history` | No created_by | **ALTER** |
| `recipes` | yield_quantity is INT | **ALTER** (REQ-008) |
| `sales_invoices` | Missing due_date, tax fields, discount | **ALTER** (REQ-014,015,019) |
| `purchase_orders` | Missing tax fields, discount | **ALTER** (REQ-014,019) |
| `si_details` | Missing tax fields, discount | **ALTER** (REQ-014,019) |
| `po_details` | Missing tax fields, discount | **ALTER** (REQ-014,019) |
| `users` | Missing is_active | **ALTER** (REQ-007) |
| `businesses` | Missing is_active | **ALTER** (REQ-010) |
| `services` | Missing updated_at | **ALTER** (REQ-009) |
