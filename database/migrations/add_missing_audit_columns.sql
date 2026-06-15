-- Migration: Add missing created_by audit columns and updated_at to people
-- Tables receiving created_by INT NULL FK→users:
--   stock_transactions, batch_transactions, partner_equity, business_profit, assets
-- Tables receiving updated_at DATETIME NULL:
--   people
-- Note: item_price_history already has `changed_by` (serves same purpose as created_by)
-- Note: people already has `created_by`

-- --------------------------------------------------------
-- stock_transactions: add created_by
-- --------------------------------------------------------
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_transactions' AND COLUMN_NAME = 'created_by');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE stock_transactions ADD COLUMN created_by INT NULL AFTER created_at',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------
-- batch_transactions: add created_by
-- --------------------------------------------------------
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'batch_transactions' AND COLUMN_NAME = 'created_by');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE batch_transactions ADD COLUMN created_by INT NULL AFTER created_at',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------
-- partner_equity: add created_by
-- --------------------------------------------------------
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'partner_equity' AND COLUMN_NAME = 'created_by');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE partner_equity ADD COLUMN created_by INT NULL AFTER created_at',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------
-- business_profit: add created_by
-- --------------------------------------------------------
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'business_profit' AND COLUMN_NAME = 'created_by');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE business_profit ADD COLUMN created_by INT NULL AFTER created_at',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------
-- assets: add created_by
-- --------------------------------------------------------
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assets' AND COLUMN_NAME = 'created_by');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE assets ADD COLUMN created_by INT NULL AFTER updated_at',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------
-- people: add updated_at
-- --------------------------------------------------------
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'people' AND COLUMN_NAME = 'updated_at');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE people ADD COLUMN updated_at DATETIME NULL AFTER created_at',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------
-- Add foreign key constraints for created_by columns (only if not already present)
-- --------------------------------------------------------

-- stock_transactions.created_by → users.id
SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_transactions' AND CONSTRAINT_NAME = 'fk_st_created_by');
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE stock_transactions ADD CONSTRAINT fk_st_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- batch_transactions.created_by → users.id
SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'batch_transactions' AND CONSTRAINT_NAME = 'fk_bt_created_by');
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE batch_transactions ADD CONSTRAINT fk_bt_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- partner_equity.created_by → users.id
SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'partner_equity' AND CONSTRAINT_NAME = 'fk_pe_created_by');
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE partner_equity ADD CONSTRAINT fk_pe_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- business_profit.created_by → users.id
SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'business_profit' AND CONSTRAINT_NAME = 'fk_bp_created_by');
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE business_profit ADD CONSTRAINT fk_bp_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- assets.created_by → users.id
SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assets' AND CONSTRAINT_NAME = 'fk_assets_created_by');
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE assets ADD CONSTRAINT fk_assets_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
