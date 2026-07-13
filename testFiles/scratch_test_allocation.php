<?php
require 'bootstrap.php';
$db = \App\Config\Database::getConnection();

// Mock AuthContext
class MockContext {
    public $business_id = 1;
    public $user_id = 1;
    public $tenant_id = 1;
    public $is_tenant_owner = true;
    public $permissions = [];
}
\App\Support\AuthContext::set(new MockContext());

$engine = new \App\Services\SettlementEngine($db);
$salesService = new \App\Services\SalesInvoiceService($db);
$collectionService = new \App\Services\CollectionService($db);

// 1. Create a collection slip with unallocated amount
$customerId = 10;
$collectionId = $collectionService->create([
    'slip_number' => 'TEST-COL-' . time(),
    'slip_date' => date('Y-m-d'),
    'payee_id' => $customerId,
    'lines' => [
        [
            'account_id' => 1,
            'method' => 'CASH',
            'amount_bc' => 100,
            'amount_fc' => 100,
            'currency' => 'USD'
        ]
    ]
], null);
echo "Created unallocated Collection Slip ID: $collectionId\n";

// 2. Create a Sales Invoice
$invoiceId = $salesService->create([
    'invoice_number' => 'TEST-INV-' . time(),
    'invoice_date' => date('Y-m-d'),
    'customer_id' => $customerId,
    'currency' => 'USD',
    'lines' => [
        [
            'service_id' => 1, // assuming service 1 exists
            'quantity' => 1,
            'unit_price_bc' => 150,
            'unit_price_fc' => 150,
            'tax_percent' => 0
        ]
    ]
]);
echo "Created Invoice ID: $invoiceId\n";

// 3. Mark as DELIVERED
$salesService->updateStatus($invoiceId, 'DELIVERED');

// 4. Verify the invoice payment status and allocations
$stmt = $db->query("SELECT status, total_amount_bc FROM sales_invoices WHERE id = $invoiceId");
$inv = $stmt->fetch(PDO::FETCH_ASSOC);
echo "Invoice Status: {$inv['status']}, Total: {$inv['total_amount_bc']}\n";

$stmt = $db->query("SELECT * FROM collection_allocations WHERE reference_id = $invoiceId AND reference_type = 'SALES_INVOICE'");
$allocations = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Allocations:\n";
print_r($allocations);
