<?php
require 'bootstrap.php';
$db = \App\Config\Database::getConnection();
print_r($db->query("SELECT * FROM recurrence_instances ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC));
