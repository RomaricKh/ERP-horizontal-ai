<?php
require 'bootstrap.php';
$db = (new \App\Config\Database())->getConnection();

$profitId = 2;

$db->beginTransaction();

try {
    // 1. Find distributions
    $stmt = $db->prepare("SELECT * FROM profit_distributions WHERE business_profit_id = ?");
    $stmt->execute([$profitId]);
    $distributions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($distributions as $dist) {
        $slipId = $dist['payment_slip_id'];
        $amount = $dist['amount_bc'];

        // Add back to account 5 (since we know it was deducted from account 5 based on timestamp)
        // Note: delta is positive here because we're reverting a deduction
        $updateAcc = $db->prepare("UPDATE business_accounts SET balance = balance + ?, balance_fc = balance_fc + ? WHERE id = 5");
        $updateAcc->execute([$amount, $amount]);

        // Delete payment_allocations
        $delAlloc = $db->prepare("DELETE FROM payment_allocations WHERE payment_slip_id = ?");
        $delAlloc->execute([$slipId]);

        // Delete payment_slips (no slip lines exist due to the old bug)
        $delSlip = $db->prepare("DELETE FROM payment_slips WHERE id = ?");
        $delSlip->execute([$slipId]);
    }

    // 2. Delete the distributions
    $stmt = $db->prepare("DELETE FROM profit_distributions WHERE business_profit_id = ?");
    $stmt->execute([$profitId]);

    // 3. Delete the profit record entirely, so the user can "issue a new one" as requested
    $stmt = $db->prepare("DELETE FROM business_profit WHERE id = ?");
    $stmt->execute([$profitId]);

    $db->commit();
    echo "Reverted profit record ID $profitId and restored account balance.\n";
} catch (Exception $e) {
    $db->rollBack();
    echo "Failed: " . $e->getMessage() . "\n";
}
