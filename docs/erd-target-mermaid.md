# ERD — Target State (Mermaid)

> Visual entity-relationship diagram of the target database schema.
> For detailed column definitions, see `erd-target-state.md`.

## Full Schema (grouped by domain)

```mermaid
erDiagram
    %% ═══════════════════════════════════════════
    %% 1. MULTI-TENANCY & AUTH
    %% ═══════════════════════════════════════════

    tenants {
        int id PK
        varchar name
        varchar description
        char base_currency
        datetime created_at
    }

    users {
        int id PK
        varchar username UK
        varchar password_hash
        varchar first_name
        varchar last_name
        varchar email UK
        tinyint is_active
        int failed_attempts
        datetime lock_until
        datetime created_at
        datetime updated_at
    }

    refresh_tokens {
        bigint id PK
        int user_id FK
        char device_id
        char token_hash
        datetime expires_at
        datetime last_used_at
        datetime revoked_at
        datetime created_at
    }

    password_reset_tokens {
        int id PK
        int user_id FK
        char token_hash
        datetime expires_at
        datetime used_at
        datetime created_at
    }

    roles {
        int id PK
        varchar name
        varchar description
        enum type
        int business_id FK
        datetime created_at
        datetime updated_at
    }

    permissions {
        int id PK
        varchar name
        varchar code UK
        datetime created_at
    }

    role_permissions {
        int role_id PK,FK
        int permission_id PK,FK
    }

    user_roles {
        int id PK
        int user_id FK
        int role_id FK
        int business_id FK
        int tenant_id FK
        tinyint is_active
    }

    %% ═══════════════════════════════════════════
    %% 2. BUSINESS & MODULE CONFIGURATION
    %% ═══════════════════════════════════════════

    businesses {
        int id PK
        int tenant_id FK
        int business_type_id FK
        varchar name
        varchar legal_name
        varchar registration_number
        varchar address
        varchar phone
        varchar email
        char currency
        tinyint is_active
        datetime created_at
        datetime updated_at
    }

    business_types {
        int id PK
        int tenant_id FK
        varchar code
        varchar name
        varchar description
        tinyint is_system
        tinyint is_default
        datetime created_at
    }

    business_type_modules {
        int id PK
        int business_type_id FK
        int module_id FK
        datetime created_at
    }

    modules {
        int id PK
        varchar code UK
        varchar name
        varchar icon
        varchar route_prefix
        json permission_prefixes
        enum category
        int sort_order
        datetime created_at
    }

    %% ═══════════════════════════════════════════
    %% 3. PEOPLE & CONTACTS
    %% ═══════════════════════════════════════════

    people {
        int id PK
        int tenant_id FK
        varchar name
        varchar phone
        varchar normalized_phone
        varchar email
        varchar address
        varchar tax_number
        varchar iban
        datetime created_at
        datetime updated_at
        int created_by FK
    }

    payee_types {
        int id PK
        int tenant_id FK
        int business_id FK
        varchar code
        varchar name
        varchar module_code FK
        tinyint is_system
        datetime created_at
        datetime updated_at
    }

    people_types {
        int people_id PK,FK
        int payee_type_id PK,FK
    }

    payees {
        int id PK
        int business_id FK
        int people_id FK
        decimal balance
        text notes
        datetime created_at
        datetime updated_at
    }

    %% ═══════════════════════════════════════════
    %% 4. INVENTORY & ITEMS
    %% ═══════════════════════════════════════════

    item_types {
        int id PK
        int tenant_id FK
        int business_id FK
        varchar code
        varchar name
        varchar module_code FK
        tinyint is_system
        datetime created_at
        datetime updated_at
    }

    items {
        int id PK
        int tenant_id FK
        int business_id FK
        int customer_id FK
        int item_type_id FK
        varchar name
        varchar sku
        varchar unit
        decimal cost_price
        decimal sale_price
        decimal min_stock
        decimal markup_percent
        tinyint markup_enabled
        tinyint has_expiry
        tinyint is_active
        tinyint has_cost_change_flag
        text notes
        datetime created_at
        datetime updated_at
        int created_by FK
    }

    stock {
        int id PK
        int business_id FK
        int item_id FK
        decimal quantity
        datetime updated_at
    }

    stock_transactions {
        int id PK
        int business_id FK
        int item_id FK
        datetime trx_date
        enum type
        enum reference_type
        int reference_id
        decimal quantity
        text notes
        int created_by FK
        datetime created_at
    }

    item_price_history {
        int id PK
        int item_id FK
        int tenant_id FK
        enum price_type
        decimal old_price
        decimal new_price
        date effective_date
        enum source_type
        int source_id
        int created_by FK
        datetime created_at
    }

    item_batches {
        int id PK
        int item_id FK
        int business_id FK
        varchar batch_number
        decimal quantity
        date manufacturing_date
        date expiry_date
        int purchase_order_id FK
        enum status
        datetime created_at
        datetime updated_at
    }

    batch_transactions {
        int id PK
        int batch_id FK
        enum transaction_type
        decimal quantity
        enum reference_type
        int reference_id
        text notes
        int created_by FK
        datetime created_at
    }

    supplier_item_prices {
        int id PK
        int supplier_id FK
        int item_id FK
        int tenant_id FK
        decimal last_price
        decimal avg_price
        decimal min_price
        decimal max_price
        date last_purchase_date
        int purchase_count
        tinyint is_preferred
        datetime created_at
        datetime updated_at
    }

    %% ═══════════════════════════════════════════
    %% 5. SALES
    %% ═══════════════════════════════════════════

    sales_invoices {
        int id PK
        int business_id FK
        int customer_id FK
        varchar invoice_number
        date invoice_date
        date due_date
        enum status
        char currency
        decimal exchange_rate
        decimal total_amount_fc
        decimal total_amount_bc
        decimal tax_total_fc
        decimal tax_total_bc
        decimal discount_percent
        text notes
        datetime created_at
        datetime updated_at
        int created_by FK
    }

    si_details {
        int id PK
        int sales_invoice_id FK
        int item_id FK
        int service_id FK
        decimal quantity
        decimal unit_price_fc
        decimal unit_price_bc
        decimal discount_percent
        decimal tax_rate
        decimal tax_amount_fc
        decimal tax_amount_bc
        decimal total_price_fc
        decimal total_price_bc
        datetime created_at
    }

    collection_slips {
        int id PK
        int business_id FK
        int account_id FK
        int payee_id FK
        varchar slip_number
        date slip_date
        char currency
        decimal exchange_rate
        decimal amount_fc
        decimal amount_bc
        enum method
        text notes
        datetime created_at
        datetime updated_at
        int created_by FK
    }

    collection_allocations {
        int id PK
        int collection_slip_id FK
        enum reference_type
        int reference_id
        decimal amount_fc
        decimal amount_bc
        datetime created_at
    }

    credit_notes {
        int id PK
        int business_id FK
        int sales_invoice_id FK
        varchar credit_note_number
        date credit_note_date
        enum status
        char currency
        decimal exchange_rate
        decimal total_amount_fc
        decimal total_amount_bc
        text notes
        datetime created_at
        int created_by FK
    }

    credit_note_items {
        int id PK
        int credit_note_id FK
        int item_id FK
        int service_id FK
        decimal quantity
        decimal unit_price_fc
        decimal unit_price_bc
        decimal total_price_fc
        decimal total_price_bc
    }

    %% ═══════════════════════════════════════════
    %% 6. PURCHASING
    %% ═══════════════════════════════════════════

    purchase_orders {
        int id PK
        int business_id FK
        int supplier_id FK
        varchar po_number
        date po_date
        enum status
        char currency
        decimal exchange_rate
        decimal total_amount_fc
        decimal total_amount_bc
        decimal tax_total_fc
        decimal tax_total_bc
        decimal discount_percent
        text notes
        datetime created_at
        datetime updated_at
        int created_by FK
    }

    po_details {
        int id PK
        int purchase_order_id FK
        int item_id FK
        decimal quantity
        decimal unit_price_fc
        decimal unit_price_bc
        decimal discount_percent
        decimal tax_rate
        decimal tax_amount_fc
        decimal tax_amount_bc
        decimal total_price_fc
        decimal total_price_bc
        datetime created_at
    }

    payment_slips {
        int id PK
        int business_id FK
        int account_id FK
        int payee_id FK
        varchar payment_number
        date payment_date
        char currency
        decimal exchange_rate
        decimal amount_fc
        decimal amount_bc
        enum method
        text notes
        datetime created_at
        datetime updated_at
        int created_by FK
    }

    payment_allocations {
        int id PK
        int payment_slip_id FK
        enum reference_type
        int reference_id
        decimal amount_fc
        decimal amount_bc
        datetime created_at
    }

    debit_notes {
        int id PK
        int business_id FK
        int purchase_order_id FK
        varchar debit_note_number
        date debit_note_date
        enum status
        char currency
        decimal exchange_rate
        decimal total_amount_fc
        decimal total_amount_bc
        text notes
        datetime created_at
        int created_by FK
    }

    debit_note_items {
        int id PK
        int debit_note_id FK
        int item_id FK
        decimal quantity
        decimal unit_price_fc
        decimal unit_price_bc
        decimal total_price_fc
        decimal total_price_bc
    }

    %% ═══════════════════════════════════════════
    %% 7. FINANCE
    %% ═══════════════════════════════════════════

    business_accounts {
        int id PK
        int business_id FK
        varchar name
        enum account_type
        char currency
        decimal balance
        decimal balance_fc
        datetime created_at
        datetime updated_at
    }

    expenses {
        int id PK
        int business_id FK
        int payee_id FK
        int expense_category_id FK
        date expense_date
        char currency
        decimal exchange_rate
        decimal amount_fc
        decimal amount_bc
        varchar reference_number
        date reference_date
        varchar attachment_path
        text notes
        datetime created_at
        datetime updated_at
    }

    expense_categories {
        int id PK
        int tenant_id FK
        int business_id FK
        varchar code
        varchar name
        varchar module_code FK
        tinyint is_system
        tinyint is_fixed
        datetime created_at
        datetime updated_at
        int created_by FK
    }

    salaries {
        int id PK
        int business_id FK
        int payee_id FK
        date period_start
        date period_end
        char currency
        decimal exchange_rate
        decimal gross_amount_fc
        decimal gross_amount_bc
        decimal tax_fc
        decimal tax_bc
        decimal net_amount_fc
        decimal net_amount_bc
        text notes
        datetime created_at
        datetime updated_at
    }

    exchange_rates {
        int id PK
        int tenant_id FK
        int business_id FK
        char currency
        date rate_date
        decimal rate
        varchar source
        datetime created_at
    }

    business_profit {
        int id PK
        int business_id FK
        date period_start
        date period_end
        decimal total_revenue
        decimal total_cogs
        decimal gross_profit
        decimal total_expenses
        decimal net_profit
        decimal safety_net_percent
        decimal safety_net_amount
        decimal distributable_profit
        enum status
        int created_by FK
        datetime created_at
        datetime updated_at
    }

    profit_distributions {
        int id PK
        int business_profit_id FK
        int partner_id FK
        decimal share_percent
        decimal amount_bc
        int payment_slip_id FK
        datetime created_at
    }

    settings_smtp {
        int business_id PK,FK
        varchar host
        smallint port
        varchar username
        text password_enc
        varchar from_address
        varchar from_name
        tinyint is_active
    }

    %% ═══════════════════════════════════════════
    %% 8. PARTNERS
    %% ═══════════════════════════════════════════

    partner_shares {
        int id PK
        int business_id FK
        int partner_id FK
        decimal ownership_pct
        decimal invested_capital
        datetime created_at
        datetime updated_at
    }

    partner_equity {
        int id PK
        int partner_id FK
        int business_id FK
        enum equity_type
        decimal amount
        enum slip_type
        int slip_id
        text notes
        int created_by FK
        datetime created_at
    }

    %% ═══════════════════════════════════════════
    %% 9. MANUFACTURING
    %% ═══════════════════════════════════════════

    recipes {
        int id PK
        int tenant_id FK
        int business_id FK
        int manufactured_item_id FK
        decimal yield_quantity
        text notes
        tinyint is_active
        int created_by FK
        datetime created_at
        datetime updated_at
    }

    recipe_items {
        int id PK
        int recipe_id FK
        int component_item_id FK
        decimal quantity
    }

    production_orders {
        int id PK
        int tenant_id FK
        int business_id FK
        int recipe_id FK
        int manufactured_item_id FK
        decimal production_quantity
        decimal manufacturing_cost
        decimal unit_manufacturing_cost
        enum status
        datetime production_date
        int created_by FK
        datetime created_at
    }

    production_order_items {
        int id PK
        int production_order_id FK
        int component_item_id FK
        decimal quantity_consumed
        decimal unit_cost
    }

    %% ═══════════════════════════════════════════
    %% 10. SERVICES
    %% ═══════════════════════════════════════════

    services {
        int id PK
        int tenant_id FK
        int business_id FK
        int category_id FK
        varchar name
        text description
        varchar unit
        decimal rate
        tinyint is_active
        int created_by FK
        datetime created_at
        datetime updated_at
    }

    service_categories {
        int id PK
        int tenant_id FK
        int business_id FK
        varchar code
        varchar name
        varchar module_code FK
        tinyint is_system
        int created_by FK
        datetime created_at
    }

    %% ═══════════════════════════════════════════
    %% 11. RECURRENCES
    %% ═══════════════════════════════════════════

    recurrence_schedules {
        int id PK
        enum entity_type
        int entity_id
        int business_id FK
        enum frequency
        decimal default_amount
        char default_currency
        date start_date
        date end_date
        tinyint is_active
        datetime created_at
        datetime updated_at
    }

    recurrence_instances {
        int id PK
        int recurrence_schedule_id FK
        enum entity_type
        int business_id FK
        date due_date
        decimal scheduled_amount
        decimal actual_amount
        char currency
        enum status
        date paid_date
        tinyint notification_sent
        int payment_record_id
        date period_start
        date period_end
        datetime created_at
        datetime updated_at
    }

    %% ═══════════════════════════════════════════
    %% 12. NOTIFICATIONS
    %% ═══════════════════════════════════════════

    notification_types {
        int id PK
        varchar code UK
        varchar name
        varchar description
    }

    notifications {
        int id PK
        int business_id FK
        int notification_type_id FK
        int recipient_id FK
        varchar title
        text message
        enum severity
        varchar reference_table
        int reference_id
        tinyint is_read
        datetime read_at
        datetime created_at
    }

    notification_preferences {
        int id PK
        int user_id FK
        int business_id FK
        int notification_type_id FK
        tinyint enabled
        datetime updated_at
    }

    %% ═══════════════════════════════════════════
    %% 13. ASSETS
    %% ═══════════════════════════════════════════

    asset_categories {
        int id PK
        int tenant_id FK
        int business_id FK
        varchar code
        varchar name
        varchar module_code FK
        tinyint is_system
        datetime created_at
    }

    assets {
        int id PK
        int business_id FK
        int category_id FK
        varchar name
        enum asset_type
        date purchase_date
        decimal purchase_value
        char currency
        decimal exchange_rate
        decimal value_bc
        enum depreciation_method
        int useful_life
        decimal accumulated_depreciation
        decimal current_value_bc
        text notes
        int created_by FK
        datetime created_at
        datetime updated_at
    }

    %% ═══════════════════════════════════════════
    %% 14. SETTINGS & i18n
    %% ═══════════════════════════════════════════

    settings_regional {
        int business_id PK,FK
        varchar language FK
        varchar timezone
        varchar date_format
        varchar number_format
        varchar currency_format
        varchar phone_format
        tinyint audit_enabled
    }

    languages {
        varchar code PK
        varchar name
        enum direction
    }

    translations {
        varchar lang PK,FK
        varchar key PK
        text value
    }

    %% ═══════════════════════════════════════════
    %% 15. AUDIT
    %% ═══════════════════════════════════════════

    audit_logs {
        int id PK
        int tenant_id FK
        int business_id FK
        int user_id FK
        varchar event_type
        varchar table_name
        int record_id
        varchar action
        json old_values
        json new_values
        varchar ip_address
        text user_agent
        datetime created_at
    }

    %% ═══════════════════════════════════════════
    %% RELATIONSHIPS
    %% ═══════════════════════════════════════════

    tenants ||--o{ businesses : "owns"
    tenants ||--o{ business_types : "has"
    tenants ||--o{ people : "has"
    tenants ||--o{ exchange_rates : "has"

    businesses ||--o{ sales_invoices : "issues"
    businesses ||--o{ purchase_orders : "creates"
    businesses ||--o{ payees : "has"
    businesses ||--o{ business_accounts : "has"
    businesses ||--o{ expenses : "records"
    businesses ||--o{ salaries : "pays"
    businesses ||--o{ stock : "tracks"
    businesses ||--o{ assets : "owns"
    businesses ||--o{ production_orders : "runs"
    businesses ||--o{ business_profit : "calculates"
    businesses ||--o{ recurrence_schedules : "schedules"
    businesses ||--o{ collection_slips : "receives"
    businesses ||--o{ payment_slips : "issues"
    businesses ||--o{ notifications : "generates"
    businesses ||--|| settings_regional : "configures"
    businesses ||--o| settings_smtp : "configures"
    businesses }o--|| business_types : "assigned"

    business_types ||--o{ business_type_modules : "activates"
    modules ||--o{ business_type_modules : "included_in"

    users ||--o{ user_roles : "assigned"
    users ||--o{ refresh_tokens : "has"
    users ||--o{ password_reset_tokens : "requests"
    users ||--o{ notifications : "receives"
    users ||--o{ notification_preferences : "sets"
    roles ||--o{ user_roles : "grants"
    roles ||--o{ role_permissions : "has"
    permissions ||--o{ role_permissions : "granted_to"

    people ||--o{ payees : "represented_as"
    people ||--o{ people_types : "classified_as"
    payee_types ||--o{ people_types : "classifies"
    payees ||--o{ sales_invoices : "customer_on"
    payees ||--o{ purchase_orders : "supplier_on"
    payees ||--o{ expenses : "vendor_on"
    payees ||--o{ salaries : "employee_on"
    payees ||--o{ collection_slips : "pays_into"
    payees ||--o{ payment_slips : "paid_from"
    payees ||--o{ partner_shares : "partner_in"
    payees ||--o{ partner_equity : "equity_of"
    payees ||--o{ profit_distributions : "receives"

    items ||--o{ si_details : "sold_on"
    items ||--o{ po_details : "purchased_on"
    items ||--o{ stock : "stocked"
    items ||--o{ stock_transactions : "moved"
    items ||--o{ item_price_history : "price_tracked"
    items ||--o{ item_batches : "batched"
    items ||--o{ recipe_items : "component_of"
    items ||--o{ production_order_items : "consumed_in"
    items }o--|| item_types : "typed_as"

    sales_invoices ||--o{ si_details : "contains"
    sales_invoices ||--o{ credit_notes : "returned_via"
    sales_invoices ||--o{ collection_allocations : "settled_by"

    purchase_orders ||--o{ po_details : "contains"
    purchase_orders ||--o{ debit_notes : "returned_via"
    purchase_orders ||--o{ payment_allocations : "settled_by"

    collection_slips ||--o{ collection_allocations : "allocated_to"
    payment_slips ||--o{ payment_allocations : "allocated_to"

    credit_notes ||--o{ credit_note_items : "contains"
    debit_notes ||--o{ debit_note_items : "contains"

    business_accounts ||--o{ collection_slips : "deposited_to"
    business_accounts ||--o{ payment_slips : "withdrawn_from"

    expense_categories ||--o{ expenses : "categorizes"
    service_categories ||--o{ services : "categorizes"
    asset_categories ||--o{ assets : "categorizes"

    recipes ||--o{ recipe_items : "composed_of"
    recipes ||--o{ production_orders : "produces_via"

    recurrence_schedules ||--o{ recurrence_instances : "generates"

    notification_types ||--o{ notifications : "typed_as"
    notification_types ||--o{ notification_preferences : "configured_for"

    business_profit ||--o{ profit_distributions : "distributed_as"
    profit_distributions }o--|| payment_slips : "paid_via"

    partner_shares }o--|| businesses : "in"
    partner_equity }o--|| businesses : "in"

    item_batches ||--o{ batch_transactions : "tracked_by"

    languages ||--o{ translations : "has"
    languages ||--o{ settings_regional : "used_by"

    services ||--o{ si_details : "sold_on"
```

---

## Table Count Summary

| Domain | Tables | Key Tables |
|--------|--------|-----------|
| Auth & Tenancy | 8 | tenants, users, roles, permissions, user_roles |
| Business Config | 4 | businesses, business_types, modules, business_type_modules |
| People & Contacts | 4 | people, payees, payee_types, people_types |
| Inventory | 8 | items, item_types, stock, stock_transactions, item_batches, batch_transactions, item_price_history, supplier_item_prices |
| Sales | 5 | sales_invoices, si_details, collection_slips, collection_allocations, credit_notes, credit_note_items |
| Purchasing | 5 | purchase_orders, po_details, payment_slips, payment_allocations, debit_notes, debit_note_items |
| Finance | 8 | business_accounts, expenses, expense_categories, salaries, exchange_rates, business_profit, profit_distributions, settings_smtp |
| Partners | 2 | partner_shares, partner_equity |
| Manufacturing | 4 | recipes, recipe_items, production_orders, production_order_items |
| Services | 2 | services, service_categories |
| Recurrences | 2 | recurrence_schedules, recurrence_instances |
| Notifications | 3 | notification_types, notifications, notification_preferences |
| Assets | 2 | assets, asset_categories |
| Settings & i18n | 3 | settings_regional, languages, translations |
| Audit | 1 | audit_logs |
| **TOTAL** | **63** | |
