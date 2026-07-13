<?php
require 'bootstrap.php';
$db = (new \App\Config\Database())->getConnection();

$stmt = $db->query("SELECT * FROM partner_shares");
$shares = $stmt->fetchAll(PDO::FETCH_ASSOC);

print_r($shares);
