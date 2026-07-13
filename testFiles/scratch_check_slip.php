<?php
require 'bootstrap.php';
$db = (new \App\Config\Database())->getConnection();
$stmt = $db->query("SELECT * FROM payment_slips WHERE id = 34");
$slip = $stmt->fetch(PDO::FETCH_ASSOC);
print_r($slip);
