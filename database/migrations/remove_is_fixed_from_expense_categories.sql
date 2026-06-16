-- Remove is_fixed from expense_categories — recurrence is a property of the expense, not the category
ALTER TABLE `expense_categories` DROP COLUMN `is_fixed`;
