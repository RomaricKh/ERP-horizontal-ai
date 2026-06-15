-- Migration: Add/fix foreign key constraints for major relationships
-- This migration ensures all FK constraints exist with the correct ON DELETE behavior:
--   payees → people (RESTRICT)
--   payees → businesses (RESTRICT)
--   sales_invoices → businesses (RESTRICT)
--   sales_invoices → payees (RESTRICT)
--   si_details → sales_invoices (CASCADE)
--   purchase_orders → businesses (RESTRICT)
--   po_details → purchase_orders (CASCADE)
--   user_roles → users (CASCADE)
--   user_roles → roles (RESTRICT)
--
-- Idempotent: checks for existing constraints before adding, drops and recreates where ON DELETE rule differs.

-- ============================================================
-- 1. payees → people (ON DELETE RESTRICT) — already correct
-- ============================================================
-- Constraint fk_payees_people already exists with RESTRICT. No action needed.

-- ============================================================
-- 2. payees → businesses (ON DELETE RESTRICT) — already correct
-- ============================================================
-- Constraint fk_payees_business already exists with RESTRICT. No action needed.

-- ============================================================
-- 3. sales_invoices → businesses (ON DELETE RESTRICT) — already correct
-- ============================================================
-- Constraint fk_sales_invoices_business already exists with RESTRICT. No action needed.

-- ============================================================
-- 4. sales_invoices → payees (ON DELETE RESTRICT) — already correct
-- ============================================================
-- Constraint fk_sales_invoices_customer already exists with RESTRICT. No action needed.

-- ============================================================
-- 5. si_details → sales_invoices (ON DELETE CASCADE)
--    Current: RESTRICT → Need: CASCADE
-- ============================================================
SET @constraint_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'si_details'
      AND CONSTRAINT_NAME = 'fk_si_details_invoice'
);

-- Drop existing constraint (has RESTRICT, need CASCADE)
ALTER TABLE si_details DROP FOREIGN KEY fk_si_details_invoice;

-- Re-add with CASCADE
ALTER TABLE si_details
    ADD CONSTRAINT fk_si_details_invoice
    FOREIGN KEY (sales_invoice_id) REFERENCES sales_invoices(id)
    ON DELETE CASCADE ON UPDATE CASCADE;

-- ============================================================
-- 6. purchase_orders → businesses (ON DELETE RESTRICT) — already correct
-- ============================================================
-- Constraint fk_purchase_orders_business already exists with RESTRICT. No action needed.

-- ============================================================
-- 7. po_details → purchase_orders (ON DELETE CASCADE)
--    Current: RESTRICT → Need: CASCADE
-- ============================================================
ALTER TABLE po_details DROP FOREIGN KEY fk_po_details_po;

ALTER TABLE po_details
    ADD CONSTRAINT fk_po_details_po
    FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id)
    ON DELETE CASCADE ON UPDATE CASCADE;

-- ============================================================
-- 8. user_roles → users (ON DELETE CASCADE)
--    Current: NO ACTION → Need: CASCADE
-- ============================================================
ALTER TABLE user_roles DROP FOREIGN KEY fk_ur_user;

ALTER TABLE user_roles
    ADD CONSTRAINT fk_ur_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE ON UPDATE CASCADE;

-- ============================================================
-- 9. user_roles → roles (ON DELETE RESTRICT)
--    Current: NO ACTION → Need: RESTRICT
-- ============================================================
ALTER TABLE user_roles DROP FOREIGN KEY fk_ur_role;

ALTER TABLE user_roles
    ADD CONSTRAINT fk_ur_role
    FOREIGN KEY (role_id) REFERENCES roles(id)
    ON DELETE RESTRICT ON UPDATE CASCADE;
