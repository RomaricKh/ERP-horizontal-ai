<?php
require 'bootstrap.php';
$db = (new \App\Config\Database())->getConnection();

// Let's find the latest business profit
$stmt = $db->query("SELECT id FROM business_profit ORDER BY id DESC LIMIT 1");
$profitId = $stmt->fetchColumn();

if (!$profitId) {
    die("No profit record found");
}

echo "Reverting profit ID $profitId...\n";

$db->beginTransaction();

try {
    $stmt = $db->prepare("SELECT * FROM profit_distributions WHERE business_profit_id = ?");
    $stmt->execute([$profitId]);
    $distributions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($distributions as $dist) {
        $slipId = $dist['payment_slip_id'];
        
        // Find slip lines to revert balances correctly
        $linesStmt = $db->prepare("SELECT * FROM payment_slip_lines WHERE payment_slip_id = ?");
        $linesStmt->execute([$slipId]);
        $lines = $linesStmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($lines as $line) {
            $amountBc = $line['amount_bc'];
            $amountFc = $line['amount_fc'];
            $accId = $line['account_id'];
            
            // Add back to account
            $updateAcc = $db->prepare("UPDATE business_accounts SET balance = balance + ?, balance_fc = balance_fc + ? WHERE id = ?");
            $updateAcc->execute([$amountBc, $amountFc, $accId]);
        }

        // Delete payment_allocations
        $delAlloc = $db->prepare("DELETE FROM payment_allocations WHERE payment_slip_id = ?");
        $delAlloc->execute([$slipId]);

        // Delete payment_slips (lines cascade)
        $delSlip = $db->prepare("DELETE FROM payment_slips WHERE id = ?");
        $delSlip->execute([$slipId]);
    }

    $stmt = $db->prepare("DELETE FROM profit_distributions WHERE business_profit_id = ?");
    $stmt->execute([$profitId]);

    $stmt = $db->prepare("DELETE FROM business_profit WHERE id = ?");
    $stmt->execute([$profitId]);

    $db->commit();
    echo "Reverted profit record ID $profitId successfully.\n";
} catch (Exception $e) {
    $db->rollBack();
    echo "Failed: " . $e->getMessage() . "\n";
}
