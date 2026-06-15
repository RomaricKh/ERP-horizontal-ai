-- Migration: Add ERP module permissions
-- Run this SQL against your MySQL database before using the new ERP modules.
-- Generated from: .kiro/specs/erp-completion/design.md § "Permissions to Add to DB"

INSERT INTO permissions (name, code, created_at) VALUES
('exchange rates manage', 'exchange-rates.manage', NOW()),
('accounts manage',       'accounts.manage',       NOW()),
('expenses view',         'expenses.view',         NOW()),
('expenses create',       'expenses.create',       NOW()),
('expenses edit',         'expenses.edit',         NOW()),
('expenses delete',       'expenses.delete',       NOW()),
('expenses manage',       'expenses.manage',       NOW()),
('po view',               'po.view',               NOW()),
('po create',             'po.create',             NOW()),
('po edit',               'po.edit',               NOW()),
('po delete',             'po.delete',             NOW()),
('po approve',            'po.approve',            NOW()),
('po receive',            'po.receive',            NOW()),
('invoices view',         'invoices.view',         NOW()),
('invoices create',       'invoices.create',       NOW()),
('invoices edit',         'invoices.edit',         NOW()),
('invoices delete',       'invoices.delete',       NOW()),
('invoices approve',      'invoices.approve',      NOW()),
('invoices pay',          'invoices.pay',          NOW()),
('payments view',         'payments.view',         NOW()),
('payments create',       'payments.create',       NOW()),
('payments delete',       'payments.delete',       NOW()),
('collections view',      'collections.view',      NOW()),
('collections create',    'collections.create',    NOW()),
('collections delete',    'collections.delete',    NOW()),
('salaries view',         'salaries.view',         NOW()),
('salaries create',       'salaries.create',       NOW()),
('salaries edit',         'salaries.edit',         NOW()),
('salaries delete',       'salaries.delete',       NOW());

-- Grant all new permissions to Business_Owner (role_id = 1)
-- NOTE: Assumes the 25 existing permissions have IDs 1–25.
--       Adjust the threshold if your permissions table has a different count.
INSERT INTO role_permissions (role_id, permission_id)
SELECT 1, id FROM permissions WHERE id > 25;

-- Grant a subset of new permissions to Accountant (role_id = 2)
INSERT INTO role_permissions (role_id, permission_id)
SELECT 2, id FROM permissions
WHERE code IN (
  'exchange-rates.manage',
  'accounts.manage',
  'expenses.view',
  'expenses.create',
  'expenses.edit',
  'expenses.manage',
  'po.view',
  'po.create',
  'po.edit',
  'po.approve',
  'po.receive',
  'invoices.view',
  'invoices.create',
  'invoices.edit',
  'invoices.approve',
  'invoices.pay',
  'payments.view',
  'payments.create',
  'collections.view',
  'collections.create',
  'salaries.view',
  'salaries.create',
  'salaries.edit'
);
