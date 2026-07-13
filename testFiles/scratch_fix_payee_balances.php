<?php
require __DIR__ . '/bootstrap.php';
$db = \App\Config\Database::getConnection();

$stmt = $db->query('SELECT id, balance FROM payees');
$payees = $stmt->fetchAll(PDO::FETCH_ASSOC);

$updatedCount = 0;

foreach ($payees as $p) {
    $payeeId = $p['id'];
    $balance = 0.0;
    
    // Sales Invoices
    $stmt = $db->prepare("SELECT COALESCE(SUM(total_amount_bc), 0) FROM sales_invoices WHERE customer_id = ? AND status != 'CANCELLED'");
    $stmt->execute([$payeeId]);
    $balance += (float)$stmt->fetchColumn();
    
    // POs
    $stmt = $db->prepare("SELECT COALESCE(SUM(total_amount_bc), 0) FROM purchase_orders WHERE supplier_id = ? AND status != 'CANCELLED'");
    $stmt->execute([$payeeId]);
    $balance -= (float)$stmt->fetchColumn();
    
    // Expenses
    $stmt = $db->prepare("SELECT COALESCE(SUM(amount_bc), 0) FROM expenses WHERE payee_id = ?");
    $stmt->execute([$payeeId]);
    $balance -= (float)$stmt->fetchColumn();
    
    // Salaries
    $stmt = $db->prepare("SELECT COALESCE(SUM(net_amount_bc), 0) FROM salaries WHERE payee_id = ?");
    $stmt->execute([$payeeId]);
    $balance -= (float)$stmt->fetchColumn();
    
    // Payments
    $stmt = $db->prepare("SELECT COALESCE(SUM(total_bc), 0) FROM payment_slips WHERE payee_id = ?");
    $stmt->execute([$payeeId]);
    $balance += (float)$stmt->fetchColumn();
    
    // Collections
    $stmt = $db->prepare("SELECT COALESCE(SUM(total_bc), 0) FROM collection_slips WHERE payee_id = ?");
    $stmt->execute([$payeeId]);
    $balance -= (float)$stmt->fetchColumn();
    
    // Debit Notes
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(dn.total_amount_bc), 0)
        FROM debit_notes dn
        JOIN purchase_orders po ON po.id = dn.purchase_order_id
        WHERE po.supplier_id = ? AND dn.status = 'ISSUED'
    ");
    $stmt->execute([$payeeId]);
    $balance += (float)$stmt->fetchColumn();
    
    // Credit Notes
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(cn.total_amount_bc), 0)
        FROM credit_notes cn
        JOIN sales_invoices si ON si.id = cn.sales_invoice_id
        WHERE si.customer_id = ? AND cn.status = 'ISSUED'
    ");
    $stmt->execute([$payeeId]);
    $balance -= (float)$stmt->fetchColumn();
    
    // Profit Distributions
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(pd.amount_bc), 0)
        FROM profit_distributions pd
        JOIN business_profit bp ON bp.id = pd.business_profit_id
        WHERE pd.partner_id = ? AND bp.status = 'DISTRIBUTED'
    ");
    $stmt->execute([$payeeId]);
    $balance -= (float)$stmt->fetchColumn();
    
    $actual = round((float)$p['balance'], 2);
    $calculated = round($balance, 2);
    
    if (abs($actual - $calculated) > 0.01) {
        $stmtUpdate = $db->prepare("UPDATE payees SET balance = ? WHERE id = ?");
        $stmtUpdate->execute([$calculated, $payeeId]);
        $updatedCount++;
        echo "Updated payee $payeeId: $actual -> $calculated\n";
    }
}

echo "Done. Updated $updatedCount payees.\n";
