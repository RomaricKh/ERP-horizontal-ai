<?php
require 'bootstrap.php';
$db = (new \App\Config\Database())->getConnection();

echo "Payment Slip Lines for Account 5:\n";
$stmt = $db->query("SELECT * FROM payment_slip_lines WHERE account_id = 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "Collection Slip Lines for Account 5:\n";
$stmt = $db->query("SELECT * FROM collection_slip_lines WHERE account_id = 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "Account transfers for Account 5:\n";
$stmt = $db->query("SELECT * FROM account_transfers WHERE from_account_id = 5 OR to_account_id = 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

