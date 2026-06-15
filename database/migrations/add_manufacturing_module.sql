-- Migration: Add Manufacturing Module tables
-- This migration creates the database schema for the manufacturing module:
-- recipes, recipe_items, production_orders, production_order_items,
-- item_cost_flags, item_auto_markup, and extends the items table.

-- --------------------------------------------------------
-- Table: recipes
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `recipes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `business_id` int NOT NULL,
  `manufactured_item_id` int NOT NULL,
  `yield_quantity` int NOT NULL DEFAULT 1,
  `notes` text,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_recipes_tenant_business` (`tenant_id`, `business_id`),
  KEY `idx_recipes_manufactured_item` (`manufactured_item_id`),
  KEY `fk_recipes_created_by` (`created_by`),
  CONSTRAINT `fk_recipes_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `fk_recipes_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`),
  CONSTRAINT `fk_recipes_manufactured_item` FOREIGN KEY (`manufactured_item_id`) REFERENCES `items` (`id`),
  CONSTRAINT `fk_recipes_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table: recipe_items
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `recipe_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `recipe_id` int NOT NULL,
  `component_item_id` int NOT NULL,
  `quantity` decimal(12,4) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_recipe_component` (`recipe_id`, `component_item_id`),
  KEY `idx_recipe_items_component` (`component_item_id`),
  CONSTRAINT `fk_recipe_items_recipe` FOREIGN KEY (`recipe_id`) REFERENCES `recipes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_recipe_items_component` FOREIGN KEY (`component_item_id`) REFERENCES `items` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table: production_orders
-- --------------------------------------------------------

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
  KEY `idx_production_orders_tenant_business` (`tenant_id`, `business_id`),
  KEY `idx_production_orders_recipe` (`recipe_id`),
  KEY `idx_production_orders_manufactured_item` (`manufactured_item_id`),
  KEY `idx_production_orders_date` (`production_date`),
  KEY `fk_production_orders_created_by` (`created_by`),
  CONSTRAINT `fk_production_orders_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `fk_production_orders_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`),
  CONSTRAINT `fk_production_orders_recipe` FOREIGN KEY (`recipe_id`) REFERENCES `recipes` (`id`),
  CONSTRAINT `fk_production_orders_manufactured_item` FOREIGN KEY (`manufactured_item_id`) REFERENCES `items` (`id`),
  CONSTRAINT `fk_production_orders_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table: production_order_items
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `production_order_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `production_order_id` int NOT NULL,
  `component_item_id` int NOT NULL,
  `quantity_consumed` decimal(12,4) NOT NULL,
  `unit_cost` decimal(12,4) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_production_order_items_component` (`component_item_id`),
  CONSTRAINT `fk_po_items_order` FOREIGN KEY (`production_order_id`) REFERENCES `production_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_po_items_component` FOREIGN KEY (`component_item_id`) REFERENCES `items` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table: item_cost_flags
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `item_cost_flags` (
  `id` int NOT NULL AUTO_INCREMENT,
  `item_id` int NOT NULL,
  `old_cost_price` decimal(12,4) NOT NULL,
  `new_cost_price` decimal(12,4) NOT NULL,
  `is_dismissed` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  `dismissed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_item_cost_flags_item` (`item_id`),
  CONSTRAINT `fk_item_cost_flags_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Table: item_auto_markup
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `item_auto_markup` (
  `id` int NOT NULL AUTO_INCREMENT,
  `item_id` int NOT NULL,
  `markup_percent` decimal(5,2) NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_item_auto_markup_item` (`item_id`),
  CONSTRAINT `fk_item_auto_markup_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------
-- Alter items table: add has_cost_change_flag column
-- --------------------------------------------------------

ALTER TABLE `items` ADD COLUMN `has_cost_change_flag` tinyint(1) NOT NULL DEFAULT 0;


-- --------------------------------------------------------
-- Seed: "Manufactured" item type for all existing businesses
-- --------------------------------------------------------

INSERT INTO `item_types` (`tenant_id`, `business_id`, `name`, `created_at`)
SELECT b.`tenant_id`, b.`id`, 'Manufactured', NOW()
FROM `businesses` b
WHERE NOT EXISTS (
    SELECT 1 FROM `item_types` it
    WHERE it.`tenant_id` = b.`tenant_id`
      AND it.`business_id` = b.`id`
      AND it.`name` = 'Manufactured'
);

-- --------------------------------------------------------
-- Seed: Manufacturing permissions
-- --------------------------------------------------------

INSERT IGNORE INTO `permissions` (`name`, `code`) VALUES
  ('manufacturing view',   'manufacturing.view'),
  ('manufacturing create', 'manufacturing.create'),
  ('manufacturing edit',   'manufacturing.edit'),
  ('manufacturing delete', 'manufacturing.delete');

-- --------------------------------------------------------
-- Seed: Assign all manufacturing permissions to business_owner (role_id = 1)
-- --------------------------------------------------------

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 1, p.`id`
FROM `permissions` p
WHERE p.`code` IN ('manufacturing.view', 'manufacturing.create', 'manufacturing.edit', 'manufacturing.delete')
  AND p.`id` NOT IN (
    SELECT rp.`permission_id`
    FROM `role_permissions` rp
    WHERE rp.`role_id` = 1
  );
