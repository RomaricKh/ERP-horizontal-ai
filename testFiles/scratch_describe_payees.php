<?php
require 'bootstrap.php';
$db = \App\Config\Database::getConnection();
$s = $db->query("DESCRIBE payees");
print_r($s->fetchAll(PDO::FETCH_ASSOC));
