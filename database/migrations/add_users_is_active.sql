-- Migration: Add is_active column to users table
-- Allows disabling user accounts without deleting them

ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER email;
UPDATE users SET is_active = 1; -- ensure all existing users are active
