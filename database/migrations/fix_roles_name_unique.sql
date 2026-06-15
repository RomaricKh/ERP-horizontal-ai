-- Migration: fix_roles_name_unique.sql
-- Drops the global UNIQUE constraint on roles.name so different businesses
-- can create roles with the same name (e.g., both having a "Manager" role).
-- The composite unique key uq_roles_business_name (business_id, name) already
-- exists and ensures name uniqueness within each business.

-- Drop the global unique index on name (prevents multi-business same-name roles)
ALTER TABLE roles DROP INDEX uq_roles_tenant_name;

-- The existing uq_roles_business_name (business_id, name) already enforces
-- that role names are unique per business. MySQL treats NULL as distinct in
-- unique indexes, so system roles (business_id IS NULL) with the same name
-- would NOT conflict — the application must ensure system role names remain unique.
