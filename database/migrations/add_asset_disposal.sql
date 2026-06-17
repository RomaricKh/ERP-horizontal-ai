-- Migration: Add disposal fields to assets table
ALTER TABLE `assets`
  ADD COLUMN `status` enum('ACTIVE','DISPOSED') NOT NULL DEFAULT 'ACTIVE' AFTER `salvage_value`,
  ADD COLUMN `disposal_date` date DEFAULT NULL AFTER `status`,
  ADD COLUMN `disposal_proceeds` decimal(12,2) DEFAULT NULL AFTER `disposal_date`,
  ADD COLUMN `disposal_gain_loss` decimal(12,2) DEFAULT NULL AFTER `disposal_proceeds`;
