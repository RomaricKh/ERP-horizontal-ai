-- Add updated_at column to services table
ALTER TABLE services ADD COLUMN updated_at DATETIME NULL AFTER created_at;

-- Backfill existing rows with created_at value
UPDATE services SET updated_at = created_at;
