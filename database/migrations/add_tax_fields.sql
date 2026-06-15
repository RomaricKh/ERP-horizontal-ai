-- Migration: Add tax fields to si_details, po_details, sales_invoices, purchase_orders
-- Task 14.1

-- Add tax columns to si_details (line-level)
ALTER TABLE si_details
  ADD COLUMN tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER total_price_bc,
  ADD COLUMN tax_amount_fc DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER tax_rate,
  ADD COLUMN tax_amount_bc DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER tax_amount_fc;

-- Add tax columns to po_details (line-level)
ALTER TABLE po_details
  ADD COLUMN tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER total_price_bc,
  ADD COLUMN tax_amount_fc DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER tax_rate,
  ADD COLUMN tax_amount_bc DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER tax_amount_fc;

-- Add tax total columns to sales_invoices (header-level)
ALTER TABLE sales_invoices
  ADD COLUMN tax_total_fc DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER total_amount_bc,
  ADD COLUMN tax_total_bc DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER tax_total_fc;

-- Add tax total columns to purchase_orders (header-level)
ALTER TABLE purchase_orders
  ADD COLUMN tax_total_fc DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER total_amount_bc,
  ADD COLUMN tax_total_bc DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER tax_total_fc;
