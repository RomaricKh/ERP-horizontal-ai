-- Migration: Add Pending Prices Table
-- Creates a queue for pending sale price updates for items, 
-- typically triggered by purchase orders or manual entry when markup is enabled.

CREATE TABLE IF NOT EXISTS `item_pending_prices` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tenant_id` int NOT NULL,
  `business_id` int NOT NULL,
  `item_id` int NOT NULL,
  `pending_sale_price` decimal(15,4) NOT NULL,
  `source_type` enum('PURCHASE_ORDER','MANUAL') NOT NULL,
  `source_id` int DEFAULT NULL,
  `status` enum('PENDING','APPLIED','CANCELLED') NOT NULL DEFAULT 'PENDING',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int DEFAULT NULL,
  `applied_at` datetime DEFAULT NULL,
  `applied_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_pending_items` (`item_id`, `status`),
  CONSTRAINT `fk_ipp_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ipp_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ipp_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
