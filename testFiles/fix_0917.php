<?php
require 'bootstrap.php';
$db = (new \App\Config\Database())->getConnection();

// Let's find the stock_transactions with notes '%0911%' created on 2026-06-24
$stmt = $db->query("SELECT * FROM stock_transactions WHERE notes LIKE '%#0911%' AND DATE(created_at) = '2026-06-24'");
$stockToFix = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($stockToFix) . " stock transactions to fix to 0917\n";

$db->beginTransaction();
try {
    $stmt1 = $db->prepare("
        UPDATE stock_transactions 
        SET notes = REPLACE(notes, '#0911', '#0917')
        WHERE id = :id
    ");
    foreach ($stockToFix as $s) {
        $stmt1->execute(['id' => $s['id']]);
    }

    $stmt2 = $db->query("SELECT * FROM batch_transactions WHERE notes LIKE '%#0911%' AND DATE(created_at) = '2026-06-24'");
    $batchToFix = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    echo "Found " . count($batchToFix) . " batch transactions to fix to 0917\n";

    $stmt3 = $db->prepare("
        UPDATE batch_transactions 
        SET reference = 'INV-0917',
            notes = REPLACE(notes, '#0911', '#0917')
        WHERE id = :id
    ");
    foreach ($batchToFix as $b) {
        $stmt3->execute(['id' => $b['id']]);
    }

    $db->commit();
    echo "Fixed to 0917 successfully.\n";
} catch (Exception $e) {
    $db->rollBack();
    echo "Error: " . $e->getMessage();
}
