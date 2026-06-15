-- Add shelf_life_days column to items for auto-calculating expiry on production
ALTER TABLE `items`
  ADD COLUMN `shelf_life_days` int DEFAULT NULL AFTER `has_expiry`;
