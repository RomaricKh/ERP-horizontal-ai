-- Add is_active column to tenants table for soft-delete support
ALTER TABLE `tenants`
  ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `base_currency`;
