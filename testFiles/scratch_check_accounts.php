<?php
require 'bootstrap.php';
$db = (new \App\Config\Database())->getConnection();

$stmt = $db->query("SELECT * FROM accounts");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt = $db->query("SELECT * FROM account_transactions ORDER BY id DESC LIMIT 10");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
