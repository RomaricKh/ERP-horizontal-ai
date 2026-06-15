-- Migration: Replace business_module_overrides with tenant-owned business types
-- REQ-011: Tenant-owned business types

-- Step 1: Drop the global unique constraint on code and add (code, tenant_id) composite
ALTER TABLE business_types DROP INDEX uq_business_types_code;
ALTER TABLE business_types ADD UNIQUE KEY uq_bt_code_tenant (code, tenant_id);

-- Step 2: For each tenant that has businesses pointing to a system type,
--         auto-create a tenant-owned copy and re-point the businesses.
--         We use a stored procedure to handle the cursor-based logic.

DROP PROCEDURE IF EXISTS migrate_system_types_to_tenant;

DELIMITER $$

CREATE PROCEDURE migrate_system_types_to_tenant()
BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE v_tenant_id INT;
    DECLARE v_business_type_id INT;
    DECLARE v_code VARCHAR(50);
    DECLARE v_name VARCHAR(150);
    DECLARE v_description TEXT;
    DECLARE v_new_type_id INT;

    -- Cursor: distinct (tenant_id, business_type_id) pairs where type is system
    DECLARE cur CURSOR FOR
        SELECT DISTINCT b.tenant_id, b.business_type_id, bt.code, bt.name, bt.description
        FROM businesses b
        JOIN business_types bt ON bt.id = b.business_type_id
        WHERE bt.is_system = 1;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;

    OPEN cur;

    read_loop: LOOP
        FETCH cur INTO v_tenant_id, v_business_type_id, v_code, v_name, v_description;
        IF done THEN
            LEAVE read_loop;
        END IF;

        -- Insert a tenant-owned copy of the system type
        INSERT INTO business_types (tenant_id, code, name, description, is_system, is_default, created_at)
        VALUES (v_tenant_id, v_code, v_name, v_description, 0, 0, NOW());

        SET v_new_type_id = LAST_INSERT_ID();

        -- Copy all business_type_modules rows from the system type to the new tenant type
        INSERT INTO business_type_modules (business_type_id, module_id, created_at)
        SELECT v_new_type_id, module_id, NOW()
        FROM business_type_modules
        WHERE business_type_id = v_business_type_id;

        -- Re-point businesses from the system type to the new tenant type
        UPDATE businesses
        SET business_type_id = v_new_type_id
        WHERE tenant_id = v_tenant_id
          AND business_type_id = v_business_type_id;

    END LOOP;

    CLOSE cur;
END$$

DELIMITER ;

-- Execute the migration procedure
CALL migrate_system_types_to_tenant();

-- Clean up the procedure
DROP PROCEDURE IF EXISTS migrate_system_types_to_tenant;

-- Step 3: Truncate business_module_overrides (no longer used)
TRUNCATE TABLE business_module_overrides;
