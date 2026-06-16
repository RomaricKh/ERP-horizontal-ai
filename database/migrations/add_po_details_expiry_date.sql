-- Migration: Add expiry_date column to po_details for batch tracking on receive
-- When an item has expiry tracking, each line can specify the expiry date for that batch.
-- Multiple lines per item = multiple batches with different expiry dates.

ALTER TABLE `po_details`
  ADD COLUMN `expiry_date` date DEFAULT NULL AFTER `discount_percent`;
