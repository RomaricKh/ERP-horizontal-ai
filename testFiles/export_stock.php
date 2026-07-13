<?php
require 'bootstrap.php';
$db = (new \App\Config\Database())->getConnection();

// Item ID for Labneh 400g is 12
$stmt = $db->query("SELECT * FROM stock_transactions WHERE item_id = 12 ORDER BY created_at ASC");
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "| Date | Type | Reference | Quantity | Notes |\n";
echo "|---|---|---|---|---|\n";

foreach ($transactions as $t) {
    // Determine sign for display
    $qty = (float)$t['quantity'];
    $sign = $t['type'] === 'IN' ? '+' : '-';
    
    $date = date('Y-m-d H:i:s', strtotime($t['created_at']));
    $type = $t['type'];
    $ref = $t['reference_type'] . ($t['reference_id'] ? ':' . $t['reference_id'] : '');
    $notes = $t['notes'];
    
    echo "| {$date} | **{$type}** | {$ref} | {$sign}{$qty} | {$notes} |\n";
}
