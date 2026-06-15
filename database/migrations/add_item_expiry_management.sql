-- Migration: Item Expiry Management
-- Feature: item-expiry-management
-- Description: Adds expiry tracking capabilities to the inventory system

-- Task 1.1: Add has_expiry column to items table
SET @col_has_expiry_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'items'
    AND COLUMN_NAME = 'has_expiry'
);

SET @add_has_expiry_sql = IF(@col_has_expiry_exists = 0,
  'ALTER TABLE `items` ADD COLUMN `has_expiry` TINYINT(1) NOT NULL DEFAULT 0 AFTER `min_stock`',
  'SELECT 1'
);

PREPARE stmt FROM @add_has_expiry_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Task 1.2: Create item_batches table
CREATE TABLE IF NOT EXISTS item_batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_id INT NOT NULL,
    business_id INT NOT NULL,
    batch_number VARCHAR(100) NOT NULL,
    quantity DECIMAL(15,4) NOT NULL DEFAULT 0,
    manufacturing_date DATE NULL,
    expiry_date DATE NOT NULL,
    purchase_order_id INT NULL,
    status ENUM('active','expired','written_off') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,

    CONSTRAINT fk_batch_item FOREIGN KEY (item_id) REFERENCES items(id),
    CONSTRAINT fk_batch_business FOREIGN KEY (business_id) REFERENCES businesses(id),
    CONSTRAINT fk_batch_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id),
    UNIQUE KEY uq_batch_number (item_id, business_id, batch_number),
    INDEX idx_batch_fefo (item_id, business_id, status, expiry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Create batch_transactions table for tracking batch-level
-- stock movements (in, out, write_off)
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS batch_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    batch_id INT NOT NULL,
    transaction_type ENUM('in','out','write_off') NOT NULL,
    quantity DECIMAL(15,4) NOT NULL,
    reference VARCHAR(255) NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL,

    CONSTRAINT fk_btx_batch FOREIGN KEY (batch_id) REFERENCES item_batches(id),
    INDEX idx_btx_batch (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
