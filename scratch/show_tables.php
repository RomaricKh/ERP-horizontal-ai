<?php
require __DIR__ . '/../bootstrap.php';
$db = (new \App\Config\Database())->getConnection();
print_r($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
