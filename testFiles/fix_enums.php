<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/Config/config.php';

use App\Config\Database;

$pdo = Database::connect($config['db']);

// Fix 1: collection_slips.reference_type - add SALES_INVOICE
echo "Fixing collection_slips.reference_type...\n";
$pdo->exec("
    ALTER TABLE collection_slips 
    MODIFY COLUMN reference_type ENUM('CUSTOMER','PARTNER_CONTRIBUTION','SALES_INVOICE') NOT NULL
");
$stmt = $pdo->query("SHOW COLUMNS FROM collection_slips WHERE Field = 'reference_type'");
echo "  Now: " . $stmt->fetch(PDO::FETCH_ASSOC)['Type'] . "\n";

// Fix 2: payment_slips.reference_type - add PURCHASE_ORDER
echo "Fixing payment_slips.reference_type...\n";
$pdo->exec("
    ALTER TABLE payment_slips 
    MODIFY COLUMN reference_type ENUM('EXPENSE','PO','SALARY','PARTNER_PROFIT','PURCHASE_ORDER') NOT NULL
");
$stmt2 = $pdo->query("SHOW COLUMNS FROM payment_slips WHERE Field = 'reference_type'");
echo "  Now: " . $stmt2->fetch(PDO::FETCH_ASSOC)['Type'] . "\n";

// Fix 3: Update the existing collection slip that has empty reference_type
// (it was supposed to be SALES_INVOICE but the enum rejected it)
$pdo->exec("
    UPDATE collection_slips 
    SET reference_type = 'SALES_INVOICE' 
    WHERE reference_type = '' AND reference_id IS NOT NULL
");
$affected = $pdo->query("SELECT ROW_COUNT()")->fetchColumn();
echo "Fixed {$affected} collection slip(s) with empty reference_type.\n";

echo "Done!\n";
