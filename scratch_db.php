<?php
require_once __DIR__ . '/bootstrap.php';
use App\Config\Database;

try {
    $db = Database::getConnection();
    
    $stmt = $db->query("SELECT id, name, currency, balance, balance_fc FROM business_accounts WHERE business_id = 3");
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "business_accounts:\n";
    print_r($accounts);
    
    $stmt = $db->query("SELECT COALESCE(SUM(total_bc), 0) FROM collection_slips WHERE business_id = 3");
    $collections = $stmt->fetchColumn();
    
    $stmt = $db->query("SELECT COALESCE(SUM(total_bc), 0) FROM payment_slips WHERE business_id = 3");
    $payments = $stmt->fetchColumn();
    
    echo "Slips Balance: " . ($collections - $payments) . "\n";
    
} catch (Exception $e) {
    echo $e->getMessage();
}
