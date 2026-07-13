<?php
require 'bootstrap.php';
$db = (new \App\Config\Database())->getConnection();

$stmt = $db->query("SELECT * FROM stock_transactions WHERE item_id = 12 AND type = 'OUT' ORDER BY created_at ASC");
$stock_out = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $db->query("SELECT * FROM batch_transactions WHERE batch_id IN (SELECT id FROM item_batches WHERE item_id = 12) AND transaction_type = 'OUT' ORDER BY created_at ASC");
$batch_out = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "--- STOCK OUT ---\n";
foreach ($stock_out as $s) {
    echo "ID: {$s['id']}, Qty: {$s['quantity']}, Ref: {$s['notes']}, Date: {$s['created_at']}\n";
}

echo "\n--- BATCH OUT ---\n";
foreach ($batch_out as $b) {
    echo "ID: {$b['id']}, Qty: {$b['quantity']}, Ref: {$b['notes']}, Date: {$b['created_at']}\n";
}
