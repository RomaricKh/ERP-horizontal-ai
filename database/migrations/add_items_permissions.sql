-- Migration: Add missing Items & Stock module permissions
-- The items routes require these permission codes but they were never inserted
-- into the permissions table, causing hasPermission() to always return false
-- and all items action buttons (edit, delete, stock adjust, types, etc.) to be hidden.

-- Step 1: Insert the 7 missing permissions (idempotent via INSERT IGNORE)
INSERT IGNORE INTO permissions (name, code, created_at) VALUES
  ('items view',        'items.view',        NOW()),
  ('items create',      'items.create',      NOW()),
  ('items edit',        'items.edit',        NOW()),
  ('items delete',      'items.delete',      NOW()),
  ('item types manage', 'item-types.manage', NOW()),
  ('stock view',        'stock.view',        NOW()),
  ('stock adjust',      'stock.adjust',      NOW());

-- Step 2: Grant all 7 to BUSINESS_OWNER (role_id = 1)
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 1, id FROM permissions
WHERE code IN (
  'items.view',
  'items.create',
  'items.edit',
  'items.delete',
  'item-types.manage',
  'stock.view',
  'stock.adjust'
);

-- Step 3: Grant a working subset to ACCOUNTANT (role_id = 2)
-- Accountants can view, create, edit items and view/adjust stock, but not delete items or manage types
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 2, id FROM permissions
WHERE code IN (
  'items.view',
  'items.create',
  'items.edit',
  'stock.view',
  'stock.adjust'
);
