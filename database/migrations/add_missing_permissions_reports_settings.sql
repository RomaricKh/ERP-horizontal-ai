-- Migration: Add missing permissions (reports.view, settings.manage, tenant.manage)
-- These permissions are referenced in routes but were never seeded.

-- Add missing permissions
INSERT IGNORE INTO permissions (code, name) VALUES
('reports.view', 'View financial reports (P&L, Cash Flow, Balance Sheet)'),
('settings.manage', 'Manage system settings (expiry threshold, etc.)'),
('tenant.manage', 'Manage tenant business types and configuration');

-- Assign new permissions to the TENANT_OWNER role (id=3)
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT 3, p.id FROM permissions p WHERE p.code IN ('reports.view', 'settings.manage', 'tenant.manage');

-- Also assign to any existing SYSTEM_OWNER role (id=1 if exists)
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r, permissions p
WHERE r.type = 'SYSTEM_OWNER'
  AND p.code IN ('reports.view', 'settings.manage', 'tenant.manage');
