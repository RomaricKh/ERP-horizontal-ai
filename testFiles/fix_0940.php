<?php
$pdo = new PDO('mysql:host=localhost;dbname=erp', 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $pdo->beginTransaction();

    // 1. Check current invoice
    $stmt = $pdo->query("SELECT * FROM sales_invoices WHERE id = 46");
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) {
        throw new Exception("Invoice 46 not found.");
    }
    
    // 2. Check si_details
    $stmt = $pdo->query("SELECT * FROM si_details WHERE id = 228");
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) {
        throw new Exception("Item 228 not found.");
    }
    
    if ($item['unit_price_fc'] != 2.25) {
        throw new Exception("Item unit price is not 2.25, it is {$item['unit_price_fc']}. Already fixed?");
    }

    // 3. Check payee
    $stmt = $pdo->query("SELECT * FROM payees WHERE id = 35");
    $payee = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$payee) {
        throw new Exception("Payee 35 not found.");
    }

    echo "Before Update:\n";
    echo "- Invoice total_amount_fc: {$invoice['total_amount_fc']}\n";
    echo "- Item unit_price_fc: {$item['unit_price_fc']} | total_price_fc: {$item['total_price_fc']}\n";
    echo "- Payee balance: {$payee['balance']}\n\n";

    // 4. Update si_details
    $pdo->exec("UPDATE si_details SET unit_price_fc = 2.00, unit_price_bc = 2.00, total_price_fc = 16.00, total_price_bc = 16.00 WHERE id = 228");
    
    // 5. Update sales_invoices
    $pdo->exec("UPDATE sales_invoices SET total_amount_fc = total_amount_fc - 2.00, total_amount_bc = total_amount_bc - 2.00 WHERE id = 46");
    
    // 6. Update payee balance
    $pdo->exec("UPDATE payees SET balance = balance - 2.00 WHERE id = 35");

    $pdo->commit();
    echo "Updates committed successfully.\n\n";

    // Verify after update
    $invoice = $pdo->query("SELECT * FROM sales_invoices WHERE id = 46")->fetch(PDO::FETCH_ASSOC);
    $item = $pdo->query("SELECT * FROM si_details WHERE id = 228")->fetch(PDO::FETCH_ASSOC);
    $payee = $pdo->query("SELECT * FROM payees WHERE id = 35")->fetch(PDO::FETCH_ASSOC);

    echo "After Update:\n";
    echo "- Invoice total_amount_fc: {$invoice['total_amount_fc']}\n";
    echo "- Item unit_price_fc: {$item['unit_price_fc']} | total_price_fc: {$item['total_price_fc']}\n";
    echo "- Payee balance: {$payee['balance']}\n";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "Transaction failed: " . $e->getMessage() . "\n";
}
