-- Migration: Create credit_notes, credit_note_items, debit_notes, debit_note_items tables
-- Tasks 24.1 and 25.1

-- Credit Notes (for sales returns)
CREATE TABLE IF NOT EXISTS credit_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    business_id INT NOT NULL,
    sales_invoice_id INT NOT NULL,
    credit_note_number VARCHAR(100) NOT NULL,
    credit_note_date DATE NOT NULL,
    status ENUM('DRAFT','ISSUED') NOT NULL DEFAULT 'DRAFT',
    currency VARCHAR(10) NOT NULL,
    exchange_rate DECIMAL(12,6) NOT NULL DEFAULT 1.000000,
    total_amount_fc DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_amount_bc DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL,
    INDEX idx_cn_business (business_id),
    INDEX idx_cn_invoice (sales_invoice_id),
    CONSTRAINT fk_cn_business FOREIGN KEY (business_id) REFERENCES businesses(id),
    CONSTRAINT fk_cn_invoice FOREIGN KEY (sales_invoice_id) REFERENCES sales_invoices(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Credit Note Items
CREATE TABLE IF NOT EXISTS credit_note_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    credit_note_id INT NOT NULL,
    item_id INT NULL,
    service_id INT NULL,
    quantity DECIMAL(12,3) NOT NULL,
    unit_price_fc DECIMAL(12,2) NOT NULL,
    unit_price_bc DECIMAL(12,2) NOT NULL,
    total_price_fc DECIMAL(12,2) NOT NULL,
    total_price_bc DECIMAL(12,2) NOT NULL,
    INDEX idx_cni_note (credit_note_id),
    CONSTRAINT fk_cni_note FOREIGN KEY (credit_note_id) REFERENCES credit_notes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Debit Notes (for purchase returns)
CREATE TABLE IF NOT EXISTS debit_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    business_id INT NOT NULL,
    purchase_order_id INT NOT NULL,
    debit_note_number VARCHAR(100) NOT NULL,
    debit_note_date DATE NOT NULL,
    status ENUM('DRAFT','ISSUED') NOT NULL DEFAULT 'DRAFT',
    currency VARCHAR(10) NOT NULL,
    exchange_rate DECIMAL(12,6) NOT NULL DEFAULT 1.000000,
    total_amount_fc DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_amount_bc DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL,
    INDEX idx_dn_business (business_id),
    INDEX idx_dn_po (purchase_order_id),
    CONSTRAINT fk_dn_business FOREIGN KEY (business_id) REFERENCES businesses(id),
    CONSTRAINT fk_dn_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Debit Note Items (items only, no services)
CREATE TABLE IF NOT EXISTS debit_note_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    debit_note_id INT NOT NULL,
    item_id INT NULL,
    quantity DECIMAL(12,3) NOT NULL,
    unit_price_fc DECIMAL(12,2) NOT NULL,
    unit_price_bc DECIMAL(12,2) NOT NULL,
    total_price_fc DECIMAL(12,2) NOT NULL,
    total_price_bc DECIMAL(12,2) NOT NULL,
    INDEX idx_dni_note (debit_note_id),
    CONSTRAINT fk_dni_note FOREIGN KEY (debit_note_id) REFERENCES debit_notes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
