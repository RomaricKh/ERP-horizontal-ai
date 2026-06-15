-- Migration: Seed notification permissions
-- Adds notifications.view and notifications.send permissions
-- and grants them to appropriate roles.
-- This is idempotent — safe to run multiple times.

-- Step 1: Insert notification permissions (skips if already present)
INSERT IGNORE INTO permissions (name, code) VALUES
  ('notifications view', 'notifications.view'),
  ('notifications send', 'notifications.send');

-- Step 2: Grant notifications.view to all default roles
-- Business Owner (role_id = 1)
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 1, id FROM permissions WHERE code = 'notifications.view';

-- Accountant (role_id = 2)
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 2, id FROM permissions WHERE code = 'notifications.view';

-- Step 3: Grant notifications.send to Business Owner (role_id = 1)
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 1, id FROM permissions WHERE code = 'notifications.send';
