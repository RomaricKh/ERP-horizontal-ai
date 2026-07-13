<?php
require 'bootstrap.php';
$db = (new \App\Config\Database())->getConnection();
$stmt = $db->query('SELECT * FROM exchange_rates ORDER BY date DESC LIMIT 5');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
