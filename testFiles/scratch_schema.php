<?php
$pdo = new PDO('mysql:host=localhost;dbname=erp', 'root', '');
$stmt = $pdo->query('SHOW CREATE TABLE collection_slips');
print_r($stmt->fetch());
$stmt = $pdo->query('SHOW CREATE TABLE payment_slips');
print_r($stmt->fetch());
