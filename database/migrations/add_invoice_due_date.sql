-- Migration: Add due_date column to sales_invoices
-- Supports AR aging report and overdue tracking

ALTER TABLE sales_invoices
  ADD COLUMN due_date DATE NULL AFTER invoice_date;
