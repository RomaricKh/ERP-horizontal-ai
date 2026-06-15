-- Migration: Fix partner equity and profit distribution architecture
-- Task 32.1 — REQ-032

-- ============================================================
-- 1. Rebuild business_profit table
-- ============================================================

-- Drop existing composite PK and rebuild with auto-increment id
ALTER TABLE business_profit
  DROP PRIMARY KEY;

ALTER TABLE business_profit
  ADD COLUMN id INT AUTO_INCREMENT PRIMARY KEY FIRST;

-- Add new P&L breakdown columns
ALTER TABLE business_profit
  ADD COLUMN total_revenue DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER period_end,
  ADD COLUMN total_cogs DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER total_revenue,
  ADD COLUMN gross_profit DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER total_cogs,
  ADD COLUMN total_expenses DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER gross_profit,
  ADD COLUMN salary_expenses DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER total_expenses,
  ADD COLUMN net_profit DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER salary_expenses,
  ADD COLUMN safety_net_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER net_profit,
  ADD COLUMN safety_net_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER safety_net_percent,
  ADD COLUMN distributable_profit DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER safety_net_amount,
  ADD COLUMN status ENUM('DRAFT','FINALIZED','DISTRIBUTED') NOT NULL DEFAULT 'DRAFT' AFTER distributable_profit,
  ADD COLUMN updated_at DATETIME NULL AFTER created_at;

-- Drop old columns
ALTER TABLE business_profit
  DROP COLUMN profit_bc,
  DROP COLUMN safety_net_type,
  DROP COLUMN safety_net_bc,
  DROP COLUMN distributable_profit_bc;

-- Add unique constraint on (business_id, period_start) to prevent overlapping periods
ALTER TABLE business_profit
  ADD UNIQUE KEY uq_bp_business_period (business_id, period_start);

-- ============================================================
-- 2. Create profit_distributions table
-- ============================================================

CREATE TABLE IF NOT EXISTS profit_distributions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  business_profit_id INT NOT NULL,
  partner_id INT NOT NULL,
  share_percent DECIMAL(5,2) NOT NULL,
  amount_bc DECIMAL(12,2) NOT NULL,
  payment_slip_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pd_profit (business_profit_id),
  KEY idx_pd_partner (partner_id),
  CONSTRAINT fk_pd_profit FOREIGN KEY (business_profit_id) REFERENCES business_profit(id) ON DELETE CASCADE,
  CONSTRAINT fk_pd_partner FOREIGN KEY (partner_id) REFERENCES payees(id),
  CONSTRAINT fk_pd_payment FOREIGN KEY (payment_slip_id) REFERENCES payment_slips(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 3. Alter partner_equity — replace reference_id with slip_type/slip_id
-- ============================================================

ALTER TABLE partner_equity
  ADD COLUMN slip_type ENUM('COLLECTION_SLIP','PAYMENT_SLIP') NULL AFTER amount,
  ADD COLUMN slip_id INT NULL AFTER slip_type,
  ADD COLUMN notes TEXT NULL AFTER slip_id;

-- Drop old reference_id column (no data to migrate since table is empty)
ALTER TABLE partner_equity
  DROP COLUMN reference_id;

