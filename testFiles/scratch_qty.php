<?php
require 'bootstrap.php';
$db = (new \App\Config\Database())->getConnection();

// Stock Trans sum
$stmt = $db->query("SELECT type, SUM(quantity) as qty FROM stock_transactions WHERE item_id = 12 GROUP BY type");
$st = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
echo "Stock Trans IN: " . ($st['IN'] ?? 0) . ", OUT: " . ($st['OUT'] ?? 0) . "\n";
echo "Expected Stock: " . (($st['IN'] ?? 0) - ($st['OUT'] ?? 0)) . "\n";

// Batch Trans sum
$stmt = $db->query("SELECT transaction_type, SUM(quantity) as qty FROM batch_transactions WHERE batch_id IN (SELECT id FROM item_batches WHERE item_id = 12) GROUP BY transaction_type");
$bt = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
echo "Batch Trans IN: " . ($bt['IN'] ?? 0) . ", OUT: " . ($bt['OUT'] ?? 0) . "\n";
echo "Expected Batch Sum: " . (($bt['IN'] ?? 0) - ($bt['OUT'] ?? 0)) . "\n";

// Current Stock table
$stmt = $db->query("SELECT quantity FROM stock WHERE item_id = 12");
echo "Stock Table quantity: " . $stmt->fetchColumn() . "\n";

// Current Batches Table sum
$stmt = $db->query("SELECT SUM(quantity) FROM item_batches WHERE item_id = 12");
echo "Item Batches sum: " . $stmt->fetchColumn() . "\n";

