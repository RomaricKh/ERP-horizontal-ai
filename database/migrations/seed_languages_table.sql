-- Seed the languages table with supported language codes.
-- The settings_regional.language column has a FK to languages.code,
-- so these rows must exist before regional settings can be saved.

INSERT IGNORE INTO `languages` (`code`, `name`, `direction`) VALUES
('en', 'English', 'LTR'),
('ar', 'Arabic', 'RTL'),
('fr', 'French', 'LTR');
