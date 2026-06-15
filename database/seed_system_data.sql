-- ============================================================================
-- ERP Horizontal — System/Seed Data
-- ============================================================================
-- Run this AFTER schema.sql to populate system lookup tables.
-- This script is idempotent — uses INSERT IGNORE to skip existing rows.
--
-- Usage:
--   mysql -u root erp < database/seed_system_data.sql
--
-- Contents:
--   1. Modules
--   2. Permissions
--   3. System Business Types + Module Assignments
--   4. Notification Types
--   5. Languages
-- ============================================================================

SET NAMES utf8mb4;

-- ============================================================================
-- 1. MODULES
-- ============================================================================

INSERT IGNORE INTO `modules` (`id`, `code`, `name`, `icon`, `route_prefix`, `permission_prefixes`, `category`, `sort_order`, `created_at`) VALUES
(1,  'auth',            'Authentication',  'fas fa-key',              'auth',            '["auth."]',           'core',       1,  NOW()),
(2,  'users',           'Users',           'fas fa-users',            'users',           '["users."]',          'core',       2,  NOW()),
(3,  'businesses',      'Businesses',      'fas fa-briefcase',        'businesses',      '["businesses."]',     'core',       3,  NOW()),
(4,  'dashboard',       'Dashboard',       'fas fa-tachometer-alt',   'dashboard',       '["dashboard."]',      'other',      4,  NOW()),
(5,  'people',          'People',          'fas fa-address-book',     'people',          '["people."]',         'operations', 5,  NOW()),
(6,  'items',           'Items',           'fas fa-boxes',            'items',           '["items."]',          'operations', 6,  NOW()),
(7,  'stock',           'Stock',           'fas fa-warehouse',        'stock',           '["stock."]',          'operations', 7,  NOW()),
(8,  'purchase_orders', 'Purchase Orders', 'fas fa-shopping-cart',    'purchase-orders', '["po."]',             'operations', 8,  NOW()),
(9,  'sales_invoices',  'Sales Invoices',  'fas fa-file-invoice',     'invoices',        '["invoices."]',       'operations', 9,  NOW()),
(10, 'manufacturing',   'Manufacturing',   'fas fa-industry',         'manufacturing',   '["manufacturing."]',  'operations', 10, NOW()),
(11, 'accounts',        'Accounts',        'fas fa-university',       'accounts',        '["accounts."]',       'finance',    11, NOW()),
(12, 'expenses',        'Expenses',        'fas fa-receipt',          'expenses',        '["expenses."]',       'finance',    12, NOW()),
(13, 'payments',        'Payments',        'fas fa-money-bill',       'payments',        '["payments."]',       'finance',    13, NOW()),
(14, 'collections',     'Collections',     'fas fa-hand-holding-usd', 'collections',     '["collections."]',    'finance',    14, NOW()),
(15, 'salaries',        'Salaries',        'fas fa-user-tie',         'salaries',        '["salaries."]',       'finance',    15, NOW()),
(16, 'exchange_rates',  'Exchange Rates',  'fas fa-exchange-alt',     'exchange-rates',  '["exchange-rates."]', 'finance',    16, NOW()),
(17, 'activity_log',    'Activity Log',    'fas fa-list',             'activity-log',    '["activityLog."]',    'other',      17, NOW()),
(18, 'settings',        'Settings',        'fas fa-cogs',             'settings',        '["settings."]',       'other',      18, NOW()),
(19, 'notifications',   'Notifications',   'fas fa-bell',             'notifications',   '["notifications."]',  'other',      19, NOW()),
(20, 'roles',           'Roles',           'fas fa-user-shield',      'roles',           '["roles."]',          'other',      20, NOW()),
(41, 'services',        'Services',        'fas fa-concierge-bell',   'services',        '["services."]',       'operations', 21, NOW()),
(43, 'partners',        'Partners',        'fas fa-handshake',        'partner-equity',  '["partners."]',       'finance',    55, NOW()),
(44, 'assets',          'Assets',          'fas fa-landmark',         'assets',          '["assets."]',         'finance',    54, NOW());

-- ============================================================================
-- 2. PERMISSIONS
-- ============================================================================

INSERT IGNORE INTO `permissions` (`id`, `name`, `code`, `created_at`) VALUES
(1,   'dashboard view',        'dashboard.view',        NOW()),
(4,   'profile view',          'profile.view',          NOW()),
(5,   'settings view',         'settings.view',         NOW()),
(6,   'activityLog view',      'activityLog.view',      NOW()),
(7,   'users management',      'users.view',            NOW()),
(8,   'user edit',             'users.edit',            NOW()),
(9,   'user delete',           'users.delete',          NOW()),
(10,  'user create',           'users.create',          NOW()),
(11,  'businesses manage',     'businesses.view',       NOW()),
(12,  'business create',       'businesses.create',     NOW()),
(13,  'business edit',         'businesses.edit',       NOW()),
(14,  'business delete',       'businesses.delete',     NOW()),
(15,  'people view',           'people.view',           NOW()),
(16,  'people create',         'people.create',         NOW()),
(17,  'people edit',           'people.edit',           NOW()),
(18,  'people delete',         'people.delete',         NOW()),
(19,  'items view',            'items.view',            NOW()),
(20,  'items create',          'items.create',          NOW()),
(21,  'items edit',            'items.edit',            NOW()),
(22,  'items delete',          'items.delete',          NOW()),
(23,  'item types manage',     'item-types.manage',     NOW()),
(24,  'stock view',            'stock.view',            NOW()),
(25,  'stock adjust',          'stock.adjust',          NOW()),
(26,  'po view',               'po.view',               NOW()),
(27,  'po create',             'po.create',             NOW()),
(28,  'po edit',               'po.edit',               NOW()),
(29,  'po delete',             'po.delete',             NOW()),
(30,  'po approve',            'po.approve',            NOW()),
(31,  'po receive',            'po.receive',            NOW()),
(32,  'invoices view',         'invoices.view',         NOW()),
(33,  'invoices create',       'invoices.create',       NOW()),
(34,  'invoices edit',         'invoices.edit',         NOW()),
(35,  'invoices delete',       'invoices.delete',       NOW()),
(36,  'invoices approve',      'invoices.approve',      NOW()),
(37,  'invoices pay',          'invoices.pay',          NOW()),
(38,  'expenses view',         'expenses.view',         NOW()),
(39,  'expenses create',       'expenses.create',       NOW()),
(40,  'expenses edit',         'expenses.edit',         NOW()),
(41,  'expenses delete',       'expenses.delete',       NOW()),
(42,  'expenses manage',       'expenses.manage',       NOW()),
(43,  'salaries view',         'salaries.view',         NOW()),
(44,  'salaries create',       'salaries.create',       NOW()),
(45,  'salaries edit',         'salaries.edit',         NOW()),
(46,  'salaries delete',       'salaries.delete',       NOW()),
(47,  'accounts manage',       'accounts.manage',       NOW()),
(48,  'exchange rates manage', 'exchange-rates.manage', NOW()),
(49,  'payments view',         'payments.view',         NOW()),
(50,  'payments create',       'payments.create',       NOW()),
(51,  'payments delete',       'payments.delete',       NOW()),
(52,  'collections view',      'collections.view',      NOW()),
(53,  'collections create',    'collections.create',    NOW()),
(54,  'collections delete',    'collections.delete',    NOW()),
(99,  'manufacturing view',    'manufacturing.view',    NOW()),
(100, 'manufacturing create',  'manufacturing.create',  NOW()),
(101, 'manufacturing edit',    'manufacturing.edit',    NOW()),
(102, 'manufacturing delete',  'manufacturing.delete',  NOW()),
(103, 'roles view',            'roles.view',            NOW()),
(104, 'roles create',          'roles.create',          NOW()),
(105, 'roles edit',            'roles.edit',            NOW()),
(106, 'roles delete',          'roles.delete',          NOW()),
(107, 'modules settings view', 'settings.modules',      NOW()),
(166, 'notifications view',    'notifications.view',    NOW()),
(167, 'notifications send',    'notifications.send',    NOW()),
(170, 'assets manage',         'assets.manage',         NOW()),
(171, 'partners manage',       'partners.manage',       NOW()),
(172, 'profit manage',         'profit.manage',         NOW()),
(173, 'services view',         'services.view',         NOW()),
(174, 'services create',       'services.create',       NOW()),
(175, 'services edit',         'services.edit',         NOW()),
(176, 'services delete',       'services.delete',       NOW());

-- ============================================================================
-- 3. SYSTEM BUSINESS TYPES
-- ============================================================================

INSERT IGNORE INTO `business_types` (`id`, `tenant_id`, `code`, `name`, `description`, `is_system`, `is_default`, `created_at`) VALUES
(1, NULL, 'general',       'General',       'All modules active — suitable for any business', 1, 1, NOW()),
(2, NULL, 'retail',        'Retail',        'Retail trade — inventory, sales, purchases',     1, 0, NOW()),
(3, NULL, 'wholesale',     'Wholesale',     'Wholesale trade — bulk inventory and sales',     1, 0, NOW()),
(4, NULL, 'manufacturing', 'Manufacturing', 'Manufacturing — production orders and recipes',  1, 0, NOW()),
(5, NULL, 'service',       'Service',       'Service business — invoicing without inventory', 1, 0, NOW());

-- Business Type Module Assignments (system types)
-- General (id=1): ALL modules
INSERT IGNORE INTO `business_type_modules` (`business_type_id`, `module_id`, `created_at`)
SELECT 1, id, NOW() FROM `modules`;

-- Retail (id=2): All except manufacturing (10)
INSERT IGNORE INTO `business_type_modules` (`business_type_id`, `module_id`, `created_at`)
SELECT 2, id, NOW() FROM `modules` WHERE id NOT IN (10);

-- Wholesale (id=3): All except manufacturing (10)
INSERT IGNORE INTO `business_type_modules` (`business_type_id`, `module_id`, `created_at`)
SELECT 3, id, NOW() FROM `modules` WHERE id NOT IN (10);

-- Manufacturing (id=4): ALL modules
INSERT IGNORE INTO `business_type_modules` (`business_type_id`, `module_id`, `created_at`)
SELECT 4, id, NOW() FROM `modules`;

-- Service (id=5): No items(6), stock(7), purchase_orders(8), manufacturing(10)
INSERT IGNORE INTO `business_type_modules` (`business_type_id`, `module_id`, `created_at`)
SELECT 5, id, NOW() FROM `modules` WHERE id NOT IN (6, 7, 8, 10);

-- ============================================================================
-- 4. NOTIFICATION TYPES
-- ============================================================================

INSERT IGNORE INTO `notification_types` (`id`, `code`, `description`) VALUES
(1, 'low_stock',       'Triggered when item stock falls below min_stock'),
(2, 'overdue_payment', 'Triggered when a recurrence instance becomes overdue'),
(3, 'expiry_alert',    'Triggered when a batch is near expiry'),
(4, 'invoice_overdue', 'Triggered when a sales invoice passes its due date'),
(5, 'system',          'General system messages'),
(6, 'EXPENSE_DUE',     'Recurring expense payment coming due'),
(7, 'SALARY_DUE',      'Recurring salary payment coming due'),
(8, 'EXPENSE_OVERDUE', 'Recurring expense payment is overdue'),
(9, 'SALARY_OVERDUE',  'Recurring salary payment is overdue');

-- ============================================================================
-- 5. LANGUAGES
-- ============================================================================

INSERT IGNORE INTO `languages` (`code`, `name`, `direction`) VALUES
('en', 'English', 'LTR'),
('ar', 'Arabic',  'RTL'),
('fr', 'French',  'LTR');

-- ============================================================================
-- NOTE: Translations (572 rows) are loaded separately from the application's
-- i18n system. The translations table is populated on first-run by the
-- application or can be imported from a translations export file.
--
-- System types for payee_types, item_types, expense_categories,
-- service_categories, and asset_categories are GLOBAL (tenant_id = NULL,
-- business_id = NULL, is_system = 1). They are visible to all tenants and
-- businesses, filtered by module_id (FK to modules) for module-gated visibility.
-- User-defined types are scoped to a specific tenant + business.
-- ============================================================================
