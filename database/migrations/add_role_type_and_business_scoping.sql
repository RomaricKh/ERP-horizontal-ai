-- Migration: Add role type column and business_id scoping to roles table
-- Transforms roles from tenant-scoped to business-scoped with type-based identification.
-- Replaces hardcoded role_id=1 checks with SYSTEM_OWNER type detection.
-- This is idempotent — safe to run multiple times.
-- Wrapped in a single transaction with rollback on failure.

START TRANSACTION;

-- ============================================================
-- Step 1: Add `type` column to roles table (if not exists)
-- ============================================================

SET @col_type_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'roles'
    AND COLUMN_NAME = 'type'
);

SET @add_type_sql = IF(@col_type_exists = 0,
  'ALTER TABLE `roles` ADD COLUMN `type` ENUM(''SYSTEM_OWNER'', ''CUSTOM'', ''TEMPLATE'') NOT NULL DEFAULT ''CUSTOM'' AFTER `description`',
  'SELECT 1'
);
PREPARE stmt FROM @add_type_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- Step 2: Add `business_id` column to roles table (if not exists)
-- ============================================================

SET @col_biz_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'roles'
    AND COLUMN_NAME = 'business_id'
);

SET @add_biz_sql = IF(@col_biz_exists = 0,
  'ALTER TABLE `roles` ADD COLUMN `business_id` INT NULL AFTER `type`',
  'SELECT 1'
);
PREPARE stmt FROM @add_biz_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- Step 3: Set type for existing roles
-- ============================================================

-- Mark role_id=1 as SYSTEM_OWNER (only if not already set)
UPDATE `roles` SET `type` = 'SYSTEM_OWNER' WHERE `id` = 1 AND `type` != 'SYSTEM_OWNER';

-- Mark all other roles as CUSTOM (only those still at default)
UPDATE `roles` SET `type` = 'CUSTOM' WHERE `id` != 1 AND `type` = 'CUSTOM';

-- ============================================================
-- Step 4: Assign business_id to existing roles based on user_roles
-- For each role, find the most common business_id in user_roles.
-- For roles with no user_roles entries, assign to the first business
-- in the same tenant (lowest businesses.id).
-- ============================================================

-- 4a: Assign business_id based on most common business in user_roles
UPDATE `roles` r
JOIN (
  SELECT ur.role_id, ur.business_id, COUNT(*) AS cnt,
         ROW_NUMBER() OVER (PARTITION BY ur.role_id ORDER BY COUNT(*) DESC, ur.business_id ASC) AS rn
  FROM `user_roles` ur
  GROUP BY ur.role_id, ur.business_id
) ranked ON ranked.role_id = r.id AND ranked.rn = 1
SET r.business_id = ranked.business_id
WHERE r.business_id IS NULL
  AND r.type != 'TEMPLATE';

-- 4b: For roles with no user_roles entries, assign to first business in tenant
UPDATE `roles` r
JOIN (
  SELECT b.tenant_id, MIN(b.id) AS first_business_id
  FROM `businesses` b
  GROUP BY b.tenant_id
) tb ON tb.tenant_id = r.tenant_id
SET r.business_id = tb.first_business_id
WHERE r.business_id IS NULL
  AND r.type != 'TEMPLATE';

-- ============================================================
-- Step 5: Handle multi-business tenants for SYSTEM_OWNER role
-- For tenants where multiple businesses have users referencing role_id=1,
-- we need to create a separate SYSTEM_OWNER role per business and
-- update user_roles to point to the new per-business role.
-- ============================================================

-- Create a temporary table to track businesses that need their own SYSTEM_OWNER
-- (businesses that have users with role_id=1 but are NOT the business already
-- assigned to role_id=1)
CREATE TEMPORARY TABLE IF NOT EXISTS `_tmp_businesses_needing_owner` (
  `business_id` INT NOT NULL,
  `tenant_id` INT NOT NULL,
  PRIMARY KEY (`business_id`)
);

-- Clear temp table for idempotent re-runs
DELETE FROM `_tmp_businesses_needing_owner`;

-- Find businesses that have users referencing role_id=1 but don't already
-- have a SYSTEM_OWNER role assigned to them
INSERT INTO `_tmp_businesses_needing_owner` (`business_id`, `tenant_id`)
SELECT DISTINCT ur.business_id, b.tenant_id
FROM `user_roles` ur
JOIN `businesses` b ON b.id = ur.business_id
WHERE ur.role_id = 1
  AND ur.business_id NOT IN (
    SELECT r.business_id FROM `roles` r
    WHERE r.type = 'SYSTEM_OWNER' AND r.business_id IS NOT NULL
  );

-- For each business that needs its own SYSTEM_OWNER, insert a new role
-- (only if one doesn't already exist for that business)
INSERT INTO `roles` (`tenant_id`, `name`, `description`, `type`, `business_id`)
SELECT tbn.tenant_id, 'Business Owner', 'System-managed owner role with full permissions', 'SYSTEM_OWNER', tbn.business_id
FROM `_tmp_businesses_needing_owner` tbn
WHERE NOT EXISTS (
  SELECT 1 FROM `roles` r
  WHERE r.type = 'SYSTEM_OWNER' AND r.business_id = tbn.business_id
);

-- Update user_roles: for users in these businesses who had role_id=1,
-- point them to the new per-business SYSTEM_OWNER role
UPDATE `user_roles` ur
JOIN `_tmp_businesses_needing_owner` tbn ON tbn.business_id = ur.business_id
JOIN `roles` r ON r.type = 'SYSTEM_OWNER' AND r.business_id = tbn.business_id
SET ur.role_id = r.id
WHERE ur.role_id = 1;

-- Clean up temporary table
DROP TEMPORARY TABLE IF EXISTS `_tmp_businesses_needing_owner`;

-- ============================================================
-- Step 6: Add FOREIGN KEY constraint on business_id (if not exists)
-- ============================================================

SET @fk_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'roles'
    AND CONSTRAINT_NAME = 'fk_roles_business'
);

SET @fk_sql = IF(@fk_exists = 0,
  'ALTER TABLE `roles` ADD CONSTRAINT `fk_roles_business` FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE',
  'SELECT 1'
);
PREPARE stmt FROM @fk_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- Step 7: Add CHECK constraint for business_id scoping rules
-- SYSTEM_OWNER and CUSTOM require non-null business_id
-- TEMPLATE requires null business_id
-- ============================================================

SET @chk_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'roles'
    AND CONSTRAINT_NAME = 'chk_roles_business_scope'
);

SET @chk_sql = IF(@chk_exists = 0,
  'ALTER TABLE `roles` ADD CONSTRAINT `chk_roles_business_scope` CHECK (
    (type IN (''SYSTEM_OWNER'', ''CUSTOM'') AND business_id IS NOT NULL) OR
    (type = ''TEMPLATE'' AND business_id IS NULL)
  )',
  'SELECT 1'
);
PREPARE stmt FROM @chk_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- Step 8: Add unique index on (business_id, name) to prevent
-- duplicate role names within the same business
-- ============================================================

SET @idx_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'roles'
    AND INDEX_NAME = 'uq_roles_business_name'
);

SET @idx_sql = IF(@idx_exists = 0,
  'ALTER TABLE `roles` ADD UNIQUE INDEX `uq_roles_business_name` (`business_id`, `name`)',
  'SELECT 1'
);
PREPARE stmt FROM @idx_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

COMMIT;
