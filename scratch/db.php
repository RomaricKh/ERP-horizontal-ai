<?php
$db = new PDO('mysql:host=localhost;dbname=erp', 'root', '');
$res = $db->query('SELECT * FROM collection_allocations')->fetchAll(PDO::FETCH_ASSOC);
print_r($res);
