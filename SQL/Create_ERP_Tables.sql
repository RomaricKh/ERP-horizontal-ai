-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Jan 19, 2026 at 12:31 PM
-- Server version: 9.1.0
-- PHP Version: 8.1.31

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `erp`
--

-- --------------------------------------------------------

--
-- Table structure for table `assets`
--

DROP TABLE IF EXISTS `assets`;
CREATE TABLE IF NOT EXISTS `assets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `business_id` int NOT NULL,
  `name` varchar(150) NOT NULL,
  `category` varchar(50) NOT NULL,
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
  PRIMARY KEY (`id`),
  KEY `fk_assets_business` (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `business_id` int DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `event_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `table_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `record_id` int NOT NULL,
  `action` enum('CREATE','UPDATE','DELETE','LOGIN','LOGOUT') NOT NULL,
  `old_values` json DEFAULT NULL,
  `new_values` json DEFAULT NULL,
  `ip_address` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_audit_logs_tenant` (`tenant_id`),
  KEY `fk_audit_logs_user` (`user_id`),
  KEY `fk_audit_logs_business` (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `businesses`
--

DROP TABLE IF EXISTS `businesses`;
CREATE TABLE IF NOT EXISTS `businesses` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `name` varchar(150) NOT NULL,
  `legal_name` varchar(200) DEFAULT NULL,
  `registration_no` varchar(100) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'USD',
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_businesses_tenant` (`tenant_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `business_accounts`
--

DROP TABLE IF EXISTS `business_accounts`;
CREATE TABLE IF NOT EXISTS `business_accounts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `business_id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `currency` varchar(10) NOT NULL,
  `balance` decimal(18,2) NOT NULL DEFAULT '0.00',
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_business_accounts_business` (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `business_payees_types`
--

DROP TABLE IF EXISTS `business_payees_types`;
CREATE TABLE IF NOT EXISTS `business_payees_types` (
  `payee_id` int NOT NULL,
  `payee_type_id` int NOT NULL,
  PRIMARY KEY (`payee_id`,`payee_type_id`),
  KEY `fk_bpt_payee_type` (`payee_type_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `business_profit`
--

DROP TABLE IF EXISTS `business_profit`;
CREATE TABLE IF NOT EXISTS `business_profit` (
  `business_id` int NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date DEFAULT NULL,
  `profit_bc` decimal(18,2) NOT NULL,
  `safety_net_type` enum('FIXED','PERCENTAGE','MIN_BALANCE') NOT NULL DEFAULT 'FIXED',
  `safety_net_bc` decimal(18,2) NOT NULL,
  `distributable_profit_bc` decimal(18,2) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`business_id`,`period_start`),
  KEY `fk_business_profit_business` (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `collection_slips`
--

DROP TABLE IF EXISTS `collection_slips`;
CREATE TABLE IF NOT EXISTS `collection_slips` (
  `id` int NOT NULL AUTO_INCREMENT,
  `business_id` int NOT NULL,
  `account_id` int NOT NULL,
  `payee_id` int NOT NULL,
  `reference_type` enum('CUSTOMER','PARTNER_CONTRIBUTION') NOT NULL,
  `reference_id` int DEFAULT NULL,
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
  KEY `fk_collection_slips_business` (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `exchange_rates`
--

DROP TABLE IF EXISTS `exchange_rates`;
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
  KEY `fk_exchange_rates_business` (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

DROP TABLE IF EXISTS `expenses`;
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
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_expenses_date` (`expense_date`),
  KEY `fk_expenses_category` (`expense_category_id`),
  KEY `fk_expenses_payee` (`payee_id`),
  KEY `fk_expenses_business` (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `expense_categories`
--

DROP TABLE IF EXISTS `expense_categories`;
CREATE TABLE IF NOT EXISTS `expense_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `business_id` int DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `is_fixed` tinyint(1) DEFAULT '0',
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_expense_categories_tenant` (`tenant_id`),
  KEY `fk_expense_categories_business` (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `items`
--

DROP TABLE IF EXISTS `items`;
CREATE TABLE IF NOT EXISTS `items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `business_id` int DEFAULT NULL,
  `item_type_id` int NOT NULL,
  `name` varchar(150) NOT NULL,
  `sku` varchar(100) DEFAULT NULL,
  `unit` varchar(50) DEFAULT NULL,
  `cost_price` decimal(12,2) DEFAULT NULL,
  `sale_price` decimal(12,2) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `notes` text,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_items_tenant_sku` (`tenant_id`,`sku`) USING BTREE,
  KEY `fk_items_business` (`business_id`),
  KEY `fk_items_tenant` (`tenant_id`),
  KEY `fk_items_item_types` (`item_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `item_prices`
--

DROP TABLE IF EXISTS `item_prices`;
CREATE TABLE IF NOT EXISTS `item_prices` (
  `id` int NOT NULL AUTO_INCREMENT,
  `item_id` int NOT NULL,
  `business_id` int NOT NULL,
  `currency` varchar(10) NOT NULL,
  `cost_price` decimal(12,2) NOT NULL,
  `sale_price` decimal(12,2) NOT NULL,
  `valid_from` date NOT NULL,
  `valid_to` date DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_item_prices_item_valid` (`item_id`,`valid_from`,`valid_to`),
  KEY `fk_item_prices_business` (`business_id`),
  KEY `fk_item_prices_items` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `supplier_item_prices`
--

DROP TABLE IF EXISTS `supplier_item_prices`;
CREATE TABLE IF NOT EXISTS `supplier_item_prices` (
  `id` int NOT NULL AUTO_INCREMENT,
  `supplier_id` int NOT NULL,
  `item_id` int NOT NULL,
  `tenant_id` int NOT NULL,
  `last_price` decimal(12,2) NOT NULL,
  `avg_price` decimal(12,2) NOT NULL,
  `min_price` decimal(12,2) NOT NULL,
  `max_price` decimal(12,2) NOT NULL,
  `last_purchase_date` date NOT NULL,
  `purchase_count` int NOT NULL DEFAULT '1',
  `is_preferred` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_supplier_item_tenant` (`supplier_id`,`item_id`,`tenant_id`),
  KEY `fk_supplier_item_prices_supplier` (`supplier_id`),
  KEY `fk_supplier_item_prices_item` (`item_id`),
  KEY `fk_supplier_item_prices_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `item_types`
--

DROP TABLE IF EXISTS `item_types`;
CREATE TABLE IF NOT EXISTS `item_types` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `business_id` int DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_item_types_tenant` (`tenant_id`),
  KEY `fk_item_types_business` (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `languages`
--

DROP TABLE IF EXISTS `languages`;
CREATE TABLE IF NOT EXISTS `languages` (
  `code` varchar(10) NOT NULL,
  `name` varchar(50) DEFAULT NULL,
  `direction` enum('LTR','RTL') DEFAULT 'LTR',
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `business_id` int DEFAULT NULL,
  `notification_type` int NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `type` enum('INFO','WARNING','ALERT') NOT NULL DEFAULT 'INFO',
  `reference_table` varchar(50) DEFAULT NULL,
  `reference_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_notifications_business` (`business_id`),
  KEY `fk_notifications_type` (`notification_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notification_roles`
--

DROP TABLE IF EXISTS `notification_roles`;
CREATE TABLE IF NOT EXISTS `notification_roles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `notification_type` int NOT NULL,
  `role_id` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_notification_roles_role` (`role_id`),
  KEY `fk_notification_roles_type` (`notification_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notification_types`
--

DROP TABLE IF EXISTS `notification_types`;
CREATE TABLE IF NOT EXISTS `notification_types` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `description` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `partner_equity`
--

DROP TABLE IF EXISTS `partner_equity`;
CREATE TABLE IF NOT EXISTS `partner_equity` (
  `id` int NOT NULL AUTO_INCREMENT,
  `partner_id` int NOT NULL,
  `business_id` int NOT NULL,
  `equity_type` enum('CONTRIBUTION','WITHDRAWAL') NOT NULL DEFAULT 'CONTRIBUTION',
  `amount` decimal(12,2) NOT NULL,
  `reference_id` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_partner_equity_business` (`business_id`),
  KEY `fk_partner_equity_partner` (`partner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `partner_shares`
--

DROP TABLE IF EXISTS `partner_shares`;
CREATE TABLE IF NOT EXISTS `partner_shares` (
  `id` int NOT NULL AUTO_INCREMENT,
  `business_id` int NOT NULL,
  `partner_id` int NOT NULL,
  `ownership_pct` decimal(5,2) NOT NULL,
  `invested_capital` decimal(12,2) NOT NULL DEFAULT '0.00',
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_partner_shares_payee` (`partner_id`),
  KEY `fk_partner_shares_business` (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payees`
--

DROP TABLE IF EXISTS `payees`;
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
  KEY `fk_payees_business` (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payee_types`
--

DROP TABLE IF EXISTS `payee_types`;
CREATE TABLE IF NOT EXISTS `payee_types` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `business_id` int DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_payee_types_tenant` (`tenant_id`),
  KEY `fk_payee_types_business` (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payment_slips`
--

DROP TABLE IF EXISTS `payment_slips`;
CREATE TABLE IF NOT EXISTS `payment_slips` (
  `id` int NOT NULL AUTO_INCREMENT,
  `business_id` int NOT NULL,
  `account_id` int NOT NULL,
  `payee_id` int NOT NULL,
  `reference_type` enum('EXPENSE','PO','SALARY','PARTNER_PROFIT') NOT NULL,
  `reference_id` int NOT NULL,
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
  KEY `fk_payment_slips_business` (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `people`
--

DROP TABLE IF EXISTS `people`;
CREATE TABLE IF NOT EXISTS `people` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `name` varchar(150) NOT NULL,
  `phone` varchar(100) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `tax_number` varchar(100) DEFAULT NULL,
  `iban` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `created_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_people_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

DROP TABLE IF EXISTS `permissions`;
CREATE TABLE IF NOT EXISTS `permissions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `code` varchar(150) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Truncate table before insert `permissions`
--

TRUNCATE TABLE `permissions`;
--
-- Dumping data for table `permissions`
--

INSERT INTO `permissions` (`id`, `name`, `code`, `created_at`) VALUES
(1, 'dashboard view', 'dashboard.view', '2026-01-11 22:04:42'),
(2, 'charts view', 'charts.view', '2026-01-11 22:04:42'),
(3, 'tables view', 'tables.view', '2026-01-11 22:05:17'),
(4, 'profile view', 'profile.view', '2026-01-11 22:05:17'),
(5, 'settings view', 'settings.view', '2026-01-11 22:05:35'),
(6, 'activityLog view', 'activityLog.view', '2026-01-11 22:05:35'),
(7, 'users management', 'users.manage', '2026-01-11 22:06:38'),
(8, 'user edit', 'user.edit', '2026-01-13 18:19:51'),
(9, 'user delete', 'user.delete', '2026-01-13 18:19:51'),
(10, 'user create', 'user.create', '2026-01-13 18:44:53'),
(11, 'businesses manage', 'businesses.manage', '2026-01-18 22:52:23'),
(12, 'business create', 'business.create', '2026-01-18 22:53:00'),
(13, 'business edit', 'business.edit', '2026-01-18 22:53:00'),
(14, 'business delete', 'business.delete', '2026-01-18 22:53:00');

-- --------------------------------------------------------

--
-- Table structure for table `po_details`
--

DROP TABLE IF EXISTS `po_details`;
CREATE TABLE IF NOT EXISTS `po_details` (
  `id` int NOT NULL AUTO_INCREMENT,
  `purchase_order_id` int NOT NULL,
  `item_id` int NOT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `unit_price_fc` decimal(12,2) NOT NULL,
  `unit_price_bc` decimal(12,2) NOT NULL,
  `total_price_fc` decimal(12,2) NOT NULL,
  `total_price_bc` decimal(12,2) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_po_details_item` (`item_id`),
  KEY `fk_po_details_po` (`purchase_order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_orders`
--

DROP TABLE IF EXISTS `purchase_orders`;
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
  `notes` text,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_purchase_orders_status` (`status`),
  KEY `fk_purchase_orders_supplier` (`supplier_id`),
  KEY `fk_purchase_orders_business` (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `refresh_tokens`
--

DROP TABLE IF EXISTS `refresh_tokens`;
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
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
CREATE TABLE IF NOT EXISTS `roles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_tenant_name` (`tenant_id`,`name`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Truncate table before insert `roles`
--

TRUNCATE TABLE `roles`;
--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `tenant_id`, `name`, `description`, `created_at`, `updated_at`) VALUES
(1, 1, 'Business_Owner', 'Access everything over a specific businesses', '2026-01-11 21:59:17', NULL),
(2, 1, 'ACCOUNTANT', 'handles accounting for a specific business', '2026-01-12 21:58:52', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

DROP TABLE IF EXISTS `role_permissions`;
CREATE TABLE IF NOT EXISTS `role_permissions` (
  `role_id` int NOT NULL,
  `permission_id` int NOT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `fk_role_permission_permission` (`permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Truncate table before insert `role_permissions`
--

TRUNCATE TABLE `role_permissions`;
--
-- Dumping data for table `role_permissions`
--

INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES
(1, 1),
(2, 1),
(1, 2),
(2, 2),
(1, 3),
(2, 3),
(1, 4),
(2, 4),
(1, 5),
(2, 5),
(1, 6),
(1, 7),
(2, 7),
(1, 8),
(1, 9),
(1, 10),
(1, 11),
(1, 12),
(1, 13),
(1, 14);

-- --------------------------------------------------------

--
-- Table structure for table `salaries`
--

DROP TABLE IF EXISTS `salaries`;
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
  KEY `fk_salaries_business` (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales_invoices`
--

DROP TABLE IF EXISTS `sales_invoices`;
CREATE TABLE IF NOT EXISTS `sales_invoices` (
  `id` int NOT NULL AUTO_INCREMENT,
  `business_id` int NOT NULL,
  `customer_id` int NOT NULL,
  `invoice_number` varchar(100) NOT NULL,
  `invoice_date` date NOT NULL,
  `status` enum('DRAFT','APPROVED','PAID','CANCELLED') NOT NULL DEFAULT 'DRAFT',
  `currency` varchar(10) NOT NULL,
  `exchange_rate` decimal(12,6) NOT NULL,
  `total_amount_fc` decimal(12,2) DEFAULT '0.00',
  `total_amount_bc` decimal(12,2) DEFAULT '0.00',
  `notes` text,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_sales_invoices_customer` (`customer_id`),
  KEY `fk_sales_invoices_business` (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `settings_regional`
--

DROP TABLE IF EXISTS `settings_regional`;
CREATE TABLE IF NOT EXISTS `settings_regional` (
  `business_id` int NOT NULL,
  `language` varchar(10) NOT NULL,
  `timezone` varchar(50) NOT NULL,
  `date_format` varchar(50) NOT NULL,
  `number_format` varchar(20) NOT NULL,
  `currency_format` varchar(20) NOT NULL,
  `phone_format` varchar(50) NOT NULL,
  PRIMARY KEY (`business_id`),
  KEY `fk_settings_regional_language` (`language`),
  KEY `fk_settings_regional_business` (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `si_details`
--

DROP TABLE IF EXISTS `si_details`;
CREATE TABLE IF NOT EXISTS `si_details` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sales_invoice_id` int NOT NULL,
  `item_id` int NOT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `unit_price_fc` decimal(12,2) NOT NULL,
  `unit_price_bc` decimal(12,2) NOT NULL,
  `total_price_fc` decimal(12,2) NOT NULL,
  `total_price_bc` decimal(12,2) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_si_details_item` (`item_id`),
  KEY `fk_si_details_invoice` (`sales_invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stock`
--

DROP TABLE IF EXISTS `stock`;
CREATE TABLE IF NOT EXISTS `stock` (
  `id` int NOT NULL AUTO_INCREMENT,
  `business_id` int NOT NULL,
  `item_id` int NOT NULL,
  `quantity` decimal(12,3) NOT NULL DEFAULT '0.000',
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `business_id` (`business_id`,`item_id`),
  KEY `fk_stock_item` (`item_id`),
  KEY `fk_stock_business` (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stock_transactions`
--

DROP TABLE IF EXISTS `stock_transactions`;
CREATE TABLE IF NOT EXISTS `stock_transactions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `business_id` int NOT NULL,
  `item_id` int NOT NULL,
  `trx_date` datetime NOT NULL,
  `type` enum('IN','OUT','ADJ') NOT NULL DEFAULT 'IN',
  `reference` varchar(100) DEFAULT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `notes` text,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_stock_transactions_item` (`item_id`),
  KEY `fk_stock_transactions_business` (`business_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_translations`
--

DROP TABLE IF EXISTS `tbl_translations`;
CREATE TABLE IF NOT EXISTS `tbl_translations` (
  `lang` varchar(10) NOT NULL,
  `key` varchar(200) NOT NULL,
  `value` text,
  PRIMARY KEY (`lang`,`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tenants`
--

DROP TABLE IF EXISTS `tenants`;
CREATE TABLE IF NOT EXISTS `tenants` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
CREATE TABLE IF NOT EXISTS `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `password_hash` varchar(355) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `name` varchar(150) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `failed_attempts` int DEFAULT '0',
  `lock_until` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_notifications`
--

DROP TABLE IF EXISTS `user_notifications`;
CREATE TABLE IF NOT EXISTS `user_notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `notification_id` int NOT NULL,
  `user_id` int NOT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_user_notifications_user` (`user_id`),
  KEY `fk_user_notifications_notification` (`notification_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_roles`
--

DROP TABLE IF EXISTS `user_roles`;
CREATE TABLE IF NOT EXISTS `user_roles` (
  `user_id` int NOT NULL,
  `role_id` int NOT NULL,
  `tenant_id` int NOT NULL,
  `business_id` int NOT NULL,
  PRIMARY KEY (`user_id`,`business_id`),
  KEY `fk_ur_role` (`role_id`),
  KEY `fk_ur_business` (`business_id`),
  KEY `fk_ur_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `assets`
--
ALTER TABLE `assets`
  ADD CONSTRAINT `fk_assets_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `fk_audit_logs_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_audit_logs_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  ADD CONSTRAINT `fk_audit_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `businesses`
--
ALTER TABLE `businesses`
  ADD CONSTRAINT `fk_businesses_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`);

--
-- Constraints for table `business_accounts`
--
ALTER TABLE `business_accounts`
  ADD CONSTRAINT `fk_business_accounts_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

--
-- Constraints for table `business_payees_types`
--
ALTER TABLE `business_payees_types`
  ADD CONSTRAINT `fk_bpt_payee_type` FOREIGN KEY (`payee_type_id`) REFERENCES `payee_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

--
-- Constraints for table `business_profit`
--
ALTER TABLE `business_profit`
  ADD CONSTRAINT `fk_business_profit_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

--
-- Constraints for table `collection_slips`
--
ALTER TABLE `collection_slips`
  ADD CONSTRAINT `fk_collection_slips_account` FOREIGN KEY (`account_id`) REFERENCES `business_accounts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT `fk_collection_slips_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT `fk_collection_slips_payee` FOREIGN KEY (`payee_id`) REFERENCES `payees` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

--
-- Constraints for table `exchange_rates`
--
ALTER TABLE `exchange_rates`
  ADD CONSTRAINT `fk_exchange_rates_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT `fk_exchange_rates_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`);

--
-- Constraints for table `expenses`
--
ALTER TABLE `expenses`
  ADD CONSTRAINT `fk_expenses_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT `fk_expenses_category` FOREIGN KEY (`expense_category_id`) REFERENCES `expense_categories` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT `fk_expenses_payee` FOREIGN KEY (`payee_id`) REFERENCES `payees` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

--
-- Constraints for table `expense_categories`
--
ALTER TABLE `expense_categories`
  ADD CONSTRAINT `fk_expense_categories_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT `fk_expense_categories_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`);

--
-- Constraints for table `items`
--
ALTER TABLE `items`
  ADD CONSTRAINT `fk_items_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT `fk_items_item_types` FOREIGN KEY (`item_type_id`) REFERENCES `item_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT `fk_items_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`);

--
-- Constraints for table `item_prices`
--
ALTER TABLE `item_prices`
  ADD CONSTRAINT `fk_item_prices_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT `fk_item_prices_items` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

--
-- Constraints for table `item_types`
--
ALTER TABLE `item_types`
  ADD CONSTRAINT `fk_item_types_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT `fk_item_types_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notifications_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT `fk_notifications_type` FOREIGN KEY (`notification_type`) REFERENCES `notification_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

--
-- Constraints for table `notification_roles`
--
ALTER TABLE `notification_roles`
  ADD CONSTRAINT `fk_notification_roles_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT `fk_notification_roles_type` FOREIGN KEY (`notification_type`) REFERENCES `notification_types` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

--
-- Constraints for table `partner_equity`
--
ALTER TABLE `partner_equity`
  ADD CONSTRAINT `fk_partner_equity_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT `fk_partner_equity_partner` FOREIGN KEY (`partner_id`) REFERENCES `payees` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

--
-- Constraints for table `partner_shares`
--
ALTER TABLE `partner_shares`
  ADD CONSTRAINT `fk_partner_shares_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT `fk_partner_shares_payee` FOREIGN KEY (`partner_id`) REFERENCES `payees` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

--
-- Constraints for table `payees`
--
ALTER TABLE `payees`
  ADD CONSTRAINT `fk_payees_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT `fk_payees_people` FOREIGN KEY (`people_id`) REFERENCES `people` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

--
-- Constraints for table `payee_types`
--
ALTER TABLE `payee_types`
  ADD CONSTRAINT `fk_payee_types_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT `fk_payee_types_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`);

--
-- Constraints for table `payment_slips`
--
ALTER TABLE `payment_slips`
  ADD CONSTRAINT `fk_payment_slips_account` FOREIGN KEY (`account_id`) REFERENCES `business_accounts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT `fk_payment_slips_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT `fk_payment_slips_payee` FOREIGN KEY (`payee_id`) REFERENCES `payees` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

--
-- Constraints for table `people`
--
ALTER TABLE `people`
  ADD CONSTRAINT `fk_people_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`);

--
-- Constraints for table `po_details`
--
ALTER TABLE `po_details`
  ADD CONSTRAINT `fk_po_details_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT `fk_po_details_po` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

--
-- Constraints for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD CONSTRAINT `fk_purchase_orders_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT `fk_purchase_orders_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `payees` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

--
-- Constraints for table `roles`
--
ALTER TABLE `roles`
  ADD CONSTRAINT `fk_roles_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`);

--
-- Constraints for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `fk_role_permission_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT `fk_role_permission_roles` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

--
-- Constraints for table `salaries`
--
ALTER TABLE `salaries`
  ADD CONSTRAINT `fk_salaries_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT `fk_salaries_payee` FOREIGN KEY (`payee_id`) REFERENCES `payees` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

--
-- Constraints for table `sales_invoices`
--
ALTER TABLE `sales_invoices`
  ADD CONSTRAINT `fk_sales_invoices_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT `fk_sales_invoices_customer` FOREIGN KEY (`customer_id`) REFERENCES `payees` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

--
-- Constraints for table `settings_regional`
--
ALTER TABLE `settings_regional`
  ADD CONSTRAINT `fk_settings_regional_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT `fk_settings_regional_language` FOREIGN KEY (`language`) REFERENCES `languages` (`code`) ON DELETE RESTRICT ON UPDATE RESTRICT;

--
-- Constraints for table `si_details`
--
ALTER TABLE `si_details`
  ADD CONSTRAINT `fk_si_details_invoice` FOREIGN KEY (`sales_invoice_id`) REFERENCES `sales_invoices` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT `fk_si_details_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

--
-- Constraints for table `stock`
--
ALTER TABLE `stock`
  ADD CONSTRAINT `fk_stock_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT `fk_stock_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

--
-- Constraints for table `stock_transactions`
--
ALTER TABLE `stock_transactions`
  ADD CONSTRAINT `fk_stock_transactions_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT `fk_stock_transactions_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

--
-- Constraints for table `tbl_translations`
--
ALTER TABLE `tbl_translations`
  ADD CONSTRAINT `fk_translation_language` FOREIGN KEY (`lang`) REFERENCES `languages` (`code`) ON DELETE RESTRICT ON UPDATE RESTRICT;

--
-- Constraints for table `user_notifications`
--
ALTER TABLE `user_notifications`
  ADD CONSTRAINT `fk_user_notifications_notification` FOREIGN KEY (`notification_id`) REFERENCES `notifications` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT `fk_user_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

--
-- Constraints for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD CONSTRAINT `fk_ur_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`),
  ADD CONSTRAINT `fk_ur_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`),
  ADD CONSTRAINT `fk_ur_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  ADD CONSTRAINT `fk_ur_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
