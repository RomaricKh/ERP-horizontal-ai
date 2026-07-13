<?php
$db = new PDO('mysql:host=localhost;dbname=erp', 'root', '');
$tables = ['sales_invoices', 'si_details', 'purchase_orders', 'po_details'];
foreach ($tables as $t) {
    echo "TABLE: $t\n";
    $stmt = $db->query("DESCRIBE $t");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
}
