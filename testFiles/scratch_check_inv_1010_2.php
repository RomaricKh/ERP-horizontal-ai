<?php
require 'bootstrap.php';
$db = (new \App\Config\Database())->getConnection();

// Mock AuthContext
\App\Support\AuthContext::set((object)[
    'user_id' => 3,
    'tenant_id' => 3,
    'business_id' => 3,
    'role' => 'ADMIN'
]);

$service = new \App\Services\SalesInvoiceService($db);

$stmt = $db->query("SELECT * FROM sales_invoices WHERE invoice_number = '1010'");
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

echo "Invoice ID: " . $invoice['id'] . "\n";
echo "Customer ID: " . $invoice['customer_id'] . "\n";

echo "Payment Status: " . $service->computePaymentStatus($invoice['id']) . "\n";

$stmt2 = $db->prepare("
    SELECT COALESCE(SUM(cs.total_bc - COALESCE(alloc.allocated_bc, 0)), 0) AS total_unlinked
    FROM collection_slips cs
    LEFT JOIN (
        SELECT collection_slip_id, SUM(amount_bc) AS allocated_bc
        FROM collection_allocations
        GROUP BY collection_slip_id
    ) alloc ON alloc.collection_slip_id = cs.id
    WHERE cs.payee_id = ?
      AND cs.business_id = ?
      AND (cs.total_bc - COALESCE(alloc.allocated_bc, 0)) > 0
");
$stmt2->execute([$invoice['customer_id'], $invoice['business_id']]);
echo "Unlinked collections for customer: " . $stmt2->fetchColumn() . "\n";

