-- Migration: Add Item Price History tables
-- This migration creates the database schema for item price tracking:
-- item_price_history (audit trail of all price changes)
-- supplier_item_prices (per-supplier pricing statistics)

-- --------------------------------------------------------
-- Table: item_price_history
-- --------------------------------------------------------

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
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_item_tenant` (`item_id`, `tenant_id`),
  INDEX `idx_effective_date` (`effective_date`),
  INDEX `idx_source` (`source_type`, `source_id`),
  CONSTRAINT `fk_iph_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_iph_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Table: supplier_item_prices
-- --------------------------------------------------------

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
  `purchase_count` int NOT NULL DEFAULT 1,
  `is_preferred` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `idx_supplier_item_tenant` (`supplier_id`, `item_id`, `tenant_id`),
  INDEX `idx_item_tenant` (`item_id`, `tenant_id`),
  CONSTRAINT `fk_sip_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `people` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sip_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sip_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
