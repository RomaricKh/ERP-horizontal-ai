<?php
require __DIR__ . '/../bootstrap.php';

use App\Services\CollectionService;
use App\Services\ExchangeRateService;
use App\Services\SettlementEngine;
use App\Repository\CollectionRepository;
use App\Support\AuthContext;

AuthContext::set((object)['id' => 1, 'business_id' => 3]);

$db = (new \App\Config\Database())->getConnection();

$rateService = new ExchangeRateService($db);
$settlement = new SettlementEngine($db);
$collectionRepo = new CollectionRepository($db);

$service = new CollectionService($db, $collectionRepo, $settlement, $rateService);

$stmt = $db->query("SELECT id, name, currency FROM business_accounts WHERE currency = 'LBP' AND business_id = 3 LIMIT 1");
$lbp = $stmt->fetch(\PDO::FETCH_ASSOC);

$stmt = $db->query("SELECT id, name, currency FROM business_accounts WHERE currency = 'USD' AND business_id = 3 LIMIT 1");
$usd = $stmt->fetch(\PDO::FETCH_ASSOC);

$data = [
    'payee_id' => 1, // assuming there's a payee 1
    'slip_number' => 'TEST-COLL-MULTI',
    'slip_date' => date('Y-m-d'),
    'notes' => 'Test multiple lines',
    'lines' => [
        [
            'account_id' => $usd ? $usd['id'] : 1, 
            'method' => 'CASH',
            'currency' => 'USD',
            'exchange_rate' => 1.0,
            'amount_fc' => 50,
            'amount_bc' => 50,
            'notes' => 'Line 1 USD'
        ],
        [
            'account_id' => $lbp ? $lbp['id'] : 2, 
            'method' => 'CASH',
            'currency' => 'LBP',
            'exchange_rate' => 89500,
            'amount_fc' => 89500,
            'amount_bc' => 1,
            'notes' => 'Line 2 LBP equivalent to 1 USD'
        ]
    ]
];

try {
    // Delete old test coll if any
    $stmt = $db->query("SELECT id FROM collection_slips WHERE slip_number = 'TEST-COLL-MULTI'");
    $oldId = $stmt->fetchColumn();
    if ($oldId) {
        $service->delete($oldId);
    }

    $id = $service->create($data);
    echo "Success! Collection ID: $id\n";
    
    // Check balances for account 1 and 2
    $usdId = $usd['id'];
    $lbpId = $lbp['id'];
    $stmt = $db->query("SELECT id, name, balance, balance_fc FROM business_accounts WHERE id IN ($usdId, $lbpId)");
    $accounts = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    print_r($accounts);
    
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n" . $e->getTraceAsString();
}
