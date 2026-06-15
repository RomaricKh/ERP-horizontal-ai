-- Migration: Migrate tenant_owners to role-based TENANT_OWNER
-- Eliminates the separate tenant_owners table by representing Tenant Owner
-- as an immutable TENANT_OWNER role type in the existing roles system.
-- This unifies authorization through a single role-based path.
-- This is idempotent — safe to run multiple times.
-- Wrapped in a single transaction for atomicity.

START TRANSACTION;

-- ============================================================
-- Step 1: Add TENANT_OWNER to roles.type ENUM
-- ============================================================

ALTER TABLE `roles` MODIFY COLUMN `type`
  ENUM('SYSTEM_OWNER', 'CUSTOM', 'TEMPLATE', 'TENANT_OWNER') NOT NULL DEFAULT 'CUSTOM';

-- ============================================================
-- Step 2: Update CHECK constraint to allow TENANT_OWNER with NULL business_id
-- The previous constraint only allowed TEMPLATE with NULL business_id.
-- Now both TEMPLATE and TENANT_OWNER are tenant-scoped (business_id = NULL).
-- ============================================================

-- Drop existing CHECK constraint if it exists (MySQL 8 does not support IF EXISTS for CHECK)
SET @constraint_exists = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'roles'
    AND CONSTRAINT_NAME = 'chk_roles_business_scope'
    AND CONSTRAINT_TYPE = 'CHECK'
);

SET @drop_sql = IF(@constraint_exists > 0,
  'ALTER TABLE `roles` DROP CHECK `chk_roles_business_scope`',
  'SELECT 1'
);
PREPARE _stmt FROM @drop_sql;
EXECUTE _stmt;
DEALLOCATE PREPARE _stmt;

ALTER TABLE `roles` ADD CONSTRAINT `chk_roles_business_scope` CHECK (
  (type IN ('SYSTEM_OWNER', 'CUSTOM') AND business_id IS NOT NULL) OR
  (type IN ('TEMPLATE', 'TENANT_OWNER') AND business_id IS NULL)
);

-- ============================================================
-- Step 3: Create one TENANT_OWNER role per tenant
-- Uses NOT EXISTS to ensure idempotent re-runs.
-- ============================================================

INSERT INTO `roles` (`tenant_id`, `business_id`, `name`, `description`, `type`)
SELECT DISTINCT t.id, NULL, 'Tenant Owner', 'Immutable tenant-wide owner role', 'TENANT_OWNER'
FROM `tenants` t
WHERE NOT EXISTS (
  SELECT 1 FROM `roles` r WHERE r.tenant_id = t.id AND r.type = 'TENANT_OWNER'
);

-- ============================================================
-- Step 4: Alter user_roles to support tenant-scoped roles (NULL business_id)
-- Currently business_id is NOT NULL and part of the PK (user_id, business_id).
-- Dynamically builds ALTER based on current table state for idempotency.
-- ============================================================

-- Check if we already migrated (surrogate id column exists)
SET @id_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'user_roles'
    AND COLUMN_NAME = 'id'
);

-- Build ALTER dynamically: only drop FKs/PK that still exist
SET @fk_business = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'user_roles' AND CONSTRAINT_NAME = 'fk_ur_business' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @fk_role = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'user_roles' AND CONSTRAINT_NAME = 'fk_ur_role' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @fk_user = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'user_roles' AND CONSTRAINT_NAME = 'fk_ur_user' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @has_pk = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_roles' AND INDEX_NAME = 'PRIMARY');

SET @alter_parts = '';
SET @alter_parts = IF(@fk_business > 0, CONCAT(@alter_parts, 'DROP FOREIGN KEY `fk_ur_business`, '), @alter_parts);
SET @alter_parts = IF(@fk_role > 0, CONCAT(@alter_parts, 'DROP FOREIGN KEY `fk_ur_role`, '), @alter_parts);
SET @alter_parts = IF(@fk_user > 0, CONCAT(@alter_parts, 'DROP FOREIGN KEY `fk_ur_user`, '), @alter_parts);
SET @alter_parts = IF(@has_pk > 0, CONCAT(@alter_parts, 'DROP PRIMARY KEY, '), @alter_parts);

SET @alter_parts = CONCAT(@alter_parts,
  'ADD COLUMN `id` INT AUTO_INCREMENT PRIMARY KEY FIRST, ',
  'MODIFY COLUMN `business_id` INT NULL DEFAULT NULL, ',
  'ADD CONSTRAINT `fk_ur_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`), ',
  'ADD CONSTRAINT `fk_ur_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`), ',
  'ADD CONSTRAINT `fk_ur_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`), ',
  'ADD UNIQUE INDEX `uq_user_role_business` (`user_id`, `role_id`, `business_id`)'
);

SET @final_sql = IF(@id_exists = 0,
  CONCAT('ALTER TABLE `user_roles` ', @alter_parts),
  'SELECT 1'
);
PREPARE _stmt FROM @final_sql;
EXECUTE _stmt;
DEALLOCATE PREPARE _stmt;

-- ============================================================
-- Step 5: Migrate tenant_owners records to user_roles
-- Each tenant_owners record becomes a user_roles entry referencing
-- the TENANT_OWNER role for that tenant. business_id is NULL since
-- TENANT_OWNER is tenant-scoped, not business-scoped.
-- Uses NOT EXISTS to prevent duplicate entries on re-run.
-- ============================================================

INSERT INTO `user_roles` (`user_id`, `role_id`, `business_id`)
SELECT
  tow.user_id,
  r.id AS role_id,
  NULL
FROM `tenant_owners` tow
JOIN `roles` r ON r.tenant_id = tow.tenant_id AND r.type = 'TENANT_OWNER'
WHERE NOT EXISTS (
  SELECT 1 FROM `user_roles` ur
  WHERE ur.user_id = tow.user_id AND ur.role_id = r.id
);

-- ============================================================
-- Step 6: Drop tenant_owners table
-- The data has been migrated to user_roles; this table is no longer needed.
-- ============================================================

DROP TABLE IF EXISTS `tenant_owners`;

COMMIT;
