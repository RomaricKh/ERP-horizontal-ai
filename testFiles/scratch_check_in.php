<?php
require 'bootstrap.php';
$db = (new \App\Config\Database())->getConnection();

// Stock INs
$stmt = $db->query("SELECT * FROM stock_transactions WHERE item_id = 12 AND type = 'IN'");
$st = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Batch INs
$stmt = $db->query("SELECT * FROM batch_transactions WHERE batch_id IN (SELECT id FROM item_batches WHERE item_id = 12) AND transaction_type = 'IN'");
$bt = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Stock IN transactions:\n";
foreach ($st as $row) {
    echo "ID: {$row['id']}, Qty: {$row['quantity']}, Ref: {$row['reference']}, Notes: {$row['notes']}\n";
}

echo "\nBatch IN transactions:\n";
foreach ($bt as $row) {
    echo "ID: {$row['id']}, Qty: {$row['quantity']}, Ref: {$row['reference']}, Notes: {$row['notes']}\n";
}
