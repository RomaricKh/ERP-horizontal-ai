-- Migration: Rename ownership_pct to share_percent in partner_shares table
-- The codebase consistently uses share_percent but the column was named ownership_pct

ALTER TABLE partner_shares CHANGE ownership_pct share_percent DECIMAL(5,2) NOT NULL;
