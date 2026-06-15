-- Migration: Add salaries module to all business types
-- Currently salaries is only included in the "general" type.
-- This adds it to retail, wholesale, manufacturing, and service.

INSERT IGNORE INTO `business_type_modules` (`business_type_id`, `module_id`)
SELECT bt.id, m.id
FROM `business_types` bt
CROSS JOIN `modules` m
WHERE m.`code` = 'salaries'
  AND bt.`code` IN ('retail', 'wholesale', 'manufacturing', 'service');
