ALTER TABLE `assets` ADD COLUMN `salvage_value` decimal(12,2) NOT NULL DEFAULT '0.00' AFTER `useful_life`;
