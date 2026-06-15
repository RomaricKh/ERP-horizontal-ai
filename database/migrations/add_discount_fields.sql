-- Migration: Add discount_percent column to si_details, po_details, sales_invoices, purchase_orders
-- Supports per-line and per-document discount percentage

ALTER TABLE si_details
  ADD COLUMN discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER tax_amount_bc;

ALTER TABLE po_details
  ADD COLUMN discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER tax_amount_bc;

ALTER TABLE sales_invoices
  ADD COLUMN discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER tax_total_bc;

ALTER TABLE purchase_orders
  ADD COLUMN discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER tax_total_bc;
