-- Fix recipes.yield_quantity type: change from INT to DECIMAL(12,4)
-- to support fractional yield quantities (e.g., 1.5 units per batch)
ALTER TABLE recipes MODIFY COLUMN yield_quantity DECIMAL(12,4) NOT NULL DEFAULT 1.0000;
