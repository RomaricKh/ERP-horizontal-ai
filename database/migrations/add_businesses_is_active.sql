-- Migration: Add is_active column to businesses table
-- Requirement: REQ-010
-- Allows tenant owners to suspend/reactivate businesses

ALTER TABLE businesses ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER email;
UPDATE businesses SET is_active = 1;
