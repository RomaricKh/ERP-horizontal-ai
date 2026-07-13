<?php
$db = new PDO('mysql:host=localhost;dbname=erp', 'root', '');

$tables = ['sales_invoices', 'si_details', 'purchase_orders', 'po_details'];

foreach ($tables as $table) {
    try {
        $db->exec("ALTER TABLE $table ADD COLUMN discount_amount DECIMAL(12,2) DEFAULT 0.00 AFTER discount_percent");
        echo "Successfully added discount_amount to $table\n";
    } catch (PDOException $e) {
        if ($e->getCode() == '42S21') {
            echo "Column discount_amount already exists in $table\n";
        } else {
            echo "Error adding discount_amount to $table: " . $e->getMessage() . "\n";
        }
    }
}
