ALTER TABLE business_accounts ADD COLUMN balance_fc DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER balance;
