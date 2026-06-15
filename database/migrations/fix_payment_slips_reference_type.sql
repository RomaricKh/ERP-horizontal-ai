-- Migration: fix_payment_slips_reference_type.sql
-- Standardises payment_slips.reference_type: removes duplicate 'PO' value.

-- Step 1: Update any existing rows that used the old 'PO' value
UPDATE payment_slips SET reference_type = 'PURCHASE_ORDER' WHERE reference_type = 'PO';

-- Step 2: Remove 'PO' from the ENUM, keeping only the canonical values
ALTER TABLE payment_slips
  MODIFY COLUMN reference_type ENUM('EXPENSE','SALARY','PARTNER_PROFIT','PURCHASE_ORDER') NOT NULL;
