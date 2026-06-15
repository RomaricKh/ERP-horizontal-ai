-- ============================================================================
-- ERP Database Cleanup Script
-- Purpose: Reset the database to a clean state for testing all features
-- Preserves: System seed data (modules, permissions, languages, translations,
--            business_types, business_type_modules, notification_types)
-- Creates: A fresh tenant, admin user, and business ready for testing
-- ============================================================================
-- USAGE:
--   mysql -u root erp < database/clean_for_testing.sql
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_SAFE_UPDATES = 0;

-- ============================================================================
-- PHASE 1: Delete all transactional/user data (leaf tables first)
-- ============================================================================

-- Notifications
TRUNCATE TABLE notifications;
TRUNCATE TABLE notification_preferences;

-- Audit & Activity
TRUNCATE TABLE audit_logs;

-- Manufacturing
TRUNCATE TABLE production_order_items;
TRUNCATE TABLE production_orders;
TRUNCATE TABLE recipe_items;
TRUNCATE TABLE recipes;

-- Stock & Inventory
TRUNCATE TABLE batch_transactions;
TRUNCATE TABLE item_batches;
TRUNCATE TABLE stock_transactions;
TRUNCATE TABLE stock;
TRUNCATE TABLE item_price_history;
TRUNCATE TABLE item_prices;
TRUNCATE TABLE supplier_item_prices;

-- Sales
TRUNCATE TABLE si_details;
TRUNCATE TABLE sales_invoices;
TRUNCATE TABLE collection_slips;

-- Purchases
TRUNCATE TABLE po_details;
TRUNCATE TABLE purchase_orders;
TRUNCATE TABLE payment_slips;

-- Finance
TRUNCATE TABLE expenses;
TRUNCATE TABLE expense_categories;
TRUNCATE TABLE salaries;
TRUNCATE TABLE partner_equity;
TRUNCATE TABLE partner_shares;
TRUNCATE TABLE business_profit;
TRUNCATE TABLE exchange_rates;

-- Services
TRUNCATE TABLE services;
TRUNCATE TABLE service_categories;

-- Assets
TRUNCATE TABLE assets;

-- Recurrences
TRUNCATE TABLE recurrence_instances;
TRUNCATE TABLE recurrence_schedules;

-- Items & Types
TRUNCATE TABLE items;
TRUNCATE TABLE item_types;

-- People & Payees
TRUNCATE TABLE payees;
TRUNCATE TABLE payee_types;
TRUNCATE TABLE people;

-- Accounts
TRUNCATE TABLE business_accounts;

-- Settings
TRUNCATE TABLE settings_regional;

-- Auth & Users
TRUNCATE TABLE refresh_tokens;
TRUNCATE TABLE user_roles;
TRUNCATE TABLE role_permissions;
TRUNCATE TABLE roles;
TRUNCATE TABLE tenant_owners;

-- Core entities
TRUNCATE TABLE businesses;
TRUNCATE TABLE users;
TRUNCATE TABLE tenants;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- PHASE 2: Seed fresh test data
-- ============================================================================

-- 2.1 Create test tenant
INSERT INTO tenants (id, name, base_currency, created_at)
VALUES (1, 'Test Company', 'USD', NOW());

-- 2.2 Create admin user (password: "admin123")
INSERT INTO users (id, username, password_hash, password_reset, first_name, last_name, email, failed_attempts, created_at)
VALUES (1, 'admin', '$2y$10$3oiaRvv2x1W55GYcdVByiOKCGHbnhCioJtbWxD0cY3xFGn.RmYn16', 0, 'Admin', 'User', 'admin@test.com', 0, NOW());

-- 2.3 Create a second test user (password: "user123")
INSERT INTO users (id, username, password_hash, password_reset, first_name, last_name, email, failed_attempts, created_at)
VALUES (2, 'testuser', '$2y$10$sJcGGDhLR6180ldU.ZLrMuFdaUJzmsACKxdA1pCOYwR4rWXyBSdO2', 0, 'Test', 'User', 'user@test.com', 0, NOW());

-- 2.4 Register admin as tenant owner
INSERT INTO tenant_owners (id, tenant_id, user_id, created_at, created_by)
VALUES (1, 1, 1, NOW(), 1);

-- 2.5 Create a test business (General type = all modules)
INSERT INTO businesses (id, tenant_id, name, business_type_id, currency, created_at)
VALUES (1, 1, 'Main Business', 1, 'USD', NOW());

-- 2.6 Create admin role with all permissions
INSERT INTO roles (id, tenant_id, name, description, type, business_id, created_at)
VALUES (1, 1, 'Administrator', 'Full access to all features', 'SYSTEM_OWNER', 1, NOW());

-- Assign ALL permissions to the admin role
INSERT INTO role_permissions (role_id, permission_id)
SELECT 1, id FROM permissions;

-- 2.7 Create a limited "Staff" role for testing permission restrictions
INSERT INTO roles (id, tenant_id, name, description, type, business_id, created_at)
VALUES (2, 1, 'Staff', 'Limited access for regular staff', 'CUSTOM', 1, NOW());

-- Staff gets view-only permissions + basic create
INSERT INTO role_permissions (role_id, permission_id)
SELECT 2, id FROM permissions
WHERE code IN (
    'dashboard.view', 'profile.view',
    'people.view', 'people.create',
    'items.view',
    'stock.view',
    'po.view', 'po.create',
    'invoices.view', 'invoices.create',
    'expenses.view',
    'payments.view',
    'collections.view',
    'notifications.view',
    'manufacturing.view',
    'services.view'
);

-- 2.8 Assign roles to users
INSERT INTO user_roles (user_id, role_id, business_id, is_active)
VALUES
    (1, 1, 1, 1),  -- admin gets Administrator role
    (2, 2, 1, 1);  -- testuser gets Staff role

-- 2.9 Regional settings for the business
INSERT INTO settings_regional (business_id, language, timezone, date_format, number_format, currency_format, phone_format, audit_enabled)
VALUES (1, 'en', 'UTC', 'YYYY-MM-DD', '1,234.56', '$#,##0.00', '+1 (###) ###-####', 1);

-- 2.10 Create default business accounts
INSERT INTO business_accounts (id, business_id, name, currency, balance, balance_fc, created_at)
VALUES
    (1, 1, 'Main Cash', 'USD', 0.00, 0.00, NOW()),
    (2, 1, 'Bank Account', 'USD', 0.00, 0.00, NOW());

-- 2.11 Create default payee types
INSERT INTO payee_types (id, tenant_id, business_id, name, created_at)
VALUES
    (1, 1, 1, 'Customer', NOW()),
    (2, 1, 1, 'Supplier', NOW()),
    (3, 1, 1, 'Employee', NOW());

-- 2.12 Create default item types
INSERT INTO item_types (id, tenant_id, business_id, name, created_at, created_by)
VALUES
    (1, 1, 1, 'Raw Material', NOW(), 1),
    (2, 1, 1, 'Finished Good', NOW(), 1),
    (3, 1, 1, 'Service Item', NOW(), 1);

-- 2.13 Set a default exchange rate (USD base)
INSERT INTO exchange_rates (id, tenant_id, business_id, currency, rate_date, rate, source, created_at)
VALUES (1, 1, 1, 'USD', CURDATE(), 1.000000, 'Manual', NOW());

SET SQL_SAFE_UPDATES = 1;

-- ============================================================================
-- VERIFICATION: Show what was created
-- ============================================================================
SELECT '=== Database cleaned and seeded successfully ===' AS status;
SELECT CONCAT('Tenant: ', name, ' (ID: ', id, ')') AS tenants FROM tenants;
SELECT CONCAT('User: ', username, ' / ', first_name, ' ', last_name) AS users FROM users;
SELECT CONCAT('Business: ', name, ' (Type: General)') AS businesses FROM businesses;
SELECT CONCAT('Roles: ', COUNT(*), ' created') AS roles FROM roles;
SELECT CONCAT('Permissions assigned: ', COUNT(*), ' total role-permission mappings') AS role_perms FROM role_permissions;
SELECT CONCAT('Accounts: ', COUNT(*), ' business accounts') AS accounts FROM business_accounts;
SELECT CONCAT('Payee Types: ', COUNT(*)) AS payee_types FROM payee_types;
SELECT CONCAT('Item Types: ', COUNT(*)) AS item_types FROM item_types;

-- ============================================================================
-- LOGIN CREDENTIALS FOR TESTING:
-- ============================================================================
-- | Username  | Password  | Role          | Access              |
-- |-----------|-----------|---------------|---------------------|
-- | admin     | admin123  | Administrator | Full access         |
-- | testuser  | user123   | Staff         | View + basic create |
-- ============================================================================
