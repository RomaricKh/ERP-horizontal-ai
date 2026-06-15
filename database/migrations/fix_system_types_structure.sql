-- =============================================================================
-- Fix system types structure across all classification tables:
-- 1. Make tenant_id NULLABLE (system types have NULL tenant/business)
-- 2. Replace module_code VARCHAR with module_id INT FK to modules(id)
-- 3. Update system type records to have NULL tenant_id and business_id
-- =============================================================================

-- ─── payee_types ─────────────────────────────────────────────────────────────

ALTER TABLE `payee_types`
  MODIFY COLUMN `tenant_id` int DEFAULT NULL,
  ADD COLUMN `module_id` int DEFAULT NULL AFTER `module_code`,
  ADD KEY `fk_payee_types_module` (`module_id`),
  ADD CONSTRAINT `fk_payee_types_module` FOREIGN KEY (`module_id`) REFERENCES `modules` (`id`);

UPDATE `payee_types` pt
  SET pt.module_id = (SELECT m.id FROM modules m WHERE m.code = pt.module_code)
  WHERE pt.module_code IS NOT NULL;

UPDATE `payee_types` SET tenant_id = NULL, business_id = NULL WHERE is_system = 1;

ALTER TABLE `payee_types` DROP COLUMN `module_code`;

-- ─── item_types ──────────────────────────────────────────────────────────────

ALTER TABLE `item_types`
  MODIFY COLUMN `tenant_id` int DEFAULT NULL,
  ADD COLUMN `module_id` int DEFAULT NULL AFTER `module_code`,
  ADD KEY `fk_item_types_module` (`module_id`),
  ADD CONSTRAINT `fk_item_types_module` FOREIGN KEY (`module_id`) REFERENCES `modules` (`id`);

UPDATE `item_types` it
  SET it.module_id = (SELECT m.id FROM modules m WHERE m.code = it.module_code)
  WHERE it.module_code IS NOT NULL;

UPDATE `item_types` SET tenant_id = NULL, business_id = NULL WHERE is_system = 1;

ALTER TABLE `item_types` DROP COLUMN `module_code`;

-- ─── expense_categories ──────────────────────────────────────────────────────

ALTER TABLE `expense_categories`
  MODIFY COLUMN `tenant_id` int DEFAULT NULL,
  ADD COLUMN `module_id` int DEFAULT NULL AFTER `module_code`,
  ADD KEY `fk_expense_categories_module` (`module_id`),
  ADD CONSTRAINT `fk_expense_categories_module` FOREIGN KEY (`module_id`) REFERENCES `modules` (`id`);

UPDATE `expense_categories` ec
  SET ec.module_id = (SELECT m.id FROM modules m WHERE m.code = ec.module_code)
  WHERE ec.module_code IS NOT NULL;

UPDATE `expense_categories` SET tenant_id = NULL, business_id = NULL WHERE is_system = 1;

ALTER TABLE `expense_categories` DROP COLUMN `module_code`;

-- ─── service_categories ──────────────────────────────────────────────────────

ALTER TABLE `service_categories`
  MODIFY COLUMN `tenant_id` int DEFAULT NULL,
  ADD COLUMN `module_id` int DEFAULT NULL AFTER `module_code`,
  ADD KEY `fk_service_categories_module` (`module_id`),
  ADD CONSTRAINT `fk_service_categories_module` FOREIGN KEY (`module_id`) REFERENCES `modules` (`id`);

UPDATE `service_categories` sc
  SET sc.module_id = (SELECT m.id FROM modules m WHERE m.code = sc.module_code)
  WHERE sc.module_code IS NOT NULL;

UPDATE `service_categories` SET tenant_id = NULL, business_id = NULL WHERE is_system = 1;

ALTER TABLE `service_categories` DROP COLUMN `module_code`;

-- ─── asset_categories ────────────────────────────────────────────────────────

ALTER TABLE `asset_categories`
  MODIFY COLUMN `tenant_id` int DEFAULT NULL,
  ADD COLUMN `module_id` int DEFAULT NULL AFTER `module_code`,
  ADD KEY `fk_asset_categories_module` (`module_id`),
  ADD CONSTRAINT `fk_asset_categories_module` FOREIGN KEY (`module_id`) REFERENCES `modules` (`id`);

UPDATE `asset_categories` ac
  SET ac.module_id = (SELECT m.id FROM modules m WHERE m.code = ac.module_code)
  WHERE ac.module_code IS NOT NULL;

UPDATE `asset_categories` SET tenant_id = NULL, business_id = NULL WHERE is_system = 1;

ALTER TABLE `asset_categories` DROP COLUMN `module_code`;
