<?php
require __DIR__ . '/../bootstrap.php';
$db = (new \App\Config\Database())->getConnection();
$stmt = $db->query("SELECT id, business_id, name, currency FROM business_accounts");
print_r($stmt->fetchAll(\PDO::FETCH_ASSOC));
