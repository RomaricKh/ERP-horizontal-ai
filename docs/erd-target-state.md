# ERD — Target State (To-Be)

> Clean, professional ERP schema. Based on analysis of current state issues,
> industry-standard ERP patterns (SAP B1, Odoo, ERPNext), and the spec requirements.
> **This is the authoritative schema reference.** The `database/schema.sql` file MUST match this exactly.
> For a visual Mermaid diagram, see `erd-target-mermaid.md`.

## Changes from Current State

### Tables DROPPED
| Table | Reason |
|-------|--------|
| `business_module_overrides` | Replaced by tenant-owned business types |
| `user_notifications` | Merged into `notifications` (recipient_id + is_read + read_at) |
| `notification_roles` | Orphaned, never implemented |
| `business_payees_types` | Replaced by `people_types` junction (REQ-031) |
| `item_cost_flags` | Consolidated into `item_price_history` + `items.has_cost_change_flag` |
| `item_auto_markup` | Merged as columns into `items` |

### Tables RENAMED
| Old Name | New Name | Reason |
|----------|----------|--------|
| `tbl_translations` | `translations` | Consistent naming convention |
| `business_payees_types` | `people_types` | Correct semantics (people ↔ types junction) |

### Tables ADDED
| Table | Reason |
|-------|--------|
| `password_reset_tokens` | Secure password reset flow (REQ-017) |
| `settings_smtp` | SMTP config for email (REQ-017) |
| `credit_notes` | Sales returns (REQ-024) |
| `credit_note_items` | Sales return line items (REQ-024) |
| `debit_notes` | Purchase returns (REQ-025) |
| `debit_note_items` | Purchase return line items (REQ-025) |
| `asset_categories` | System-typed asset classification (REQ-031) |
| `people_types` | Junction: people ↔ payee_types (many-to-many) (REQ-031) |
| `payment_allocations` | Multi-document payment distribution (REQ-021) |
| `collection_allocations` | Multi-document collection distribution (REQ-021) |
| `profit_distributions` | Per-partner payout record per profit period (REQ-032) |

---

## 1. Multi-Tenancy & Auth

```
tenants
├── id PK
├── name NN
├── description
├── base_currency CHAR(3) NN DEFAULT 'USD'
└── created_at NN

users
├── id PK
├── username UQ NN
├── password_hash NN
├── first_name NN
├── last_name NN
├── email UQ NN
├── is_active TINYINT(1) NN DEFAULT 1        ← NEW (REQ-007)
├── failed_attempts INT DEFAULT 0
├── lock_until DATETIME
├── created_at NN
└── updated_at
  REMOVED: password_reset INT (replaced by password_reset_tokens table)
  REMOVED: password_reset INT (replaced by password_reset_tokens table)

password_reset_tokens                         ← NEW (REQ-017)
├── id PK
├── user_id FK→users NN
├── token_hash CHAR(64) NN
├── expires_at DATETIME NN
├── used_at DATETIME
└── created_at NN
  INDEX: token_hash, user_id

refresh_tokens
├── id PK
├── user_id FK→users NN
├── device_id CHAR(36) NN
├── token_hash CHAR(64) NN
├── expires_at DATETIME NN
├── last_used_at DATETIME
├── revoked_at DATETIME
└── created_at NN
  UQ: (user_id, device_id)

roles
├── id PK
├── name NN
├── description
├── type ENUM(SYSTEM_OWNER,CUSTOM,TEMPLATE,TENANT_OWNER) NN
├── business_id FK→businesses (NULL for system/tenant roles)
├── created_at NN
└── updated_at
  UQ: (name, business_id)  ← FIXED (REQ-005)

permissions
├── id PK
├── name NN
├── code UQ NN
└── created_at NN

role_permissions
├── role_id FK→roles PK
└── permission_id FK→permissions PK

user_roles
├── id PK
├── user_id FK→users NN
├── role_id FK→roles NN
├── business_id FK→businesses (NULL for tenant-owner assignments)
├── tenant_id FK→tenants NN
└── is_active TINYINT(1) DEFAULT 1
  UQ: (user_id, role_id, business_id, tenant_id)
  GUARD: tenant owners cannot hold business-scoped roles in same tenant (REQ-026)
```

---

## 2. Business & Module Configuration

```
businesses
├── id PK
├── tenant_id FK→tenants NN
├── business_type_id FK→business_types NN
├── name NN
├── legal_name
├── registration_number
├── address
├── phone
├── email
├── currency CHAR(3) NN
├── is_active TINYINT(1) NN DEFAULT 1        ← NEW (REQ-010)
├── created_at NN
└── updated_at
  CONSTRAINT: business_type_id must reference a tenant-owned type (is_system=0)

business_types
├── id PK
├── tenant_id FK→tenants (NULL = system template, never assigned to businesses)
├── code NN
├── name NN
├── description
├── is_system TINYINT(1) NN DEFAULT 0
├── is_default TINYINT(1) NN DEFAULT 0
└── created_at NN
  UQ: (code, tenant_id)  ← FIXED (REQ-011)
  RULE: is_system=1 rows are read-only templates; businesses only use is_system=0 rows

business_type_modules
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

DROPPED: business_module_overrides
```

---

## 3. People & Contacts

```
people
├── id PK
├── tenant_id FK→tenants NN
├── name NN
├── phone NN
├── normalized_phone
├── email
├── address
├── tax_number
├── iban
├── created_at NN
├── updated_at                               ← NEW
└── created_by FK→users
  UQ: (tenant_id, normalized_phone)
  NOTE: A person's role (customer/supplier/employee/partner) is determined
  by the people_types junction — a person can have multiple types.

payee_types                                  ← REDESIGNED (REQ-031)
├── id PK
├── tenant_id FK→tenants (NULL = system type)
├── business_id FK→businesses (NULL = tenant-wide or system)
├── code VARCHAR(50) (NULL for user-defined, set for system types)
├── name NN
├── module_code FK→modules.code (NULL = always visible)
├── is_system TINYINT(1) NN DEFAULT 0
├── created_at NN
└── updated_at
  System seed: CUSTOMER, SUPPLIER, EMPLOYEE, PARTNER (module=partners), OTHER
  RULE: is_system=1 rows are immutable
  RULE: module_code-gated types only shown when module is active

people_types                                 ← NEW (replaces business_payees_types)
├── people_id FK→people PK (composite)
└── payee_type_id FK→payee_types PK (composite)
  NOTE: A person can have multiple types (e.g., both SUPPLIER and PARTNER)
  Used by selectors: invoices filter by CUSTOMER, POs by SUPPLIER, etc.

payees
├── id PK
├── business_id FK→businesses NN
├── people_id FK→people NN
├── balance DECIMAL(12,2) DEFAULT 0
├── notes
├── created_at NN
└── updated_at
  UQ: (business_id, people_id)
  NOTE: balance is a display summary only — settlement is document-based (REQ-021)

DROPPED: business_payees_types (replaced by people_types)
```

---

## 4. Inventory & Items

```
items
├── id PK
├── tenant_id FK→tenants NN
├── business_id FK→businesses (NULL = tenant-wide)
├── customer_id FK→people (NULL = standard item)
├── item_type_id FK→item_types NN
├── name NN
├── sku (UQ per tenant)
├── unit
├── cost_price DECIMAL(12,4)
├── sale_price DECIMAL(12,4)
├── min_stock DECIMAL(12,3)
├── markup_percent DECIMAL(5,2) DEFAULT NULL  ← MERGED from item_auto_markup
├── markup_enabled TINYINT(1) DEFAULT 0       ← MERGED from item_auto_markup
├── has_expiry TINYINT(1) DEFAULT 0
├── is_active TINYINT(1) DEFAULT 1
├── has_cost_change_flag TINYINT(1) DEFAULT 0
├── notes
├── created_at NN
├── updated_at
└── created_by FK→users

DROPPED: item_auto_markup (merged into items)

item_types                                   ← REDESIGNED (REQ-031)
├── id PK
├── tenant_id FK→tenants (NULL = system type)
├── business_id FK→businesses (NULL = tenant-wide or system)
├── code VARCHAR(50) (NULL for user-defined, set for system types)
├── name NN
├── module_code FK→modules.code (NULL = always visible)
├── is_system TINYINT(1) NN DEFAULT 0
├── created_at NN
└── updated_at
  System seed:
    TRADING (module=NULL), CONSUMABLE (module=NULL), FINISHED_GOOD (module=NULL),
    RAW_MATERIAL (module=manufacturing), MANUFACTURED (module=manufacturing),
    SERVICE_ITEM (module=services), ASSET_ITEM (module=assets)
  RULE: is_system=1 rows are immutable
  RULE: Manufacturing module validates BOM components are RAW_MATERIAL/CONSUMABLE
        and BOM outputs are MANUFACTURED/FINISHED_GOOD

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
├── type ENUM(IN,OUT,ADJ) NN
├── reference_type ENUM(PURCHASE_ORDER,SALES_INVOICE,PRODUCTION,MANUAL,CREDIT_NOTE,DEBIT_NOTE) ← NEW
├── reference_id INT                         ← NEW (polymorphic FK to source document)
├── quantity DECIMAL(12,3) NN
├── notes
├── created_by FK→users                      ← NEW
└── created_at NN

item_price_history
├── id PK
├── item_id FK→items NN
├── tenant_id FK→tenants NN
├── price_type ENUM(COST,SALE) NN
├── old_price DECIMAL(15,4)
├── new_price DECIMAL(15,4) NN
├── effective_date DATE NN
├── source_type ENUM(INITIAL,PURCHASE_ORDER,MANUAL_EDIT,MANUFACTURING) NN
├── source_id INT
├── created_by FK→users                      ← NEW (REQ-023 requires "changed by")
└── created_at NN

DROPPED: item_cost_flags (has_cost_change_flag on items is sufficient)

item_batches
├── id PK
├── item_id FK→items NN
├── business_id FK→businesses NN
├── batch_number NN
├── quantity DECIMAL(15,4) DEFAULT 0
├── manufacturing_date DATE
├── expiry_date DATE NN
├── purchase_order_id FK→purchase_orders
├── status ENUM(active,expired,written_off) NN DEFAULT active
├── created_at NN
└── updated_at
  UQ: (item_id, business_id, batch_number)

batch_transactions
├── id PK
├── batch_id FK→item_batches NN
├── transaction_type ENUM(IN,OUT,WRITE_OFF) NN  ← FIXED casing
├── quantity DECIMAL(15,4) NN
├── reference_type ENUM(PURCHASE_ORDER,SALES_INVOICE,MANUAL)
├── reference_id INT
├── notes
├── created_by FK→users                      ← NEW
└── created_at NN

supplier_item_prices
├── id PK
├── supplier_id FK→payees NN                 ← FIXED (was people.id)
├── item_id FK→items NN
├── tenant_id FK→tenants NN
├── last_price DECIMAL(15,4) NN
├── avg_price DECIMAL(15,4) NN
├── min_price DECIMAL(15,4) NN
├── max_price DECIMAL(15,4) NN
├── last_purchase_date DATE NN
├── purchase_count INT DEFAULT 1
├── is_preferred TINYINT(1) DEFAULT 0
├── created_at NN
└── updated_at NN
  UQ: (supplier_id, item_id, tenant_id)
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
├── due_date DATE                            ← NEW (REQ-015)
├── status ENUM(DRAFT,DELIVERED,PAID,CANCELLED) NN DEFAULT DRAFT
├── currency CHAR(3) NN
├── exchange_rate DECIMAL(12,6) NN
├── total_amount_fc DECIMAL(12,2) DEFAULT 0
├── total_amount_bc DECIMAL(12,2) DEFAULT 0
├── tax_total_fc DECIMAL(12,2) DEFAULT 0    ← NEW (REQ-014)
├── tax_total_bc DECIMAL(12,2) DEFAULT 0    ← NEW (REQ-014)
├── discount_percent DECIMAL(5,2) DEFAULT 0 ← NEW (REQ-019)
├── notes
├── created_at NN
├── updated_at
└── created_by FK→users

si_details
├── id PK
├── sales_invoice_id FK→sales_invoices NN
├── item_id FK→items (NULL if service)
├── service_id FK→services (NULL if item)
├── quantity DECIMAL(12,3) NN
├── unit_price_fc DECIMAL(12,2) NN
├── unit_price_bc DECIMAL(12,2) NN
├── discount_percent DECIMAL(5,2) DEFAULT 0 ← NEW (REQ-019)
├── tax_rate DECIMAL(5,2) DEFAULT 0         ← NEW (REQ-014)
├── tax_amount_fc DECIMAL(12,2) DEFAULT 0   ← NEW (REQ-014)
├── tax_amount_bc DECIMAL(12,2) DEFAULT 0   ← NEW (REQ-014)
├── total_price_fc DECIMAL(12,2) NN
├── total_price_bc DECIMAL(12,2) NN
└── created_at NN
  CHECK: exactly one of item_id or service_id must be set
  LINE TOTAL FORMULA: unit_price × qty × (1 - discount/100)
  TAX FORMULA: line_total × (tax_rate/100)

collection_slips
├── id PK
├── business_id FK→businesses NN
├── account_id FK→business_accounts NN
├── payee_id FK→payees NN
├── slip_number NN
├── slip_date DATE NN
├── currency CHAR(3) NN
├── exchange_rate DECIMAL(12,6) NN
├── amount_fc DECIMAL(12,2) NN
├── amount_bc DECIMAL(12,2) NN
├── method ENUM(CASH,BANK,CHECK,TRANSFER) NN
├── notes
├── created_at NN
├── updated_at
└── created_by FK→users
  REMOVED: reference_type, reference_id (moved to collection_allocations)

collection_allocations                       ← NEW (REQ-021)
├── id PK
├── collection_slip_id FK→collection_slips NN
├── reference_type ENUM(SALES_INVOICE,PARTNER_CONTRIBUTION) NN
├── reference_id INT NN
├── amount_fc DECIMAL(12,2) NN
├── amount_bc DECIMAL(12,2) NN
└── created_at NN
  NOTE: reference_id points to sales_invoices.id or partner_equity.id
  CONSTRAINT: SUM(amount_bc) per slip = slip.amount_bc
  Settlement: invoice is PAID when SUM(allocations) >= invoice.total_amount_bc

credit_notes                                 ← NEW (REQ-024)
├── id PK
├── business_id FK→businesses NN
├── sales_invoice_id FK→sales_invoices NN
├── credit_note_number NN
├── credit_note_date DATE NN
├── status ENUM(DRAFT,ISSUED) NN DEFAULT DRAFT
├── currency CHAR(3) NN
├── exchange_rate DECIMAL(12,6) NN DEFAULT 1
├── total_amount_fc DECIMAL(12,2) DEFAULT 0
├── total_amount_bc DECIMAL(12,2) DEFAULT 0
├── notes
├── created_at NN
└── created_by FK→users

credit_note_items                            ← NEW (REQ-024)
├── id PK
├── credit_note_id FK→credit_notes NN
├── item_id FK→items
├── service_id FK→services
├── quantity DECIMAL(12,3) NN
├── unit_price_fc DECIMAL(12,2) NN
├── unit_price_bc DECIMAL(12,2) NN
├── total_price_fc DECIMAL(12,2) NN
└── total_price_bc DECIMAL(12,2) NN
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
├── currency CHAR(3) NN
├── exchange_rate DECIMAL(12,6) NN
├── total_amount_fc DECIMAL(12,2) DEFAULT 0
├── total_amount_bc DECIMAL(12,2) DEFAULT 0
├── tax_total_fc DECIMAL(12,2) DEFAULT 0    ← NEW (REQ-014)
├── tax_total_bc DECIMAL(12,2) DEFAULT 0    ← NEW (REQ-014)
├── discount_percent DECIMAL(5,2) DEFAULT 0 ← NEW (REQ-019)
├── notes
├── created_at NN
├── updated_at
└── created_by FK→users

po_details
├── id PK
├── purchase_order_id FK→purchase_orders NN
├── item_id FK→items NN
├── quantity DECIMAL(12,3) NN
├── unit_price_fc DECIMAL(12,2) NN
├── unit_price_bc DECIMAL(12,2) NN
├── discount_percent DECIMAL(5,2) DEFAULT 0 ← NEW (REQ-019)
├── tax_rate DECIMAL(5,2) DEFAULT 0         ← NEW (REQ-014)
├── tax_amount_fc DECIMAL(12,2) DEFAULT 0   ← NEW (REQ-014)
├── tax_amount_bc DECIMAL(12,2) DEFAULT 0   ← NEW (REQ-014)
├── total_price_fc DECIMAL(12,2) NN
├── total_price_bc DECIMAL(12,2) NN
└── created_at NN

payment_slips
├── id PK
├── business_id FK→businesses NN
├── account_id FK→business_accounts NN
├── payee_id FK→payees NN
├── payment_number NN
├── payment_date DATE NN
├── currency CHAR(3) NN
├── exchange_rate DECIMAL(12,6) NN DEFAULT 1
├── amount_fc DECIMAL(12,2) NN
├── amount_bc DECIMAL(12,2) NN
├── method ENUM(CASH,BANK,CHECK,TRANSFER) NN
├── notes
├── created_at NN
├── updated_at
└── created_by FK→users
  REMOVED: reference_type, reference_id (moved to payment_allocations)

payment_allocations                          ← NEW (REQ-021)
├── id PK
├── payment_slip_id FK→payment_slips NN
├── reference_type ENUM(PURCHASE_ORDER,EXPENSE,SALARY,PARTNER_PROFIT) NN
├── reference_id INT NN
├── amount_fc DECIMAL(12,2) NN
├── amount_bc DECIMAL(12,2) NN
└── created_at NN
  NOTE: reference_id points to purchase_orders.id, expenses.id, salaries.id,
        or business_profit composite key depending on reference_type
  CONSTRAINT: SUM(amount_bc) per slip = slip.amount_bc
  Settlement: PO is CLOSED when SUM(allocations) >= po.total_amount_bc

debit_notes                                  ← NEW (REQ-025)
├── id PK
├── business_id FK→businesses NN
├── purchase_order_id FK→purchase_orders NN
├── debit_note_number NN
├── debit_note_date DATE NN
├── status ENUM(DRAFT,ISSUED) NN DEFAULT DRAFT
├── currency CHAR(3) NN
├── exchange_rate DECIMAL(12,6) NN DEFAULT 1
├── total_amount_fc DECIMAL(12,2) DEFAULT 0
├── total_amount_bc DECIMAL(12,2) DEFAULT 0
├── notes
├── created_at NN
└── created_by FK→users

debit_note_items                             ← NEW (REQ-025)
├── id PK
├── debit_note_id FK→debit_notes NN
├── item_id FK→items NN
├── quantity DECIMAL(12,3) NN
├── unit_price_fc DECIMAL(12,2) NN
├── unit_price_bc DECIMAL(12,2) NN
├── total_price_fc DECIMAL(12,2) NN
└── total_price_bc DECIMAL(12,2) NN
```

---

## 7. Finance

```
business_accounts
├── id PK
├── business_id FK→businesses NN
├── name NN
├── account_type ENUM(BANK,CASH,CREDIT,OTHER) NN DEFAULT CASH  ← NEW
├── currency CHAR(3) NN
├── balance DECIMAL(18,2) DEFAULT 0
├── balance_fc DECIMAL(15,2) DEFAULT 0
├── created_at NN
└── updated_at

expenses
├── id PK
├── business_id FK→businesses NN
├── payee_id FK→payees NN
├── expense_category_id FK→expense_categories NN
├── expense_date DATE NN
├── currency CHAR(3) NN
├── exchange_rate DECIMAL(12,6) NN
├── amount_fc DECIMAL(12,2) NN
├── amount_bc DECIMAL(12,2) NN
├── reference_number VARCHAR(100)            ← NEW (vendor invoice/receipt #)
├── reference_date DATE                      ← NEW (date on vendor document)
├── attachment_path VARCHAR(500)             ← NEW (uploaded scan/PDF)
├── notes
├── created_at NN
└── updated_at

expense_categories                           ← REDESIGNED (REQ-031)
├── id PK
├── tenant_id FK→tenants (NULL = system type)
├── business_id FK→businesses (NULL = tenant-wide or system)
├── code VARCHAR(50) (NULL for user-defined, set for system types)
├── name NN
├── module_code FK→modules.code (NULL = always visible)
├── is_system TINYINT(1) NN DEFAULT 0
├── is_fixed TINYINT(1) DEFAULT 0
├── created_at NN
├── updated_at
└── created_by FK→users
  System seed: RENT, UTILITIES, SALARIES, MARKETING, MAINTENANCE, TRANSPORT, OTHER
  RULE: is_system=1 rows are immutable

salaries
├── id PK
├── business_id FK→businesses NN
├── payee_id FK→payees NN
├── period_start DATE NN
├── period_end DATE NN
├── currency CHAR(3) NN
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
├── business_id FK→businesses (NULL = tenant-wide)
├── currency CHAR(3) NN
├── rate_date DATE NN
├── rate DECIMAL(12,6) NN
├── source VARCHAR(100)
└── created_at NN

business_profit                              ← REDESIGNED (REQ-032)
├── id PK (AUTO_INCREMENT)                   ← CHANGED from composite PK
├── business_id FK→businesses NN
├── period_start DATE NN
├── period_end DATE NN
├── total_revenue DECIMAL(18,2) NN           ← sum of PAID invoices (accrual)
├── total_cogs DECIMAL(18,2) NN DEFAULT 0    ← cost of goods sold
├── gross_profit DECIMAL(18,2) NN            ← revenue - COGS
├── total_expenses DECIMAL(18,2) NN          ← operating expenses in period
├── net_profit DECIMAL(18,2) NN              ← gross_profit - expenses
├── safety_net_percent DECIMAL(5,2) NN       ← % retained for operations
├── safety_net_amount DECIMAL(18,2) NN       ← net_profit × safety_net_percent/100
├── distributable_profit DECIMAL(18,2) NN    ← net_profit - safety_net_amount
├── status ENUM(DRAFT,FINALIZED,DISTRIBUTED) NN DEFAULT DRAFT
├── created_by FK→users
├── created_at NN
└── updated_at
  UQ: (business_id, period_start)
  RULE: Only DRAFT records can be edited/deleted
  RULE: Only FINALIZED records can be distributed
  REVENUE: uses PAID invoices (accrual basis), NOT collection sums

profit_distributions                         ← NEW (REQ-032)
├── id PK
├── business_profit_id FK→business_profit NN
├── partner_id FK→payees NN
├── share_percent DECIMAL(5,2) NN            ← snapshot of ownership at distribution time
├── amount_bc DECIMAL(12,2) NN
├── payment_slip_id FK→payment_slips NN      ← the actual cash outflow
└── created_at NN
  NOTE: Permanent record of who got what for each period.
  Even if ownership changes later, this shows the historical split.

settings_smtp                                ← NEW (REQ-017)
├── business_id FK→businesses PK
├── host VARCHAR(255) NN
├── port SMALLINT NN DEFAULT 587
├── username VARCHAR(255) NN
├── password_enc TEXT NN
├── from_address VARCHAR(255) NN
├── from_name VARCHAR(255) NN
└── is_active TINYINT(1) DEFAULT 0
```

---

## 8. Partners & Profit Distribution

```
partner_shares
├── id PK
├── business_id FK→businesses NN
├── partner_id FK→payees NN
├── ownership_pct DECIMAL(5,2) NN
├── invested_capital DECIMAL(12,2) DEFAULT 0  ← running total, updated by equity entries
├── created_at NN
└── updated_at
  CONSTRAINT: SUM(ownership_pct) per business <= 100
  NOTE: invested_capital = SUM(contributions) - SUM(withdrawals) for this partner

partner_equity
├── id PK
├── partner_id FK→payees NN
├── business_id FK→businesses NN
├── equity_type ENUM(CONTRIBUTION,WITHDRAWAL) NN
├── amount DECIMAL(12,2) NN
├── slip_type ENUM(COLLECTION_SLIP,PAYMENT_SLIP) NN  ← NEW (replaces ambiguous reference_type)
├── slip_id INT NN                                    ← NEW (FK to the actual cash movement)
├── notes TEXT
├── created_by FK→users                              ← NEW
└── created_at NN
  CONTRIBUTION: partner puts money IN → creates collection_slip → slip_type='COLLECTION_SLIP'
  WITHDRAWAL: partner takes money OUT → creates payment_slip → slip_type='PAYMENT_SLIP'
  Each equity entry MUST have a corresponding slip (cash movement is always recorded)
```

---

## 9. Manufacturing

```
recipes
├── id PK
├── tenant_id FK→tenants NN
├── business_id FK→businesses NN
├── manufactured_item_id FK→items NN
├── yield_quantity DECIMAL(12,4) NN DEFAULT 1.0000  ← FIXED (REQ-008)
├── notes
├── is_active TINYINT(1) DEFAULT 1
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
├── is_active TINYINT(1) DEFAULT 1
├── created_by FK→users NN
├── created_at NN
└── updated_at                               ← NEW (REQ-009)

service_categories                           ← REDESIGNED (REQ-031)
├── id PK
├── tenant_id FK→tenants (NULL = system type)
├── business_id FK→businesses (NULL = tenant-wide or system)
├── code VARCHAR(50) (NULL for user-defined, set for system types)
├── name NN
├── module_code FK→modules.code (NULL = always visible)
├── is_system TINYINT(1) NN DEFAULT 0
├── created_by FK→users
└── created_at NN
  System seed: CONSULTING (module=services), MAINTENANCE (module=services),
    DELIVERY (module=services), INSTALLATION (module=services), OTHER (module=services)
  UQ: (tenant_id, business_id, name)
  RULE: is_system=1 rows are immutable
```

---

## 11. Recurrences

```
recurrence_schedules
├── id PK (UNSIGNED)
├── entity_type ENUM(EXPENSE,SALARY) NN      ← FIXED casing
├── entity_id INT NN
├── business_id FK→businesses NN
├── frequency ENUM(WEEKLY,BI_WEEKLY,MONTHLY,QUARTERLY,YEARLY) NN  ← FIXED casing
├── default_amount DECIMAL(15,2) NN
├── default_currency CHAR(3) NN
├── start_date DATE NN
├── end_date DATE
├── is_active TINYINT(1) DEFAULT 1
├── created_at NN
└── updated_at NN
  NOTE: entity_id still polymorphic — acceptable for this pattern

recurrence_instances
├── id PK (UNSIGNED)
├── recurrence_schedule_id FK→recurrence_schedules NN
├── entity_type ENUM(EXPENSE,SALARY) NN
├── business_id FK→businesses NN
├── due_date DATE NN
├── scheduled_amount DECIMAL(15,2) NN
├── actual_amount DECIMAL(15,2)
├── currency CHAR(3) NN
├── status ENUM(PENDING,PAID,SKIPPED,OVERDUE) NN DEFAULT PENDING  ← FIXED casing
├── paid_date DATE
├── notification_sent TINYINT(1) DEFAULT 0
├── payment_record_id INT
├── period_start DATE
├── period_end DATE
├── created_at NN
└── updated_at NN
  UQ: (recurrence_schedule_id, due_date)
  REMOVED: is_advance_payment, bulk_advance_payment_id, metadata (unused complexity)
```

---

## 12. Notifications

```
notification_types
├── id PK
├── code UQ NN
├── name NN                                  ← NEW (was only description)
└── description NN

notifications
├── id PK
├── business_id FK→businesses
├── notification_type_id FK→notification_types NN
├── recipient_id FK→users NN                 ← NEW (was in user_notifications)
├── title NN
├── message NN
├── severity ENUM(INFO,WARNING,ALERT) NN DEFAULT INFO
├── reference_table VARCHAR(50)
├── reference_id INT
├── is_read TINYINT(1) DEFAULT 0             ← NEW (was in user_notifications)
├── read_at DATETIME                         ← NEW (was in user_notifications)
└── created_at NN

notification_preferences
├── id PK
├── user_id FK→users NN
├── business_id FK→businesses NN
├── notification_type_id FK→notification_types NN  ← FIXED (was hardcoded ENUM)
├── enabled TINYINT(1) DEFAULT 1
└── updated_at NN
  UQ: (user_id, business_id, notification_type_id)

DROPPED: user_notifications
DROPPED: notification_roles
```

---

## 13. Assets

```
asset_categories                             ← NEW (REQ-031, replaces free-text category)
├── id PK
├── tenant_id FK→tenants (NULL = system type)
├── business_id FK→businesses (NULL = tenant-wide or system)
├── code VARCHAR(50) (NULL for user-defined, set for system types)
├── name NN
├── module_code FK→modules.code (NULL = always visible)
├── is_system TINYINT(1) NN DEFAULT 0
└── created_at NN
  System seed:
    LAND_BUILDINGS (module=assets), EQUIPMENT (module=assets),
    VEHICLES (module=assets), FURNITURE (module=assets),
    IT_EQUIPMENT (module=assets), INTANGIBLE (module=assets),
    INVENTORY_ASSET (module=assets)
  UQ: (business_id, name) for user-defined; (code) for system types
  RULE: is_system=1 rows are immutable

assets
├── id PK
├── business_id FK→businesses NN
├── category_id FK→asset_categories NN      ← FIXED (was free text)
├── name NN
├── asset_type ENUM(FIXED,CURRENT,INTANGIBLE) NN
├── purchase_date DATE NN
├── purchase_value DECIMAL(12,2) NN
├── currency CHAR(3) NN
├── exchange_rate DECIMAL(12,6) DEFAULT 1
├── value_bc DECIMAL(12,2) NN
├── depreciation_method ENUM(STRAIGHT_LINE,DECLINING_BALANCE) DEFAULT STRAIGHT_LINE
├── useful_life INT
├── accumulated_depreciation DECIMAL(12,2) DEFAULT 0
├── current_value_bc DECIMAL(12,2)
├── notes
├── created_by FK→users                     ← NEW
├── created_at NN
└── updated_at
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
└── audit_enabled TINYINT(1) DEFAULT 1

languages
├── code PK
├── name
└── direction ENUM(LTR,RTL) DEFAULT LTR

translations                                 ← RENAMED from tbl_translations
├── lang FK→languages PK (composite)
├── key PK (composite)
└── value TEXT
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

## Relationship Summary (Key FKs)

```
tenants ──< businesses ──< sales_invoices ──< si_details
                       ──< purchase_orders ──< po_details
                       ──< payees ──< collection_slips ──< collection_allocations
                                  ──< payment_slips ──< payment_allocations
                                  ──< partner_shares
                                  ──< partner_equity
                       ──< business_accounts
                       ──< expenses
                       ──< salaries
                       ──< stock ──< items
                       ──< production_orders
                       ──< assets ──< asset_categories
                       ──< business_profit ──< profit_distributions
        ──< business_types ──< business_type_modules ──< modules
        ──< users ──< user_roles ──< roles ──< role_permissions ──< permissions
                  ──< refresh_tokens
                  ──< password_reset_tokens
people ──< payees (per business)
       ──< people_types ──< payee_types
       ──< supplier_item_prices
items ──< stock_transactions
      ──< item_batches ──< batch_transactions
      ──< item_price_history
      ──< recipe_items ──< recipes ──< production_orders
sales_invoices ──< credit_notes ──< credit_note_items
               ──< collection_allocations (settlement)
purchase_orders ──< debit_notes ──< debit_note_items
                ──< payment_allocations (settlement)
recurrence_schedules ──< recurrence_instances
notification_types ──< notifications ──> users (recipient)
                   ──< notification_preferences
```
