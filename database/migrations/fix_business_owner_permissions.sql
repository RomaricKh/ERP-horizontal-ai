-- Fix: Remove dummy demo permissions and ensure all ERP permissions exist.
-- Then grant ALL permissions to BUSINESS_OWNER (role_id = 1).
-- This is idempotent — safe to run multiple times.

-- Step 1: Remove dummy demo permissions (Charts, Tables) that are no longer in the UI
DELETE FROM role_permissions WHERE permission_id IN (
    SELECT id FROM permissions WHERE code IN ('charts.view', 'tables.view')
);
DELETE FROM permissions WHERE code IN ('charts.view', 'tables.view');

-- Step 2: Insert all ERP module permissions (skips if already present)
INSERT IGNORE INTO permissions (name, code) VALUES
  -- Purchase Orders
  ('po view',               'po.view'),
  ('po create',             'po.create'),
  ('po edit',               'po.edit'),
  ('po delete',             'po.delete'),
  ('po approve',            'po.approve'),
  ('po receive',            'po.receive'),
  -- Sales Invoices
  ('invoices view',         'invoices.view'),
  ('invoices create',       'invoices.create'),
  ('invoices edit',         'invoices.edit'),
  ('invoices delete',       'invoices.delete'),
  ('invoices approve',      'invoices.approve'),
  ('invoices pay',          'invoices.pay'),
  -- Expenses
  ('expenses view',         'expenses.view'),
  ('expenses create',       'expenses.create'),
  ('expenses edit',         'expenses.edit'),
  ('expenses delete',       'expenses.delete'),
  ('expenses manage',       'expenses.manage'),
  -- Salaries
  ('salaries view',         'salaries.view'),
  ('salaries create',       'salaries.create'),
  ('salaries edit',         'salaries.edit'),
  ('salaries delete',       'salaries.delete'),
  -- Accounts
  ('accounts manage',       'accounts.manage'),
  -- Exchange Rates
  ('exchange rates manage', 'exchange-rates.manage'),
  -- Payments
  ('payments view',         'payments.view'),
  ('payments create',       'payments.create'),
  ('payments delete',       'payments.delete'),
  -- Collections
  ('collections view',      'collections.view'),
  ('collections create',    'collections.create'),
  ('collections delete',    'collections.delete');

-- Step 3: Grant ALL permissions to BUSINESS_OWNER (role_id = 1)
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 1, p.id
FROM permissions p
WHERE p.id NOT IN (
    SELECT rp.permission_id
    FROM role_permissions rp
    WHERE rp.role_id = 1
);

-- Verify: show all permissions now assigned to BUSINESS_OWNER
SELECT p.code, p.name
FROM role_permissions rp
JOIN permissions p ON p.id = rp.permission_id
WHERE rp.role_id = 1
ORDER BY p.code;
