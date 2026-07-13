<?php
require 'bootstrap.php';
$db = (new \App\Config\Database())->getConnection();

// Set up AuthContext manually for the script
$userStmt = $db->query("SELECT id FROM users LIMIT 1");
$userId = $userStmt->fetchColumn();
\App\Support\AuthContext::set((object)[
    'user_id' => $userId,
    'business_id' => 3, // based on previous queries
    'tenant_id' => 1
]);

$batchService = new \App\Services\BatchService($db);

// We know the item is Labneh 400g (ID 12).
// The credit note returned 3 items, which never got put into a batch.
// Shelf life for Labneh is 20 days.
$shelfLife = 20;
$expiryDate = date('Y-m-d', strtotime("+$shelfLife days"));

echo "Creating retro-batch for missing 3 units from Credit Note...\n";

// We won't use reference_id because the Credit Note ID is unknown (though it is CN-2026-001)
// We will just let the batch_transactions log it with notes.
$batchId = $batchService->createBatch(
    12, // itemId
    3,  // quantity
    $expiryDate
);

// We need to update the batch_transaction to clearly say it was from the credit note
// since BatchService defaults to "Initial batch stock from receipt"
$stmt = $db->prepare("UPDATE batch_transactions SET notes = 'Stock IN from Credit Note #CN-2026-001 (Retro-fix)' WHERE batch_id = ? AND transaction_type = 'IN'");
$stmt->execute([$batchId]);

echo "Created batch ID $batchId successfully. Parity restored!\n";
