<?php
require 'bootstrap.php';
$db = (new \App\Config\Database())->getConnection();

// The 6 missing sales invoices for Item 12:
// 0902 (Qty: 3)
// 0903 (Qty: 4)
// 0905 (Qty: 3)
// 0907 (Qty: 3)
// 0909 (Qty: 3)
// INV-2026-004 (Qty: 8)

$missingInvoices = [
    ['qty' => 3, 'ref' => 'INV-0902', 'notes' => 'Retroactive batch deduction for missing sale 0902'],
    ['qty' => 4, 'ref' => 'INV-0903', 'notes' => 'Retroactive batch deduction for missing sale 0903'],
    ['qty' => 3, 'ref' => 'INV-0905', 'notes' => 'Retroactive batch deduction for missing sale 0905'],
    ['qty' => 3, 'ref' => 'INV-0907', 'notes' => 'Retroactive batch deduction for missing sale 0907'],
    ['qty' => 3, 'ref' => 'INV-0909', 'notes' => 'Retroactive batch deduction for missing sale 0909'],
    ['qty' => 8, 'ref' => 'INV-2026-004', 'notes' => 'Retroactive batch deduction for missing sale 2026-004']
];

$batchService = new \App\Services\BatchService($db);
// Set mock context for the script
$userStmt = $db->query("SELECT id FROM users LIMIT 1");
$userId = $userStmt->fetchColumn();
$businessId = 3; // From our previous queries

// Emulate AuthContext
\App\Support\AuthContext::set((object)[
    'user_id' => $userId,
    'business_id' => $businessId,
    'tenant_id' => 1
]);

foreach ($missingInvoices as $inv) {
    echo "Deducting {$inv['qty']} for {$inv['ref']}...\n";
    $batchService->consumeFEFO(12, $businessId, $inv['qty'], $inv['ref'], $inv['notes']);
}

echo "Done.\n";
