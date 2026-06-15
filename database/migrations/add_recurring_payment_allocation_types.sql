-- Migration: Extend payment_allocations reference_type enum
-- Add 'RECURRING_EXPENSE' and 'RECURRING_SALARY' to support recurring payment tracking
-- Requirements: 19.3, 19.7

ALTER TABLE payment_allocations
MODIFY COLUMN reference_type ENUM('PURCHASE_ORDER','EXPENSE','SALARY','PARTNER_PROFIT','RECURRING_EXPENSE','RECURRING_SALARY') NOT NULL;
