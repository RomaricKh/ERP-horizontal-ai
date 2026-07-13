<?php
require 'bootstrap.php';
$db = (new \App\Config\Database())->getConnection();

$stmt = $db->query("SELECT * FROM accounting_entries WHERE account_id = 5 ORDER BY id DESC LIMIT 10");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
