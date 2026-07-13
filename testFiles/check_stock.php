<?php
require 'bootstrap.php';
$db = (new \App\Config\Database())->getConnection();
$stmt = $db->query("SELECT * FROM items WHERE name LIKE '%Labneh%'");
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

print_r($items);

if (empty($items)) {
    echo "No items found.\n";
    exit;
}
$itemId = $items[0]['id'];

echo "--- STOCK ---\n";
$stmt = $db->query("SELECT * FROM stock WHERE item_id = $itemId");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "--- STOCK TRANSACTIONS ---\n";
$stmt = $db->query("SELECT * FROM stock_transactions WHERE item_id = $itemId ORDER BY created_at ASC");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "--- ITEM BATCHES ---\n";
$stmt = $db->query("SELECT * FROM item_batches WHERE item_id = $itemId");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "--- BATCH TRANSACTIONS ---\n";
$stmt = $db->query("SELECT * FROM batch_transactions WHERE batch_id IN (SELECT id FROM item_batches WHERE item_id = $itemId) ORDER BY created_at ASC");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

