<?php
require __DIR__ . '/../bootstrap.php';
$db = (new \App\Config\Database())->getConnection();

echo "BUSINESS_ACCOUNTS:\n";
print_r($db->query('DESCRIBE business_accounts')->fetchAll(PDO::FETCH_ASSOC));

echo "\nBUSINESSES:\n";
print_r($db->query('DESCRIBE businesses')->fetchAll(PDO::FETCH_ASSOC));
