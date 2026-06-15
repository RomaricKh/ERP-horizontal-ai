# Product Overview

ERP-horizontal is a multi-tenant, multi-business ERP (Enterprise Resource Planning) web application. It provides small-to-medium businesses with modules for:

- **Sales Invoices** — create, track, collect on customer invoices; tax/VAT per line; discounts; due dates; PDF generation; credit notes for returns
- **Purchase Orders** — manage supplier orders and payments; tax/VAT per line; discounts; PDF generation; debit notes for returns
- **Inventory / Items** — item catalog with system-typed classification (Trading, Raw Material, Manufactured, etc.); stock levels; stock adjustments; batch/expiry tracking; price history; negative stock prevention
- **Manufacturing** — recipes (BOM) with validated component types; production orders that consume/produce stock; fractional yields
- **People (Contacts)** — unified contact model with multi-type classification (Customer, Supplier, Employee, Partner); type-filtered selectors on documents
- **Finance** — bank/cash accounts with account types; allocation-based payments and collections (FIFO or manual); exchange rates; AR aging report; P&L, Cash Flow, Balance Sheet reports
- **Expenses** — categorized expenses with vendor document references (invoice #, date, attachment); recurring expense schedules
- **Salaries** — employee payroll with gross/net/tax; recurring salary schedules
- **Assets** — fixed/current/intangible asset tracking with system-typed categories; depreciation (straight-line, declining balance)
- **Partners** — partner shares (ownership %); equity contributions/withdrawals linked to collection/payment slips; profit calculation (accrual-basis) and distribution with permanent records
- **Services** — service catalog with system-typed categories; service-based invoicing
- **Notifications** — in-app notification system with typed notifications and user preferences
- **Activity Log / Audit** — track user actions across the system with full old/new value capture
- **Settings** — regional settings (language, timezone, formats); SMTP email configuration
- **User Profile** — self-service password change and profile editing

## Multi-Tenancy Model

- A **tenant** owns one or more **businesses**.
- Users are linked to businesses with a **role** that grants granular permissions.
- Every data query is scoped by `tenant_id` and/or `business_id` (enforced server-side via `AuthContext`).
- Module activation is per-business via **tenant-owned business types** (copied from system templates).
- **Tenant Owners** have a special `TENANT_OWNER` role that grants cross-business management capabilities.
- Tenant owners CANNOT hold business-level roles in their own tenant (enforced server-side).

## Tenant Owner Features

Tenant Owners have access to a dedicated management section (`#/tenant/*`) for:
- **Tenant management** — view/create tenants
- **Business management** — full CRUD on businesses; active/inactive toggle
- **Business Types** — copy system templates to create tenant-owned types; manage module sets per type
- **User management** — create users; assign roles; activate/deactivate users
- **Reports** — cross-business financial reports (P&L, balance sheet, cash flow, aging, activity)

## Business Type & Module System

- **System business types** (General, Retail, Wholesale, Manufacturing, Service) are immutable templates
- Tenant owners **copy** a system type to create a tenant-owned type, then customize its module set
- Businesses are only assigned to tenant-owned types (never directly to system types)
- Changing a type's modules immediately affects all businesses using that type
- Classification tables (item_types, payee_types, etc.) use `module_code` gating — types linked to inactive modules are hidden from the UI

## Settlement & Payment System

- **Allocation-based:** A single payment/collection slip can cover multiple documents (POs, expenses, salaries, invoices)
- **FIFO auto-allocation:** System distributes amount across unpaid documents oldest-first by default
- **Manual override:** User can pick specific documents and amounts
- **Document-level settlement:** Invoice is PAID when SUM(allocations) >= total; PO is CLOSED when SUM(allocations) >= total
- **Payee balance** is a convenience display field only — never used for settlement decisions

## Profit & Partner System

- **Accrual-basis revenue:** Profit uses PAID invoice totals, not collection sums
- **COGS calculation:** Cost of goods sold from item cost prices on PAID invoices
- **Safety net:** Configurable % of net profit retained for operations
- **Distribution:** Creates permanent `profit_distributions` records with ownership snapshots + payment slips
- **Equity:** Every contribution/withdrawal creates a corresponding collection/payment slip (cash movement always recorded)

## Key Domain Concepts

| Concept | Description |
|---------|-------------|
| Tenant | Top-level owner entity; owns businesses and users |
| Business | Operating unit within a tenant; scopes all transactional data |
| Role | Named set of permissions assigned to a user within a business |
| Module | Feature group that can be enabled/disabled per business type |
| Business Type | Tenant-owned template (copied from system type) defining which modules a business has |
| System Type | Immutable, predefined classification record (is_system=1) that carries semantic meaning |
| Person | A contact (customer, supplier, employee, partner) — can hold multiple types simultaneously |
| Payee | A person's financial identity within a specific business (balance, transactions) |
| Account | Financial account (bank, cash, credit) with currency and running balance |
| Asset | Business asset with category, depreciation method, and value tracking |
| Partner Share | Ownership percentage + invested capital for a partner in a business |
| Partner Equity | Contribution/withdrawal ledger entry linked to a collection/payment slip |
| Settlement | Allocation-based system that distributes payments/collections across documents |
| Payment Allocation | Detail row showing how a payment slip's amount is distributed across POs/expenses/salaries |
| Collection Allocation | Detail row showing how a collection slip's amount is distributed across invoices |
| Credit Note | Document issued to a customer for returned goods; reverses stock and reduces payee balance |
| Debit Note | Document issued to a supplier for returned goods; reverses stock and reduces payee balance |
| Profit Distribution | Permanent record of a partner's share of distributable profit for a period |
| Recurrence Schedule | Template for auto-generating periodic expense/salary instances |
| AR Aging | Report showing outstanding customer invoices grouped by how overdue they are |

## Deployment Flow

1. Create database: `CREATE DATABASE erp CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci`
2. Run schema: `mysql -u root erp < database/schema.sql`
3. Seed system data: `mysql -u root erp < database/seed_system_data.sql`
4. Configure `.env` with database credentials and JWT secret
5. Run first-installation flow (creates initial tenant, user, business)
