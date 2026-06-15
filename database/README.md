# Database Setup

## Overview

The ERP database uses MySQL 9.x with the `utf8mb4` character set. There are two approaches to setting up the database:

1. **Fresh install** — Use `schema.sql` + `seed_system_data.sql` (recommended for new deployments)
2. **Incremental migration** — Apply individual migration scripts from `migrations/` (for existing databases)

## Fresh Install (from scratch)

```bash
# 1. Create the database
mysql -u root -e "CREATE DATABASE IF NOT EXISTS erp CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"

# 2. Create all tables
mysql -u root erp < database/schema.sql

# 3. Insert system/seed data (modules, permissions, business types, etc.)
mysql -u root erp < database/seed_system_data.sql
```

On Windows (WAMP):
```powershell
# 1. Create the database
& "C:\wamp64\bin\mysql\mysql9.1.0\bin\mysql.exe" -u root -e "CREATE DATABASE IF NOT EXISTS erp CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"

# 2. Create all tables
& "C:\wamp64\bin\mysql\mysql9.1.0\bin\mysql.exe" -u root erp < database/schema.sql

# 3. Insert system/seed data
& "C:\wamp64\bin\mysql\mysql9.1.0\bin\mysql.exe" -u root erp < database/seed_system_data.sql
```

After running both scripts, the application is ready for the **first-installation flow**: no tenant exists, so the setup wizard activates and guides the user through creating the first tenant, business, and admin user.

## File Descriptions

| File | Purpose |
|------|---------|
| `schema.sql` | Creates ALL 63 tables with indexes and FK constraints. Idempotent (uses `CREATE TABLE IF NOT EXISTS`). This is the single source of truth for the database structure. |
| `seed_system_data.sql` | Inserts system lookup data: modules, permissions, system business types with module assignments, notification types, and languages. Uses `INSERT IGNORE` for idempotency. |
| `migrations/` | Individual SQL migration scripts applied incrementally during development. Each file addresses a specific change (add column, create table, fix constraint, etc.). |
| `clean_for_testing.sql` | Truncates all data tables for test environment reset. Does NOT drop tables or remove system data. |

## Relationship: schema.sql vs. migrations/

- `schema.sql` represents the **final state** of the database after ALL migrations have been applied.
- The `migrations/` folder contains the **history** of how the schema evolved over time.
- For a fresh install, you only need `schema.sql` + `seed_system_data.sql`.
- For an existing database, apply only the migrations that haven't been run yet.

**Important:** If you modify the schema, update BOTH the relevant migration file AND `schema.sql` to keep them in sync.

## Running Migrations (existing database)

Migrations are plain SQL files applied manually. There is no migration tracking table — you must track which migrations have been applied.

```bash
# Apply a single migration
mysql -u root erp < database/migrations/add_some_feature.sql
```

## Table Count

The schema contains **63 tables** organized across these domains:

- Multi-tenancy & Auth (tenants, users, roles, permissions, refresh_tokens, password_reset_tokens, user_roles, role_permissions)
- Business & Modules (businesses, business_types, business_type_modules, modules)
- People & Contacts (people, payees, payee_types, people_types)
- Inventory (items, item_types, stock, stock_transactions, item_price_history, item_batches, batch_transactions, supplier_item_prices)
- Sales (sales_invoices, si_details, collection_slips, collection_allocations, credit_notes, credit_note_items)
- Purchasing (purchase_orders, po_details, payment_slips, payment_allocations, debit_notes, debit_note_items)
- Finance (business_accounts, expenses, expense_categories, salaries, exchange_rates, business_profit, profit_distributions, settings_smtp)
- Partners (partner_shares, partner_equity)
- Manufacturing (recipes, recipe_items, production_orders, production_order_items)
- Services (services, service_categories)
- Recurrences (recurrence_schedules, recurrence_instances)
- Notifications (notification_types, notifications, notification_preferences)
- Assets (assets, asset_categories)
- Settings & i18n (languages, settings_regional, translations)
- Audit (audit_logs)

## First-Installation Flow

After a fresh database setup (schema + seed data), the application detects that no tenant exists and activates the setup wizard. The wizard:

1. Creates the first tenant
2. Creates the first business (copying a system business type to a tenant-owned type)
3. Creates the admin user with TENANT_OWNER role
4. Seeds tenant-scoped system types (payee types, item types, expense categories, service categories, asset categories)

No manual data entry is required beyond running the two SQL scripts.
