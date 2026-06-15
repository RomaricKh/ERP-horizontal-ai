-- Migration: fix_data_consistency.sql
-- REQ-030: Fix data consistency issues across multiple tables
-- This migration handles all schema changes in one file.

-- =====================================================================
-- 1. Rename tbl_translations to translations (IF EXISTS)
-- =====================================================================
SET @tbl_exists = (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'tbl_translations');
SET @translations_exists = (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'translations');

SET @sql = IF(@tbl_exists > 0 AND @translations_exists = 0,
    'RENAME TABLE tbl_translations TO translations',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================================
-- 2. Add account_type to business_accounts (IF NOT EXISTS)
-- =====================================================================
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'business_accounts' AND column_name = 'account_type');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE business_accounts ADD COLUMN account_type ENUM(''BANK'',''CASH'',''CREDIT'',''OTHER'') NOT NULL DEFAULT ''CASH'' AFTER currency',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================================
-- 3. Add reference_number, reference_date, attachment_path to expenses
-- =====================================================================
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'expenses' AND column_name = 'reference_number');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE expenses ADD COLUMN reference_number VARCHAR(100) NULL AFTER notes',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'expenses' AND column_name = 'reference_date');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE expenses ADD COLUMN reference_date DATE NULL AFTER reference_number',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'expenses' AND column_name = 'attachment_path');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE expenses ADD COLUMN attachment_path VARCHAR(500) NULL AFTER reference_date',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================================
-- 4. Fix supplier_item_prices.supplier_id to reference payees.id
--    Re-map existing rows: supplier_id currently points to people.id,
--    we need it to point to payees.id (via payees.people_id)
-- =====================================================================
-- First, re-map existing supplier_id values from people.id to payees.id
UPDATE supplier_item_prices sip
JOIN payees p ON p.people_id = sip.supplier_id
SET sip.supplier_id = p.id
WHERE sip.supplier_id NOT IN (SELECT id FROM payees);

-- Add FK constraint if not already present
SET @fk_exists = (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'supplier_item_prices'
    AND constraint_name = 'fk_sip_supplier_payee');

SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE supplier_item_prices ADD CONSTRAINT fk_sip_supplier_payee FOREIGN KEY (supplier_id) REFERENCES payees(id)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================================
-- 5. Standardize batch_transactions.transaction_type to uppercase
-- =====================================================================
UPDATE batch_transactions SET transaction_type = 'IN' WHERE transaction_type = 'in';
UPDATE batch_transactions SET transaction_type = 'OUT' WHERE transaction_type = 'out';
UPDATE batch_transactions SET transaction_type = 'WRITE_OFF' WHERE transaction_type = 'write_off';

ALTER TABLE batch_transactions
  MODIFY COLUMN transaction_type ENUM('IN','OUT','WRITE_OFF') NOT NULL;

-- =====================================================================
-- 6. Replace stock_transactions.reference with reference_type + reference_id
-- =====================================================================
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'stock_transactions' AND column_name = 'reference_type');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE stock_transactions ADD COLUMN reference_type ENUM(''PURCHASE_ORDER'',''SALES_INVOICE'',''PRODUCTION'',''MANUAL'',''CREDIT_NOTE'',''DEBIT_NOTE'') NULL AFTER reference',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'stock_transactions' AND column_name = 'reference_id');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE stock_transactions ADD COLUMN reference_id INT NULL AFTER reference_type',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Migrate existing reference data where parseable
-- Pattern: "PO #123" -> PURCHASE_ORDER, 123
UPDATE stock_transactions
SET reference_type = 'PURCHASE_ORDER',
    reference_id = CAST(SUBSTRING(reference, 5) AS UNSIGNED)
WHERE reference LIKE 'PO #%' AND reference_type IS NULL;

-- Pattern: "INV #123" -> SALES_INVOICE, 123
UPDATE stock_transactions
SET reference_type = 'SALES_INVOICE',
    reference_id = CAST(SUBSTRING(reference, 6) AS UNSIGNED)
WHERE reference LIKE 'INV #%' AND reference_type IS NULL;

-- Pattern: "PROD #123" -> PRODUCTION, 123
UPDATE stock_transactions
SET reference_type = 'PRODUCTION',
    reference_id = CAST(SUBSTRING(reference, 7) AS UNSIGNED)
WHERE reference LIKE 'PROD #%' AND reference_type IS NULL;

-- Anything else that's manual
UPDATE stock_transactions
SET reference_type = 'MANUAL'
WHERE reference IS NOT NULL AND reference_type IS NULL;

-- Drop old reference column
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'stock_transactions' AND column_name = 'reference');

SET @sql = IF(@col_exists > 0,
    'ALTER TABLE stock_transactions DROP COLUMN reference',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================================
-- 7. Drop users.password_reset column (replaced by password_reset_tokens table)
-- =====================================================================
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'password_reset');

SET @sql = IF(@col_exists > 0,
    'ALTER TABLE users DROP COLUMN password_reset',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================================
-- 8. Standardize recurrence_schedules.entity_type to uppercase
-- =====================================================================
UPDATE recurrence_schedules SET entity_type = 'EXPENSE' WHERE entity_type = 'expense_category';
UPDATE recurrence_schedules SET entity_type = 'SALARY' WHERE entity_type = 'salary';

ALTER TABLE recurrence_schedules
  MODIFY COLUMN entity_type ENUM('EXPENSE','SALARY') NOT NULL;

-- =====================================================================
-- 9. Standardize recurrence_instances.entity_type to uppercase
-- =====================================================================
UPDATE recurrence_instances SET entity_type = 'EXPENSE' WHERE entity_type = 'expense_category';
UPDATE recurrence_instances SET entity_type = 'SALARY' WHERE entity_type = 'salary';

ALTER TABLE recurrence_instances
  MODIFY COLUMN entity_type ENUM('EXPENSE','SALARY') NOT NULL;

-- =====================================================================
-- 10. Drop unused columns from recurrence_instances
-- =====================================================================
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'recurrence_instances' AND column_name = 'is_advance_payment');

SET @sql = IF(@col_exists > 0,
    'ALTER TABLE recurrence_instances DROP COLUMN is_advance_payment',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'recurrence_instances' AND column_name = 'bulk_advance_payment_id');

SET @sql = IF(@col_exists > 0,
    'ALTER TABLE recurrence_instances DROP COLUMN bulk_advance_payment_id',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'recurrence_instances' AND column_name = 'metadata');

SET @sql = IF(@col_exists > 0,
    'ALTER TABLE recurrence_instances DROP COLUMN metadata',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================================
-- 11. Add recipient_id to notifications and notification_type_id to notification_preferences
--     The notifications table already has notification_type (int) column.
--     We need to add recipient_id INT for user targeting.
-- =====================================================================
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'notifications' AND column_name = 'recipient_id');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE notifications ADD COLUMN recipient_id INT NULL AFTER notification_type',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add notification_type_id to notification_preferences if not exists
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'notification_preferences' AND column_name = 'notification_type_id');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE notification_preferences ADD COLUMN notification_type_id INT NULL AFTER type',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Done
SELECT 'fix_data_consistency migration completed successfully' AS result;
