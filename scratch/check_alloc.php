<?php
require __DIR__ . '/../bootstrap.php';
$db = (new \App\Config\Database())->getConnection();
print_r($db->query('SELECT id, amount_bc, amount_fc FROM collection_allocations')->fetchAll(PDO::FETCH_ASSOC));
