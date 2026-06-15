<?php
/**
 * Runner script for migrate_tenant_owners_to_roles migration.
 * Executes each step individually with error reporting.
 */

$pdo = new PDO('mysql:host=localhost;dbname=erp;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

echo "Connected to database.\n\n";

// First, show current state of user_roles
echo "=== Current user_roles structure ===\n";
$stmt = $pdo->query("SHOW CREATE TABLE user_roles");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
echo $row['Create Table'] . "\n\n";

// Check if tenant_owners table exists
$stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'erp' AND TABLE_NAME = 'tenant_owners'");
$hasTenantOwners = (int)$stmt->fetchColumn();
echo "tenant_owners table exists: " . ($hasTenantOwners ? "YES" : "NO") . "\n\n";

// Check current roles.type enum
$stmt = $pdo->query("SHOW COLUMNS FROM roles LIKE 'type'");
$typeCol = $stmt->fetch(PDO::FETCH_ASSOC);
echo "roles.type column: " . $typeCol['Type'] . "\n\n";

echo "=== Starting Migration ===\n\n";

try {
    $pdo->beginTransaction();

    // Step 1: Add TENANT_OWNER to roles.type ENUM
    echo "Step 1: Modifying roles.type ENUM...\n";
    $pdo->exec("ALTER TABLE `roles` MODIFY COLUMN `type` ENUM('SYSTEM_OWNER', 'CUSTOM', 'TEMPLATE', 'TENANT_OWNER') NOT NULL DEFAULT 'CUSTOM'");
    echo "  Done.\n\n";

    // Step 2: Update CHECK constraint
    echo "Step 2: Updating CHECK constraint...\n";
    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = 'erp' AND TABLE_NAME = 'roles' AND CONSTRAINT_NAME = 'chk_roles_business_scope' AND CONSTRAINT_TYPE = 'CHECK'");
    $constraintExists = (int)$stmt->fetchColumn();
    if ($constraintExists) {
        $pdo->exec("ALTER TABLE `roles` DROP CHECK `chk_roles_business_scope`");
        echo "  Dropped existing constraint.\n";
    }
    $pdo->exec("ALTER TABLE `roles` ADD CONSTRAINT `chk_roles_business_scope` CHECK (
        (type IN ('SYSTEM_OWNER', 'CUSTOM') AND business_id IS NOT NULL) OR
        (type IN ('TEMPLATE', 'TENANT_OWNER') AND business_id IS NULL)
    )");
    echo "  Added new constraint.\n\n";

    // Step 3: Create TENANT_OWNER roles per tenant
    echo "Step 3: Creating TENANT_OWNER roles per tenant...\n";
    $stmt = $pdo->exec("INSERT INTO `roles` (`tenant_id`, `business_id`, `name`, `description`, `type`)
        SELECT DISTINCT t.id, NULL, 'Tenant Owner', 'Immutable tenant-wide owner role', 'TENANT_OWNER'
        FROM `tenants` t
        WHERE NOT EXISTS (
            SELECT 1 FROM `roles` r WHERE r.tenant_id = t.id AND r.type = 'TENANT_OWNER'
        )");
    echo "  Inserted $stmt row(s).\n\n";

    // Step 4: Alter user_roles table
    echo "Step 4: Restructuring user_roles table...\n";

    // Check if already migrated
    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'erp' AND TABLE_NAME = 'user_roles' AND COLUMN_NAME = 'id'");
    $idExists = (int)$stmt->fetchColumn();

    if ($idExists) {
        echo "  Already migrated (id column exists). Skipping.\n\n";
    } else {
        // Find which FKs exist
        $fks = [];
        foreach (['fk_ur_business', 'fk_ur_role', 'fk_ur_user', 'fk_ur_tenant'] as $fkName) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = 'erp' AND TABLE_NAME = 'user_roles' AND CONSTRAINT_NAME = '$fkName' AND CONSTRAINT_TYPE = 'FOREIGN KEY'");
            if ((int)$stmt->fetchColumn() > 0) {
                $fks[] = $fkName;
            }
        }
        echo "  Existing FKs: " . (empty($fks) ? "none" : implode(', ', $fks)) . "\n";

        // Check if PK exists
        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = 'erp' AND TABLE_NAME = 'user_roles' AND INDEX_NAME = 'PRIMARY'");
        $hasPk = (int)$stmt->fetchColumn() > 0;
        echo "  Has PRIMARY KEY: " . ($hasPk ? "YES" : "NO") . "\n";

        // Build ALTER statement
        $parts = [];
        foreach ($fks as $fk) {
            $parts[] = "DROP FOREIGN KEY `$fk`";
        }
        // Drop leftover index keys (only if they exist as actual indexes)
        $stmt2 = $pdo->query("SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = 'erp' AND TABLE_NAME = 'user_roles' AND INDEX_NAME != 'PRIMARY'");
        $existingIndexes = $stmt2->fetchAll(PDO::FETCH_COLUMN);
        echo "  Existing indexes: " . implode(', ', $existingIndexes) . "\n";
        foreach ($fks as $fk) {
            if (in_array($fk, $existingIndexes)) {
                $parts[] = "DROP INDEX `$fk`";
            }
        }
        if ($hasPk) {
            $parts[] = "DROP PRIMARY KEY";
        }
        $parts[] = "ADD COLUMN `id` INT AUTO_INCREMENT PRIMARY KEY FIRST";
        $parts[] = "MODIFY COLUMN `business_id` INT NULL DEFAULT NULL";

        $sql = "ALTER TABLE `user_roles` " . implode(', ', $parts);
        echo "  SQL (restructure): $sql\n";
        $pdo->exec($sql);
        echo "  Restructured.\n";

        // Now re-add FKs and unique index in a separate ALTER
        $sql2 = "ALTER TABLE `user_roles`
            ADD CONSTRAINT `fk_ur_business` FOREIGN KEY (`business_id`) REFERENCES `businesses` (`id`),
            ADD CONSTRAINT `fk_ur_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`),
            ADD CONSTRAINT `fk_ur_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
            ADD UNIQUE INDEX `uq_user_role_business` (`user_id`, `role_id`, `business_id`)";
        echo "  SQL (constraints): $sql2\n";
        $pdo->exec($sql2);
        echo "  Done.\n\n";
    }

    // Step 5: Migrate tenant_owners to user_roles
    echo "Step 5: Migrating tenant_owners to user_roles...\n";
    if ($hasTenantOwners) {
        $count = $pdo->exec("INSERT INTO `user_roles` (`user_id`, `role_id`, `business_id`)
            SELECT
                tow.user_id,
                r.id AS role_id,
                NULL
            FROM `tenant_owners` tow
            JOIN `roles` r ON r.tenant_id = tow.tenant_id AND r.type = 'TENANT_OWNER'
            WHERE NOT EXISTS (
                SELECT 1 FROM `user_roles` ur
                WHERE ur.user_id = tow.user_id AND ur.role_id = r.id
            )");
        echo "  Migrated $count row(s).\n\n";
    } else {
        echo "  No tenant_owners table found. Skipping.\n\n";
    }

    // Step 6: Drop tenant_owners
    echo "Step 6: Dropping tenant_owners table...\n";
    $pdo->exec("DROP TABLE IF EXISTS `tenant_owners`");
    echo "  Done.\n\n";

    $pdo->commit();
    echo "=== Migration completed successfully! ===\n";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "\n!!! ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Migration rolled back.\n";
    exit(1);
}
