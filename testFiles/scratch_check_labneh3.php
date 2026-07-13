<?php
require 'bootstrap.php';
$db = (new \App\Config\Database())->getConnection();

$itemId = 12;

$stmt = $db->query("SELECT * FROM stock_transactions WHERE item_id = $itemId");
$stockTrans = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $db->query("SELECT bt.* FROM batch_transactions bt JOIN item_batches ib ON ib.id = bt.batch_id WHERE ib.item_id = $itemId");
$batchTrans = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Print stock trans fields
if (!empty($stockTrans)) {
    echo "Stock Transaction Keys: " . implode(', ', array_keys($stockTrans[0])) . "\n";
}

// Map stock trans by reference
$stockByRef = [];
foreach ($stockTrans as $st) {
    // maybe type is 'type'?
    $type = $st['type'] ?? $st['transaction_type'] ?? 'UNKNOWN';
    if (!isset($stockByRef[$st['reference']])) {
        $stockByRef[$st['reference']] = 0;
    }
    // Is quantity positive/negative or absolute?
    if ($type === 'IN') {
        $stockByRef[$st['reference']] += $st['quantity'];
    } elseif ($type === 'OUT') {
        $stockByRef[$st['reference']] -= $st['quantity'];
    } else {
        // Just sum if it's signed
        $stockByRef[$st['reference']] += $st['quantity'];
    }
}

// Map batch trans by reference
$batchByRef = [];
foreach ($batchTrans as $bt) {
    if (!isset($batchByRef[$bt['reference']])) {
        $batchByRef[$bt['reference']] = 0;
    }
    if ($bt['transaction_type'] === 'IN') {
        $batchByRef[$bt['reference']] += $bt['quantity'];
    } elseif ($bt['transaction_type'] === 'OUT') {
        $batchByRef[$bt['reference']] -= $bt['quantity'];
    }
}

foreach ($stockByRef as $ref => $qty) {
    $bQty = $batchByRef[$ref] ?? 0;
    if (abs($qty - $bQty) > 0.001) {
        echo "Mismatch for reference $ref: Stock = $qty, Batch = $bQty\n";
    }
}

foreach ($batchByRef as $ref => $qty) {
    if (!isset($stockByRef[$ref])) {
        echo "In Batch but not in Stock for reference $ref: Batch = $qty\n";
    }
}

