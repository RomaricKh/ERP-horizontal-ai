-- --------------------------------------------------------
-- Add min_stock column to items table for low stock alerts
-- --------------------------------------------------------

ALTER TABLE `items` ADD COLUMN `min_stock` decimal(12,2) DEFAULT NULL AFTER `sale_price`;
