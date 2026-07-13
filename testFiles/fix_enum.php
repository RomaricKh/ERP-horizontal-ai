<?php
/**
 * Quick migration: fix sales_invoices.status enum to include DELIVERED
 */
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/Config/config.php';

use App\Config\Database;

$pdo = Database::connect($config['db']);

// Check current column definition
$stmt = $pdo->query("SHOW COLUMNS FROM sales_invoices WHERE Field = 'status'");
$col = $stmt->fetch(PDO::FETCH_ASSOC);
echo "Current status column: " . $col['Type'] . "\n";

// Alter the enum to include DELIVERED and remove APPROVED/PAID (those are computed)
$pdo->exec("
    ALTER TABLE sales_invoices 
    MODIFY COLUMN status ENUM('DRAFT', 'DELIVERED', 'CANCELLED') NOT NULL DEFAULT 'DRAFT'
");

// Verify
$stmt2 = $pdo->query("SHOW COLUMNS FROM sales_invoices WHERE Field = 'status'");
$col2 = $stmt2->fetch(PDO::FETCH_ASSOC);
echo "Updated status column: " . $col2['Type'] . "\n";

// Fix any invoices that had their status silently cleared
$pdo->exec("
    UPDATE sales_invoices SET status = 'DRAFT' WHERE status = '' OR status IS NULL
");

$affected = $pdo->query("SELECT ROW_COUNT()")->fetchColumn();
echo "Fixed {$affected} invoice(s) with empty status back to DRAFT.\n";

echo "Done!\n";
