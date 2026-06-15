-- Migration: Add tenant_id column to user_roles
-- This column is the source of truth for tenant scoping on role assignments.
-- For TENANT_OWNER roles (business_id = NULL), it identifies which tenant the
-- ownership applies to. For business-scoped roles, it mirrors businesses.tenant_id
-- for efficient querying without extra JOINs.
-- Idempotent — safe to run multiple times.

START TRANSACTION;

-- ============================================================
-- Step 1: Add tenant_id column to user_roles (if not exists)
-- ============================================================

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'user_roles'
    AND COLUMN_NAME = 'tenant_id'
);

SET @add_col_sql = IF(@col_exists = 0,
  'ALTER TABLE `user_roles` ADD COLUMN `tenant_id` INT NOT NULL AFTER `business_id`',
  'SELECT 1'
);
PREPARE _stmt FROM @add_col_sql;
EXECUTE _stmt;
DEALLOCATE PREPARE _stmt;

-- ============================================================
-- Step 2: Backfill tenant_id from businesses table
-- For business-scoped roles, derive tenant from the business.
-- ============================================================

UPDATE `user_roles` ur
JOIN `businesses` b ON b.id = ur.business_id
SET ur.tenant_id = b.tenant_id
WHERE ur.business_id IS NOT NULL AND ur.tenant_id = 0;

-- ============================================================
-- Step 3: Add foreign key constraint (if not exists)
-- ============================================================

SET @fk_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'user_roles'
    AND CONSTRAINT_NAME = 'fk_ur_tenant'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);

SET @add_fk_sql = IF(@fk_exists = 0,
  'ALTER TABLE `user_roles` ADD CONSTRAINT `fk_ur_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)',
  'SELECT 1'
);
PREPARE _stmt FROM @add_fk_sql;
EXECUTE _stmt;
DEALLOCATE PREPARE _stmt;

COMMIT;
