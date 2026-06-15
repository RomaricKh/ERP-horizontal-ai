-- Migration: Drop deprecated and orphaned tables
-- REQ-027: Remove tables that are no longer used by the application
--
-- Tables dropped (in dependency order — child/orphaned tables first):
--   1. notification_roles     — orphaned, 0 rows, no code references
--   2. business_payees_types  — old junction table, replaced by people_types (REQ-031)
--   3. business_module_overrides — replaced by tenant-owned business types (REQ-011)
--   4. user_notifications     — replaced by direct notifications table usage (REQ-012)
--
-- Uses DROP TABLE IF EXISTS for idempotency.

-- 1. notification_roles: orphaned concept, never implemented, 0 rows
DROP TABLE IF EXISTS `notification_roles`;

-- 2. business_payees_types: old payee-type junction, being replaced
DROP TABLE IF EXISTS `business_payees_types`;

-- 3. business_module_overrides: replaced by tenant-owned business types (task 11)
DROP TABLE IF EXISTS `business_module_overrides`;

-- 4. user_notifications: replaced by direct notifications table usage (task 12)
DROP TABLE IF EXISTS `user_notifications`;
