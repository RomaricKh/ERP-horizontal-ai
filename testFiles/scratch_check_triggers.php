<?php
require 'bootstrap.php';
$db = (new \App\Config\Database())->getConnection();
$stmt = $db->query("SHOW TRIGGERS LIKE 'item_batches'");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
