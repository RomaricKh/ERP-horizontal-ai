<?php
require 'bootstrap.php';
$db = (new \App\Config\Database())->getConnection();

echo "--- INDIVIDUAL STOCK TRANSACTIONS ---\n";
$stmt = $db->query("SELECT * FROM stock_transactions WHERE item_id = 12 ORDER BY created_at ASC");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
