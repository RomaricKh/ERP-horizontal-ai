<?php
require __DIR__ . '/../bootstrap.php';

$db = (new \App\Config\Database())->getConnection();

$stmt = $db->query("SELECT id, name, currency FROM business_accounts WHERE currency = 'LBP' LIMIT 1");
$lbp = $stmt->fetch(\PDO::FETCH_ASSOC);

$stmt = $db->query("SELECT id, name, currency FROM business_accounts WHERE currency = 'USD' LIMIT 1");
$usd = $stmt->fetch(\PDO::FETCH_ASSOC);

echo "LBP Account: " . $lbp['id'] . "\n";
echo "USD Account: " . $usd['id'] . "\n";
