-- ============================================================================
-- Migration: Merge item_auto_markup and item_cost_flags into items table
-- REQ-028: item_auto_markup is a one-to-one table whose columns belong on items.
--          item_cost_flags duplicates data already in item_price_history;
--          items.has_cost_change_flag is the authoritative dismissed state.
-- ============================================================================

-- Step 1: Add markup columns to items table
ALTER TABLE items
  ADD COLUMN markup_percent DECIMAL(5,2) NULL AFTER min_stock,
  ADD COLUMN markup_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER markup_percent;

-- Step 2: Copy data from item_auto_markup into the new items columns
UPDATE items i
JOIN item_auto_markup iam ON iam.item_id = i.id
SET i.markup_percent = iam.markup_percent,
    i.markup_enabled = iam.is_enabled;

-- Step 3: Drop the auxiliary tables (no longer needed)
DROP TABLE IF EXISTS item_auto_markup;
DROP TABLE IF EXISTS item_cost_flags;
