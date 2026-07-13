<?php
require 'bootstrap.php';
$db = (new \App\Config\Database())->getConnection();

$itemId = 12; // Labneh 400g

// Stock Sum
$stmt = $db->query("SELECT transaction_type, SUM(quantity) as total FROM stock_transactions WHERE item_id = $itemId GROUP BY transaction_type");
$stockSums = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Batch Sum
$stmt = $db->query("SELECT transaction_type, SUM(quantity) as total FROM batch_transactions WHERE batch_id IN (SELECT id FROM item_batches WHERE item_id = $itemId) GROUP BY transaction_type");
$batchSums = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Stock Sums: \n";
print_r($stockSums);

echo "Batch Sums: \n";
print_r($batchSums);

$stmt = $db->query("SELECT quantity FROM stock WHERE item_id = $itemId");
$stock = $stmt->fetchColumn();

$stmt = $db->query("SELECT SUM(quantity) FROM item_batches WHERE item_id = $itemId");
$batchTotal = $stmt->fetchColumn();

echo "Stock table quantity: $stock\n";
echo "Item batches sum: $batchTotal\n";

// Let's find exactly which transactions exist in stock_transactions but NOT in batch_transactions, or vice-versa.
$stmt = $db->query("
    SELECT st.reference, st.transaction_type, st.quantity as stock_qty, bt.quantity as batch_qty
    FROM stock_transactions st
    LEFT JOIN (
        SELECT reference, transaction_type, SUM(quantity) as quantity
        FROM batch_transactions
        WHERE batch_id IN (SELECT id FROM item_batches WHERE item_id = $itemId)
        GROUP BY reference, transaction_type
    ) bt ON st.reference = bt.reference AND st.transaction_type = bt.transaction_type
    WHERE st.item_id = $itemId
    HAVING stock_qty != COALESCE(batch_qty, 0)
");
echo "Mismatched transactions:\n";
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

