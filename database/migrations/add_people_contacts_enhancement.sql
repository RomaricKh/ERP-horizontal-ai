-- Migration: People & Contacts Enhancement
-- Feature: people-contacts-enhancement
-- Description: Adds phone uniqueness support and performance indexes for statement queries

-- --------------------------------------------------------
-- Task 1.1: Add normalized_phone column to people table
-- This column stores the digits-only version of the phone
-- number, maintained by the application layer on create/update.
-- --------------------------------------------------------

SET @col_normalized_phone_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'people'
    AND COLUMN_NAME = 'normalized_phone'
);

SET @add_normalized_phone_sql = IF(@col_normalized_phone_exists = 0,
  'ALTER TABLE `people` ADD COLUMN `normalized_phone` VARCHAR(50) NULL AFTER `phone`',
  'SELECT 1'
);

PREPARE stmt FROM @add_normalized_phone_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- --------------------------------------------------------
-- Unique index on normalized_phone within tenant.
-- Ensures no two people in the same tenant share the same
-- phone number (after normalization).
-- --------------------------------------------------------

CREATE UNIQUE INDEX idx_people_normalized_phone_tenant
ON people (tenant_id, normalized_phone);

-- --------------------------------------------------------
-- NOTE: Before running the ALTER below, backfill existing records:
--
--   UPDATE people
--   SET phone = COALESCE(phone, ''),
--       normalized_phone = REGEXP_REPLACE(phone, '[^0-9]', '')
--   WHERE phone IS NULL OR normalized_phone IS NULL;
--
-- Resolve any duplicate normalized_phone values within the
-- same tenant before applying the NOT NULL constraint.
-- --------------------------------------------------------

ALTER TABLE people MODIFY phone VARCHAR(50) NOT NULL;

-- --------------------------------------------------------
-- Performance indexes for statement-of-account queries.
-- These cover the common access pattern of fetching all
-- transactions for a specific payee within a date range.
-- --------------------------------------------------------

CREATE INDEX idx_expenses_payee_date
ON expenses (payee_id, expense_date);

CREATE INDEX idx_purchase_orders_supplier_date
ON purchase_orders (supplier_id, po_date);

CREATE INDEX idx_sales_invoices_customer_date
ON sales_invoices (customer_id, invoice_date);

CREATE INDEX idx_payment_slips_payee_date
ON payment_slips (payee_id, payment_date);

CREATE INDEX idx_collection_slips_payee_date
ON collection_slips (payee_id, collection_date);
