<?php
require __DIR__ . '/../bootstrap.php';
$db = (new \App\Config\Database())->getConnection();

// Fix Collection Allocations
$stmt = $db->query("
    SELECT ca.id, ca.amount_bc, ca.amount_fc, si.total_amount_fc, si.total_amount_bc 
    FROM collection_allocations ca
    JOIN sales_invoices si ON ca.reference_type = 'SALES_INVOICE' AND ca.reference_id = si.id
");
$colls = $stmt->fetchAll(PDO::FETCH_ASSOC);

$updateStmt = $db->prepare("UPDATE collection_allocations SET amount_fc = :new_fc WHERE id = :id");
$fixedColls = 0;
foreach ($colls as $ca) {
    if ($ca['total_amount_bc'] > 0) {
        $correctFc = ($ca['amount_bc'] / $ca['total_amount_bc']) * $ca['total_amount_fc'];
        $correctFc = round($correctFc, 2);
        
        if (abs($correctFc - $ca['amount_fc']) > 0.05) { // Needs fixing
            $updateStmt->execute(['new_fc' => $correctFc, 'id' => $ca['id']]);
            $fixedColls++;
        }
    }
}

// Fix Payment Allocations
$stmt = $db->query("
    SELECT pa.id, pa.amount_bc, pa.amount_fc, po.total_amount_fc, po.total_amount_bc 
    FROM payment_allocations pa
    JOIN purchase_orders po ON pa.reference_type = 'PURCHASE_ORDER' AND pa.reference_id = po.id
");
$pays = $stmt->fetchAll(PDO::FETCH_ASSOC);

$updateStmt = $db->prepare("UPDATE payment_allocations SET amount_fc = :new_fc WHERE id = :id");
$fixedPays = 0;
foreach ($pays as $pa) {
    if ($pa['total_amount_bc'] > 0) {
        $correctFc = ($pa['amount_bc'] / $pa['total_amount_bc']) * $pa['total_amount_fc'];
        $correctFc = round($correctFc, 2);
        
        if (abs($correctFc - $pa['amount_fc']) > 0.05) { // Needs fixing
            $updateStmt->execute(['new_fc' => $correctFc, 'id' => $pa['id']]);
            $fixedPays++;
        }
    }
}

echo "Fixed Collections: $fixedColls\nFixed Payments: $fixedPays\n";
