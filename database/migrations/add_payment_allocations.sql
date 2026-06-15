-- Migration: Add payment_allocations and collection_allocations tables
-- Task 21.1: Create allocation tables
-- Task 21.2: Data migration (move reference_type/reference_id data to allocations)
-- Task 21.3: Drop reference_type and reference_id from payment_slips and collection_slips

-- ============================================================
-- STEP 1: Create payment_allocations table
-- ============================================================
CREATE TABLE IF NOT EXISTS payment_allocations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_slip_id INT NOT NULL,
    reference_type ENUM('PURCHASE_ORDER','EXPENSE','SALARY','PARTNER_PROFIT') NOT NULL,
    reference_id INT NOT NULL,
    amount_fc DECIMAL(12,2) NOT NULL,
    amount_bc DECIMAL(12,2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pa_slip (payment_slip_id),
    INDEX idx_pa_ref (reference_type, reference_id),
    CONSTRAINT fk_pa_payment_slip FOREIGN KEY (payment_slip_id) REFERENCES payment_slips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- STEP 2: Create collection_allocations table
-- ============================================================
CREATE TABLE IF NOT EXISTS collection_allocations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    collection_slip_id INT NOT NULL,
    reference_type ENUM('SALES_INVOICE') NOT NULL,
    reference_id INT NOT NULL,
    amount_fc DECIMAL(12,2) NOT NULL,
    amount_bc DECIMAL(12,2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ca_slip (collection_slip_id),
    INDEX idx_ca_ref (reference_type, reference_id),
    CONSTRAINT fk_ca_collection_slip FOREIGN KEY (collection_slip_id) REFERENCES collection_slips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- STEP 3: Data migration — move existing reference data to allocations
-- For payment_slips: insert allocation row with full slip amount
-- ============================================================
INSERT INTO payment_allocations (payment_slip_id, reference_type, reference_id, amount_fc, amount_bc, created_at)
SELECT ps.id, ps.reference_type, ps.reference_id, ps.amount_fc, ps.amount_bc, ps.created_at
FROM payment_slips ps
WHERE ps.reference_type IS NOT NULL
  AND ps.reference_id IS NOT NULL
  AND ps.reference_id > 0;

-- For collection_slips: only migrate SALES_INVOICE references (not CUSTOMER or PARTNER_CONTRIBUTION)
INSERT INTO collection_allocations (collection_slip_id, reference_type, reference_id, amount_fc, amount_bc, created_at)
SELECT cs.id, 'SALES_INVOICE', cs.reference_id, cs.amount_fc, cs.amount_bc, cs.created_at
FROM collection_slips cs
WHERE cs.reference_type = 'SALES_INVOICE'
  AND cs.reference_id IS NOT NULL
  AND cs.reference_id > 0;

-- ============================================================
-- STEP 4: Drop reference_type and reference_id from both tables
-- Data now lives in the allocation tables
-- ============================================================
ALTER TABLE payment_slips DROP COLUMN reference_type;
ALTER TABLE payment_slips DROP COLUMN reference_id;

ALTER TABLE collection_slips DROP COLUMN reference_type;
ALTER TABLE collection_slips DROP COLUMN reference_id;
