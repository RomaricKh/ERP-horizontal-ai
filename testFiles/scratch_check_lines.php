<?php
require 'bootstrap.php';
$db = (new \App\Config\Database())->getConnection();
$stmt = $db->query('SELECT * FROM payment_slip_lines ORDER BY id DESC LIMIT 5');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
