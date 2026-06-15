-- Migration: Add Business Types & Module Activation System
-- This migration creates the module registry, business types, junction tables,
-- override tables, and seeds all required data for the module activation system.
-- Uses IF NOT EXISTS and INSERT IGNORE for idempotent re-runs.

-- --------------------------------------------------------
-- Table: modules
-- Central registry of all ERP modules
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `modules` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(50) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `icon` VARCHAR(100) DEFAULT NULL,
  `route_prefix` VARCHAR(100) NOT NULL,
  `permission_prefixes` JSON NOT NULL,
  `category` ENUM('core','operations','finance','other') NOT NULL DEFAULT 'other',
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_modules_code` (`code`),
  KEY `idx_modules_sort_order` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table: business_types
-- Predefined business classifications
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `business_types` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(50) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_business_types_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table: business_type_modules
-- Junction table mapping business types to their default modules
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `business_type_modules` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `business_type_id` INT NOT NULL,
  `module_id` INT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bt_modules` (`business_type_id`, `module_id`),
  KEY `idx_btm_module` (`module_id`),
  CONSTRAINT `fk_btm_business_type` FOREIGN KEY (`business_type_id`)
    REFERENCES `business_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_btm_module` FOREIGN KEY (`module_id`)
    REFERENCES `modules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table: business_module_overrides
-- Per-business module activation/deactivation overrides
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `business_module_overrides` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `business_id` INT NOT NULL,
  `module_id` INT NOT NULL,
  `action` ENUM('activate','deactivate') NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` INT NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bmo_business_module` (`business_id`, `module_id`),
  KEY `idx_bmo_module` (`module_id`),
  KEY `idx_bmo_created_by` (`created_by`),
  CONSTRAINT `fk_bmo_business` FOREIGN KEY (`business_id`)
    REFERENCES `businesses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bmo_module` FOREIGN KEY (`module_id`)
    REFERENCES `modules` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bmo_created_by` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Seed: Module definitions (20 modules)
-- --------------------------------------------------------

INSERT IGNORE INTO `modules` (`code`, `name`, `icon`, `route_prefix`, `permission_prefixes`, `category`, `sort_order`) VALUES
('auth',            'Authentication',    'fas fa-key',              'auth',            '["auth."]',            'core',       1),
('users',           'Users',             'fas fa-users',            'users',           '["users."]',           'core',       2),
('businesses',      'Businesses',        'fas fa-briefcase',        'businesses',      '["businesses."]',      'core',       3),
('dashboard',       'Dashboard',         'fas fa-tachometer-alt',   'dashboard',       '["dashboard."]',       'other',      4),
('people',          'People',            'fas fa-address-book',     'people',          '["people."]',          'operations', 5),
('items',           'Items',             'fas fa-boxes',            'items',           '["items."]',           'operations', 6),
('stock',           'Stock',             'fas fa-warehouse',        'stock',           '["stock."]',           'operations', 7),
('purchase_orders', 'Purchase Orders',   'fas fa-shopping-cart',    'purchase-orders', '["po."]',              'operations', 8),
('sales_invoices',  'Sales Invoices',    'fas fa-file-invoice',     'invoices',        '["invoices."]',        'operations', 9),
('manufacturing',   'Manufacturing',     'fas fa-industry',         'manufacturing',   '["manufacturing."]',   'operations', 10),
('accounts',        'Accounts',          'fas fa-university',       'accounts',        '["accounts."]',        'finance',    11),
('expenses',        'Expenses',          'fas fa-receipt',          'expenses',        '["expenses."]',        'finance',    12),
('payments',        'Payments',          'fas fa-money-bill',       'payments',        '["payments."]',        'finance',    13),
('collections',     'Collections',       'fas fa-hand-holding-usd', 'collections',     '["collections."]',     'finance',    14),
('salaries',        'Salaries',          'fas fa-user-tie',         'salaries',        '["salaries."]',        'finance',    15),
('exchange_rates',  'Exchange Rates',    'fas fa-exchange-alt',     'exchange-rates',  '["exchange-rates."]',  'finance',    16),
('activity_log',    'Activity Log',      'fas fa-list',             'activity-log',    '["activityLog."]',     'other',      17),
('settings',        'Settings',          'fas fa-cogs',             'settings',        '["settings."]',        'other',      18),
('notifications',   'Notifications',     'fas fa-bell',             'notifications',   '["notifications."]',   'other',      19),
('roles',           'Roles',             'fas fa-user-shield',      'roles',           '["roles."]',           'other',      20);

-- --------------------------------------------------------
-- Seed: Business type definitions (5 types)
-- --------------------------------------------------------

INSERT IGNORE INTO `business_types` (`id`, `code`, `name`, `description`, `is_default`) VALUES
(1, 'general',       'General',       'All modules active — suitable for any business', 1),
(2, 'retail',        'Retail',        'Retail trade — inventory, sales, purchases',      0),
(3, 'wholesale',     'Wholesale',     'Wholesale trade — bulk inventory and sales',      0),
(4, 'manufacturing', 'Manufacturing', 'Manufacturing — production orders and recipes',   0),
(5, 'service',       'Service',       'Service business — invoicing without inventory',  0);

-- --------------------------------------------------------
-- Seed: Business type module mappings
-- --------------------------------------------------------

-- General (id=1): all 20 modules
INSERT IGNORE INTO `business_type_modules` (`business_type_id`, `module_id`)
SELECT 1, id FROM `modules`;

-- Retail (id=2): 19 modules (excludes manufacturing)
INSERT IGNORE INTO `business_type_modules` (`business_type_id`, `module_id`)
SELECT 2, id FROM `modules`
WHERE `code` IN ('auth','users','businesses','dashboard','people','items','stock',
  'purchase_orders','sales_invoices','accounts','expenses','payments','collections',
  'salaries','exchange_rates','activity_log','settings','notifications','roles');

-- Wholesale (id=3): 19 modules (excludes manufacturing)
INSERT IGNORE INTO `business_type_modules` (`business_type_id`, `module_id`)
SELECT 3, id FROM `modules`
WHERE `code` IN ('auth','users','businesses','dashboard','people','items','stock',
  'purchase_orders','sales_invoices','accounts','expenses','payments','collections',
  'salaries','exchange_rates','activity_log','settings','notifications','roles');

-- Manufacturing (id=4): 20 modules (all)
INSERT IGNORE INTO `business_type_modules` (`business_type_id`, `module_id`)
SELECT 4, id FROM `modules`
WHERE `code` IN ('auth','users','businesses','dashboard','people','items','stock',
  'purchase_orders','sales_invoices','manufacturing','accounts','expenses','payments',
  'collections','salaries','exchange_rates','activity_log','settings','notifications','roles');

-- Service (id=5): 16 modules (excludes items, stock, purchase_orders, manufacturing)
INSERT IGNORE INTO `business_type_modules` (`business_type_id`, `module_id`)
SELECT 5, id FROM `modules`
WHERE `code` IN ('auth','users','businesses','dashboard','people',
  'sales_invoices','accounts','expenses','payments','collections',
  'salaries','exchange_rates','activity_log','settings','notifications','roles');

-- --------------------------------------------------------
-- Alter businesses table: add business_type_id column
-- --------------------------------------------------------

-- Add column with default pointing to "general" type (id=1)
-- Using a procedure to make this idempotent (skip if column already exists)
SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'businesses'
    AND COLUMN_NAME = 'business_type_id'
);

SET @alter_sql = IF(@col_exists = 0,
  'ALTER TABLE `businesses` ADD COLUMN `business_type_id` INT NOT NULL DEFAULT 1 AFTER `currency`',
  'SELECT 1'
);
PREPARE stmt FROM @alter_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add foreign key constraint (skip if already exists)
SET @fk_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'businesses'
    AND CONSTRAINT_NAME = 'fk_businesses_type'
);

SET @fk_sql = IF(@fk_exists = 0,
  'ALTER TABLE `businesses` ADD CONSTRAINT `fk_businesses_type` FOREIGN KEY (`business_type_id`) REFERENCES `business_types` (`id`)',
  'SELECT 1'
);
PREPARE stmt FROM @fk_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Update all existing businesses to general type (id=1)
UPDATE `businesses` SET `business_type_id` = 1 WHERE `business_type_id` = 1;

-- --------------------------------------------------------
-- Seed: settings.modules permission
-- --------------------------------------------------------

INSERT IGNORE INTO `permissions` (`name`, `code`) VALUES
('modules settings view', 'settings.modules');

-- Assign to business_owner role (role_id = 1)
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 1, p.`id`
FROM `permissions` p
WHERE p.`code` = 'settings.modules'
  AND p.`id` NOT IN (
    SELECT rp.`permission_id`
    FROM `role_permissions` rp
    WHERE rp.`role_id` = 1
  );
