<?php
require 'bootstrap.php';
$db = (new \App\Config\Database())->getConnection();
$stmt = $db->query("SELECT * FROM batch_transactions LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
