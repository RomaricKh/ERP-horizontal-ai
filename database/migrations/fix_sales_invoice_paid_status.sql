-- Migration: fix_sales_invoice_paid_status.sql
-- Adds 'PAID' to sales_invoices.status ENUM and backfills fully-paid DELIVERED invoices.

-- Step 1: Add PAID to the ENUM
ALTER TABLE sales_invoices
  MODIFY COLUMN status ENUM('DRAFT','DELIVERED','PAID','CANCELLED') NOT NULL DEFAULT 'DRAFT';

-- Step 2: Mark fully-paid DELIVERED invoices as PAID
-- An invoice is fully paid when sum of collection slips >= total_amount_bc
UPDATE sales_invoices si
SET si.status = 'PAID'
WHERE si.status = 'DELIVERED'
  AND si.total_amount_bc > 0
  AND (
    SELECT COALESCE(SUM(cs.amount_bc), 0)
    FROM collection_slips cs
    WHERE cs.reference_id  = si.id
      AND cs.business_id   = si.business_id
      AND cs.reference_type = 'SALES_INVOICE'
  ) >= si.total_amount_bc;
