-- =============================================================================
-- Fix system types structure across all classification tables:
-- 1. Make tenant_id NULLABLE (system types have NULL tenant/business)
-- 2. Replace module_code VARCHAR with module_id INT FK to modules(id)
-- 3. Update system type records to have NULL tenant_id and business_id
-- =============================================================================

-- ─── payee_types ─────────────────────────────────────────────────────────────

ALTER TABLE `payee_types` MODIFY COLUMN `tenant_id` int DEFAULT NULL;

ALTER TABLE `payee_types` ADD COLUMN `module_id` int DEFAULT NULL;

UPDATE `payee_types`
  SET `module_id` = (SELECT `id` FROM `modules` WHERE `modules`.`code` = `payee_types`.`module_code`)
  WHERE `module_code` IS NOT NULL;

UPDATE `payee_types` SET `tenant_id` = NULL, `business_id` = NULL WHERE `is_system` = 1;

ALTER TABLE `payee_types` DROP COLUMN `module_code`;

ALTER TABLE `payee_types` ADD CONSTRAINT `fk_payee_types_module` FOREIGN KEY (`module_id`) REFERENCES `modules` (`id`);

-- ─── item_types ──────────────────────────────────────────────────────────────

ALTER TABLE `item_types` MODIFY COLUMN `tenant_id` int DEFAULT NULL;

ALTER TABLE `item_types` ADD COLUMN `module_id` int DEFAULT NULL;

UPDATE `item_types`
  SET `module_id` = (SELECT `id` FROM `modules` WHERE `modules`.`code` = `item_types`.`module_code`)
  WHERE `module_code` IS NOT NULL;

UPDATE `item_types` SET `tenant_id` = NULL, `business_id` = NULL WHERE `is_system` = 1;

ALTER TABLE `item_types` DROP COLUMN `module_code`;

ALTER TABLE `item_types` ADD CONSTRAINT `fk_item_types_module` FOREIGN KEY (`module_id`) REFERENCES `modules` (`id`);

-- ─── expense_categories ──────────────────────────────────────────────────────

ALTER TABLE `expense_categories` MODIFY COLUMN `tenant_id` int DEFAULT NULL;

ALTER TABLE `expense_categories` ADD COLUMN `module_id` int DEFAULT NULL;

UPDATE `expense_categories`
  SET `module_id` = (SELECT `id` FROM `modules` WHERE `modules`.`code` = `expense_categories`.`module_code`)
  WHERE `module_code` IS NOT NULL;

UPDATE `expense_categories` SET `tenant_id` = NULL, `business_id` = NULL WHERE `is_system` = 1;

ALTER TABLE `expense_categories` DROP COLUMN `module_code`;

ALTER TABLE `expense_categories` ADD CONSTRAINT `fk_expense_categories_module` FOREIGN KEY (`module_id`) REFERENCES `modules` (`id`);

-- ─── service_categories ──────────────────────────────────────────────────────

ALTER TABLE `service_categories` MODIFY COLUMN `tenant_id` int DEFAULT NULL;

ALTER TABLE `service_categories` ADD COLUMN `module_id` int DEFAULT NULL;

UPDATE `service_categories`
  SET `module_id` = (SELECT `id` FROM `modules` WHERE `modules`.`code` = `service_categories`.`module_code`)
  WHERE `module_code` IS NOT NULL;

UPDATE `service_categories` SET `tenant_id` = NULL, `business_id` = NULL WHERE `is_system` = 1;

ALTER TABLE `service_categories` DROP COLUMN `module_code`;

ALTER TABLE `service_categories` ADD CONSTRAINT `fk_service_categories_module` FOREIGN KEY (`module_id`) REFERENCES `modules` (`id`);

-- ─── asset_categories ────────────────────────────────────────────────────────

ALTER TABLE `asset_categories` MODIFY COLUMN `tenant_id` int DEFAULT NULL;

ALTER TABLE `asset_categories` ADD COLUMN `module_id` int DEFAULT NULL;

UPDATE `asset_categories`
  SET `module_id` = (SELECT `id` FROM `modules` WHERE `modules`.`code` = `asset_categories`.`module_code`)
  WHERE `module_code` IS NOT NULL;

UPDATE `asset_categories` SET `tenant_id` = NULL, `business_id` = NULL WHERE `is_system` = 1;

ALTER TABLE `asset_categories` DROP COLUMN `module_code`;

ALTER TABLE `asset_categories` ADD CONSTRAINT `fk_asset_categories_module` FOREIGN KEY (`module_id`) REFERENCES `modules` (`id`);
