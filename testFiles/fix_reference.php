<?php
require 'bootstrap.php';

if ($argc < 4) {
    echo "Usage: php fix_reference.php <TYPE> <OLD_NUMBER> <NEW_NUMBER>\n";
    echo "TYPE can be: SI (Sales Invoice) or PO (Purchase Order)\n";
    echo "Example: php fix_reference.php SI 0901 0901-CORRECTED\n";
    exit(1);
}

$type = strtoupper($argv[1]);
$oldNumber = $argv[2];
$newNumber = $argv[3];

if ($type !== 'SI' && $type !== 'PO') {
    echo "Invalid type. Use SI or PO.\n";
    exit(1);
}

$db = (new \App\Config\Database())->getConnection();

if ($type === 'SI') {
    $oldRef = 'INV-' . $oldNumber;
    $newRef = 'INV-' . $newNumber;
    $noteSearch = '#' . $oldNumber;
    $noteReplace = '#' . $newNumber;
} else {
    $oldRef = 'PO-' . $oldNumber;
    $newRef = 'PO-' . $newNumber;
    // Purchase Orders sometimes use PURCHASE_ORDER:ID as reference, let's also check for that if needed.
    // But the notes typically say "Stock IN from Purchase Order #<number>"
    $noteSearch = '#' . $oldNumber;
    $noteReplace = '#' . $newNumber;
}

$db->beginTransaction();
try {
    // 1. Update stock_transactions

    // Also update where reference_type matches but reference might be missing?
    // In our codebase, the reference string is actually stored in the 'reference' column... wait, stock_transactions doesn't have a 'reference' column! It has 'reference_type' and 'reference_id', and 'notes'.
    // Let me double check if stock_transactions has 'reference' column.
    // Actually earlier I saw `reference` column in `batch_transactions`. 
    // In `stock_transactions`, it's `reference_type`, `reference_id`, and `notes`. Wait, the notes contain the invoice number.
    // Let's just update `notes` for stock_transactions where notes LIKE '%#oldNumber%'
    $stmt1 = $db->prepare("
        UPDATE stock_transactions 
        SET notes = REPLACE(notes, :note_search, :note_replace)
        WHERE notes LIKE :note_like
    ");
    $stmt1->execute([
        'note_search' => $noteSearch,
        'note_replace' => $noteReplace,
        'note_like' => '%' . $noteSearch . '%'
    ]);
    $stockUpdated = $stmt1->rowCount();

    // 2. Update batch_transactions
    // batch_transactions DOES have a `reference` column (e.g. INV-0915)
    $stmt2 = $db->prepare("
        UPDATE batch_transactions 
        SET reference = :new_ref,
            notes = REPLACE(notes, :note_search, :note_replace)
        WHERE reference = :old_ref
    ");
    $stmt2->execute([
        'new_ref' => $newRef,
        'note_search' => $noteSearch,
        'note_replace' => $noteReplace,
        'old_ref' => $oldRef
    ]);
    $batchUpdated = $stmt2->rowCount();

    $db->commit();
    echo "Successfully updated!\n";
    echo "- {$stockUpdated} stock_transactions updated.\n";
    echo "- {$batchUpdated} batch_transactions updated.\n";

} catch (Exception $e) {
    $db->rollBack();
    echo "Error updating records: " . $e->getMessage() . "\n";
}
