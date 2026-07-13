<?php
require 'bootstrap.php';
$db = (new \App\Config\Database())->getConnection();

$stmt = $db->query("SELECT * FROM sales_invoices WHERE invoice_number = '1010'");
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

print_r($invoice);

$stmt2 = $db->query("SELECT * FROM payment_allocations WHERE invoice_id = " . (int)$invoice['id']);
$allocs = $stmt2->fetchAll(PDO::FETCH_ASSOC);
echo "Allocations:\n";
print_r($allocs);
