<?php
require __DIR__ . '/../bootstrap.php';
$db = (new \App\Config\Database())->getConnection();
print_r($db->query('DESCRIBE collection_slips')->fetchAll(PDO::FETCH_ASSOC));
