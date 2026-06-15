-- Fix sales_invoices.status enum to match the application workflow
-- Old enum: DRAFT, APPROVED, PAID, CANCELLED
-- New enum: DRAFT, DELIVERED, CANCELLED
-- (Payment status is computed from collection_slips, not stored in this column)

ALTER TABLE sales_invoices 
MODIFY COLUMN status ENUM('DRAFT', 'DELIVERED', 'CANCELLED') NOT NULL DEFAULT 'DRAFT';

-- Fix collection_slips.reference_type to include SALES_INVOICE
-- Old enum: CUSTOMER, PARTNER_CONTRIBUTION
ALTER TABLE collection_slips 
MODIFY COLUMN reference_type ENUM('CUSTOMER', 'PARTNER_CONTRIBUTION', 'SALES_INVOICE') NOT NULL;

-- Fix payment_slips.reference_type to include PURCHASE_ORDER
-- Old enum: EXPENSE, PO, SALARY, PARTNER_PROFIT
ALTER TABLE payment_slips 
MODIFY COLUMN reference_type ENUM('EXPENSE', 'PO', 'SALARY', 'PARTNER_PROFIT', 'PURCHASE_ORDER') NOT NULL;

-- Fix any invoices that had their status silently cleared due to the old enum mismatch
UPDATE sales_invoices SET status = 'DRAFT' WHERE status = '' OR status IS NULL;

-- Fix any collection slips that had reference_type silently cleared
UPDATE collection_slips SET reference_type = 'SALES_INVOICE' WHERE reference_type = '' AND reference_id IS NOT NULL;
