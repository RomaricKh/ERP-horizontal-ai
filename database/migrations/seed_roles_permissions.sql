-- Migration: Seed roles management permissions
-- Adds roles.view, roles.create, roles.edit, roles.delete permissions
-- and grants them to Business Owner (role_id = 1).
-- This is idempotent — safe to run multiple times.

-- Step 1: Insert roles management permissions (skips if already present)
INSERT IGNORE INTO permissions (name, code) VALUES
  ('roles view',   'roles.view'),
  ('roles create', 'roles.create'),
  ('roles edit',   'roles.edit'),
  ('roles delete', 'roles.delete');

-- Step 2: Grant all roles.* permissions to Business Owner (role_id = 1)
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 1, id FROM permissions WHERE code LIKE 'roles.%';
