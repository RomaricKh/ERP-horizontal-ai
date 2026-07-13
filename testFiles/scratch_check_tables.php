<?php
require 'bootstrap.php';
$db = (new \App\Config\Database())->getConnection();

$stmt = $db->query("SHOW TABLES");
print_r($stmt->fetchAll(PDO::FETCH_COLUMN));
