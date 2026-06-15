-- Migration: fix_tenant_owner_business_roles.sql
-- Removes any user_roles rows where the user is a tenant owner of the same tenant
-- AND the row has a non-null business_id (i.e., it's a business-scoped role).
-- This enforces the rule that tenant owners cannot hold business-level roles.

DELETE ur
FROM user_roles ur
INNER JOIN (
    SELECT DISTINCT ur2.user_id, ur2.tenant_id
    FROM user_roles ur2
    JOIN roles r ON r.id = ur2.role_id
    WHERE r.type = 'TENANT_OWNER'
) owners ON owners.user_id = ur.user_id AND owners.tenant_id = ur.tenant_id
WHERE ur.business_id IS NOT NULL;
