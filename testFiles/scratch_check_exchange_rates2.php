<?php
require 'bootstrap.php';
$db = (new \App\Config\Database())->getConnection();

// Fetch exchange rates
$stmt = $db->query('SELECT * FROM exchange_rates ORDER BY rate_date DESC LIMIT 5');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
