-- ============================================================================
-- ERP Horizontal — Full Database Schema
-- ============================================================================
-- This is the FINAL production-ready schema for the ERP system.
-- It creates all tables from scratch in the correct dependency order.
-- 
-- Usage:
--   mysql -u root -e "CREATE DATABASE IF NOT EXISTS erp CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
--   mysql -u root erp < database/schema.sql
--
-- After running this script, run database/seed_system_data.sql to insert
-- system/seed data (modules, permissions, business types, etc.)
--
-- Generated from the live database after all migrations have been applied.
-- Matches the target ERD in docs/erd-target-state.md
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- ============================================================================
-- 1. MULTI-TENANCY & AUTH
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tenants` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `base_currency` varchar(3) NOT NULL DEFAULT 'USD',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `password_hash` varchar(355) NOT NULL,
  `first_name` varchar(150) NOT NULL,
  `last_name` varchar(150) NOT NULL,
  `email` varchar(150) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `failed_attempts` int DEFAULT '0',
  `lock_until` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `token_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_prt_user_id` (`user_id`),
  KEY `idx_prt_token_hash` (`token_hash`),
  KEY `idx_prt_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `refresh_tokens` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `device_id` char(36) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_device` (`user_id`,`device_id`),
  KEY `idx_token_hash` (`token_hash`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `permissions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `code` varchar(150) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ============================================================================
-- 2. BUSINESS & MODULE CONFIGURATION
-- ============================================================================

CREATE TABLE IF NOT EXISTS `modules` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `name` varchar(150) NOT NULL,
  `icon` varchar(100) DEFAULT NULL,
  `route_prefix` varchar(100) NOT NULL,
  `permission_prefixes` json NOT NULL,
  `category` enum('core','operations','finance','other') NOT NULL DEFAULT 'other',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_modules_code` (`code`),
  KEY `idx_modules_sort_order` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `business_types` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int DEFAULT NULL,
  `code` varchar(50) NOT NULL,
  `name` varchar(150) NOT NULL,
  `description` text,
  `is_system` tinyint(1) NOT NULL DEFAULT '0',
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_business_types_tenant_name` (`tenant_id`,`name`),
  UNIQUE KEY `uq_bt_code_tenant` (`code`,`tenant_id`),
  CONSTRAINT `fk_business_types_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `business_type_modules` (
  `id` int NOT NULL AUTO_INCREMENT,
  `business_type_id` int NOT NULL,
  `module_id` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bt_modules` (`business_type_id`,`module_id`),
  KEY `idx_btm_module` (`module_id`),
  CONSTRAINT `fk_btm_business_type` FOREIGN KEY (`business_type_id`) REFERENCES `business_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_btm_module` FOREIGN KEY (`module_id`) REFERENCES `modules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `businesses` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `name` varchar(150) NOT NULL,
  `legal_name` varchar(200) DEFAULT NULL,
  `registration_no` varchar(100) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `currency` varchar(10) NOT NULL DEFAULT 'USD',
  `business_type_id` int NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_businesses_tenant` (`tenant_id`),
  KEY `fk_businesses_type` (`business_type_id`),
  CONSTRAINT `fk_businesses_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `fk_businesses_type` FOREIGN KEY (`business_type_id`) REFERENCES `business_types` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `roles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `type` enum('SYSTEM_OWNER','CUSTOM','TEMPLATE','TENANT_OWNER') NOT NULL DEFAULT 'CUSTOM',
  `business_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_business_name` (`business_id`,`name`),
  CONSTRAINT `fk_roles_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_roles_business_scope` CHECK ((((`type` in ('SYSTEM_OWNER','CUSTOM')) and (`business_id` is not null)) or ((`type` in ('TEMPLATE','TENANT_OWNER')) and (`business_id` is null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `role_permissions` (
  `role_id` int NOT NULL,
  `permission_id` int NOT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `fk_role_permission_permission` (`permission_id`),
  CONSTRAINT `fk_role_permission_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_role_permission_roles` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `user_roles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `role_id` int NOT NULL,
  `business_id` int DEFAULT NULL,
  `tenant_id` int NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_role_business` (`user_id`,`role_id`,`business_id`,`tenant_id`),
  KEY `fk_ur_business` (`business_id`),
  KEY `fk_ur_tenant` (`tenant_id`),
  KEY `fk_ur_role` (`role_id`),
  CONSTRAINT `fk_ur_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`),
  CONSTRAINT `fk_ur_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_ur_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_ur_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ============================================================================
-- 3. PEOPLE & CONTACTS
-- ============================================================================

CREATE TABLE IF NOT EXISTS `people` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `name` varchar(150) NOT NULL,
  `phone` varchar(50) NOT NULL,
  `normalized_phone` varchar(50) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `tax_number` varchar(100) DEFAULT NULL,
  `iban` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_people_normalized_phone_tenant` (`tenant_id`,`normalized_phone`),
  CONSTRAINT `fk_people_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `payee_types` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `business_id` int DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT '0',
  `module_code` varchar(50) DEFAULT NULL,
  `code` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_payee_types_tenant` (`tenant_id`),
  KEY `fk_payee_types_business` (`business_id`),
  CONSTRAINT `fk_payee_types_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_payee_types_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `people_types` (
  `id` int NOT NULL AUTO_INCREMENT,
  `people_id` int NOT NULL,
  `payee_type_id` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_people_type` (`people_id`,`payee_type_id`),
  KEY `idx_people_types_people` (`people_id`),
  KEY `idx_people_types_type` (`payee_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `payees` (
  `id` int NOT NULL AUTO_INCREMENT,
  `business_id` int NOT NULL,
  `people_id` int NOT NULL,
  `balance` decimal(12,2) NOT NULL DEFAULT '0.00',
  `notes` text,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `business_id` (`business_id`,`people_id`),
  KEY `fk_payees_people` (`people_id`),
  KEY `fk_payees_business` (`business_id`),
  CONSTRAINT `fk_payees_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_payees_people` FOREIGN KEY (`people_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ============================================================================
-- 4. INVENTORY & ITEMS
-- ============================================================================

CREATE TABLE IF NOT EXISTS `item_types` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `business_id` int DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT '0',
  `module_code` varchar(50) DEFAULT NULL,
  `code` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_item_types_tenant` (`tenant_id`),
  KEY `fk_item_types_business` (`business_id`),
  CONSTRAINT `fk_item_types_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_item_types_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `business_id` int DEFAULT NULL,
  `customer_id` int DEFAULT NULL,
  `item_type_id` int NOT NULL,
  `name` varchar(150) NOT NULL,
  `sku` varchar(100) DEFAULT NULL,
  `unit` varchar(50) DEFAULT NULL,
  `cost_price` decimal(12,2) DEFAULT NULL,
  `sale_price` decimal(12,2) DEFAULT NULL,
  `min_stock` decimal(12,2) DEFAULT NULL,
  `markup_percent` decimal(5,2) DEFAULT NULL,
  `markup_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `has_expiry` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) DEFAULT '1',
  `notes` text,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `has_cost_change_flag` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_items_tenant_sku` (`tenant_id`,`sku`),
  KEY `fk_items_business` (`business_id`),
  KEY `fk_items_tenant` (`tenant_id`),
  KEY `fk_items_item_types` (`item_type_id`),
  KEY `idx_items_customer_id` (`customer_id`),
  CONSTRAINT `fk_items_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_items_customer` FOREIGN KEY (`customer_id`) REFERENCES `people` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_items_item_types` FOREIGN KEY (`item_type_id`) REFERENCES `item_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_items_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `stock` (
  `id` int NOT NULL AUTO_INCREMENT,
  `business_id` int NOT NULL,
  `item_id` int NOT NULL,
  `quantity` decimal(12,3) NOT NULL DEFAULT '0.000',
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `business_id` (`business_id`,`item_id`),
  KEY `fk_stock_item` (`item_id`),
  KEY `fk_stock_business` (`business_id`),
  CONSTRAINT `fk_stock_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_stock_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `stock_transactions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `business_id` int NOT NULL,
  `item_id` int NOT NULL,
  `trx_date` datetime NOT NULL,
  `type` enum('IN','OUT','ADJ') NOT NULL DEFAULT 'IN',
  `reference_type` enum('PURCHASE_ORDER','SALES_INVOICE','PRODUCTION','MANUAL','CREDIT_NOTE','DEBIT_NOTE') DEFAULT NULL,
  `reference_id` int DEFAULT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `notes` text,
  `created_at` datetime NOT NULL,
  `created_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_stock_transactions_item` (`item_id`),
  KEY `fk_stock_transactions_business` (`business_id`),
  KEY `fk_st_created_by` (`created_by`),
  CONSTRAINT `fk_st_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_stock_transactions_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_stock_transactions_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `item_price_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `item_id` int NOT NULL,
  `tenant_id` int NOT NULL,
  `price_type` enum('COST','SALE') NOT NULL,
  `old_price` decimal(15,4) DEFAULT NULL,
  `new_price` decimal(15,4) NOT NULL,
  `effective_date` date NOT NULL,
  `source_type` enum('INITIAL','PURCHASE_ORDER','MANUAL_EDIT','MANUFACTURING') NOT NULL,
  `source_id` int DEFAULT NULL,
  `changed_by` int unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_item_tenant` (`item_id`,`tenant_id`),
  KEY `idx_effective_date` (`effective_date`),
  KEY `idx_source` (`source_type`,`source_id`),
  KEY `fk_iph_tenant` (`tenant_id`),
  CONSTRAINT `fk_iph_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_iph_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `item_batches` (
  `id` int NOT NULL AUTO_INCREMENT,
  `item_id` int NOT NULL,
  `business_id` int NOT NULL,
  `batch_number` varchar(100) NOT NULL,
  `quantity` decimal(15,4) NOT NULL DEFAULT '0.0000',
  `manufacturing_date` date DEFAULT NULL,
  `expiry_date` date NOT NULL,
  `purchase_order_id` int DEFAULT NULL,
  `status` enum('active','expired','written_off') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_batch_number` (`item_id`,`business_id`,`batch_number`),
  KEY `fk_batch_business` (`business_id`),
  KEY `fk_batch_po` (`purchase_order_id`),
  KEY `idx_batch_fefo` (`item_id`,`business_id`,`status`,`expiry_date`),
  CONSTRAINT `fk_batch_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`),
  CONSTRAINT `fk_batch_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`),
  CONSTRAINT `fk_batch_po` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `batch_transactions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `batch_id` int NOT NULL,
  `transaction_type` enum('IN','OUT','WRITE_OFF') NOT NULL,
  `quantity` decimal(15,4) NOT NULL,
  `reference` varchar(255) DEFAULT NULL,
  `notes` text,
  `created_at` datetime NOT NULL,
  `created_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_btx_batch` (`batch_id`),
  KEY `fk_bt_created_by` (`created_by`),
  CONSTRAINT `fk_bt_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_btx_batch` FOREIGN KEY (`batch_id`) REFERENCES `item_batches` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `supplier_item_prices` (
  `id` int NOT NULL AUTO_INCREMENT,
  `supplier_id` int NOT NULL,
  `item_id` int NOT NULL,
  `tenant_id` int NOT NULL,
  `last_price` decimal(15,4) NOT NULL,
  `avg_price` decimal(15,4) NOT NULL,
  `min_price` decimal(15,4) NOT NULL,
  `max_price` decimal(15,4) NOT NULL,
  `last_purchase_date` date NOT NULL,
  `purchase_count` int NOT NULL DEFAULT '1',
  `is_preferred` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_supplier_item_tenant` (`supplier_id`,`item_id`,`tenant_id`),
  KEY `idx_item_tenant` (`item_id`,`tenant_id`),
  KEY `fk_sip_tenant` (`tenant_id`),
  CONSTRAINT `fk_sip_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sip_supplier_payee` FOREIGN KEY (`supplier_id`) REFERENCES `payees` (`id`),
  CONSTRAINT `fk_sip_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ============================================================================
-- 5. SALES
-- ============================================================================

CREATE TABLE IF NOT EXISTS `sales_invoices` (
  `id` int NOT NULL AUTO_INCREMENT,
  `business_id` int NOT NULL,
  `customer_id` int NOT NULL,
  `invoice_number` varchar(100) NOT NULL,
  `invoice_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `status` enum('DRAFT','DELIVERED','PAID','CANCELLED') NOT NULL DEFAULT 'DRAFT',
  `currency` varchar(10) NOT NULL,
  `exchange_rate` decimal(12,6) NOT NULL,
  `total_amount_fc` decimal(12,2) DEFAULT '0.00',
  `total_amount_bc` decimal(12,2) DEFAULT '0.00',
  `tax_total_fc` decimal(12,2) NOT NULL DEFAULT '0.00',
  `tax_total_bc` decimal(12,2) NOT NULL DEFAULT '0.00',
  `discount_percent` decimal(5,2) NOT NULL DEFAULT '0.00',
  `notes` text,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_sales_invoices_customer` (`customer_id`),
  KEY `fk_sales_invoices_business` (`business_id`),
  CONSTRAINT `fk_sales_invoices_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_sales_invoices_customer` FOREIGN KEY (`customer_id`) REFERENCES `payees` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `si_details` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sales_invoice_id` int NOT NULL,
  `item_id` int DEFAULT NULL,
  `service_id` int DEFAULT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `unit_price_fc` decimal(12,2) NOT NULL,
  `unit_price_bc` decimal(12,2) NOT NULL,
  `total_price_fc` decimal(12,2) NOT NULL,
  `total_price_bc` decimal(12,2) NOT NULL,
  `tax_rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `tax_amount_fc` decimal(12,2) NOT NULL DEFAULT '0.00',
  `tax_amount_bc` decimal(12,2) NOT NULL DEFAULT '0.00',
  `discount_percent` decimal(5,2) NOT NULL DEFAULT '0.00',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_si_details_item` (`item_id`),
  KEY `fk_si_details_service` (`service_id`),
  KEY `fk_si_details_invoice` (`sales_invoice_id`),
  CONSTRAINT `fk_si_details_invoice` FOREIGN KEY (`sales_invoice_id`) REFERENCES `sales_invoices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_si_details_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_si_details_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `chk_si_details_item_or_service` CHECK ((((`item_id` is not null) and (`service_id` is null)) or ((`item_id` is null) and (`service_id` is not null))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `collection_slips` (
  `id` int NOT NULL AUTO_INCREMENT,
  `business_id` int NOT NULL,
  `account_id` int NOT NULL,
  `payee_id` int NOT NULL,
  `slip_number` varchar(100) NOT NULL,
  `slip_date` date NOT NULL,
  `currency` varchar(10) NOT NULL,
  `exchange_rate` decimal(12,6) NOT NULL,
  `amount_fc` decimal(12,2) NOT NULL,
  `amount_bc` decimal(12,2) NOT NULL,
  `method` enum('CASH','BANK','CHECK','TRANSFER') NOT NULL,
  `notes` text,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_collection_slips_date` (`slip_date`),
  KEY `fk_collection_slips_payee` (`payee_id`),
  KEY `fk_collection_slips_account` (`account_id`),
  KEY `fk_collection_slips_business` (`business_id`),
  CONSTRAINT `fk_collection_slips_account` FOREIGN KEY (`account_id`) REFERENCES `business_accounts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_collection_slips_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_collection_slips_payee` FOREIGN KEY (`payee_id`) REFERENCES `payees` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `collection_allocations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `collection_slip_id` int NOT NULL,
  `reference_type` enum('SALES_INVOICE') NOT NULL,
  `reference_id` int NOT NULL,
  `amount_fc` decimal(12,2) NOT NULL,
  `amount_bc` decimal(12,2) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ca_slip` (`collection_slip_id`),
  KEY `idx_ca_ref` (`reference_type`,`reference_id`),
  CONSTRAINT `fk_ca_collection_slip` FOREIGN KEY (`collection_slip_id`) REFERENCES `collection_slips` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `credit_notes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `business_id` int NOT NULL,
  `sales_invoice_id` int NOT NULL,
  `credit_note_number` varchar(100) NOT NULL,
  `credit_note_date` date NOT NULL,
  `status` enum('DRAFT','ISSUED') NOT NULL DEFAULT 'DRAFT',
  `currency` varchar(10) NOT NULL,
  `exchange_rate` decimal(12,6) NOT NULL DEFAULT '1.000000',
  `total_amount_fc` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total_amount_bc` decimal(12,2) NOT NULL DEFAULT '0.00',
  `notes` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cn_business` (`business_id`),
  KEY `idx_cn_invoice` (`sales_invoice_id`),
  CONSTRAINT `fk_cn_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`),
  CONSTRAINT `fk_cn_invoice` FOREIGN KEY (`sales_invoice_id`) REFERENCES `sales_invoices` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `credit_note_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `credit_note_id` int NOT NULL,
  `item_id` int DEFAULT NULL,
  `service_id` int DEFAULT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `unit_price_fc` decimal(12,2) NOT NULL,
  `unit_price_bc` decimal(12,2) NOT NULL,
  `total_price_fc` decimal(12,2) NOT NULL,
  `total_price_bc` decimal(12,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cni_note` (`credit_note_id`),
  CONSTRAINT `fk_cni_note` FOREIGN KEY (`credit_note_id`) REFERENCES `credit_notes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ============================================================================
-- 6. PURCHASING
-- ============================================================================

CREATE TABLE IF NOT EXISTS `purchase_orders` (
  `id` int NOT NULL AUTO_INCREMENT,
  `business_id` int NOT NULL,
  `supplier_id` int NOT NULL,
  `po_number` varchar(100) NOT NULL,
  `po_date` date NOT NULL,
  `status` enum('DRAFT','APPROVED','RECEIVED','CLOSED','CANCELLED') NOT NULL DEFAULT 'DRAFT',
  `currency` varchar(10) NOT NULL,
  `exchange_rate` decimal(12,6) NOT NULL,
  `total_amount_fc` decimal(12,2) DEFAULT '0.00',
  `total_amount_bc` decimal(12,2) DEFAULT '0.00',
  `tax_total_fc` decimal(12,2) NOT NULL DEFAULT '0.00',
  `tax_total_bc` decimal(12,2) NOT NULL DEFAULT '0.00',
  `discount_percent` decimal(5,2) NOT NULL DEFAULT '0.00',
  `notes` text,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_purchase_orders_status` (`status`),
  KEY `fk_purchase_orders_supplier` (`supplier_id`),
  KEY `fk_purchase_orders_business` (`business_id`),
  CONSTRAINT `fk_purchase_orders_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_purchase_orders_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `payees` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `po_details` (
  `id` int NOT NULL AUTO_INCREMENT,
  `purchase_order_id` int NOT NULL,
  `item_id` int NOT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `unit_price_fc` decimal(12,2) NOT NULL,
  `unit_price_bc` decimal(12,2) NOT NULL,
  `total_price_fc` decimal(12,2) NOT NULL,
  `total_price_bc` decimal(12,2) NOT NULL,
  `tax_rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `tax_amount_fc` decimal(12,2) NOT NULL DEFAULT '0.00',
  `tax_amount_bc` decimal(12,2) NOT NULL DEFAULT '0.00',
  `discount_percent` decimal(5,2) NOT NULL DEFAULT '0.00',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_po_details_item` (`item_id`),
  KEY `fk_po_details_po` (`purchase_order_id`),
  CONSTRAINT `fk_po_details_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_po_details_po` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `payment_slips` (
  `id` int NOT NULL AUTO_INCREMENT,
  `business_id` int NOT NULL,
  `account_id` int NOT NULL,
  `payee_id` int NOT NULL,
  `payment_number` varchar(100) NOT NULL,
  `payment_date` date NOT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'USD',
  `exchange_rate` decimal(12,6) NOT NULL DEFAULT '1.000000',
  `amount_fc` decimal(12,2) NOT NULL,
  `amount_bc` decimal(12,2) NOT NULL,
  `method` enum('CASH','BANK','CHECK','TRANSFER') NOT NULL,
  `notes` text,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_payment_slips_date` (`payment_date`),
  KEY `fk_payment_slips_payee` (`payee_id`),
  KEY `fk_payment_slips_account` (`account_id`),
  KEY `fk_payment_slips_business` (`business_id`),
  CONSTRAINT `fk_payment_slips_account` FOREIGN KEY (`account_id`) REFERENCES `business_accounts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_payment_slips_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_payment_slips_payee` FOREIGN KEY (`payee_id`) REFERENCES `payees` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `payment_allocations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `payment_slip_id` int NOT NULL,
  `reference_type` enum('PURCHASE_ORDER','EXPENSE','SALARY','PARTNER_PROFIT','RECURRING_EXPENSE','RECURRING_SALARY') NOT NULL,
  `reference_id` int NOT NULL,
  `amount_fc` decimal(12,2) NOT NULL,
  `amount_bc` decimal(12,2) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pa_slip` (`payment_slip_id`),
  KEY `idx_pa_ref` (`reference_type`,`reference_id`),
  CONSTRAINT `fk_pa_payment_slip` FOREIGN KEY (`payment_slip_id`) REFERENCES `payment_slips` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `debit_notes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `business_id` int NOT NULL,
  `purchase_order_id` int NOT NULL,
  `debit_note_number` varchar(100) NOT NULL,
  `debit_note_date` date NOT NULL,
  `status` enum('DRAFT','ISSUED') NOT NULL DEFAULT 'DRAFT',
  `currency` varchar(10) NOT NULL,
  `exchange_rate` decimal(12,6) NOT NULL DEFAULT '1.000000',
  `total_amount_fc` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total_amount_bc` decimal(12,2) NOT NULL DEFAULT '0.00',
  `notes` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_dn_business` (`business_id`),
  KEY `idx_dn_po` (`purchase_order_id`),
  CONSTRAINT `fk_dn_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`),
  CONSTRAINT `fk_dn_po` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `debit_note_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `debit_note_id` int NOT NULL,
  `item_id` int DEFAULT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `unit_price_fc` decimal(12,2) NOT NULL,
  `unit_price_bc` decimal(12,2) NOT NULL,
  `total_price_fc` decimal(12,2) NOT NULL,
  `total_price_bc` decimal(12,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_dni_note` (`debit_note_id`),
  CONSTRAINT `fk_dni_note` FOREIGN KEY (`debit_note_id`) REFERENCES `debit_notes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ============================================================================
-- 7. FINANCE
-- ============================================================================

CREATE TABLE IF NOT EXISTS `business_accounts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `business_id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `currency` varchar(10) NOT NULL,
  `account_type` enum('BANK','CASH','CREDIT','OTHER') NOT NULL DEFAULT 'CASH',
  `balance` decimal(18,2) NOT NULL DEFAULT '0.00',
  `balance_fc` decimal(15,2) NOT NULL DEFAULT '0.00',
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_business_accounts_business` (`business_id`),
  CONSTRAINT `fk_business_accounts_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `expense_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `business_id` int DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT '0',
  `module_code` varchar(50) DEFAULT NULL,
  `code` varchar(50) DEFAULT NULL,
  `is_fixed` tinyint(1) DEFAULT '0',
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_expense_categories_tenant` (`tenant_id`),
  KEY `fk_expense_categories_business` (`business_id`),
  CONSTRAINT `fk_expense_categories_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_expense_categories_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `expenses` (
  `id` int NOT NULL AUTO_INCREMENT,
  `business_id` int NOT NULL,
  `payee_id` int NOT NULL,
  `expense_category_id` int NOT NULL,
  `expense_date` date NOT NULL,
  `currency` varchar(10) NOT NULL,
  `exchange_rate` decimal(12,6) NOT NULL,
  `amount_fc` decimal(12,2) NOT NULL,
  `amount_bc` decimal(12,2) NOT NULL,
  `notes` text,
  `reference_number` varchar(100) DEFAULT NULL,
  `reference_date` date DEFAULT NULL,
  `attachment_path` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_expenses_date` (`expense_date`),
  KEY `fk_expenses_category` (`expense_category_id`),
  KEY `fk_expenses_business` (`business_id`),
  KEY `idx_expenses_payee_date` (`payee_id`,`expense_date`),
  CONSTRAINT `fk_expenses_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_expenses_category` FOREIGN KEY (`expense_category_id`) REFERENCES `expense_categories` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_expenses_payee` FOREIGN KEY (`payee_id`) REFERENCES `payees` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `salaries` (
  `id` int NOT NULL AUTO_INCREMENT,
  `business_id` int NOT NULL,
  `payee_id` int NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `currency` varchar(10) NOT NULL,
  `exchange_rate` decimal(12,6) NOT NULL,
  `gross_amount_fc` decimal(12,2) NOT NULL,
  `gross_amount_bc` decimal(12,2) NOT NULL,
  `tax_fc` decimal(12,2) DEFAULT '0.00',
  `tax_bc` decimal(12,2) DEFAULT '0.00',
  `net_amount_fc` decimal(12,2) NOT NULL,
  `net_amount_bc` decimal(12,2) NOT NULL,
  `notes` text,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_salaries_period` (`period_start`,`period_end`),
  KEY `fk_salaries_payee` (`payee_id`),
  KEY `fk_salaries_business` (`business_id`),
  CONSTRAINT `fk_salaries_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_salaries_payee` FOREIGN KEY (`payee_id`) REFERENCES `payees` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `exchange_rates` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `business_id` int DEFAULT NULL,
  `currency` varchar(10) NOT NULL,
  `rate_date` date NOT NULL,
  `rate` decimal(12,6) NOT NULL,
  `source` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_exchange_rates_currency_date` (`currency`,`rate_date`),
  KEY `fk_exchange_rates_tenant` (`tenant_id`),
  KEY `fk_exchange_rates_business` (`business_id`),
  CONSTRAINT `fk_exchange_rates_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_exchange_rates_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `business_profit` (
  `id` int NOT NULL AUTO_INCREMENT,
  `business_id` int NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date DEFAULT NULL,
  `total_revenue` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total_cogs` decimal(12,2) NOT NULL DEFAULT '0.00',
  `gross_profit` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total_expenses` decimal(12,2) NOT NULL DEFAULT '0.00',
  `salary_expenses` decimal(12,2) NOT NULL DEFAULT '0.00',
  `net_profit` decimal(12,2) NOT NULL DEFAULT '0.00',
  `safety_net_percent` decimal(5,2) NOT NULL DEFAULT '0.00',
  `safety_net_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `distributable_profit` decimal(12,2) NOT NULL DEFAULT '0.00',
  `status` enum('DRAFT','FINALIZED','DISTRIBUTED') NOT NULL DEFAULT 'DRAFT',
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bp_business_period` (`business_id`,`period_start`),
  KEY `fk_business_profit_business` (`business_id`),
  KEY `fk_bp_created_by` (`created_by`),
  CONSTRAINT `fk_bp_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_business_profit_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `profit_distributions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `business_profit_id` int NOT NULL,
  `partner_id` int NOT NULL,
  `share_percent` decimal(5,2) NOT NULL,
  `amount_bc` decimal(12,2) NOT NULL,
  `payment_slip_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pd_profit` (`business_profit_id`),
  KEY `idx_pd_partner` (`partner_id`),
  KEY `fk_pd_payment` (`payment_slip_id`),
  CONSTRAINT `fk_pd_partner` FOREIGN KEY (`partner_id`) REFERENCES `payees` (`id`),
  CONSTRAINT `fk_pd_payment` FOREIGN KEY (`payment_slip_id`) REFERENCES `payment_slips` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pd_profit` FOREIGN KEY (`business_profit_id`) REFERENCES `business_profit` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `settings_smtp` (
  `business_id` int NOT NULL,
  `host` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `port` smallint NOT NULL DEFAULT '587',
  `username` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_enc` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `from_address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `from_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 8. PARTNERS & PROFIT DISTRIBUTION
-- ============================================================================

CREATE TABLE IF NOT EXISTS `partner_shares` (
  `id` int NOT NULL AUTO_INCREMENT,
  `business_id` int NOT NULL,
  `partner_id` int NOT NULL,
  `share_percent` decimal(5,2) NOT NULL,
  `invested_capital` decimal(12,2) NOT NULL DEFAULT '0.00',
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_partner_shares_payee` (`partner_id`),
  KEY `fk_partner_shares_business` (`business_id`),
  CONSTRAINT `fk_partner_shares_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_partner_shares_payee` FOREIGN KEY (`partner_id`) REFERENCES `payees` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `partner_equity` (
  `id` int NOT NULL AUTO_INCREMENT,
  `partner_id` int NOT NULL,
  `business_id` int NOT NULL,
  `equity_type` enum('CONTRIBUTION','WITHDRAWAL') NOT NULL DEFAULT 'CONTRIBUTION',
  `amount` decimal(12,2) NOT NULL,
  `slip_type` enum('COLLECTION_SLIP','PAYMENT_SLIP') DEFAULT NULL,
  `slip_id` int DEFAULT NULL,
  `notes` text,
  `created_at` datetime NOT NULL,
  `created_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_partner_equity_business` (`business_id`),
  KEY `fk_partner_equity_partner` (`partner_id`),
  KEY `fk_pe_created_by` (`created_by`),
  CONSTRAINT `fk_partner_equity_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_partner_equity_partner` FOREIGN KEY (`partner_id`) REFERENCES `payees` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_pe_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ============================================================================
-- 9. MANUFACTURING
-- ============================================================================

CREATE TABLE IF NOT EXISTS `recipes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `business_id` int NOT NULL,
  `manufactured_item_id` int NOT NULL,
  `yield_quantity` decimal(12,4) NOT NULL DEFAULT '1.0000',
  `notes` text,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` int NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_recipes_tenant_business` (`tenant_id`,`business_id`),
  KEY `idx_recipes_manufactured_item` (`manufactured_item_id`),
  KEY `fk_recipes_created_by` (`created_by`),
  KEY `fk_recipes_business` (`business_id`),
  CONSTRAINT `fk_recipes_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`),
  CONSTRAINT `fk_recipes_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_recipes_manufactured_item` FOREIGN KEY (`manufactured_item_id`) REFERENCES `items` (`id`),
  CONSTRAINT `fk_recipes_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `recipe_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `recipe_id` int NOT NULL,
  `component_item_id` int NOT NULL,
  `quantity` decimal(12,4) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_recipe_component` (`recipe_id`,`component_item_id`),
  KEY `idx_recipe_items_component` (`component_item_id`),
  CONSTRAINT `fk_recipe_items_component` FOREIGN KEY (`component_item_id`) REFERENCES `items` (`id`),
  CONSTRAINT `fk_recipe_items_recipe` FOREIGN KEY (`recipe_id`) REFERENCES `recipes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `production_orders` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `business_id` int NOT NULL,
  `recipe_id` int NOT NULL,
  `manufactured_item_id` int NOT NULL,
  `production_quantity` decimal(12,4) NOT NULL,
  `manufacturing_cost` decimal(12,4) NOT NULL,
  `unit_manufacturing_cost` decimal(12,4) NOT NULL,
  `status` enum('COMPLETED','REVERSED') NOT NULL DEFAULT 'COMPLETED',
  `production_date` datetime NOT NULL,
  `created_by` int NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_production_orders_tenant_business` (`tenant_id`,`business_id`),
  KEY `idx_production_orders_recipe` (`recipe_id`),
  KEY `idx_production_orders_manufactured_item` (`manufactured_item_id`),
  KEY `idx_production_orders_date` (`production_date`),
  KEY `fk_production_orders_created_by` (`created_by`),
  KEY `fk_production_orders_business` (`business_id`),
  CONSTRAINT `fk_production_orders_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`),
  CONSTRAINT `fk_production_orders_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_production_orders_manufactured_item` FOREIGN KEY (`manufactured_item_id`) REFERENCES `items` (`id`),
  CONSTRAINT `fk_production_orders_recipe` FOREIGN KEY (`recipe_id`) REFERENCES `recipes` (`id`),
  CONSTRAINT `fk_production_orders_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `production_order_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `production_order_id` int NOT NULL,
  `component_item_id` int NOT NULL,
  `quantity_consumed` decimal(12,4) NOT NULL,
  `unit_cost` decimal(12,4) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_production_order_items_component` (`component_item_id`),
  KEY `fk_po_items_order` (`production_order_id`),
  CONSTRAINT `fk_po_items_component` FOREIGN KEY (`component_item_id`) REFERENCES `items` (`id`),
  CONSTRAINT `fk_po_items_order` FOREIGN KEY (`production_order_id`) REFERENCES `production_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ============================================================================
-- 10. SERVICES
-- ============================================================================

CREATE TABLE IF NOT EXISTS `service_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `business_id` int NOT NULL,
  `name` varchar(150) NOT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT '0',
  `module_code` varchar(50) DEFAULT NULL,
  `code` varchar(50) DEFAULT NULL,
  `created_by` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_service_categories_name` (`tenant_id`,`business_id`,`name`),
  KEY `fk_service_categories_tenant` (`tenant_id`),
  KEY `fk_service_categories_business` (`business_id`),
  KEY `fk_service_categories_created_by` (`created_by`),
  CONSTRAINT `fk_service_categories_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`),
  CONSTRAINT `fk_service_categories_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_service_categories_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `services` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `business_id` int NOT NULL,
  `category_id` int DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `description` text,
  `unit` varchar(50) NOT NULL,
  `rate` decimal(15,2) NOT NULL DEFAULT '0.00',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_services_tenant_business` (`tenant_id`,`business_id`),
  KEY `fk_services_category` (`category_id`),
  KEY `fk_services_created_by` (`created_by`),
  KEY `fk_services_business` (`business_id`),
  CONSTRAINT `fk_services_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`),
  CONSTRAINT `fk_services_category` FOREIGN KEY (`category_id`) REFERENCES `service_categories` (`id`),
  CONSTRAINT `fk_services_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_services_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ============================================================================
-- 11. RECURRENCES
-- ============================================================================

CREATE TABLE IF NOT EXISTS `recurrence_schedules` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `entity_type` enum('EXPENSE','SALARY') NOT NULL,
  `entity_id` int NOT NULL,
  `business_id` int NOT NULL,
  `frequency` enum('weekly','bi-weekly','monthly','quarterly','yearly') NOT NULL,
  `default_amount` decimal(15,2) NOT NULL,
  `default_currency` varchar(3) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_business_entity_active` (`business_id`,`entity_type`,`is_active`),
  KEY `idx_entity_type_id` (`entity_type`,`entity_id`),
  CONSTRAINT `fk_recurrence_schedules_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `recurrence_instances` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `recurrence_schedule_id` int unsigned NOT NULL,
  `entity_type` enum('EXPENSE','SALARY') NOT NULL,
  `business_id` int NOT NULL,
  `due_date` date NOT NULL,
  `scheduled_amount` decimal(15,2) NOT NULL,
  `actual_amount` decimal(15,2) DEFAULT NULL,
  `currency` varchar(3) NOT NULL,
  `status` enum('pending','paid','skipped','overdue') NOT NULL DEFAULT 'pending',
  `paid_date` date DEFAULT NULL,
  `notification_sent` tinyint(1) NOT NULL DEFAULT '0',
  `payment_record_id` int DEFAULT NULL,
  `period_start` date DEFAULT NULL,
  `period_end` date DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_schedule_due_date` (`recurrence_schedule_id`,`due_date`),
  KEY `idx_instances_business_due_status` (`business_id`,`due_date`,`status`),
  KEY `idx_instances_payment_record` (`payment_record_id`),
  CONSTRAINT `fk_instances_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_instances_schedule` FOREIGN KEY (`recurrence_schedule_id`) REFERENCES `recurrence_schedules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ============================================================================
-- 12. NOTIFICATIONS
-- ============================================================================

CREATE TABLE IF NOT EXISTS `notification_types` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `description` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `business_id` int DEFAULT NULL,
  `notification_type` int NOT NULL,
  `notification_type_id` int DEFAULT NULL,
  `sender_id` int DEFAULT NULL,
  `recipient_id` int DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `body` text,
  `entity_type` varchar(50) DEFAULT NULL,
  `entity_id` int DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `read_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `type` enum('INFO','WARNING','ALERT') NOT NULL DEFAULT 'INFO',
  `reference_table` varchar(50) DEFAULT NULL,
  `reference_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_notifications_business` (`business_id`),
  KEY `fk_notifications_type` (`notification_type`),
  CONSTRAINT `fk_notifications_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_notifications_type` FOREIGN KEY (`notification_type`) REFERENCES `notification_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `notification_preferences` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `business_id` int NOT NULL,
  `type` enum('PO_STATUS_CHANGE','INVOICE_DUE','STOCK_LOW','PAYMENT_RECEIVED','USER_MESSAGE','SYSTEM_ALERT','EXPENSE_DUE','SALARY_DUE','EXPENSE_OVERDUE','SALARY_OVERDUE') NOT NULL,
  `notification_type_id` int DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_business_type` (`user_id`,`business_id`,`type`),
  KEY `fk_notification_preferences_business` (`business_id`),
  CONSTRAINT `fk_notification_preferences_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notification_preferences_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ============================================================================
-- 13. ASSETS
-- ============================================================================

CREATE TABLE IF NOT EXISTS `asset_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `business_id` int DEFAULT NULL,
  `code` varchar(50) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `module_code` varchar(50) DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_asset_categories_tenant` (`tenant_id`),
  KEY `idx_asset_categories_business` (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `assets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `business_id` int NOT NULL,
  `name` varchar(150) NOT NULL,
  `category` varchar(50) NOT NULL,
  `category_id` int DEFAULT NULL,
  `asset_type` enum('FIXED','CURRENT','INTANGIBLE') NOT NULL,
  `purchase_date` date NOT NULL,
  `purchase_value` decimal(12,2) NOT NULL,
  `currency` varchar(10) NOT NULL,
  `exchange_rate` decimal(12,6) NOT NULL DEFAULT '1.000000',
  `value_bc` decimal(12,2) NOT NULL,
  `depreciation_method` enum('STRAIGHT_LINE','DECLINING_BALANCE') DEFAULT 'STRAIGHT_LINE',
  `useful_life` int DEFAULT NULL,
  `accumulated_depreciation` decimal(12,2) DEFAULT '0.00',
  `current_value_bc` decimal(12,2) DEFAULT NULL,
  `notes` text,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_assets_business` (`business_id`),
  KEY `fk_assets_created_by` (`created_by`),
  CONSTRAINT `fk_assets_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_assets_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ============================================================================
-- 14. SETTINGS & i18n
-- ============================================================================

CREATE TABLE IF NOT EXISTS `languages` (
  `code` varchar(10) NOT NULL,
  `name` varchar(50) DEFAULT NULL,
  `direction` enum('LTR','RTL') DEFAULT 'LTR',
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `settings_regional` (
  `business_id` int NOT NULL,
  `language` varchar(10) NOT NULL,
  `timezone` varchar(50) NOT NULL,
  `date_format` varchar(50) NOT NULL,
  `number_format` varchar(20) NOT NULL,
  `currency_format` varchar(20) NOT NULL,
  `phone_format` varchar(50) NOT NULL,
  `audit_enabled` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`business_id`),
  KEY `fk_settings_regional_language` (`language`),
  KEY `fk_settings_regional_business` (`business_id`),
  CONSTRAINT `fk_settings_regional_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `fk_settings_regional_language` FOREIGN KEY (`language`) REFERENCES `languages` (`code`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `translations` (
  `lang` varchar(10) NOT NULL,
  `key` varchar(200) NOT NULL,
  `value` text,
  PRIMARY KEY (`lang`,`key`),
  CONSTRAINT `fk_translation_language` FOREIGN KEY (`lang`) REFERENCES `languages` (`code`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ============================================================================
-- 15. AUDIT
-- ============================================================================

CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int DEFAULT NULL,
  `business_id` int DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `event_type` varchar(100) NOT NULL,
  `table_name` varchar(100) NOT NULL,
  `record_id` int NOT NULL,
  `action` varchar(250) NOT NULL,
  `old_values` json DEFAULT NULL,
  `new_values` json DEFAULT NULL,
  `ip_address` varchar(50) DEFAULT NULL,
  `user_agent` text,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_audit_logs_tenant` (`tenant_id`),
  KEY `fk_audit_logs_user` (`user_id`),
  KEY `fk_audit_logs_business` (`business_id`),
  CONSTRAINT `fk_audit_logs_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_audit_logs_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `fk_audit_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ============================================================================
-- RE-ENABLE CHECKS
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 1;
