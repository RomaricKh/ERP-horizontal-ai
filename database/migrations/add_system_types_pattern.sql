-- Migration: Add system-types pattern to all classification tables
-- REQ-031: System-defined types with module visibility gating

-- ============================================================================
-- 1. ITEM_TYPES: Add system-type columns and seed data
-- ============================================================================

-- Add new columns
ALTER TABLE item_types
    ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0 AFTER name,
    ADD COLUMN module_code VARCHAR(50) NULL AFTER is_system,
    ADD COLUMN code VARCHAR(50) NULL AFTER module_code;

-- Seed system item types
INSERT INTO item_types (tenant_id, business_id, name, is_system, module_code, code, created_at, created_by)
VALUES
    (1, NULL, 'Trading Good', 1, NULL, 'TRADING', NOW(), NULL),
    (1, NULL, 'Consumable', 1, NULL, 'CONSUMABLE', NOW(), NULL),
    (1, NULL, 'Finished Good', 1, NULL, 'FINISHED_GOOD', NOW(), NULL),
    (1, NULL, 'Raw Material', 1, 'manufacturing', 'RAW_MATERIAL', NOW(), NULL),
    (1, NULL, 'Manufactured', 1, 'manufacturing', 'MANUFACTURED', NOW(), NULL),
    (1, NULL, 'Service Item', 1, 'services', 'SERVICE_ITEM', NOW(), NULL),
    (1, NULL, 'Asset Item', 1, 'assets', 'ASSET_ITEM', NOW(), NULL);

-- Map existing rows to system types where names match
UPDATE item_types SET is_system = 1, code = 'RAW_MATERIAL', module_code = 'manufacturing'
WHERE name = 'Raw Material' AND is_system = 0 AND code IS NULL;

UPDATE item_types SET is_system = 1, code = 'FINISHED_GOOD', module_code = NULL
WHERE name = 'Finished Good' AND is_system = 0 AND code IS NULL;

UPDATE item_types SET is_system = 1, code = 'SERVICE_ITEM', module_code = 'services'
WHERE name = 'Service Item' AND is_system = 0 AND code IS NULL;

UPDATE item_types SET is_system = 1, code = 'MANUFACTURED', module_code = 'manufacturing'
WHERE name = 'Manufactured' AND is_system = 0 AND code IS NULL;

-- Remove duplicate system types (keep the ones with lowest id per code per tenant)
DELETE t1 FROM item_types t1
INNER JOIN item_types t2
ON t1.code = t2.code AND t1.tenant_id = t2.tenant_id AND t1.is_system = 1 AND t2.is_system = 1
WHERE t1.id > t2.id;

-- ============================================================================
-- 2. PAYEE_TYPES: Add system-type columns and seed data
-- ============================================================================

ALTER TABLE payee_types
    ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0 AFTER name,
    ADD COLUMN module_code VARCHAR(50) NULL AFTER is_system,
    ADD COLUMN code VARCHAR(50) NULL AFTER module_code;

-- Map existing rows to system types
UPDATE payee_types SET is_system = 1, code = 'CUSTOMER' WHERE name = 'Customer' AND code IS NULL;
UPDATE payee_types SET is_system = 1, code = 'SUPPLIER' WHERE name = 'Supplier' AND code IS NULL;
UPDATE payee_types SET is_system = 1, code = 'EMPLOYEE' WHERE name = 'Employee' AND code IS NULL;
UPDATE payee_types SET is_system = 1, code = 'PARTNER' WHERE name = 'Partner' AND code IS NULL;

-- Insert OTHER if not exists
INSERT INTO payee_types (tenant_id, business_id, name, is_system, module_code, code, created_at)
SELECT 1, NULL, 'Other', 1, NULL, 'OTHER', NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM payee_types WHERE code = 'OTHER' AND tenant_id = 1);

-- ============================================================================
-- 3. PEOPLE_TYPES junction table (replaces business_payees_types)
-- ============================================================================

CREATE TABLE IF NOT EXISTS people_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    people_id INT NOT NULL,
    payee_type_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_people_type (people_id, payee_type_id),
    KEY idx_people_types_people (people_id),
    KEY idx_people_types_type (payee_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migrate data from business_payees_types to people_types (if table still exists)
-- business_payees_types has payee_id (FK→payees), payee_type_id
-- We need people_id from payees table
-- NOTE: If business_payees_types was already dropped (task 27), this step is skipped.
-- INSERT IGNORE INTO people_types (people_id, payee_type_id, created_at)
-- SELECT DISTINCT py.people_id, bpt.payee_type_id, bpt.created_at
-- FROM business_payees_types bpt
-- JOIN payees py ON py.id = bpt.payee_id
-- WHERE py.people_id IS NOT NULL;

-- ============================================================================
-- 4. EXPENSE_CATEGORIES: Add system-type columns and seed data
-- ============================================================================

ALTER TABLE expense_categories
    ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0 AFTER name,
    ADD COLUMN module_code VARCHAR(50) NULL AFTER is_system,
    ADD COLUMN code VARCHAR(50) NULL AFTER module_code;

-- Seed system expense categories (tenant_id=1, business_id=NULL for global)
INSERT INTO expense_categories (tenant_id, business_id, name, is_system, module_code, code, is_fixed, created_at)
VALUES
    (1, NULL, 'Rent', 1, NULL, 'RENT', 1, NOW()),
    (1, NULL, 'Utilities', 1, NULL, 'UTILITIES', 1, NOW()),
    (1, NULL, 'Marketing', 1, NULL, 'MARKETING', 0, NOW()),
    (1, NULL, 'Maintenance', 1, NULL, 'MAINTENANCE', 0, NOW()),
    (1, NULL, 'Transport', 1, NULL, 'TRANSPORT', 0, NOW()),
    (1, NULL, 'Other', 1, NULL, 'OTHER', 0, NOW());

-- ============================================================================
-- 5. SERVICE_CATEGORIES: Add system-type columns and seed data
-- ============================================================================

ALTER TABLE service_categories
    ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0 AFTER name,
    ADD COLUMN module_code VARCHAR(50) NULL AFTER is_system,
    ADD COLUMN code VARCHAR(50) NULL AFTER module_code;

-- Seed system service categories
INSERT INTO service_categories (tenant_id, business_id, name, is_system, module_code, code, created_by, created_at)
VALUES
    (1, 1, 'Consulting', 1, NULL, 'CONSULTING', 1, NOW()),
    (1, 1, 'Maintenance', 1, NULL, 'MAINTENANCE', 1, NOW()),
    (1, 1, 'Delivery', 1, NULL, 'DELIVERY', 1, NOW()),
    (1, 1, 'Installation', 1, NULL, 'INSTALLATION', 1, NOW()),
    (1, 1, 'Other', 1, NULL, 'OTHER', 1, NOW());

-- ============================================================================
-- 6. ASSET_CATEGORIES: Create table and seed data
-- ============================================================================

CREATE TABLE IF NOT EXISTS asset_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    business_id INT NULL,
    code VARCHAR(50) NULL,
    name VARCHAR(150) NOT NULL,
    module_code VARCHAR(50) NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    KEY idx_asset_categories_tenant (tenant_id),
    KEY idx_asset_categories_business (business_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed system asset categories
INSERT INTO asset_categories (tenant_id, business_id, code, name, module_code, is_system, created_at)
VALUES
    (1, NULL, 'LAND_BUILDINGS', 'Land & Buildings', NULL, 1, NOW()),
    (1, NULL, 'EQUIPMENT', 'Equipment', NULL, 1, NOW()),
    (1, NULL, 'VEHICLES', 'Vehicles', NULL, 1, NOW()),
    (1, NULL, 'FURNITURE', 'Furniture', NULL, 1, NOW()),
    (1, NULL, 'IT_EQUIPMENT', 'IT Equipment', NULL, 1, NOW()),
    (1, NULL, 'INTANGIBLE', 'Intangible Assets', NULL, 1, NOW()),
    (1, NULL, 'INVENTORY_ASSET', 'Inventory Asset', NULL, 1, NOW());

-- Add category_id FK to assets table (migrate from free-text category)
ALTER TABLE assets ADD COLUMN category_id INT NULL AFTER category;

-- Map existing free-text categories to asset_categories where possible
UPDATE assets a
JOIN asset_categories ac ON (
    LOWER(TRIM(a.category)) = LOWER(TRIM(ac.name))
    OR (LOWER(TRIM(a.category)) LIKE '%equipment%' AND ac.code = 'EQUIPMENT')
    OR (LOWER(TRIM(a.category)) LIKE '%vehicle%' AND ac.code = 'VEHICLES')
    OR (LOWER(TRIM(a.category)) LIKE '%furniture%' AND ac.code = 'FURNITURE')
    OR (LOWER(TRIM(a.category)) LIKE '%building%' AND ac.code = 'LAND_BUILDINGS')
    OR (LOWER(TRIM(a.category)) LIKE '%land%' AND ac.code = 'LAND_BUILDINGS')
    OR (LOWER(TRIM(a.category)) LIKE '%intangible%' AND ac.code = 'INTANGIBLE')
    OR (LOWER(TRIM(a.category)) LIKE '%it%' AND ac.code = 'IT_EQUIPMENT')
)
SET a.category_id = ac.id
WHERE a.category_id IS NULL;
